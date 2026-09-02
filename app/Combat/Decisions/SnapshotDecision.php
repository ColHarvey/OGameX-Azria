<?php

namespace OGame\Combat\Decisions;

use InvalidArgumentException;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\RallyWindowImpact;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\SnapshotSource;

/**
 * Ce que la selection collective retient, ou ecarte, au moment de figer la photographie.
 *
 * **Une decision de groupe, pas de flotte.** Les limites d'un camp — cinq joueurs, seize flottes —
 * ne se tranchent pas sur une candidate isolee : il faut les voir toutes, triees par heure
 * planifiee puis identifiant de mission, pour savoir qui occupe la derniere place. C'est pourquoi
 * ce contrat existe separement de `ArrivalDecision`, qui ne decide que du mouvement physique.
 *
 * ## Les invariants que cet objet fait respecter
 *
 * - un **exclu n'apporte rien** : aucune contribution, jamais, et il ne prolonge rien ;
 * - un **retenu apporte au moins une chose**, et jamais deux fois la meme ;
 * - **seule une candidate retenue par la selection peut prolonger la fenetre.**
 *
 * Ce dernier point a du etre corrige. Exiger « une contribution de type flotte combattante » ne
 * suffisait pas : `DefendingFleet` designe aussi bien une Defense ACS candidate qu'un retour
 * personnel charge. Un retour pouvait donc maintenir la fenetre ouverte **pour s'y inclure
 * lui-meme** — l'exact contraire de la regle, qui veut qu'il entre dans la photographie s'il
 * arrive avant une echeance fixee par d'autres, sans jamais contribuer a la fixer.
 *
 * D'ou deux fabriques distinctes plutot qu'un parametre d'impact librement fourni : l'appelant ne
 * choisit pas s'il prolonge, il choisit ce qu'il est. Prolongent le ralliement, et eux seuls :
 * une attaque ou une attaque ACS candidate retenue, et une Defense ACS candidate retenue. Ni un
 * retour, ni un deploiement, ni un transport, ni la garnison deja stationnee.
 */
