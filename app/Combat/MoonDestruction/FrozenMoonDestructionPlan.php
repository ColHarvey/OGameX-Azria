<?php

namespace OGame\Combat\MoonDestruction;

use InvalidArgumentException;
use LogicException;

/**
 * Le plan immuable des tentatives de destruction, produit a la fermeture du ralliement.
 *
 * ## Un seul combat commun, une tentative par mission
 *
 * Toutes les flottes admises participent a **une** photographie et a **une** simulation. Aucun
 * moteur de bataille supplementaire, aucun second pillage, aucun second champ de debris de combat,
 * aucun second rapport commun. Ce plan ne rejoue rien : il ordonne ce que chaque mission de
 * destruction obtient une fois le resultat commun connu.
 *
 * Une attaque ordinaire — meme chargee d'etoiles de la mort — n'obtient jamais de tentative. Seules
 * les missions dont le genre d'origine est reellement une destruction de lune la conservent.
 *
 * ## Tout est cache jusqu'a la resolution
 *
 * Le plan est calcule et persiste des la fermeture, mais **rien n'en est visible** avant l'echeance :
 * ni pourcentage, ni tirage, ni resultat probable, ni perte. Un joueur qui verrait son tirage deux
 * heures a l'avance saurait avant le defenseur ce qui va arriver a la lune.
 *
 * ## Pourquoi geler plutot que rejouer
 *
 * Le hasard n'est jamais retire a l'echeance. Conserver une simple graine ne suffirait pas : PHP et
 * Rust ne consomment pas forcement les tirages dans le meme ordre ni en meme nombre, et le resultat
 * relu pourrait differer de celui qui a ete calcule. Ce sont donc les valeurs effectivement tirees
 * qui sont conservees.
 *
 * ## Ce que l'ordre garantit
 *
 *     1. heure d'arrivee planifiee
 *     2. identifiant de mission
 *
 * Deux lectures de la base dans un ordre different donnent le meme plan. Une mission sautee ne
 * consomme aucun tirage : sans cela, l'ordre de lecture deplacerait le hasard des suivantes.
 */
