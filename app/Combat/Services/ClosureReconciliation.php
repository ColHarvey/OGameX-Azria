<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Causality\CausalEventSliceClaim;
use OGame\Combat\Causality\CausalEventSource;
use OGame\Combat\Causality\CausalOrderReconciler;
use OGame\Combat\Causality\CausalWindow;
use OGame\Combat\Causality\PartitionBarrier;
use OGame\Combat\Causality\ReconciledEvent;
use OGame\Combat\Causality\VerifiedCompleteEventSlice;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Projection\MissileStrikeProjection;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\EffectOrderKey;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\SnapshotContributionSet;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use RuntimeException;

/**
 * La fermeture reconciliee : les faits relus sous verrou, le reconciliateur qui decide, les
 * gestionnaires canoniques qui appliquent, et la reserve protegee qui en resulte.
 *
 * ## Une seule decision autoritative
 *
 * Aucune regle temporelle ne vit ici. La tranche est lue par `CausalEventReader`, verifiee complete
 * contre la barriere de partition, et c'est `CausalOrderReconciler` — pur, versionne — qui dit de
 * chaque evenement s'il entre dans la photographie, s'il doit d'abord etre applique, ou s'il y est
 * deja. Ce service execute ce plan, dans l'ordre des effets, et rien d'autre.
 *
 * ## Ce qui est applique, et par qui
 *
 * Une **arrivee** admissible est livree par son gestionnaire canonique, `updateMission()`, idempotent
 * par `processed` : ce que le travailleur a deja fait ne se refait pas, ce qu'il n'a pas encore fait
 * se fait ici, sous les verrous de la fermeture. Les arrivees qui ouvrent un combat ou renforcent la
 * defense ne sont pas livrees ici : les selecteurs d'admission les decident, sous la meme fenetre, et
 * les inscrivent eux-memes.
 *
 * ## Les files : appliquees au monde, comptees a moitie
 *
 * `updateUnitQueue()` et ses voisines traitent **toute la file echue** du corps, pas l'element qu'on
 * leur designe : il n'existe pas de gestionnaire d'un achevement precis. La fermeture les appelle
 * quand meme, et c'est le bon choix : ces achevements **sont echus**, le monde a le droit d'avancer,
 * et leur refuser l'application n'y changerait rien — il les appliquerait a la page suivante.
 *
 * Ce qui compte est ailleurs : la photographie n'en retient que l'element **admissible**. Les unites
 * inadmissibles existent donc dans le monde sans se battre, et c'est exactement ce qu'on veut. Le
 * drainage a lieu **avant** toute mesure de delta (voir `drainTheQueuesFirst()`).
 *
 * **Compter sans appliquer serait une faute de conservation**, et c'est ce que faisait la version
 * precedente : la bataille tuait des unites que le corps ne portait pas, le reglement retirait ce
 * qui n'existait pas, et le monde, en appliquant la file plus tard, **ressuscitait** ce qui venait de
 * mourir. `AppliedBeforeSnapshot` dit ce qu'il faut faire dans l'ordre : applique **puis**
 * photographie, jamais photographie sans application.
 *
 * ## La reserve protegee
 *
 * L'etat d'ouverture, plus la cargaison de chaque livraison admissible qui n'y etait pas deja. Jamais
 * le stock vivant : la production, les livraisons decidees apres l'ouverture, tout ce que le monde a
 * fait depuis reste au monde. Le reglement, lui, debitera contre le stock vivant avec son minimum.
 */
final class ClosureReconciliation
{
    public function __construct(
        private CausalEventReader $reader = new CausalEventReader(),
        private CausalOrderReconciler $reconciler = new CausalOrderReconciler(),
        private CausalEventOrderRegistry|null $orders = null,
        private CombatEffectLedger $ledger = new CombatEffectLedger(),
    ) {
    }

