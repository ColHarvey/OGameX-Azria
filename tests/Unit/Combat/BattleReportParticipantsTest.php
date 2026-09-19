<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Exceptions\IncoherentRoundAttribution;
use OGame\Combat\Presentation\BattleReportParticipants;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * La relecture du bloc des participants d un rapport de combat (journal §161) : une porte de confiance — un schema
 * inconnu, un camp absent, un niveau ou un effectif qui n est pas un entier sont refuses, jamais ramenes.
 */
final class BattleReportParticipantsTest extends UnitTestCase
{
    /**
     * **Le bloc porte chaque participant a chaque round, dans son ordre, meme si le moteur rend une carte creuse.**
     *
     * Le moteur PHP pose une entree vide pour chaque flotte a chaque round ; la bibliotheque Rust ne cree l entree qu a
     * la premiere perte, et ses tables de hachage ne se serialisent pas dans un ordre stable. Sous Rust, un renfort qui
     * traversait le round 0 sans perte **disparaissait du round** du bloc gele : la CI l a fait rougir sur `c0161281`
     * (journal §166). Le bloc ne lit donc plus les cartes dans leur ordre : il demande a chaque participant ce qu il a
     * perdu. Ici, la carte du moteur est volontairement creuse **et desordonnee**.
     */
    public function testEveryParticipantIsInEveryRoundEvenWhenTheEngineMapIsSparse(): void
    {
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $lanceur = ObjectService::getUnitObjectByMachineName('rocket_launcher');

        $premier = new BattleResultRound();
        $pertesAttaquantes = new UnitCollection();
        $pertesAttaquantes->addUnit($chasseur, 3);
        $premier->attackerLossesInRoundPerFleet = [19 => $pertesAttaquantes];
        $pertesGarnison = new UnitCollection();
        $pertesGarnison->addUnit($lanceur, 2);
        // Le renfort (18) a traverse ce round sans perte : la bibliotheque ne le nomme pas.
        $premier->defenderLossesInRoundPerFleet = [0 => $pertesGarnison];

        $second = new BattleResultRound();
        $second->attackerLossesInRoundPerFleet = [19 => new UnitCollection()];
        $pertesRenfort = new UnitCollection();
        $pertesRenfort->addUnit($chasseur, 1);
        // Et ici, dans l ordre inverse de celui des flottes.
        $second->defenderLossesInRoundPerFleet = [18 => $pertesRenfort, 0 => new UnitCollection()];

        $resultat = new BattleResult();
        $resultat->rounds = [$premier, $second];

        $rounds = BattleReportParticipants::roundsOf(
            [19 => CombatParticipantKey::forFleet(19)],
            [0 => BattleReportParticipants::GARRISON_KEY, 18 => CombatParticipantKey::forFleet(18)],
            $resultat
        );

        $attendu = [CombatParticipantKey::forFleet(19), BattleReportParticipants::GARRISON_KEY, CombatParticipantKey::forFleet(18)];
        $this->assertCount(2, $rounds);
        $this->assertSame($attendu, array_keys($rounds[0]['losses']), 'Round 1 : les trois participants, dans l ordre du bloc.');
        $this->assertSame($attendu, array_keys($rounds[1]['losses']), 'Round 2 : l ordre du bloc, pas celui de la carte du moteur.');
        $this->assertSame(['light_fighter' => 3], $rounds[0]['losses'][CombatParticipantKey::forFleet(19)]);
        $this->assertSame(['rocket_launcher' => 2], $rounds[0]['losses'][BattleReportParticipants::GARRISON_KEY]);
        $this->assertSame([], $rounds[0]['losses'][CombatParticipantKey::forFleet(18)], 'Un participant sans perte figure au round, les mains vides.');
        $this->assertSame(['light_fighter' => 1], $rounds[1]['losses'][CombatParticipantKey::forFleet(18)]);
    }

    /**
     * **Une perte attribuee a une flotte absente du bloc arrete la derivation** — elle ne disparait pas.
     *
     * Le bloc interroge les participants au lieu de recopier la carte du moteur : ce qu un moteur attribuerait a un
     * identifiant inconnu n irait donc nulle part. Le chemin Rust refuse deja ce cas (`RustRoundShape`) ; le meme refus
     * vaut ici, pour le moteur PHP comme pour un appelant qui reconstruirait la liste des flottes au lieu de passer
     * celle que le moteur a recue (revue du candidat, journal §166.2).
     */
    public function testALossAttributedToAFleetTheBlockDoesNotKnowIsRefused(): void
    {
        $perdu = new UnitCollection();
        $perdu->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 2);

        $round = new BattleResultRound();
        $round->attackerLossesInRoundPerFleet = [19 => new UnitCollection()];
        $round->defenderLossesInRoundPerFleet = [0 => new UnitCollection(), 99 => $perdu];

        $resultat = new BattleResult();
        $resultat->rounds = [$round];

        try {
            BattleReportParticipants::roundsOf(
                [19 => CombatParticipantKey::forFleet(19)],
                [0 => BattleReportParticipants::GARRISON_KEY],
                $resultat
            );
            $this->fail('Une perte attribuee a une flotte inconnue du bloc a ete acceptee, et elle aurait disparu.');
        } catch (IncoherentRoundAttribution $refus) {
            $this->assertStringContainsString('99', $refus->getMessage(), 'Le refus ne nomme pas la flotte fautive.');
            $this->assertStringContainsString('defenseur', $refus->getMessage());
        }
    }

    /**
     * Mais une entree **vide** sous un identifiant inconnu ne refuse rien : elle ne porte aucune perte, donc rien ne se
     * perdrait. Sans ce second cas, un refus trop large passerait pour juste.
     */
    public function testAnEmptyEntryUnderAnUnknownFleetRefusesNothing(): void
    {
        $round = new BattleResultRound();
        $round->attackerLossesInRoundPerFleet = [19 => new UnitCollection()];
        $round->defenderLossesInRoundPerFleet = [0 => new UnitCollection(), 99 => new UnitCollection()];

        $resultat = new BattleResult();
        $resultat->rounds = [$round];

        $rounds = BattleReportParticipants::roundsOf(
            [19 => CombatParticipantKey::forFleet(19)],
            [0 => BattleReportParticipants::GARRISON_KEY],
            $resultat
        );

        $this->assertSame([CombatParticipantKey::forFleet(19), BattleReportParticipants::GARRISON_KEY], array_keys($rounds[0]['losses']));
    }

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
