<?php

namespace OGame\Combat\Support;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Policies\LootPolicySelector;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\Models\Resources;
use OGame\Services\CharacterClassService;
use OGame\Services\PlanetService;

/**
 * La photographie des faits de pillage pour un combat instantane.
 *
 * **Le seul endroit qui decide quand observer.** Le moteur recoit un contexte deja constitue ; c'est
 * ici, et ici seulement, que les modeles vivants sont interroges. Le chemin persistant a sa propre
 * photographie, prise a l'ouverture du ralliement, et relue par `LootContext::fromFrozenFacts()`.
 *
 * ## Une seule lecture de l'horloge
 *
 * L'instant est lu une fois, en UTC, et sert a toutes les decisions du contexte. Deux lectures
 * successives pourraient tomber de part et d'autre de la frontiere des sept jours et produire un
 * contexte dont l'inactivite ne correspond pas a l'instant qu'il declare.
 *
 * ## Ce que cette fabrique ne decide pas
 *
 * Elle observe ; elle ne choisit pas la regle. Le genre d'acteur de chaque flotte et la permission
 * de la mission vont a `LootPolicySelector`, qui refuse explicitement ce qu'aucune regle ne couvre.
 */
final class LiveLootContextFactory
{
    /**
     * Le nombre de jours sans connexion au-dela duquel une cible est inactive.
     *
     * La meme valeur que `PlayerService::isInactive()`. Elle est reprise ici plutot qu'appelee,
     * parce que cette methode-la relit l'horloge pour son propre compte : le contexte doit tout
     * decider a partir de l'unique instant qu'il conserve.
     */
    public const int INACTIVITY_THRESHOLD_DAYS = 7;

    /**
     * Le contexte d'un combat dont la mission autorise le pillage.
     *
     * @param array<AttackerFleet> $attackers Les flottes offensives retenues, initiateur compris.
     * @param PlanetService $target Le corps vise.
     * @param FrozenLootAllocation $allocation L'allocateur de cette operation, choisi a son debut.
     * @return LootContext
     */
    public static function forBattle(array $attackers, PlanetService $target, FrozenLootAllocation $allocation, Resources|null $protectedResources = null): LootContext
    {
        return self::snapshotOf(null, $attackers, $target, $allocation, $protectedResources);
    }

    /**
     * Le contexte d'un combat qui ne pille pas, et qui dit pourquoi.
     *
     * @param NoLootReason $reason
     * @param array<AttackerFleet> $attackers
     * @param PlanetService $target
     * @param FrozenLootAllocation $allocation
     * @return LootContext
     */
    public static function withoutLoot(NoLootReason $reason, array $attackers, PlanetService $target, FrozenLootAllocation $allocation, Resources|null $protectedResources = null): LootContext
    {
        return self::snapshotOf($reason, $attackers, $target, $allocation, $protectedResources);
    }

    /**
     * La photographie proprement dite.
     *
     * ## « Live » porte sur les faits, pas sur les regles
     *
     * Cette fabrique lit l'etat vivant du monde — les cargaisons, l'inactivite de la cible, les
     * classes de personnage — et c'est bien son role : ce sont les faits de l'instant. Elle
     * choisissait aussi **la version courante de l'allocateur**, ce qui n'a rien a voir : une
     * regle n'est pas un fait observe, et la choisir ici la faisait dependre du moment ou la
     * photographie etait prise plutot que du moment ou le combat s'est ouvert.
     *
     * @param NoLootReason|null $refusal
     * @param array<AttackerFleet> $attackers
     * @param PlanetService $target
     * @param FrozenLootAllocation $allocation
     * @return LootContext
     */
    private static function snapshotOf(NoLootReason|null $refusal, array $attackers, PlanetService $target, FrozenLootAllocation $allocation, Resources|null $protectedResources = null): LootContext
    {
        $observe = self::now();
        $inactive = self::targetIsInactiveAt($target, $observe);

        $classes = app(CharacterClassService::class);
        $fret = AttackerCargoShare::none();
        $genres = [];
        $flottes = [];

        foreach ($attackers as $attacker) {
            $utilisateur = $attacker->player->getUser();
            $genre = ActorKindResolver::of($utilisateur);

            // **Seul un joueur peut etre Decouvreur.** Un compte pilote par le serveur, comme le
            // compte systeme, porte une colonne de classe comme n'importe quel compte : la lire
            // ferait heriter un pirate du bonus par accident de donnee.
            $estDecouvreur = $genre === ActorKind::Player && $classes->isDiscoverer($utilisateur);
            $libre = self::freeCargoOf($attacker);

            $genres[$attacker->fleetMissionId] = $genre;
            $fret = $fret->plus($libre, $estDecouvreur);
            $flottes[] = AttackerFleetSnapshot::of($attacker, $genre, $estDecouvreur, $libre);
        }

        $politique = LootPolicySelector::select($refusal, $genres, $inactive, $fret);

        return LootContext::fromObservedFacts(
            $politique,
            $flottes,
            self::targetFactsOf($target),
            $observe,
            $allocation->version,
            null,
            $protectedResources,
        );
    }

    /**
     * L'instant de la photographie, en secondes UTC.
     *
     * @return int
     */
    private static function now(): int
    {
        return Date::now('UTC')->getTimestamp();
    }

    /**
     * L'inactivite de la cible, decidee au seul instant de la photographie.
     *
     * La frontiere est **fermee du cote inactif** : a exactement sept jours, la cible est inactive.
     * Ecrit autrement, la condition est `derniereConnexion <= instant - 7 jours`. Le cas se produit
     * rarement a la seconde pres, mais un comportement non choisi finirait par etre observe en jeu
     * et pris pour un defaut.
     *
     * Une cible sans proprietaire n'est ni active ni inactive : elle ne donne aucun bonus.
     *
     * @param PlanetService $target
     * @param int $observedAt
     * @return bool
     */
    private static function targetIsInactiveAt(PlanetService $target, int $observedAt): bool
    {
        $proprietaire = $target->getPlayer();

        if ($proprietaire === null) {
            return false;
        }

        $derniereConnexion = (int)$proprietaire->getUser()->time;
        $seuil = Date::createFromTimestamp($observedAt, 'UTC')
            ->subDays(self::INACTIVITY_THRESHOLD_DAYS)
            ->getTimestamp();

        return $derniereConnexion <= $seuil;
    }

    /**
     * La capacite de fret encore libre d'une flotte attaquante.
     *
     * Ce qu'elle peut porter, moins ce qu'elle transporte deja : une soute pleine ne peut rien
     * emporter de plus, et ne doit donc pas peser dans le taux.
     *
     * @param AttackerFleet $attacker
     * @return int
     */
    private static function freeCargoOf(AttackerFleet $attacker): int
    {
        $libre = $attacker->units->getTotalCargoCapacity($attacker->player) - $attacker->cargoResources->sum();

        return (int)max(0, floor($libre));
    }

    /**
     * Les faits de la cible qui entrent dans l'empreinte.
     *
     * @param PlanetService $target
     * @return array<string, mixed>
     */
    private static function targetFactsOf(PlanetService $target): array
    {
        return [
            'body_key' => CombatParticipantKey::forBody($target),
            'owner_id' => $target->getPlayer()?->getId() ?? 0,
        ];
    }
}
