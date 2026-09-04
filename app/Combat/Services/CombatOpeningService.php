<?php

namespace OGame\Combat\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Admission\AdmissionBudget;
use OGame\Combat\Admission\AttackAdmissionSelector;
use OGame\Combat\Admission\DefensiveAdmissionSelector;
use OGame\Combat\Admission\DefensiveRallyCandidate;
use OGame\Combat\Admission\FoundingGroup;
use OGame\Combat\Admission\FrozenAllianceMembership;
use OGame\Combat\Admission\RallyGrouping;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\MoonDestruction\MoonDestructionRuleRegistry;
use OGame\Combat\Policies\LootPolicyRegistry;
use OGame\Combat\Projection\SnapshotProjectionRegistry;
use OGame\Combat\Support\ActorKindResolver;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\CombatRallyWindow;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\SnapshotFingerprint;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\User;

/**
 * L'ouverture durable d'un combat : l'instant ou tout se fige.
 *
 * ## Ce que l'ouverture decide, et pour toute la duree du combat
 *
 * Un combat qui dure traverse des changements — un reglage ajuste, une regle versionnee, un joueur
 * qui quitte son alliance. **Aucun ne doit changer l'issue d'une bataille deja engagee.** Ce qui
 * gouverne le combat est donc choisi ici, une seule fois, et ecrit avec lui.
 *
 * C'est aussi le seul endroit du chemin persistant qui a le droit de demander les versions
 * courantes — voir `FrozenCombatVersionSet::chosenAtOpening()`, et la garde architecturale qui le
 * verifie.
 *
 * ## La course est arbitree par la base, pas par l'ordre des workers
 *
 * Deux flottes peuvent arriver a la meme seconde sur le meme corps. Une lecture « ce corps est-il
 * libre ? » ne verrouille rien : les deux liraient « libre » et ouvriraient deux combats, le second
 * effacant la photographie du premier.
 *
 * `celestial_body_combat_barriers.target_body_id` est unique. La base refuse donc la seconde
 * insertion, et le perdant de la course **rejoint** au lieu d'ouvrir. C'est elle qui tranche, et
 * elle ne se trompe pas sur l'ordre.
 *
 * Le `catch` distingue la course d'une vraie panne : il relit la barriere. Si elle existe, quelqu'un
 * a gagne et le combat est le sien ; sinon l'erreur est reelle et remonte.
 *
 * ## Ce que ce service fait desormais, et que ce commentaire a longtemps nie
 *
 * Il photographie les appartenances d'alliance, lit les candidates et calcule l'echeance reelle du
 * ralliement sur les flottes qui seraient admises. **Ce paragraphe affirmait le contraire alors que
 * le code le faisait deja** — une documentation inversee sur la frontiere d'ouverture est plus
 * dangereuse qu'une documentation absente : elle invite a rajouter une garantie qui existe, ou pire,
 * a supprimer celle qu'on croit manquante.
 *
 * ## Ce qu'il ne fait pas, et ne fera pas en premiere version
 *
 * **Il n'immobilise aucune ressource.** Une reservation a ete branchee ici puis retiree : la
 * regle arretee par le proprietaire est que le defenseur **peut depenser pendant le combat**, et
 * que le reglement se fait a la resolution par `min(butin potentiel, ressources restantes)`,
 * composante par composante.
 *
 * La difference n'est pas technique : avec une reservation, un defenseur qui vide ses caisses ne
 * sauve rien ; sans elle, il sauve ce qu'il a eu le temps de depenser. Ce sont deux jeux, et
 * c'est le second qui a ete choisi.
 *
 * Le dire evite de croire l'ouverture terminee.
 */