final readonly class SnapshotDecision implements CombatDecision
{
    /**
     * @param bool $included
     * @param array<int, SnapshotContribution> $contributions
     * @param SnapshotSource|null $source
     * @param RallyWindowImpact $rallyWindowImpact
     * @param CombatReasonCode $reason
     * @param string|null $openQuestion
     */
    private function __construct(
        public bool $included,
        public array $contributions,
        public SnapshotSource|null $source,
        public RallyWindowImpact $rallyWindowImpact,
        private CombatReasonCode $reason,
        private string|null $openQuestion = null,
    ) {
    }

    /**
     * Une candidate au ralliement, retenue par la selection de son camp.
     *
     * **La seule fabrique qui produise `Extend`.** Elle exige une flotte combattante : une
     * candidate qui n'apporterait que des ressources n'a pas de bataille a faire attendre.
     *
     * @param array<int, SnapshotContribution> $contributions
     * @return self
     */
    public static function includeSelectedRallyCandidate(array $contributions): self
    {
        self::guardContributions($contributions);
        self::guardContributionsMatchSource($contributions, SnapshotSource::SelectedRallyCandidate);

        if (!self::containsAFightingFleet($contributions)) {
            throw new InvalidArgumentException(
                'Une candidate retenue doit apporter une flotte combattante : sans elle, il n y a pas de bataille a faire attendre.'
            );
        }

        return new self(
            true,
            array_values($contributions),
            SnapshotSource::SelectedRallyCandidate,
            RallyWindowImpact::Extend,
            CombatReasonCode::NoCombatEffect,
        );
    }

    /**
     * Tout le reste de ce qui entre dans la photographie, sans retenir la fenetre.
     *
     * La garnison et les defenses deja presentes, les retours, deploiements et transports arrives
     * avant une echeance fixee par d'autres.
     *
     * @param array<int, SnapshotContribution> $contributions
     * @param SnapshotSource $source
     * @return self
     */
    public static function includeWithoutExtendingWindow(array $contributions, SnapshotSource $source): self
    {
        self::guardContributions($contributions);
        self::guardContributionsMatchSource($contributions, $source);

        return new self(
            true,
            array_values($contributions),
            $source,
            RallyWindowImpact::None,
            CombatReasonCode::NoCombatEffect,
        );
    }

    /**
     * Le participant est ecarte de la photographie.
     *
     * @param CombatReasonCode $reason
     * @return self
     */
    public static function exclude(CombatReasonCode $reason): self
    {
        return new self(false, [], null, RallyWindowImpact::None, $reason);
    }

    /**
     * La regle de cette case n'est pas arretee.
     *
     * @param string $question
     * @return self
     */
    public static function unresolved(string $question): self
    {
        return new self(false, [], null, RallyWindowImpact::None, CombatReasonCode::Undecided, $question);
    }

    /**
     * Ce que ce participant apporte. Leve si la case n'est pas tranchee.
     *
     * @return array<int, SnapshotContribution>
     */
    public function contributions(): array
    {
        if ($this->openQuestion !== null) {
            throw new UnresolvedCombatDecision($this->openQuestion);
        }

        return $this->contributions;
    }

    /**
     * Si ce participant retient la fenetre ouverte. Leve si la case n'est pas tranchee.
     */
    public function extendsRallyWindow(): bool
    {
        if ($this->openQuestion !== null) {
            throw new UnresolvedCombatDecision($this->openQuestion);
        }

        return $this->rallyWindowImpact === RallyWindowImpact::Extend;
    }

    public function reason(): CombatReasonCode
    {
        return $this->reason;
    }

    public function isResolved(): bool
    {
        return $this->openQuestion === null;
    }

    public function openQuestion(): string|null
    {
        return $this->openQuestion;
    }

    /**
     * Un retenu apporte au moins une chose, et jamais deux fois la meme.
     *
     * @param array<int, SnapshotContribution> $contributions
     * @return void
     */
    private static function guardContributions(array $contributions): void
    {
        if ($contributions === []) {
            throw new InvalidArgumentException(
                'Un participant retenu doit apporter quelque chose : sans contribution, il n a rien a faire dans la photographie.'
            );
        }

        $distinctes = array_unique(array_map(static fn (SnapshotContribution $c): string => $c->value, $contributions));

        if (count($contributions) !== count($distinctes)) {
            throw new InvalidArgumentException('La meme contribution est declaree deux fois.');
        }
    }

    /**
     * Chaque provenance ne declare que ce qui lui revient.
     *
     * **La regle generale qui remplace deux rustines.** Le double comptage a d'abord ete corrige
     * sur les ressources, puis retrouve a l'identique sur les vaisseaux : une flotte de retour
     * livree avant la barriere appartient a la garnison, donc la declarer comme
     * `DefendingFleet` la comptait deux fois — cent en garnison plus trente livres donnaient cent
     * soixante au lieu de cent trente.
     *
     * Le modele est desormais explicite, et ses trois ensembles sont disjoints :
     *
     *     defense totale = garnison photographiee une seule fois
     *                    + Defenses ACS retenues, qui ne s y fondent pas
     *
     * Une arrivee de passage ne peut donc declarer que des **marqueurs d'audit** : ce qu'elle a
     * livre figure deja dans l'etat global. Meme raisonnement pour un missile pre-photographie —
     * ses degats sont dans les defenses lues, pas a retrancher une seconde fois.
     *
     * @param array<int, SnapshotContribution> $contributions
     * @param SnapshotSource $source
     * @return void
     */
    private static function guardContributionsMatchSource(array $contributions, SnapshotSource $source): void
    {
        $admises = SnapshotContribution::allowedFor($source);

        foreach ($contributions as $contribution) {
            if (in_array($contribution, $admises, true)) {
                continue;
            }

            throw new InvalidArgumentException(
                'La contribution « ' . $contribution->value . ' » ne peut pas venir de « ' . $source->value . ' ». '
                . 'Provenances admises : ' . implode(', ', array_map(
                    static fn (SnapshotContribution $c): string => $c->value,
                    $admises
                )) . '. Compter la meme unite deux fois est exactement ce que cette table empeche.'
            );
        }
    }

    /**
     * @param array<int, SnapshotContribution> $contributions
     * @return bool
     */
    private static function containsAFightingFleet(array $contributions): bool
    {
        foreach ($contributions as $contribution) {
            if ($contribution->isFightingFleet()) {
                return true;
            }
        }

        return false;
    }
}