final readonly class FrozenMoonDestructionPlan
{
    /**
     * La version de la regle d'orchestration.
     *
     * Elle ne couvre pas les probabilites, qui sont celles du jeu et ne changent pas ici : elle
     * couvre l'ordre, la selection et le gel.
     */
    public const string VERSION = 'moon_destruction_frozen_v1';

    /**
     * Le nom du compteur qui rend la cle d'idempotence unique.
     */
    public const string ATTEMPT_KEY = 'moon_destruction_attempt';

    /**
     * @param int $combatInstanceId Le combat commun auquel ce plan appartient.
     * @param array<int, FrozenMoonDestructionAttempt> $attempts Les tentatives, dans leur ordre.
     */
    private function __construct(
        public int $combatInstanceId,
        public array $attempts,
    ) {
        if ($combatInstanceId < 1) {
            throw new InvalidArgumentException(
                'Un plan de destruction appartient a un combat persiste : sans son identifiant, la cle '
                . 'd idempotence ne distinguerait pas deux combats.'
            );
        }

        $detruites = 0;
        $rangAttendu = 0;
        $missions = [];

        foreach ($attempts as $tentative) {
            $rangAttendu++;

            if ($tentative->order !== $rangAttendu) {
                throw new LogicException(
                    'Les rangs d un plan doivent se suivre a partir de 1 : rang ' . $tentative->order
                    . ' trouve en position ' . $rangAttendu . '.'
                );
            }

            if (isset($missions[$tentative->fleetMissionId])) {
                throw new LogicException(
                    'La mission ' . $tentative->fleetMissionId . ' a deux tentatives. Chaque mission admise '
                    . 'en obtient au plus une.'
                );
            }

            $missions[$tentative->fleetMissionId] = true;

            if ($tentative->destroyedTheMoon()) {
                $detruites++;
            }
        }

        if ($detruites > 1) {
            throw new LogicException(
                'Un plan detruit la lune ' . $detruites . ' fois. Des qu elle est detruite, les tentatives '
                . 'suivantes constatent que la cible n existe plus.'
            );
        }
    }

    /**
     * Gele les tentatives d'un combat, dans l'ordre deterministe.
     *
     * @param int $combatInstanceId
     * @param array<int, MoonDestructionCandidate> $candidates Les missions **admises**, dans n'importe quel ordre.
     * @param bool $attackSideWon Selon la condition deja utilisee par la mission de destruction.
     * @param int $moonDiameter
     * @param callable(): int $roll Un tirage dans la plage du jeu. Injecte pour que le gel soit
     *                              observable : ce sont ses resultats qui sont conserves, pas lui.
     * @return self
     */
    public static function freeze(
        int $combatInstanceId,
        array $candidates,
        bool $attackSideWon,
        int $moonDiameter,
        callable $roll,
    ): self {
        $ordonnees = self::inDeterministicOrder($candidates);

        $tentatives = [];
        $luneDetruite = false;
        $rang = 0;

        foreach ($ordonnees as $candidate) {
            $rang++;

            // L'ordre de ces trois refus n'est pas indifferent : il decide quelle raison le joueur
            // lira. Le camp battu passe avant tout le reste, puis la lune deja detruite, puis
            // l'absence de survivantes — de la cause la plus generale a la plus particuliere.
            $issue = match (true) {
                !$attackSideWon => MoonDestructionOutcome::AttackSideDidNotWin,
                $luneDetruite => MoonDestructionOutcome::TargetAlreadyDestroyed,
                $candidate->survivingDeathstars === 0 => MoonDestructionOutcome::NoSurvivingDeathstar,
                default => null,
            };

            if ($issue !== null) {
                $tentatives[] = new FrozenMoonDestructionAttempt(
                    $candidate->fleetMissionId,
                    $rang,
                    $candidate->survivingDeathstars,
                    $moonDiameter,
                    self::VERSION,
                    null,
                    null,
                    $issue,
                    0
                );

                continue;
            }

            // Deux tirages, dans cet ordre, comme aujourd'hui : la destruction puis la perte.
            $tirageDestruction = $roll();
            $tiragePerte = $roll();

            $detruite = MoonDestructionOdds::succeeds(
                $tirageDestruction,
                MoonDestructionOdds::destructionChance($moonDiameter, $candidate->survivingDeathstars)
            );

            $perdues = MoonDestructionOdds::succeeds(
                $tiragePerte,
                MoonDestructionOdds::deathstarLossChance($moonDiameter)
            ) ? $candidate->survivingDeathstars : 0;

            $tentatives[] = new FrozenMoonDestructionAttempt(
                $candidate->fleetMissionId,
                $rang,
                $candidate->survivingDeathstars,
                $moonDiameter,
                self::VERSION,
                $tirageDestruction,
                $tiragePerte,
                $detruite ? MoonDestructionOutcome::MoonDestroyed : MoonDestructionOutcome::AttemptFailed,
                $perdues
            );

            $luneDetruite = $luneDetruite || $detruite;
        }

        return new self($combatInstanceId, $tentatives);
    }

    /**
     * Le plan relu, sans rien recalculer.
     *
     * @param int $combatInstanceId
     * @param array<int, array<string, int|string|null>> $facts
     * @return self
     */
    public static function fromFrozenFacts(int $combatInstanceId, array $facts): self
    {
        return new self(
            $combatInstanceId,
            array_map(
                static fn (array $fait): FrozenMoonDestructionAttempt
                    => FrozenMoonDestructionAttempt::fromFrozenFacts($fait),
                array_values($facts)
            )
        );
    }

    /**
     * Le plan, sous une forme comparable apres serialisation.
     *
     * @return array<int, array<string, int|string|null>>
     */
    public function toFrozenFacts(): array
    {
        return array_map(
            static fn (FrozenMoonDestructionAttempt $tentative): array => $tentative->toFrozenFacts(),
            $this->attempts
        );
    }

    /**
     * Si ce plan detruit la lune.
     */
    public function destroysTheMoon(): bool
    {
        foreach ($this->attempts as $tentative) {
            if ($tentative->destroyedTheMoon()) {
                return true;
            }
        }

        return false;
    }

    /**
     * La cle qui rend l'application d'une tentative rejouable sans second effet.
     *
     * Un rejeu relit le resultat gele : il ne retire pas de nouvelles etoiles de la mort, ne detruit
     * pas deux fois la lune et ne recree aucun rapport.
     *
     * @param FrozenMoonDestructionAttempt $attempt
     * @return string
     */
    public function idempotencyKeyOf(FrozenMoonDestructionAttempt $attempt): string
    {
        return $this->combatInstanceId . ':' . $attempt->fleetMissionId . ':' . self::ATTEMPT_KEY;
    }

    /**
     * Les candidates triees par heure d'arrivee planifiee, puis par identifiant.
     *
     * @param array<int, MoonDestructionCandidate> $candidates
     * @return array<int, MoonDestructionCandidate>
     */
    private static function inDeterministicOrder(array $candidates): array
    {
        $ordonnees = array_values($candidates);

        usort(
            $ordonnees,
            static fn (MoonDestructionCandidate $a, MoonDestructionCandidate $b): int
                => [$a->scheduledArrivalAt, $a->fleetMissionId] <=> [$b->scheduledArrivalAt, $b->fleetMissionId]
        );

        return $ordonnees;
    }
}