    public function reconcile(CombatInstance $combat, int $targetBodyId, int $openedAt, int $closedAt): ReconciledClosure
    {
        $ouverture = OpeningStateRecorder::protectedStateOf($combat);
        $ordre = ($this->orders ?? CausalEventOrderRegistry::default())->forVersion((string)$combat->causal_order_version);
        $fenetre = new CausalWindow($openedAt, $closedAt, $ordre);
        // **Le curseur de la partition couvre la fermeture elle-meme.** Une barriere porte le rang zero,
        // que tout evenement reel depasse : posee a `$closedAt`, elle ne couvrirait que ce qui precede
        // strictement, et la tranche ne pourrait pas se dire complete au sujet d'un effet tombant
        // exactement a la fermeture — l'initiatrice d'une fenetre nulle, par exemple. Posee juste
        // apres, elle couvre ce que la lecture a vu, et le reconciliateur exclut ensuite ce qui doit
        // l'etre.
        $barriere = new PartitionBarrier((int)$combat->id, $targetBodyId, EffectOrderKey::barrierAt($closedAt + 1, $ordre));
        $proprietaire = (int)(DB::table('planets')->where('id', $targetBodyId)->value('user_id') ?? 0);

        $evenements = $this->reader->eventsToward($targetBodyId, $proprietaire, $closedAt, $ordre, (int)$combat->mission_id);
        $tranche = VerifiedCompleteEventSlice::verifiedUnderLock(
            CausalEventSliceClaim::assembledFrom($evenements, CausalEventSource::cases()),
            $barriere
        );
        $photographie = $this->reconciler->reconcile(
            $ouverture,
            CombatEventIdentity::forFleetArrival((int)$combat->mission_id),
            $fenetre,
            $tranche
        );

        $appliques = $this->apply($photographie->toApply(), $combat, $targetBodyId, $openedAt);

        return new ReconciledClosure(
            $photographie,
            $this->protectedResourcesOf($combat, $photographie->inTheSnapshot()),
            $this->photographedGarrisonOf($combat, $photographie->inTheSnapshot(), $openedAt),
            $this->photographedDefenderOf($combat, $photographie->inTheSnapshot()),
            // **Les reglages ne se reconcilient pas** : aucun evenement causal ne les change, et
            // aucune barriere ne s'y applique. Ils sont lus tels que l'ouverture les a fixes.
            OpeningStateRecorder::openingUniverseOf($combat),
            $appliques,
            $this->inclusionsOf($photographie->inTheSnapshot()),
        );
    }

    /**
     * @param array<int, ReconciledEvent> $aAppliquer
     * @return array<int, string>
     */
    private function apply(array $aAppliquer, CombatInstance $combat, int $targetBodyId, int $openedAt): array
    {
        $appliques = [];

        $this->drainTheQueuesFirst($aAppliquer, $targetBodyId);

        foreach ($aAppliquer as $reconcilie) {
            $mission = $this->reader->missionOf($reconcilie->event->identity);

            // Une file : deja drainee au monde ci-dessus, et comptee par la photographie seule.
            if ($mission === null) {
                continue;
            }

            $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);
            if ($mission->parent_id === null && ($genre->opensCombat() || $genre->reinforcesTheDefence())) {
                continue;
            }

            // **Un effet que le monde a deja livre ne se rejoue pas : son delta se lit au registre.**
            // Le gestionnaire est idempotent, une mesure autour de lui donnerait zero, et la bataille
            // se jouerait contre des defenses qu'un missile a detruites. La porte a inscrit ce que
            // l'effet a change quand elle l'a applique sous la barriere de ce combat.
            if ((int)$mission->processed === 1) {
                $delta = $this->ledger->deltaOf((int)$combat->id, $reconcilie->event->identity);
                if ($delta === null) {
                    // Traite et arrive **avant** l'ouverture : l'etat d'ouverture le reflete deja, et
                    // aucune barriere ne le tenait — pas de ligne, et c'est juste.
                    if ((int)$mission->time_arrival < $openedAt) {
                        continue;
                    }

                    // Traite apres l'ouverture sans ligne : un chemin a applique un effet gouverne
                    // sans passer par la porte. Inventer un delta fausserait la photographie.
                    throw new RuntimeException('Le combat ' . $combat->id . ' ne peut pas photographier l effet ' . $reconcilie->event->identity . ' : applique pendant le ralliement sans passer par la porte, son delta n a pas ete inscrit au registre.');
                }

                continue;
            }

            // **Applique par la porte unique, et lu au registre.** `updateMission()` mesure le corps
            // avant et apres le gestionnaire — cette fermeture tient la barriere — et inscrit le
            // delta reel, quel que soit le sens et le genre : une flotte deposee ajoute, un missile
            // retire. La fermeture ne mesure rien elle-meme : une seule source, la meme que pour un
            // effet que le monde a livre avant elle.
            resolve(FleetMissionService::class)->updateMission($mission);
            $delta = $this->ledger->deltaOf((int)$combat->id, $reconcilie->event->identity);
            if ($delta === null) {
                throw new RuntimeException('Le combat ' . $combat->id . ' a applique l effet ' . $reconcilie->event->identity . ' sans que le registre en garde le delta : la barriere n a pas ete vue par la porte, ou l effet n a pas eu lieu.');
            }

            $appliques[] = $reconcilie->event->identity;
        }

