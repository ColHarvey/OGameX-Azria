<?php

namespace OGame\Patrol\Combat;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\MoonDestruction\MoonDestructionRuleRegistry;
use OGame\Combat\Policies\LootPolicyRegistry;
use OGame\Combat\Projection\SnapshotProjectionRegistry;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\SnapshotFingerprint;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\PatrolCombatBarrier;
use OGame\Patrol\FrozenPatrolTarget;

/**
 * L ouverture d un combat la ou il n y a pas de corps celeste.
 *
 * ------------------------------------------------------------------------------------
 * CE QUI EST TENU : LA PATROUILLE, JAMAIS LE POINT
 *
 * `celestial_body_combat_barriers` tient un corps ; `patrol_combat_barriers` tient une
 * patrouille. La difference est une decision de jeu, pas un detail : deux patrouilles
 * peuvent occuper le meme point sans partager leur sort, et une attaque vise une identite
 * gelee au lancement, jamais « ce qui se trouve la a l arrivee » (revues 120 et 121, D18-D19).
 *
 * `patrol_id` est **unique**. Deux vagues arrivant a la meme seconde ne peuvent pas ouvrir
 * deux combats sur la meme patrouille : la base refuse la seconde insertion, et le perdant
 * apprend qu il rejoint. C est elle qui arbitre, pas l ordre des travailleurs.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CE SERVICE EXISTE A COTE DE `CombatOpeningService`
 *
 * L ouverture sur un corps photographie l etat du corps — stocks, files, garnison,
 * antimissiles — et retient les Defenses ACS deja posees dessus. **Un point libre n a ni
 * stock, ni file, ni garnison, et rien n y est pose** : ces deux gestes n ont pas d objet
 * ici, et les executer demanderait au service des corps de semer des « si le corps existe »
 * dans chacune de ses etapes, c est-a-dire d y installer un second regime partout.
 *
 * Deux services qui partagent la **meme barriere logique** et le meme ordre de verrous,
 * chacun repondant a une seule question, se relisent mieux qu un service qui repond a deux.
 *
 * ------------------------------------------------------------------------------------
 * L ORDRE DES VERROUS EST INCHANGE
 *
 * Barriere, instance, union, missions. Cette barriere-ci occupe exactement la place de
 * celle du corps, et n est jamais prise apres elle : une arrivee vise un corps **ou** une
 * patrouille, jamais les deux.
 *
 * ------------------------------------------------------------------------------------
 * CE QUI N EST PAS PROUVE ICI, ET QUI SE DIT
 *
 * La jointure repose sur **deux** gestes : la lecture prealable, et le rattrapage de la
 * violation d unicite. Les mutations le montrent — retirer l un **ou** l autre ne fait
 * rougir aucun essai, retirer les deux fait rougir. Les essais locaux n empruntent donc
 * jamais le chemin de rattrapage : ils voient toujours la barriere avant d insister.
 *
 * C est attendu, et c est une limite, pas une garantie : la vraie course demande deux
 * processus et une base qui verrouille — le banc `tests/MariaDb/`. Tant qu elle n y est pas,
 * ce qui est etabli est que la paire porte la regle, pas lequel des deux gestes agit.
 *
 * ------------------------------------------------------------------------------------
 * L ECHEANCE POSEE ICI EST PROVISOIRE, ET CE N EST PAS UN DETAIL
 *
 * `owned_through_effect_at` vaut l instant d ouverture : aucun effet planifie apres n
 * appartient encore a ce combat. **C est l etat d une tranche intermediaire, pas la regle
 * du jeu**, et l ecrire autrement serait installer l ancien modele comme resultat final.
 *
 * La regle voulue est l inverse : un joueur doit pouvoir envoyer ses propres flottes et les
 * renforts de son alliance **pendant** la bataille. Ce que le moteur progressif construit
 * (journal §116) est exactement cela — un etat de champ persiste round par round, ou de
 * nouveaux arrivants entrent entre deux pas au lieu d etre juges a l avance.
 *
 * Le raccordement est donc nomme des maintenant :
 *
 *   - la fermeture d un combat spatial ne figera **pas** un verdict complet ; elle produira
 *     l etat de champ initial, et l avanceur jouera les rounds ;
 *   - l admission des renforts se prononcera entre deux pas, sur l etat relu, jamais sur une
 *     photographie prise avant le premier tir.
 *
 * ------------------------------------------------------------------------------------
 * ET LA BORNE NE PEUT PAS ETRE « LA FIN REELLE » PENDANT LA BATAILLE
 *
 * J avais ecrit que `owned_through_effect_at` serait « etendu a la fin reelle de la
 * bataille ». **C est impossible, et Keven l a releve** : pendant qu on se bat, cette fin
 * n est pas connue — elle depend des rounds qui restent a jouer, donc des renforts qui vont
 * arriver. Une borne qui l attendrait refuserait justement ce qu elle doit accepter.
 *
 * Il y a donc deux etats a distinguer, et non une date a remplir :
 *
 *   - **tant que la bataille est ouverte**, une arrivee de la periode lui appartient. La
 *     question n est pas « avant quelle date », mais « ce combat accepte-t-il encore » ;
 *   - **a la resolution**, la borne se fige sur l instant reellement atteint, et devient
 *     definitive. C est elle qui tranche ensuite pour un travailleur en retard : un effet
 *     planifie apres n appartient plus a ce combat, mais au suivant.
 *
 * Ce que la colonne portera est donc la **borne figee**, ecrite une fois a la resolution.
 * Ce qui gouverne pendant le combat est l **etat**, pas la date.
 *
 * Tant que ce raccordement n existe pas, aucune arrivee ne peut rejoindre un combat spatial —
 * et c est pour cela que rien n appelle ce service : `patrols_enabled` vaut 0.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE CE SERVICE NE FAIT PAS ENCORE
 *
 * Il ouvre et il tient. Il ne ferme pas, ne photographie pas la flotte defenseuse et ne
 * regle rien.
 *
 * Ce que le reglement devra traiter, et qui est note ici pour ne pas etre reduit en
 * chemin — **la consigne est celle de chaque flotte participante, jamais une consigne
 * unique heritee de la patrouille visee** :
 *
 *   - rentrer ou rester, **par flotte**, chacune gardant ses propres survivants ;
 *   - la reserve de carburant suffisante pour ce retour, sans quoi il n a pas lieu ;
 *   - l immobilisation quand cette reserve ne suffit plus ;
 *   - la disparition d une flotte entierement detruite, dont rien ne rentre.
 */
