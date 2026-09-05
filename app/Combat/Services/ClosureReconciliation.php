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
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\EffectOrderKey;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\SnapshotContributionSet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;

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
 * ## Pourquoi les files ne sont pas appliquees ici
 *
 * `updateUnitQueue()` et ses voisines traitent **toute la file echue** du corps, pas l'element qu'on
 * leur designe : il n'existe pas de gestionnaire d'un achevement precis. Les appeler pour un
 * achevement admissible drainerait au passage les achevements **inadmissibles** echus, dont les
 * unites entreraient dans une garnison encore lue vivante. Leur idempotence ne protege pas de cela.
 *
 * Les files sont donc lues, classees et comptees dans la tranche — une source qu'on n'interroge pas
 * rend la tranche incomplete —, mais leur effet reste au monde, qui les appliquera a la page
 * suivante de leur proprietaire. La photographie de l'effectif et des technologies, ou cet effet a
 * sa place, est la tranche suivante du raccordement : les deux vont ensemble, et l'une sans l'autre
 * donnerait une garnison composee au hasard de qui est passe.
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

        $appliques = $this->apply($photographie->toApply());

        return new ReconciledClosure(
            $photographie,
            $this->protectedResourcesOf($combat, $photographie->inTheSnapshot()),
            $this->photographedGarrisonOf($combat, $photographie->inTheSnapshot()),
            $this->photographedDefenderOf($combat, $photographie->inTheSnapshot()),
            $appliques,
            $this->inclusionsOf($photographie->inTheSnapshot()),
        );
    }

    /**
     * @param array<int, ReconciledEvent> $aAppliquer
     * @return array<int, string>
     */
    private function apply(array $aAppliquer): array
    {
        $appliques = [];

        foreach ($aAppliquer as $reconcilie) {
            $mission = $this->reader->missionOf($reconcilie->event->identity);

            // Une file : lue et classee, mais laissee au monde (voir le contrat de cette classe).
            if ($mission === null) {
                continue;
            }

            $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);
            if ($mission->parent_id === null && ($genre->opensCombat() || $genre->reinforcesTheDefence())) {
                continue;
            }

            resolve(FleetMissionService::class)->updateMission($mission);
            $appliques[] = $reconcilie->event->identity;
        }

        return $appliques;
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
     * L'effectif contre lequel la bataille se joue : celui de l'ouverture, plus les unites que des
     * effets admissibles ont produites.
     *
     * ## Compter sans appliquer
     *
     * Une file de vaisseaux ou de defenses achevee dans la fenetre, engagee avant l'ouverture, produit
     * des unites qui appartiennent a ce combat. Elles sont **comptees ici** sans que la fermeture
     * touche a la file : le gestionnaire d'une file traite toute la file echue, et l'appeler drainerait
     * les achevements inadmissibles. Le monde appliquera la file a la page suivante de son
     * proprietaire ; la photographie, elle, sait deja ce qu'elle vaut.
     *
     * Une flotte **deposee** par un deploiement admissible ajoute ses vaisseaux de la meme facon. Elle,
     * en revanche, a bien ete appliquee : `updateMission()` ne traite qu'une mission, il ne draine rien.
     *
     * @param array<int, ReconciledEvent> $dansLaPhotographie
     */
    private function photographedGarrisonOf(CombatInstance $combat, array $dansLaPhotographie): UnitCollection
    {
        $effectif = OpeningStateRecorder::openingUnitsOf($combat);

        foreach ($dansLaPhotographie as $reconcilie) {
            if ($reconcilie->admission !== CausalAdmission::AppliedBeforeSnapshot) {
                continue;
            }

            $file = $this->reader->unitQueueOf($reconcilie->event->identity);
            if ($file !== null && $file->amount > 0) {
                $objet = ObjectService::getUnitObjectById($file->objectId);
                if (!self::fightsInAGarrison($objet->machine_name)) {
                    continue;
                }
                $effectif->addUnit($objet, $file->amount);
                continue;
            }

            if (!in_array(SnapshotContribution::DeliveredFleet, $reconcilie->event->contributions, true)) {
                continue;
            }
            $mission = $this->reader->missionOf($reconcilie->event->identity);
            if ($mission === null) {
                continue;
            }
            $effectif->addCollection(resolve(FleetMissionService::class)->getFleetUnits($mission));
        }

        return $effectif;
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
