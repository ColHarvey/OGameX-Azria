<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Presentation\CombatPresentationTimelineV1;
use OGame\Combat\Presentation\PresentationEvent;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatParticipant;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * La regle de presentation : deterministe depuis le resultat gele, datee par le calendrier, ordonnee.
 *
 * Le banc unitaire, et non le TestCase nu : les objets de jeu se resolvent par l'application.
 */
class CombatPresentationTimelineV1Test extends UnitTestCase
{
    private const int DEBUT = 1_800_000_000;

    /**
     * Le meme resultat rend exactement les memes evenements, quel que soit l'ordre des cartes.
     */
    public function testTheSameResultProjectsTheSameEventsWhateverTheMapOrder(): void
    {
        $regle = new CombatPresentationTimelineV1();

        $droit = $regle->project($this->aResult(false), [10, 20], self::DEBUT);
        $melange = $regle->project($this->aResult(true), [10, 20], self::DEBUT);

        $this->assertNotSame([], $droit);
        $this->assertEquals($droit, $melange, 'The iteration order of the loss maps leaked into the events.');
    }

    /**
     * Chaque perte devient visible a la fin de la periode de son round, comptee depuis le debut.
     */
    public function testALossBecomesVisibleAtTheEndOfItsRoundPeriod(): void
    {
        $evenements = (new CombatPresentationTimelineV1())->project($this->aResult(false), [10, 20], self::DEBUT);

        $instants = array_unique(array_map(static fn (PresentationEvent $e): int => $e->visibleAt, $evenements));
        sort($instants);

        $this->assertSame([self::DEBUT + 10, self::DEBUT + 30], $instants, 'The losses are not dated at the end of their round period.');
    }

    /**
     * L'ordre est fixe : round, camp attaquant avant defenseur, clef, unite ; la sequence va de un a n.
     */
    public function testTheSequenceIsStableAndFollowsRoundSideParticipantAndUnit(): void
    {
        $evenements = (new CombatPresentationTimelineV1())->project($this->aResult(false), [10, 20], self::DEBUT);

        $this->assertSame(range(1, count($evenements)), array_map(static fn (PresentationEvent $e): int => $e->sequence, $evenements));

        $premierRound = array_values(array_filter($evenements, static fn (PresentationEvent $e): bool => $e->visibleAt === self::DEBUT + 10));

        $this->assertSame(
            [
                [CombatParticipant::SIDE_ATTACKER, CombatParticipantKey::forFleet(41), 'light_fighter', 3],
                [CombatParticipant::SIDE_DEFENDER, CombatParticipantKey::forFleet(777), 'light_fighter', 2],
                [CombatParticipant::SIDE_DEFENDER, CombatParticipantKey::forPlanet(9), 'rocket_launcher', 4],
            ],
            array_map(static fn (PresentationEvent $e): array => [$e->side, $e->participantKey, $e->unit, $e->amount], $premierRound)
        );
    }

    /**
     * Une perte nulle ne fait pas d'evenement, et un round sans perte n'en fait aucun.
     */
    public function testZeroLossesAndEmptyRoundsProduceNoEvent(): void
    {
        $resultat = $this->aResult(false);
        $resultat->rounds[1]->lossesInRoundByParticipant = [
            CombatParticipantKey::forFleet(41) => $this->units(['light_fighter' => 0]),
        ];

        $evenements = (new CombatPresentationTimelineV1())->project($resultat, [10, 20], self::DEBUT);

        $this->assertSame([self::DEBUT + 10], array_values(array_unique(array_map(static fn (PresentationEvent $e): int => $e->visibleAt, $evenements))));
    }

    /**
     * Un calendrier qui ne compte pas les rounds du resultat se refuse : il daterait une autre bataille.
     */
    public function testAScheduleThatDoesNotMatchTheRoundsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CombatPresentationTimelineV1())->project($this->aResult(false), [10], self::DEBUT);
    }

    /**
     * Deux rounds : au premier, l'attaquante 41 perd trois chasseurs, le renfort 777 en perd deux et
     * la garnison (corps 9) perd quatre lanceurs ; au second, l'attaquante perd un croiseur.
     */
    private function aResult(bool $melange): BattleResult
    {
        $resultat = new BattleResult();
        $resultat->attackerFleetResults = [new AttackerFleetResult(41, 7, $this->units(['light_fighter' => 10, 'cruiser' => 2]))];

        $premier = new BattleResultRound();
        $pertes = [
            CombatParticipantKey::forPlanet(9) => $this->units(['rocket_launcher' => 4]),
            CombatParticipantKey::forFleet(777) => $this->units(['light_fighter' => 2]),
            CombatParticipantKey::forFleet(41) => $this->units(['light_fighter' => 3]),
        ];
        $premier->lossesInRoundByParticipant = $melange ? $pertes : array_reverse($pertes, true);

        $second = new BattleResultRound();
        $second->lossesInRoundByParticipant = [CombatParticipantKey::forFleet(41) => $this->units(['cruiser' => 1])];

        $resultat->rounds = [$premier, $second];

        return $resultat;
    }

    /**
     * @param array<string, int> $unites
     */
    private function units(array $unites): UnitCollection
    {
        $collection = new UnitCollection();

        foreach ($unites as $machine => $montant) {
            $collection->addUnit(ObjectService::getUnitObjectByMachineName($machine), $montant);
        }

        return $collection;
    }
}
