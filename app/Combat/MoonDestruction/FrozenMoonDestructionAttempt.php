<?php

namespace OGame\Combat\MoonDestruction;

use InvalidArgumentException;

/**
 * Une tentative de destruction, entierement figee au moment de la fermeture du ralliement.
 *
 * ## Pourquoi les tirages sont conserves, et pas une graine
 *
 * Une graine ne suffit pas : PHP et Rust ne consomment pas forcement les tirages dans le meme
 * ordre ni en meme nombre. Deux heures plus tard, rejouer la graine pourrait donner un autre
 * resultat que celui qui avait ete calcule — et le joueur verrait le second.
 *
 * Ce sont donc les **valeurs effectivement tirees** qui sont conservees, avec les entrees qui ont
 * servi aux probabilites. Le resultat est relisible sans rien recalculer.
 *
 * ## Les invariants que cet objet fait respecter
 *
 * - **une issue qui ne tire pas ne porte aucun tirage**, et ne perd aucune etoile de la mort. Une
 *   mission sautee qui consommerait quand meme un tirage decalerait la suite du hasard ;
 * - **une issue qui tire porte ses deux tirages**. Il y en a deux : la destruction, puis la perte ;
 * - **`NoSurvivingDeathstar` n'est possible qu'avec zero survivante**, et reciproquement ;
 * - **on ne perd pas plus d'etoiles de la mort qu'on n'en a**.
 */
final readonly class FrozenMoonDestructionAttempt
{
    /**
     * @param int $fleetMissionId La mission a qui appartient cette tentative.
     * @param int $order Son rang, a partir de 1, dans l'ordre deterministe.
     * @param int $survivingDeathstars Les survivantes de cette mission seule.
     * @param int $moonDiameter L'entree des deux probabilites, conservee pour l'audit.
     * @param string $ruleVersion La version de la regle qui a produit ce gel.
     * @param int|null $destructionRoll Le tirage de destruction, ou null si la tentative n'a pas eu lieu.
     * @param int|null $deathstarLossRoll Le tirage de perte, ou null de meme.
     * @param MoonDestructionOutcome $outcome Ce qui a ete obtenu.
     * @param int $extraDeathstarLosses Les etoiles de la mort perdues en plus du combat.
     */
    public function __construct(
        public int $fleetMissionId,
        public int $order,
        public int $survivingDeathstars,
        public int $moonDiameter,
        public string $ruleVersion,
        public int|null $destructionRoll,
        public int|null $deathstarLossRoll,
        public MoonDestructionOutcome $outcome,
        public int $extraDeathstarLosses,
    ) {
        if ($fleetMissionId < 1 || $order < 1) {
            throw new InvalidArgumentException(
                'Une tentative gelee exige un identifiant de mission et un rang strictement positifs.'
            );
        }

        $aTire = $destructionRoll !== null || $deathstarLossRoll !== null;

        if ($outcome->consumedADraw() !== $aTire) {
            throw new InvalidArgumentException(
                'L issue « ' . $outcome->value . ' » et les tirages conserves se contredisent. Une mission '
                . 'sautee qui consommerait un tirage decalerait la suite du hasard, et deux rejeux du meme '
                . 'plan ne donneraient plus le meme resultat.'
            );
        }

        if ($aTire && ($destructionRoll === null || $deathstarLossRoll === null)) {
            throw new InvalidArgumentException(
                'Une tentative qui a eu lieu porte ses deux tirages : la destruction, puis la perte.'
            );
        }

        foreach ([$destructionRoll, $deathstarLossRoll] as $tirage) {
            if ($tirage !== null && ($tirage < MoonDestructionOdds::ROLL_MINIMUM || $tirage > MoonDestructionOdds::ROLL_MAXIMUM)) {
                throw new InvalidArgumentException(
                    'Un tirage hors de la plage du jeu (' . MoonDestructionOdds::ROLL_MINIMUM . ' a '
                    . MoonDestructionOdds::ROLL_MAXIMUM . ') n aurait pas pu etre produit par le jeu : « '
                    . $tirage . ' ».'
                );
            }
        }

        if (($outcome === MoonDestructionOutcome::NoSurvivingDeathstar) !== ($survivingDeathstars === 0)) {
            throw new InvalidArgumentException(
                'L issue « aucune etoile de la mort survivante » et le nombre de survivantes se contredisent.'
            );
        }

        if ($extraDeathstarLosses < 0 || $extraDeathstarLosses > $survivingDeathstars) {
            throw new InvalidArgumentException(
                'On ne perd pas plus d etoiles de la mort qu on n en a : ' . $extraDeathstarLosses
                . ' perdues pour ' . $survivingDeathstars . ' survivantes.'
            );
        }

        if (!$outcome->consumedADraw() && $extraDeathstarLosses !== 0) {
            throw new InvalidArgumentException(
                'Une tentative qui n a pas eu lieu ne coute aucune etoile de la mort.'
            );
        }
    }

    /**
     * Si cette tentative a detruit la lune.
     */
    public function destroyedTheMoon(): bool
    {
        return $this->outcome === MoonDestructionOutcome::MoonDestroyed;
    }

    /**
     * La tentative, sous une forme comparable apres serialisation.
     *
     * @return array<string, int|string|null>
     */
    public function toFrozenFacts(): array
    {
        return [
            'fleet_mission_id' => $this->fleetMissionId,
            'order' => $this->order,
            'surviving_deathstars' => $this->survivingDeathstars,
            'moon_diameter' => $this->moonDiameter,
            'rule_version' => $this->ruleVersion,
            'destruction_roll' => $this->destructionRoll,
            'deathstar_loss_roll' => $this->deathstarLossRoll,
            'outcome' => $this->outcome->value,
            'extra_deathstar_losses' => $this->extraDeathstarLosses,
        ];
    }

    /**
     * La tentative relue, sans rien recalculer.
     *
     * @param array<string, int|string|null> $facts
     * @return self
     */
    public static function fromFrozenFacts(array $facts): self
    {
        return new self(
            (int)$facts['fleet_mission_id'],
            (int)$facts['order'],
            (int)$facts['surviving_deathstars'],
            (int)$facts['moon_diameter'],
            (string)$facts['rule_version'],
            $facts['destruction_roll'] === null ? null : (int)$facts['destruction_roll'],
            $facts['deathstar_loss_roll'] === null ? null : (int)$facts['deathstar_loss_roll'],
            MoonDestructionOutcome::from((string)$facts['outcome']),
            (int)$facts['extra_deathstar_losses'],
        );
    }
}
