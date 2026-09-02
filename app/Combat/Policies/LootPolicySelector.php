<?php

namespace OGame\Combat\Policies;

use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Enums\UnsupportedSideReason;
use OGame\Combat\Exceptions\UnsupportedActorSide;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\LootPolicy;

/**
 * Quelle regle de pillage s'applique a ce combat.
 *
 * ## L'ordre des questions, et pourquoi il est celui-la
 *
 * 1. **La mission autorise-t-elle le pillage ?** Si non, `no_loot_v1`, quels que soient les acteurs.
 *    Une expedition reste sans butin meme si tout son camp est pilote par le serveur : c'est la
 *    nature de la mission qui tranche, pas la composition du camp.
 * 2. **Tous les attaquants sont-ils des joueurs ?** Alors `cargo_weighted_v1`.
 * 3. **Tous sont-ils pilotes par le serveur ?** Alors `npc_base_v1`.
 * 4. **Sinon** — camp vide, melange, ou comprenant le compte systeme — aucune regle ne convient, et
 *    le selecteur refuse.
 *
 * ## Pourquoi refuser plutot que retomber sur la regle des joueurs
 *
 * `cargo_weighted_v1` appliquee a un camp mixte traiterait le fret pirate comme du fret
 * non-Decouvreur. Le taux serait calcule, plausible, et faux : personne n'a jamais decide ce qu'un
 * camp mixte doit piller. Un `else` qui choisit a notre place ecrit une regle de jeu par accident.
 *
 * Le cas n'existe pas aujourd'hui — aucune flotte pilotee par le serveur ne rejoint une union. Le
 * refus protege donc une incoherence future.
 */
final class LootPolicySelector
{
    /**
     * La politique de ce combat.
     *
     * @param NoLootReason|null $missionRefusal Le refus de la mission, s'il y en a un.
     * @param array<int, ActorKind> $actorKinds Le genre de chaque attaquant, par identifiant de mission.
     * @param bool $targetIsInactive
     * @param AttackerCargoShare $cargo
     * @return LootPolicy
     */
    public static function select(
        NoLootReason|null $missionRefusal,
        array $actorKinds,
        bool $targetIsInactive,
        AttackerCargoShare $cargo,
    ): LootPolicy {
        // 1. La permission de la mission prime sur tout le reste.
        if ($missionRefusal !== null) {
            return LootPolicy::noLoot($missionRefusal);
        }

        $genres = array_unique(array_map(static fn (ActorKind $kind): string => $kind->value, $actorKinds));

        // 2 et 3. Un camp homogene, et d'un genre auquel une regle correspond.
        if (count($genres) === 1) {
            $genre = reset($genres);

            if ($genre === ActorKind::Player->value) {
                return new LootPolicy($targetIsInactive, $cargo);
            }

            if ($genre === ActorKind::Npc->value) {
                return LootPolicy::forNpcAttacker($targetIsInactive, $cargo);
            }
        }

        // 4. Camp vide, melange, ou comprenant le compte systeme. Chaque cas porte sa raison :
        // le repli operationnel la persiste, et un rapport peut dire ce qui n allait pas.
        throw UnsupportedActorSide::because(
            self::reasonFor($actorKinds, $genres),
            array_map(static fn (ActorKind $kind): string => $kind->value, $actorKinds)
        );
    }

    /**
     * Ce qui rend ce camp irrecevable.
     *
     * L ordre compte : la presence du compte systeme est signalee **avant** le melange, parce
     * qu elle est plus grave. Un camp Systeme + Joueur est les deux a la fois ; le nommer « mixte »
     * ferait chercher du cote des pirates alors que le probleme est ailleurs.
     *
     * @param array<int, ActorKind> $actorKinds
     * @param array<int, string> $genres Les genres distincts rencontres.
     * @return UnsupportedSideReason
     */
    private static function reasonFor(array $actorKinds, array $genres): UnsupportedSideReason
    {
        if (count($actorKinds) === 0) {
            return UnsupportedSideReason::EmptySide;
        }

        if (in_array(ActorKind::System->value, $genres, true)) {
            return UnsupportedSideReason::SystemActorPresent;
        }

        return UnsupportedSideReason::MixedPlayerNpc;
    }
}