final class CombatOpeningService
{
    /**
     * @param RallyCandidateReader $reader Le lecteur des candidates, qui porte la requete « ce corps,
     *                                     cette fenetre ». La dupliquer ici en ferait deux.
     */
    public function __construct(
        private RallyCandidateReader $reader = new RallyCandidateReader(),
        private AttackAdmissionSelector $selector = new AttackAdmissionSelector(),
        private RallyGrouping $grouping = new RallyGrouping(),
        private CausalEventOrderRegistry|null $causalOrders = null,
        private LootAllocatorRegistry|null $allocators = null,
        private LootPolicyRegistry|null $policies = null,
        private MoonDestructionRuleRegistry|null $moonRules = null,
        private SnapshotProjectionRegistry|null $projections = null,
        private RallyClosureService|null $closure = null,
        private DefensiveAdmissionSelector $defenceSelector = new DefensiveAdmissionSelector(),
    ) {
    }

    /**
     * Ouvre un combat sur ce corps, ou rend celui qui le tient deja.
     *
     * @param FleetMission $opener La mission qui arrive et pretend ouvrir.
     * @param int $targetBodyId Le corps **exact** vise. Une planete et sa lune partagent leurs coordonnees.
     * @param int $openedAt L'instant d'ouverture, en secondes.
     * @return CombatInstance
     */
    public function openOrJoin(FleetMission $opener, int $targetBodyId, int $openedAt): CombatInstance
    {
        $tenue = $this->combatHolding($targetBodyId);

        if ($tenue !== null) {
            return $tenue;
        }

        try {
            return DB::transaction(fn (): CombatInstance => $this->open($opener, $targetBodyId, $openedAt));
        } catch (QueryException $course) {
            // **La course, ou une vraie panne.** Relire tranche : si une barriere existe maintenant,
            // quelqu'un l'a posee entre notre lecture et notre insertion, et le combat est le sien.
            $gagnante = $this->combatHolding($targetBodyId);

            if ($gagnante === null) {
                throw $course;
            }

            return $gagnante;
        }
    }

    /**
     * Le combat qui tient ce corps, ou `null`.
     */
    private function combatHolding(int $targetBodyId): CombatInstance|null
    {
        $barriere = CelestialBodyCombatBarrier::query()
            ->where('target_body_id', $targetBodyId)
            ->first();

        return $barriere?->combatInstance;
    }

