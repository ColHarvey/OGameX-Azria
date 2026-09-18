<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Presentation\BattleReportParticipants;
use OGame\Combat\Support\CombatParticipantKey;
use PHPUnit\Framework\TestCase;

/**
 * La relecture du bloc des participants d un rapport de combat (journal §161) : une porte de confiance — un schema
 * inconnu, un camp absent, un niveau ou un effectif qui n est pas un entier sont refuses, jamais ramenes.
 */
final class BattleReportParticipantsTest extends TestCase
{
    public function testANullBlockIsAnOldReport(): void
    {
        $this->assertNull(BattleReportParticipants::fromStorage(null));
    }

    public function testAWellFormedBlockSurvivesStorage(): void
    {
        $bloc = self::bloc();
        $this->assertSame($bloc, BattleReportParticipants::fromStorage($bloc));
        $this->assertSame(CombatParticipantKey::forFleet(12), BattleReportParticipants::keyOfFleet(12));
        $this->assertSame(CombatParticipantKey::EPHEMERAL_ATTACKER, BattleReportParticipants::keyOfFleet(0));
    }

    public function testANumericStringOrAFloatIsRefused(): void
    {
        $fautes = [
            'schema inconnu' => ['schema' => 2] + self::bloc(),
            'schema en chaine' => ['schema' => '1'] + self::bloc(),
            'camp absent' => array_diff_key(self::bloc(), ['defenders' => 1]),
            'rounds absents' => array_diff_key(self::bloc(), ['rounds' => 1]),
            'joueur en chaine' => self::avec('attackers', 'player_id', '7'),
            'niveau en chaine' => self::avec('attackers', 'weapon_technology', '5'),
            'niveau flottant' => self::avec('defenders', 'armor_technology', 1.0),
            'niveaux de classe flottants' => self::avec('attackers', 'class_combat_levels', 2.0),
            'effectif en chaine' => self::avec('attackers', 'units_start', ['light_fighter' => '30']),
            'caracteristique flottante' => self::avec('attackers', 'unit_characteristics', ['light_fighter' => ['weapon' => 75.0, 'shield' => 15, 'armor' => 603]]),
            'clef absente' => self::avec('defenders', 'key', null),
        ];
        foreach ($fautes as $nom => $faute) {
            try {
                BattleReportParticipants::fromStorage($faute);
                $this->fail("« $nom » devait etre refuse.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function bloc(): array
    {
        $participant = static fn (string $clef, string $genre, int $joueur): array => [
            'key' => $clef,
            'kind' => $genre,
            'fleet_mission_id' => $genre === 'garrison' ? null : 12,
            'player_id' => $joueur,
            'player_name' => 'Joueur ' . $joueur,
            'character_class' => null,
            'origin' => null,
            'weapon_technology' => 5,
            'shielding_technology' => 5,
            'armor_technology' => 5,
            'class_combat_levels' => 0,
            'units_start' => ['light_fighter' => 30],
            'units_result' => ['light_fighter' => 20],
            'units_lost' => ['light_fighter' => 10],
            'unit_characteristics' => ['light_fighter' => ['weapon' => 75, 'shield' => 15, 'armor' => 603, 'lifeform_percent' => 0.9, 'lifeform_points' => ['weapon' => 0, 'shield' => 0, 'armor' => 3]]],
        ];

        return [
            'schema' => BattleReportParticipants::SCHEMA,
            'attackers' => [$participant(CombatParticipantKey::forFleet(12), 'fleet', 7)],
            'defenders' => [$participant(BattleReportParticipants::GARRISON_KEY, 'garrison', 8)],
            'rounds' => [['losses' => [CombatParticipantKey::forFleet(12) => ['light_fighter' => 10], BattleReportParticipants::GARRISON_KEY => []]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function avec(string $camp, string $champ, mixed $valeur): array
    {
        $bloc = self::bloc();
        if ($valeur === null) {
            unset($bloc[$camp][0][$champ]);
        } else {
            $bloc[$camp][0][$champ] = $valeur;
        }

        return $bloc;
    }
}