        return $appliques;
    }

    /**
     * Les files echues sont drainees au monde **avant** toute mesure de delta.
     *
     * ## Pourquoi elles sont appliquees
     *
     * Ces achevements **sont echus** : le monde a le droit d'avancer, et le lui refuser ne change
     * rien — il les appliquerait a la page suivante de leur proprietaire. Le gestionnaire traite
     * toute la file echue, admissibles et inadmissibles ensemble, et c'est acceptable parce que la
     * photographie, elle, ne retient que l'admissible : les unites inadmissibles existent dans le
     * monde sans se battre.
     *
     * **Compter sans appliquer serait une faute de conservation** : la bataille tuait des unites que
     * le corps ne portait pas, le reglement retirait ce qui n'existait pas, et le monde, en appliquant
     * la file plus tard, ressuscitait ce qui venait de mourir. `AppliedBeforeSnapshot` dit l'ordre :
     * applique **puis** photographie.
     *
     * ## Pourquoi avant, et pas apres
     *
     * `MissileMission` et `MoonDestructionMission` appellent `$corps->update()`, qui draine la file
     * echue au passage. Un drainage laisse apres la boucle tomberait donc **dans la fenetre de mesure**
     * d'un de ces gestionnaires : l'apport admissible serait compte deux fois — une par le delta, une
     * par la photographie — et l'apport **inadmissible** entrerait dans la photographie par le delta,
     * ce que tout le contrat interdit. Draine d'abord, la file n'est plus dans aucune fenetre.
     *
     * @param array<int, ReconciledEvent> $aAppliquer
     */
    private function drainTheQueuesFirst(array $aAppliquer, int $targetBodyId): void
    {
        // L'appel n'a lieu que si ce combat a une file a compter : sans cela, la fermeture ferait
        // avancer une file qui ne la regarde pas.
        foreach ($aAppliquer as $reconcilie) {
            if ($this->reader->unitQueueOf($reconcilie->event->identity) === null) {
                continue;
            }

            $corps = resolve(PlanetServiceFactory::class)->make($targetBodyId, true);
            if ($corps === null) {
                throw new RuntimeException('Le corps ' . $targetBodyId . ' a disparu pendant la fermeture.');
            }
            $corps->updateUnitQueue();

            return;
        }
    }

    /**
     * @param array<int, ReconciledEvent> $dansLaPhotographie
     */
    private function protectedResourcesOf(CombatInstance $combat, array $dansLaPhotographie): Resources
    {
        $reserve = OpeningStateRecorder::openingResourcesOf($combat);
        $metal = (int)$reserve->metal->get();
        $cristal = (int)$reserve->crystal->get();
        $deuterium = (int)$reserve->deuterium->get();

        foreach ($dansLaPhotographie as $reconcilie) {
            if ($reconcilie->admission !== CausalAdmission::AppliedBeforeSnapshot) {
                continue;
            }
            if (!in_array(SnapshotContribution::DeliveredCargo, $reconcilie->event->contributions, true)) {
                continue;
            }
            $mission = $this->reader->missionOf($reconcilie->event->identity);
            if ($mission === null) {
                continue;
            }
            $metal += ResourceBoundary::wholeUnitsOfCarriedCargo((float)$mission->metal, 'metal', 'fermeture')->units;
            $cristal += ResourceBoundary::wholeUnitsOfCarriedCargo((float)$mission->crystal, 'crystal', 'fermeture')->units;
            $deuterium += ResourceBoundary::wholeUnitsOfCarriedCargo((float)$mission->deuterium, 'deuterium', 'fermeture')->units;
        }

        return new Resources($metal, $cristal, $deuterium, 0);
    }

    /**
     * L'effectif contre lequel la bataille se joue, reconstruit **dans l'ordre des effets**.
     *
     * ## Pourquoi un parcours ordonne, et pas une somme
     *
     * Une somme — ouverture, plus les files admissibles, plus les deltas — suffisait tant que chaque
     * effet etait lineaire. Le missile ne l'est pas : il intercepte avec les antimissiles presents a
     * cet instant, il detruit par priorite dans ce qui est present a cet instant, et le delta que le
     * monde a subi ne dit rien de ce que la photographie admissible aurait perdu. La photographie se
     * construit donc effet par effet, dans l'ordre que le reconciliateur a fixe : chaque salve
     * admissible est **projetee** (`MissileStrikeProjection`, la formule meme du jeu) sur l'etat de la
     * photographie a son rang, avec les antimissiles de la photographie et le blindage de la
     * photographie ; chaque livraison ajoute son delta ; chaque file admissible ajoute son apport.
     *
     * Le monde, lui, a recu chaque salve une fois, par son gestionnaire, sous la porte unique. Les deux
     * ordres — salve appliquee par le monde avant la fermeture, ou par la fermeture elle-meme —
     * donnent la meme photographie, parce que la fermeture ne lit jamais le delta d'une salve : elle
     * lit ses **faits** (`MissileStrikeFacts`) et rejoue.
     *
     * ## Ce que l'on sait, et ce que l'on ne sait pas
     *
     * Les antimissiles de la planete mere d'une lune ne sont pas photographies — la planete mere n'est
     * pas le corps tenu — : la projection prend ceux que le monde avait a l'impact, releves dans les
     * faits. C'est un fait du monde, dit comme tel.
     *
     * @param array<int, ReconciledEvent> $dansLaPhotographie
     */
    private function photographedGarrisonOf(CombatInstance $combat, array $dansLaPhotographie, int $openedAt): UnitCollection
    {
        $effectif = OpeningStateRecorder::openingUnitsOf($combat)->toArray();
        $antimissiles = OpeningStateRecorder::openingInterceptorsOf($combat);
        $blindage = OpeningStateRecorder::openingDefenderOf($combat)->armorLevel;

        foreach ($dansLaPhotographie as $reconcilie) {
            if ($reconcilie->admission !== CausalAdmission::AppliedBeforeSnapshot) {
                continue;
            }
            $identite = $reconcilie->event->identity;

            $recherche = $this->reader->researchOf($identite);
            if ($recherche !== null) {
                if (ObjectService::getResearchObjectById($recherche->objectId)->machine_name === 'armor_technology') {
                    $blindage = max($blindage, $recherche->levelTarget);
                }

                continue;
            }

            $file = $this->reader->unitQueueOf($identite);
            if ($file !== null) {
                if ($file->amount <= 0) {
                    continue;
                }
                $nom = ObjectService::getUnitObjectById($file->objectId)->machine_name;
                if ($nom === 'anti_ballistic_missile') {
                    $antimissiles += $file->amount;
                } elseif (self::fightsInAGarrison($nom)) {
                    $effectif[$nom] = ($effectif[$nom] ?? 0) + $file->amount;
                }

                continue;
            }

            $mission = $this->reader->missionOf($identite);
            if ($mission === null) {
                // Un batiment : rien dans l'effectif.
                continue;
            }
            $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);
            if ($mission->parent_id === null && ($genre->opensCombat() || $genre->reinforcesTheDefence())) {
                // Les arrivees qui composent les camps sont decidees par les selecteurs d'admission.
                continue;
            }
            if ((int)$mission->processed === 1 && (int)$mission->time_arrival < $openedAt) {
                // Traite et arrive avant l'ouverture : l'etat d'ouverture le reflete deja.
                continue;
            }

            if ($mission->parent_id === null && $genre === CombatMissionKind::Missile) {
                $faits = $this->ledger->factsOf((int)$combat->id, $identite);
                if ($faits === null) {
                    throw new RuntimeException('Le combat ' . $combat->id . ' ne peut pas projeter la salve ' . $identite . ' : le registre n en porte pas les faits.');
                }

                $interceptes = MissileStrikeProjection::intercepted($faits->missiles, $antimissiles + $faits->parentInterceptorsBefore);
                foreach (MissileStrikeProjection::destroyedOn($effectif, $faits->missiles - $interceptes, $faits->weaponTech, $blindage, $faits->priority) as $nom => $nombre) {
                    $effectif[$nom] = max(0, ($effectif[$nom] ?? 0) - $nombre);
                }
                // La planete mere prete les siens en premier, comme le gestionnaire le fait.
                $antimissiles = max(0, $antimissiles - max(0, $interceptes - $faits->parentInterceptorsBefore));

                continue;
            }

            $delta = $this->ledger->deltaOf((int)$combat->id, $identite);
            if ($delta === null) {
                throw new RuntimeException('Le combat ' . $combat->id . ' ne peut pas photographier l effet ' . $identite . ' : le registre n en porte pas le delta.');
            }
            foreach ($delta as $nom => $quantite) {
                $effectif[$nom] = max(0, ($effectif[$nom] ?? 0) + $quantite);
            }
        }

        $photographie = new UnitCollection();
        foreach ($effectif as $nom => $quantite) {
            if ($quantite > 0) {
                $photographie->addUnit(ObjectService::getUnitObjectByMachineName((string)$nom), (int)$quantite);
            }
        }

        return $photographie;
    }

    /**
     * Les quatre faits que la bataille prend au defenseur, releves par les seuls effets admissibles.
     *
     * Une recherche de combat achevee dans la fenetre, engagee avant l'ouverture, porte son niveau ;
     * un chantier spatial acheve de meme porte le sien. Une recherche engagee **apres** l'ouverture ne
     * releve rien, meme achevee avant la fermeture, meme deja appliquee par le monde : elle n'appartient
     * pas a ce combat. Comme pour les files d'unites, l'effet est compte sans etre applique.
     *
     * @param array<int, ReconciledEvent> $dansLaPhotographie
     */
    private function photographedDefenderOf(CombatInstance $combat, array $dansLaPhotographie): PhotographedDefender
    {
        $defenseur = OpeningStateRecorder::openingDefenderOf($combat);

        foreach ($dansLaPhotographie as $reconcilie) {
            if ($reconcilie->admission !== CausalAdmission::AppliedBeforeSnapshot) {
                continue;
            }

            $recherche = $this->reader->researchOf($reconcilie->event->identity);
            if ($recherche !== null) {
                $defenseur = $defenseur->withResearchLevel(
                    ObjectService::getResearchObjectById($recherche->objectId)->machine_name,
                    $recherche->levelTarget
                );
                continue;
            }

            $batiment = $this->reader->buildingQueueOf($reconcilie->event->identity);
            if ($batiment !== null && ObjectService::getObjectById($batiment->objectId)->machine_name === 'space_dock') {
                $defenseur = $defenseur->withSpaceDockLevel($batiment->levelTarget);
            }
        }

        return $defenseur;
    }

    /**
     * Les missiles ne se battent pas dans une garnison : la garnison du moteur les exclut, et une file
     * qui en produit ne doit pas les y faire entrer par la photographie.
     */
    private static function fightsInAGarrison(string $machineName): bool
    {
        return !in_array($machineName, ['interplanetary_missile', 'anti_ballistic_missile'], true);
    }

    /**
     * Les inclusions que la fermeture ecrit : chaque evenement qui entre dans la photographie et y
     * apporte quelque chose, sauf les arrivees que les selecteurs inscrivent eux-memes.
     *
     * @param array<int, ReconciledEvent> $dansLaPhotographie
     * @return array<string, SnapshotContributionSet>
     */
    private function inclusionsOf(array $dansLaPhotographie): array
    {
        $inclusions = [];
        foreach ($dansLaPhotographie as $reconcilie) {
            $evenement = $reconcilie->event;
            if ($evenement->contributions === [] || $evenement->isFoundingInitiator) {
                continue;
            }
            $mission = $this->reader->missionOf($evenement->identity);
            // Une file n'est pas incluse : son effet n'est pas applique par la fermeture, et une
            // inclusion affirmerait qu'il est dans la photographie.
            if ($mission === null) {
                continue;
            }
            if ($mission->parent_id === null) {
                $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);
                if ($genre->opensCombat() || $genre->reinforcesTheDefence()) {
                    continue;
                }
            }
            $inclusions[$evenement->identity] = SnapshotContributionSet::of($evenement->contributions);
        }

        return $inclusions;
    }
}