    /**
     * Cree l'instance et sa barriere, dans la meme transaction.
     */
    private function open(FleetMission $opener, int $targetBodyId, int $openedAt): CombatInstance
    {
        // **Le seul endroit du chemin persistant qui demande les versions courantes.** Les
        // registres sont injectables pour qu un essai puisse installer des V2 factices et
        // verifier que le combat garde les siennes, sans toucher a un etat global.
        $versions = FrozenCombatVersionSet::chosenAtOpening(
            $this->causalOrders,
            $this->allocators,
            $this->policies,
            $this->moonRules,
            $this->projections,
        );
        // **Charge par requete typee, pas par la relation.** `$opener->union` rend un modele
        // generique : le budget et le createur se liraient alors sur un type que rien ne verifie.
        $union = $opener->union_id === null ? null : FleetUnion::find($opener->union_id);

        // L'union de l'ouvreur gouverne. Sans union, le groupe implicite prend les valeurs
        // canoniques du jeu — jamais celles d'une autre union qui passerait par la.
        $budget = $union === null
            ? AdmissionBudget::canonical()
            : new AdmissionBudget($union->max_fleets, $union->max_players);

        // Le createur de l union gouverne ; sans union, c est l ouvreur lui-meme.
        $createur = $union === null ? $opener->user_id : $union->user_id;
        $allianceGouvernante = $this->allianceOf($createur);

        // **La photographie, prise ici et nulle part ailleurs.** La fermeture la relira ; elle ne
        // reconstruira rien depuis la table vivante, ou la trace d'un depart aura disparu.
        $appartenances = $this->photographMembership($allianceGouvernante, $targetBodyId, $openedAt);

        $faits = [
            'opener_identity' => CombatParticipantKey::forFleet($opener->id),
            'founding_creator_id' => $createur,
            'governing_alliance_id' => $allianceGouvernante,
            'alliance_members_at_opening' => $appartenances->memberUserIds(),
            'authoritative_arrival_at' => $opener->time_arrival,
            'max_fleets' => $budget->maxFleets,
            'max_players' => $budget->maxPlayers,
            'target_body_id' => $targetBodyId,
            'opened_at' => $openedAt,
        ];

        $combat = CombatInstance::create([
            'status' => CombatState::Rallying,
            'mission_id' => $opener->id,
            'union_id' => $union?->id,
            'target_planet_id' => $targetBodyId,
            'target_type' => $opener->type_to,
            'galaxy' => $opener->galaxy_to ?? 0,
            'system' => $opener->system_to ?? 0,
            'position' => $opener->position_to ?? 0,
            'started_at' => $openedAt,
            'causal_order_version' => $versions->causalOrder,
            'loot_allocator_version' => $versions->lootAllocator,
            'loot_policy_version' => $versions->lootPolicy,
            'moon_destruction_rule_version' => $versions->moonDestruction,
            'fingerprint_schema_version' => (string)SnapshotFingerprint::SCHEMA,
            // **Depuis l'ensemble gele, comme les quatre autres.** La projection a d'abord vecu
            // sous une constante de classe : c'etait un second mecanisme de gel pour un besoin
            // que le premier couvrait deja.
            'projection_version' => $versions->projection,
            'frozen_alliance_membership' => $appartenances->toStorage(),
            ...$this->frozenColumns($faits),
            // L'empreinte porte les versions **et** les faits : deux combats sous deux regles
            // differentes ne doivent jamais partager une empreinte, sans quoi un rejeu passerait
            // pour un doublon deja applique.
            'frozen_facts_fingerprint' => SnapshotFingerprint::of($faits + $versions->fingerprintFacts()),
        ]);

        CelestialBodyCombatBarrier::create([
            'target_body_id' => $targetBodyId,
            'combat_instance_id' => $combat->id,
            'opened_at' => $openedAt,
            'owned_through_effect_at' => $this->closingTime(
                $opener,
                $targetBodyId,
                $openedAt,
                $appartenances,
                $union,
                $createur,
                $budget
            ),
            'revision' => 0,
        ]);

        // **Une fenetre nulle se ferme ici, pas a la minute suivante.**
        //
        // Quand personne n'est attendu, l'echeance du ralliement vaut l'instant d'ouverture : la
        // fenetre dynamique existe precisement pour qu'un attaquant isole n'immobilise pas une
        // planete. Laisser la fermeture au travail planifie reintroduirait l'attente qu'elle
        // supprime — jusqu'a une minute de verrou pour une fenetre de zero seconde, et autant apres
        // la fin du combat.
        //
        // La fermeture prend la barriere puis l'instance, dans le meme ordre que partout ailleurs,
        // et sa transaction s'imbrique dans celle-ci : si elle echoue, l'ouverture disparait avec
        // elle. Un combat a demi ouvert ne s'ecrit pas.
        // **Les renforts deja poses sur le corps sont retenus des l'ouverture.** Ils font partie
        // de l'etat du corps : ni un rappel, ni la fin de leur stationnement ne peut les faire
        // partir avant que l'admission ait prononce son verdict. Ceux qui volent encore seront
        // retenus a leur arrivee physique, par le meme lien.
        $this->holdReinforcementsAlreadyPresent($combat, $targetBodyId, $openedAt, $appartenances);

        if ($this->closure()->close($combat->id, $openedAt)->closed) {
            $combat->refresh();
        }

        return $combat;
    }

    /**
     * La fermeture qui ferme une fenetre nulle, **avec les registres de cette ouverture**.
     *
     * Une fermeture construite par defaut lirait les projections courantes : un combat ouvert sous
     * un registre injecte se fermerait alors sous un autre, et le rejeu ne prouverait plus rien.
     * La resoudre ici, et non dans la signature, est ce qui permet de lui passer ce que l'ouverture
     * a recu — une valeur par defaut de parametre ne peut pas lire une autre propriete.
     */
    private function closure(): RallyClosureService
    {
        return $this->closure ??= new RallyClosureService(projections: $this->projections);
    }

