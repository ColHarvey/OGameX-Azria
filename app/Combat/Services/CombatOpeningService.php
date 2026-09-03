<?php

namespace OGame\Combat\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Admission\AdmissionBudget;
use OGame\Combat\Admission\FrozenAllianceMembership;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\CombatRallyWindow;
use OGame\Combat\Support\CombatRuleVersionSet;
use OGame\Combat\Support\SnapshotFingerprint;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;

/**
 * L'ouverture durable d'un combat : l'instant ou tout se fige.
 *
 * ## Ce que l'ouverture decide, et pour deux heures
 *
 * Un combat qui dure traverse des changements — un reglage ajuste, une regle versionnee, un joueur
 * qui quitte son alliance. **Aucun ne doit changer l'issue d'une bataille deja engagee.** Ce qui
 * gouverne le combat est donc choisi ici, une seule fois, et ecrit avec lui.
 *
 * C'est aussi le seul endroit du chemin persistant qui a le droit de demander les versions
 * courantes — voir `CombatRuleVersionSet::chosenAtOpening()`, et la garde architecturale qui le
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
 * ## Ce que ce service ne fait pas encore
 *
 * Il ne photographie pas les appartenances d'alliance, ne lit pas les candidates et ne calcule pas
 * l'echeance de ralliement : `owned_through_effect_at` prend le plafond, faute de savoir quelles
 * flottes sont attendues. Ces trois-la demandent le selecteur, et viennent ensuite.
 *
 * Le dire evite de croire l'ouverture terminee.
 */
final class CombatOpeningService
{
    /**
     * @param RallyCandidateReader $reader Le lecteur des candidates, qui porte la requete « ce corps,
     *                                     cette fenetre ». La dupliquer ici en ferait deux.
     */
    public function __construct(private RallyCandidateReader $reader = new RallyCandidateReader())
    {
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
        $versions = CombatRuleVersionSet::chosenAtOpening();
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
            // **Le plafond, faute de mieux pour l'instant.** L'echeance reelle se raccourcit des que
            // la derniere candidate attendue est arrivee ; la calculer demande le selecteur, qui
            // vient ensuite. Prendre le plafond est le choix sur : il ne laisse echapper aucun
            // evenement qui appartenait a ce combat.
            'owned_through_effect_at' => $openedAt + CombatRallyWindow::WINDOW_SECONDS,
            'revision' => 0,
        ]);

        return $combat;
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
