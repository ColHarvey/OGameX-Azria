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
 * L'application a l'echeance ne consulte donc **ni le registre courant, ni le hasard, ni le diametre
 * vivant de la lune** : elle relit.
 *
 * ## Ce que l'ordre garantit
 *
 *     1. heure d'arrivee planifiee
 *     2. identifiant de mission
 *
 * Deux lectures de la base dans un ordre different donnent le meme plan. Une mission sautee ne
 * consomme aucun tirage : sans cela, l'ordre de lecture deplacerait le hasard des suivantes.
 *
 * ## Ordre d'application, a l'echeance
 *
 * Il est impose, et le chemin actuel ne le respecte pas encore — il cree les retours avant que les
 * pertes propres aux tentatives ne soient connues :
 *
 *     resultat de bataille commun
 *       -> butin, pertes et debris du combat
 *       -> tentatives de destruction gelees, dans leur ordre
 *       -> pertes supplementaires d'etoiles de la mort
 *       -> destruction ou reroutage de la lune, au plus une fois
 *       -> creation des retours avec les survivants definitifs
 *       -> rapports, encore caches
 *       -> Resolved, commit, puis notifications
 *
 * Une erreur au milieu annule tout : aucune lune detruite sans retours, aucun retour cree sans
 * pertes, aucun rapport visible avant le resultat definitif.
 */
final readonly class FrozenMoonDestructionPlan
{
    /**
     * La version du **schema** du plan : la forme des faits persistes.
     *
     * Distincte de la version de la **regle**, qui gouverne les probabilites. Les deux evoluent
     * independamment : on peut ajouter un champ sans toucher aux formules, et l'inverse.
     */
    public const int SCHEMA = 1;

    /**
     * Le nom du compteur qui rend la cle d'idempotence unique.
     */
    public const string ATTEMPT_KEY = 'moon_destruction_attempt';

    /**
     * @param int $combatInstanceId Le combat commun auquel ce plan appartient.
     * @param FrozenMoonIdentity $moon La lune visee, telle qu'elle etait a la fermeture.
     * @param string $ruleVersion La regle qui a produit les chances et lu les tirages.
     * @param array<int, FrozenMoonDestructionAttempt> $attempts Les tentatives, dans leur ordre.
     */
    private function __construct(
        public int $combatInstanceId,
        public FrozenMoonIdentity $moon,
        public string $ruleVersion,
        public array $attempts,
    ) {
        if ($combatInstanceId < 1) {
            throw new InvalidArgumentException(
                'Un plan de destruction appartient a un combat persiste : sans son identifiant, la cle '
                . 'd idempotence ne distinguerait pas deux combats.'
            );
        }

        if ($ruleVersion === '') {
            throw new InvalidArgumentException(
                'Un plan sans version de regle serait relu sous la regle courante, qui n est peut-etre plus '
                . 'celle qui l a calcule.'
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
     * @param FrozenMoonIdentity $moon Son diametre est **la** entree des probabilites : celui de la
     *                                 fermeture, jamais le diametre vivant a l'echeance.
     * @param array<int, MoonDestructionCandidate> $candidates Les missions **admises**, dans n'importe quel ordre.
     * @param bool $attackSideWon Selon la condition deja utilisee par la mission de destruction.
     * @param MoonDestructionRule $rule La regle courante, prise dans le registre au moment du gel.
     * @param callable(): int $roll Un tirage dans la plage du jeu. Injecte pour que le gel soit
     *                              observable : ce sont ses resultats qui sont conserves, pas lui.
     * @return self
     */
    public static function freeze(
        int $combatInstanceId,
        FrozenMoonIdentity $moon,
        array $candidates,
        bool $attackSideWon,
        MoonDestructionRule $rule,
        callable $roll,
    ): self {
        $tentatives = [];
        $luneDetruite = false;
        $rang = 0;

        $chancePerte = $rule->deathstarLossChance($moon->diameter);
        $seuilPerte = $rule->thresholdFor($chancePerte);

        foreach (self::inDeterministicOrder($candidates) as $candidate) {
            $rang++;

            $chanceDestruction = $rule->destructionChance($moon->diameter, $candidate->survivingDeathstars);
            $seuilDestruction = $rule->thresholdFor($chanceDestruction);

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
                    $chanceDestruction,
                    $chancePerte,
                    $seuilDestruction,
                    $seuilPerte,
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

            // Les seuils entiers, jamais les chances flottantes : c'est cette comparaison-la que la
            // relecture refera, et elle doit donner le meme resultat sans recalculer une probabilite.
            $detruite = MoonDestructionOdds::succeedsAgainst($tirageDestruction, $seuilDestruction);
            $perdues = MoonDestructionOdds::succeedsAgainst($tiragePerte, $seuilPerte)
                ? $candidate->survivingDeathstars
                : 0;

            $tentatives[] = new FrozenMoonDestructionAttempt(
                $candidate->fleetMissionId,
                $rang,
                $candidate->survivingDeathstars,
                $chanceDestruction,
                $chancePerte,
                $seuilDestruction,
                $seuilPerte,
                $tirageDestruction,
                $tiragePerte,
                $detruite ? MoonDestructionOutcome::MoonDestroyed : MoonDestructionOutcome::AttemptFailed,
                $perdues
            );

            $luneDetruite = $luneDetruite || $detruite;
        }

        return new self($combatInstanceId, $moon, $rule->version(), $tentatives);
    }

    /**
     * Le plan relu, sans rien recalculer et sans consulter aucun registre.
     *
     * @param array<string, mixed> $facts
     * @return self
     */
    public static function fromFrozenFacts(array $facts): self
    {
        $schema = (int)($facts['schema'] ?? 0);

        if ($schema !== self::SCHEMA) {
            throw new InvalidArgumentException(
                'Ce plan se reclame du schema ' . $schema . ', et celui qui est connu est le '
                . self::SCHEMA . '. Le relire au petit bonheur donnerait des champs manquants pour des '
                . 'valeurs nulles, et un resultat different de celui qui a ete calcule.'
            );
        }

        /** @var array<string, int|string> $lune */
        $lune = $facts['moon'];

        /** @var array<int, array<string, int|float|string|null>> $tentatives */
        $tentatives = $facts['attempts'];

        return new self(
            (int)$facts['combat_instance_id'],
            FrozenMoonIdentity::fromFrozenFacts($lune),
            (string)$facts['rule_version'],
            array_map(
                static fn (array $fait): FrozenMoonDestructionAttempt
                    => FrozenMoonDestructionAttempt::fromFrozenFacts($fait),
                array_values($tentatives)
            )
        );
    }

    /**
     * Le plan, sous une forme comparable apres serialisation.
     *
     * @return array<string, mixed>
     */
    public function toFrozenFacts(): array
    {
        return [
            'schema' => self::SCHEMA,
            'combat_instance_id' => $this->combatInstanceId,
            'rule_version' => $this->ruleVersion,
            'moon' => $this->moon->toFrozenFacts(),
            'attempts' => array_map(
                static fn (FrozenMoonDestructionAttempt $tentative): array => $tentative->toFrozenFacts(),
                $this->attempts
            ),
        ];
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