    /**
     * Les colonnes de faits geles a ecrire sur l'instance.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private function frozenColumns(array $facts): array
    {
        return [
            'opener_identity' => $facts['opener_identity'],
            'founding_creator_id' => $facts['founding_creator_id'],
            'governing_alliance_id' => $facts['governing_alliance_id'],
            'authoritative_arrival_at' => $facts['authoritative_arrival_at'],
            'max_fleets' => $facts['max_fleets'],
            'max_players' => $facts['max_players'],
        ];
    }

    /**
     * L'instant ou le ralliement se fermera, calcule une fois et pour toutes.
     *
     * ## Le selecteur tranche, ici comme a la fermeture
     *
     * `closesAt()` veut les arrivees des flottes **qui seraient admises**. Un filtre maison a cote du
     * selecteur creerait une seconde regle d'admission ; le selecteur etant pur, il tourne deux fois
     * et rend deux fois la meme chose, les faits etant figes entre les deux.
     *
     * ## La protection contre le harcelement
     *
     * Sans ce calcul, la fenetre durait soixante secondes meme pour un attaquant isole : un unique
     * chasseur leger, envoye en boucle, immobilisait une minute les departs et les ressources d'une
     * planete pour un cout derisoire. Elle tombe desormais a l'ouverture s'il n'y a personne a
     * attendre.
     */
    /**
     * Retient les Defenses ACS deja presentes sur le corps a l'ouverture.
     *
     * Presente veut dire : arrivee physique atteinte, stationnement non acheve. L'analogie avec une
     * vague attaquante rappelee avant la fermeture ne tient pas — cette vague vole encore, le renfort
     * est pose. Le lien est celui que l'arrivee d'une attaque pose deja ; il est leve a la fermeture
     * pour les refusees, et devient une participation pour les admises.
     */
    private function holdReinforcementsAlreadyPresent(
        CombatInstance $combat,
        int $targetBodyId,
        int $openedAt,
        FrozenAllianceMembership $membership,
    ): void {
        $candidates = $this->reader->read($targetBodyId, $openedAt, $membership, 0);

        foreach (DefensiveRallyCandidate::ofAll($candidates) as $renfort) {
            $candidate = $renfort->mission;

            // **Rappelee, elle est deja repartie** : la retenir immobiliserait une flotte que le
            // joueur a rappelee avant que ce combat existe.
            if ($candidate->recalled) {
                continue;
            }

            if ($candidate->scheduledArrivalAt > $openedAt || !$candidate->isStillHoldingAt($openedAt)) {
                continue;
            }

            // **Jamais par-dessus un lien existant** : une flotte deja rattachee a un combat
            // appartient a celui-la, et le lui reprendre la ferait disparaitre de sa photographie.
            FleetMission::query()
                ->whereKey($candidate->missionId)
                ->whereNull('combat_instance_id')
                ->update(['combat_instance_id' => $combat->id]);
        }
    }

