<?php

namespace OGame\Combat\Decisions;

use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Exceptions\ImpossibleCombatSituation;
use OGame\Combat\Support\ReturnPlan;

/**
 * Une situation d'arrivee, telle que la matrice la recoit.
 *
 * ## Pourquoi un objet plutot que quatre parametres
 *
 * La matrice compte **396 cellules** : onze genres de mission, deux etapes de vol, trois genres
 * d'acteur, et six etats possibles du corps vise — les cinq etats d'un combat, plus l'absence de
 * combat. Quatre parametres nus dans cet ordre-la seraient inversables sans que rien ne le voie.
 *
 * L'objet permet aussi de **denombrer** : un essai enumere toutes les situations et compte celles
 * qui n'ont pas encore de regle. Le nombre ne peut que descendre, et « zero decision ouverte »
 * devient verifiable au lieu d'etre une impression.
 *
 * ## L'etat est celui de l'heure planifiee, jamais celui de la lecture
 *
 * C'est la regle la plus facile a enfreindre sans s'en apercevoir, et la plus couteuse. Un worker
 * en retard qui lirait l'etat **courant** de la cible transformerait une attaque prevue pendant la
 * bataille en attaque arrivant sur un corps libre — elle ouvrirait alors un second combat, c'est-a-dire
 * exactement la file d'attente que le jeu refuse.
 *
 *     evenement prevu pendant Active, traite apres Resolved
 *       -> reste une attaque tardive : elle repart
 *       -> elle n'ouvre pas un combat nouveau
 *
 * `targetState` porte donc l'etat du combat **a l'instant ou l'evenement etait prevu**. `Resolved`
 * ne vaut « corps libre » que pour un evenement reellement prevu apres la fin du combat, et qui
 * n'avait pas deja recu une continuation differee.
 *
 * ## La portee compte autant que les coordonnees
 *
 * Un champ de debris, une position de colonisation et l'espace profond peuvent partager les
 * coordonnees d'une planete assiegee sans rien partager d'autre. `scope()` les separe : le verrou
 * de combat ne couvre que la planete ou la lune elle-meme.
 */
final readonly class CombatSituation
{
    /**
     * @param CombatMissionKind $mission Ce que la flotte etait partie faire.
     * @param FlightLeg $leg L'aller ou le retour.
     * @param ActorKind $actor Qui tient la flotte.
     * @param CombatState|null $targetState L'etat du combat sur le corps vise **a l'heure planifiee
     *                                      de l'evenement**, ou `null` s'il n'y en avait aucun.
     */
    public function __construct(
        public CombatMissionKind $mission,
        public FlightLeg $leg,
        public ActorKind $actor,
        public CombatState|null $targetState,
    ) {
    }

    /**
     * Toutes les situations possibles, sans exception.
     *
     * Y compris celles qui ne peuvent pas se produire : les enumerer permet de les **classer**
     * `StructurallyNotApplicable` au lieu de les oublier. Une case absente du denombrement est une
     * case que personne ne verifie.
     *
     * @return array<int, self>
     */
    public static function all(): array
    {
        $situations = [];

        foreach (CombatMissionKind::cases() as $mission) {
            foreach (FlightLeg::cases() as $leg) {
                foreach (ActorKind::cases() as $actor) {
                    foreach ([null, ...CombatState::cases()] as $etat) {
                        $situations[] = new self($mission, $leg, $actor, $etat);
                    }
                }
            }
        }

        return $situations;
    }

    /**
     * La situation, sous une forme lisible dans un message d'essai.
     */
    public function describe(): string
    {
        return $this->mission->value
            . ' / ' . $this->leg->value
            . ' / ' . $this->actor->value
            . ' / ' . ($this->targetState?->value ?? 'aucun combat');
    }

    /**
     * Ce que cette arrivee touche reellement.
     *
     * **Un retour se pose toujours sur un corps celeste**, quel qu'ait ete l'objet de son aller :
     * une expedition qui rentre atterrit chez elle, et cette planete-la peut etre assiegee. Faire
     * dependre la portee du seul genre de mission ferait sortir ces retours du domaine du verrou,
     * alors qu'ils y sont pleinement.
     */
    public function scope(): TargetScope
    {
        if ($this->leg === FlightLeg::Return) {
            return TargetScope::CelestialBody;
        }

        return $this->mission->targetScope();
    }

    /**
     * La portee reelle, une fois le plan de retour resolu sous verrou.
     *
     * **`scope()` dit l'intention, celle-ci dit le fait.** `FlightLeg::Return` ne garantit pas un
     * corps celeste : la lune d'origine peut avoir ete detruite pendant le vol. Le jeu prevoit les
     * recours et ils sont ordonnes — corps d'origine, planete associee, planete mere — mais un
     * acteur peut les epuiser tous. C'est `ReturnPlan` qui porte le fait, apres les avoir epuises.
     *
     * @param ReturnPlan $resolvedReturn Le plan fige au moment de la decision, sous verrou.
     * @return TargetScope
     */
    public function scopeFor(ReturnPlan $resolvedReturn): TargetScope
    {
        if ($this->leg !== FlightLeg::Return) {
            return $this->mission->targetScope();
        }

        return $resolvedReturn->isPossible() ? TargetScope::CelestialBody : TargetScope::NoDestination;
    }

    /**
     * Si le corps vise porte un combat qui n'est pas encore termine.
     *
     * `Resolved` et `Cancelled` sont terminaux : le corps est libre, une arrivee y est traitee
     * comme s'il ne s'etait rien passe — a la condition, rappelee dans l'en-tete de cette classe,
     * que l'evenement ait vraiment ete prevu apres la fin du combat.
     */
    public function targetIsEngaged(): bool
    {
        return $this->targetState === CombatState::Rallying
            || $this->targetState === CombatState::Active
            || $this->targetState === CombatState::Resolving;
    }

    /**
     * Si cette situation peut exister ailleurs que dans une enumeration.
     *
     * Deux impossibilites structurelles :
     *
     * - **un missile n'a pas d'etape de retour.** Il frappe ou il est annule ; rien ne rentre ;
     * - **une expedition ne rencontre pas l'etat de combat d'un corps celeste** sur son aller.
     *   Elle vise l'espace profond, qui n'en porte aucun.
     */
    public function isPossible(): bool
    {
        if ($this->mission === CombatMissionKind::Missile && $this->leg === FlightLeg::Return) {
            return false;
        }

        return !($this->scope() === TargetScope::DeepSpace && $this->targetState !== null);
    }

    /**
     * Refuse une situation qui ne peut pas se produire.
     *
     * A appeler sur tout chemin qui construit une situation a partir de **donnees reelles**. La
     * matrice, elle, les classe sans lever : elle enumere des cases, et une case impossible se
     * range plutot qu'elle n'echoue.
     *
     * @return void
     */
    public function ensureItCanOccur(): void
    {
        if ($this->isPossible()) {
            return;
        }

        throw new ImpossibleCombatSituation(
            'La situation « ' . $this->describe() . ' » ne peut pas se produire : un missile n a pas d etape '
            . 'de retour, et une expedition ne rencontre pas l etat de combat d un corps celeste. La traiter '
            . 'comme une arrivee ordinaire laisserait invisible le defaut qui l a produite.'
        );
    }
}