final class SpatialCombatOpening
{
    public function __construct(
        private CausalEventOrderRegistry|null $causalOrders = null,
        private LootAllocatorRegistry|null $allocators = null,
        private LootPolicyRegistry|null $policies = null,
        private MoonDestructionRuleRegistry|null $moonRules = null,
        private SnapshotProjectionRegistry|null $projections = null,
    ) {
    }

    /**
     * Ouvre un combat sur cette patrouille, ou rend celui qui la tient deja.
     *
     * @param FleetMission $opener La mission qui arrive et pretend ouvrir.
     * @param FrozenPatrolTarget $target La cible **gelee au lancement** : identite et emplacement.
     * @param int $openedAt L instant d ouverture, en secondes.
     */
    public function openOrJoin(FleetMission $opener, FrozenPatrolTarget $target, int $openedAt): CombatInstance
    {
        $tenu = $this->combatHolding($target->patrolId);

        if ($tenu !== null) {
            return $tenu;
        }

        try {
            return DB::transaction(fn (): CombatInstance => $this->open($opener, $target, $openedAt));
        } catch (QueryException $course) {
            // **La course, ou une vraie panne.** Relire tranche : si une barriere existe maintenant,
            // quelqu un l a posee entre notre lecture et notre insertion, et le combat est le sien.
            $gagnante = $this->combatHolding($target->patrolId);

            if ($gagnante === null) {
                throw $course;
            }

            return $gagnante;
        }
    }