    private function closingTime(
        FleetMission $opener,
        int $targetBodyId,
        int $openedAt,
        FrozenAllianceMembership $membership,
        FleetUnion|null $union,
        int $creatorUserId,
        AdmissionBudget $budget,
    ): int {
        $candidates = $this->reader->read($targetBodyId, $openedAt, $membership, 0);
        $arrivees = [];

        // **Seules les formes que la matrice delegue au selecteur.** Un retour ou un transport
        // arrive ici serait une entree contradictoire, et le selecteur leve — a juste titre. Ce
        // filtre-ci n'est pas une regle d'admission : c'est l'aiguillage que la matrice fait dans le
        // flux reel.
        $combattantes = $this->grouping->fightingShapesOnly($candidates);

        [$fondatrices, $autres] = $this->grouping->splitFounding($combattantes, $opener->id, $union?->id);

        if ($fondatrices !== []) {
            $verdict = $this->selector->select(
                new FoundingGroup($creatorUserId, $membership->allianceId, $fondatrices, $budget),
                $targetBodyId,
                $this->actorHolding($targetBodyId),
                $openedAt,
                $this->grouping->intoGroups($autres)
            );

            foreach ($verdict->admitted() as $groupe) {
                $arrivees[] = $groupe->scheduledArrivalAt();
            }
        }

        // **Les deux camps.** La regle arretee est « derniere arrivee admise des deux camps + 1 » :
        // un renfort defensif en vol prolonge la fenetre exactement comme une vague attaquante. Une
        // flotte deja presente n'a rien a prolonger — `closesAt()` ne retient que les arrivees a venir.
        $renforts = DefensiveRallyCandidate::ofAll($candidates);
        $proprietaire = $this->ownerOf($targetBodyId);

        if ($renforts !== [] && $proprietaire >= 1) {
            $defense = $this->defenceSelector->select($proprietaire, $targetBodyId, $openedAt, $renforts);

            foreach ($defense->admitted() as $groupe) {
                $arrivees[] = $groupe->scheduledArrivalAt();
            }
        }

        return CombatRallyWindow::closesAt($openedAt, $arrivees);
    }

    /**
     * Le genre d'acteur qui tient le corps vise.
     *
     * Un combat contre une faction pilotee par le serveur ne se rassemble pas : l'ouvreur y va seul.
     */
    private function ownerOf(int $targetBodyId): int
    {
        $planete = Planet::find($targetBodyId);

        return $planete === null ? 0 : (int)$planete->user_id;
    }

    private function actorHolding(int $targetBodyId): ActorKind
    {
        $planete = Planet::find($targetBodyId);

        if ($planete === null) {
            return ActorKind::Player;
        }

        $proprietaire = User::find($planete->user_id);

        return $proprietaire === null ? ActorKind::Player : ActorKindResolver::of($proprietaire);
    }

    /**
     * Qui appartient a l'alliance gouvernante, a cette seconde.
     *
     * **Lire l'etat courant est exact ici, et seulement ici.** L'ouverture *est* l'instant present :
     * la question « qui est membre ? » y a une reponse juste. Deux heures plus tard, la meme
     * question n'en a plus — une sortie supprime la ligne, et rien ne dit qu'elle a existe.
     */
    private function photographMembership(
        int|null $allianceId,
        int $targetBodyId,
        int $openedAt,
    ): FrozenAllianceMembership {
        if ($allianceId === null) {
            // Sans alliance qui gouverne, personne d'autre que le createur ne rejoint : il n'y a
            // rien a photographier.
            return FrozenAllianceMembership::none();
        }

        $proprietaires = $this->reader->ownersAimingAt($targetBodyId, $openedAt);

        if ($proprietaires === []) {
            return FrozenAllianceMembership::of($allianceId, []);
        }

        $membres = DB::table('alliance_members')
            ->where('alliance_id', $allianceId)
            ->whereIn('user_id', $proprietaires)
            ->pluck('user_id')
            ->all();

        return FrozenAllianceMembership::of(
            $allianceId,
            array_map(static fn (mixed $id): int => (int)$id, $membres)
        );
    }

    /**
     * L'alliance de ce joueur **maintenant**, c'est-a-dire a l'ouverture.
     *
     * Ici, et seulement ici, lire l'etat courant est exact : l'ouverture *est* l'instant present.
     * Partout ailleurs, c'est la photographie qui fait foi.
     */
    private function allianceOf(int $userId): int|null
    {
        $alliance = DB::table('users')->where('id', $userId)->value('alliance_id');

        return is_numeric($alliance) ? (int)$alliance : null;
    }
}