    /**
     * Le combat qui tient cette patrouille, ou `null`.
     */
    public function combatHolding(int $patrolId): CombatInstance|null
    {
        $barriere = PatrolCombatBarrier::query()
            ->where('patrol_id', $patrolId)
            ->first();

        // **La relation s appelle `combat`, et se tromper de nom ne leve rien.** Eloquent cherche
        // d abord une methode, puis un attribut ; un nom inconnu rend simplement `null`. La
        // premiere version lisait `combatInstance` et ce service repondait « personne ne la tient »
        // sur une patrouille tenue — la course s ouvrait alors deux fois, et seule la contrainte
        // d unicite arretait la seconde, en exception plutot qu en jointure.
        return $barriere?->combat;
    }

    /**
     * Cree l instance et sa barriere, dans la meme transaction.
     */
    private function open(FleetMission $opener, FrozenPatrolTarget $target, int $openedAt): CombatInstance
    {
        $versions = FrozenCombatVersionSet::chosenAtOpening(
            $this->causalOrders,
            $this->allocators,
            $this->policies,
            $this->moonRules,
            $this->projections,
        );

        // **Aucune union, aucune alliance gouvernante.** Les regroupements autour d une patrouille
        // sont hors perimetre (revue 121, R9) : le groupe est l ouvreur, et le dire explicitement
        // vaut mieux que de lire une union qui ne devrait pas exister.
        $faits = [
            'opener_identity' => CombatParticipantKey::forFleet($opener->id),
            'founding_creator_id' => $opener->user_id,
            'governing_alliance_id' => null,
            'authoritative_arrival_at' => $opener->time_arrival,
            'max_fleets' => 1,
            'max_players' => 1,
            'target_patrol_id' => $target->patrolId,
            'point_x' => $target->x,
            'point_y' => $target->y,
            'opened_at' => $openedAt,
        ];

        $combat = CombatInstance::create([
            'status' => CombatState::Rallying,
            'mission_id' => $opener->id,
            'union_id' => null,
            // **Aucun corps vise, et la colonne le dit.** La laisser a zero ferait de ce combat le
            // voisin de tous les autres combats sans corps dans l index `combat_target_status_idx`.
            'target_planet_id' => null,
            'target_patrol_id' => $target->patrolId,
            'target_type' => PlanetType::SpatialPoint->value,
            'galaxy' => $target->galaxy,
            'system' => $target->system,
            // **Zero, et c est exact.** Un point libre n occupe aucune des quinze positions ; lui en
            // donner une ferait tomber ses debris dans le champ de la planete qui l occupe.
            'position' => 0,
            'point_x' => $target->x,
            'point_y' => $target->y,
            'started_at' => $openedAt,
            'causal_order_version' => $versions->causalOrder,
            'loot_allocator_version' => $versions->lootAllocator,
            'loot_policy_version' => $versions->lootPolicy,
            'moon_destruction_rule_version' => $versions->moonDestruction,
            'fingerprint_schema_version' => (string)SnapshotFingerprint::SCHEMA,
            'projection_version' => $versions->projection,
            'opener_identity' => $faits['opener_identity'],
            'founding_creator_id' => $faits['founding_creator_id'],
            'governing_alliance_id' => $faits['governing_alliance_id'],
            'authoritative_arrival_at' => $faits['authoritative_arrival_at'],
            'max_fleets' => $faits['max_fleets'],
            'max_players' => $faits['max_players'],
            'frozen_facts_fingerprint' => SnapshotFingerprint::of($faits + $versions->fingerprintFacts()),
        ]);

        PatrolCombatBarrier::create([
            'patrol_id' => $target->patrolId,
            'combat_instance_id' => $combat->id,
            'opened_at' => $openedAt,
            // **Provisoire, et le mot compte.** Tant que le raccordement au moteur progressif n existe
            // pas, aucune arrivee ne peut rejoindre un combat spatial : l echeance vaut donc l instant
            // d ouverture, l egalite comptant pour « apres » comme partout dans ce socle. Elle sera
            // etendue a la fin reelle de la bataille quand l avanceur jouera les rounds — voir l en-tete
            // de classe. Ce n est pas la regle du jeu, c est l etat d une tranche.
            'owned_through_effect_at' => $openedAt,
            'revision' => 0,
        ]);

        return $combat;
    }
}
