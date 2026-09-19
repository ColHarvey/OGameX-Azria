<?php

namespace OGame\Combat\Presentation;

use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;

/**
 * Ce que le rapport de combat montre par participant, depuis le bloc gele (journal §161) — ou, pour un rapport
 * anterieur au bloc, depuis l effectif additionne qu il porte, sans rien inventer.
 *
 * Deux sorties : les membres de chaque camp pour le gabarit (nom, origine, classe, niveaux, unites, lignes des formes
 * de vie), et la structure `combatData` que le script herite du jeu officiel (`ogame.messages.combatreport`) lit —
 * `member` par clef de participant, `combatRounds` avec les vaisseaux et les pertes de chaque participant a chaque
 * round. Le gabarit ecrivait ce JSON a la main avec des identifiants, des coordonnees, une alliance et des
 * caracteristiques de vaisseaux inventes : ils sont partis.
 */
final class BattleReportParticipantsView
{
    /**
     * @param array<string, mixed>|null $frozen le bloc gele, ou null pour un rapport ancien
     * @param array<int, BattleResultRound> $rounds les rounds additionnes du rapport
     * @param array{name: string, player_id: int, coords: string, planet_type: int, planet_name: string, character_class: string|null, weapons: int, shields: int, armor: int, units: UnitCollection} $attacker
     * @param array{name: string, player_id: int, coords: string, planet_type: int, planet_name: string, character_class: string|null, weapons: int, shields: int, armor: int, units: UnitCollection} $defender
     * @return array{frozen: bool, attackers: array<int, array<string, mixed>>, defenders: array<int, array<string, mixed>>, combat_data: array<string, mixed>}
     */
    public static function compose(array|null $frozen, array $rounds, array $attacker, array $defender): array
    {
        if ($frozen === null) {
            return self::fromAggregate($rounds, $attacker, $defender);
        }

        $attaquants = array_map(static fn (array $p): array => self::member($p, $attacker), $frozen['attackers']);
        $defenseurs = array_map(static fn (array $p): array => self::member($p, $defender), $frozen['defenders']);

        return [
            'frozen' => true,
            'attackers' => $attaquants,
            'defenders' => $defenseurs,
            'combat_data' => [
                'combatRounds' => self::sharedRounds($rounds, $attaquants, $defenseurs, $frozen['rounds']),
                'isExpedition' => false,
                'attackerJSON' => self::sideJson($attaquants, $rounds, $frozen['rounds'], true),
                'defenderJSON' => self::sideJson($defenseurs, $rounds, $frozen['rounds'], false),
            ],
        ];
    }

    /**
     * Un participant gele, pret pour le gabarit : ses unites en collections, ses lignes de formes de vie.
     *
     * @param array<string, mixed> $p
     * @param array{coords: string, planet_type: int, planet_name: string} $fallback la planete du rapport, pour la garnison
     * @return array<string, mixed>
     */
    private static function member(array $p, array $fallback): array
    {
        $origine = $p['origin'] ?? null;
        $garnison = ($p['kind'] ?? '') === 'garrison';
        $coords = $garnison || !is_array($origine) ? $fallback['coords'] : $origine['galaxy'] . ':' . $origine['system'] . ':' . $origine['position'];
        $type = $garnison || !is_array($origine) ? $fallback['planet_type'] : (int)$origine['type'];

        $lignes = [];
        foreach ($p['unit_characteristics'] ?? [] as $machine => $c) {
            $pourcent = (float)($c['lifeform_percent'] ?? 0);
            if ($pourcent <= 0) {
                continue;
            }
            $objet = ObjectService::getUnitObjectByMachineName($machine);
            $lignes[] = [
                'unit' => $objet->title,
                'percent' => $pourcent,
                'weapon' => (int)($c['lifeform_points']['weapon'] ?? 0),
                'shield' => (int)($c['lifeform_points']['shield'] ?? 0),
                'armor' => (int)($c['lifeform_points']['armor'] ?? 0),
            ];
        }

        return [
            'key' => (string)$p['key'],
            'script_id' => self::scriptId((string)$p['key']),
            'kind' => (string)($p['kind'] ?? 'fleet'),
            'player_id' => (int)$p['player_id'],
            'name' => (string)($p['player_name'] ?? ''),
            'coords' => $coords,
            'planet_type' => $type,
            'label' => (string)($p['player_name'] ?? '') . ' [' . $coords . ']',
            'character_class' => $p['character_class'] ?? null,
            'weapons' => (int)$p['weapon_technology'] * 10,
            'shields' => (int)$p['shielding_technology'] * 10,
            'armor' => (int)$p['armor_technology'] * 10,
            'class_levels' => (int)($p['class_combat_levels'] ?? 0),
            'units_start' => self::collection((array)($p['units_start'] ?? [])),
            'units_lost' => self::collection((array)($p['units_lost'] ?? [])),
            'unit_characteristics' => (array)($p['unit_characteristics'] ?? []),
            'lifeform_lines' => $lignes,
        ];
    }

    /**
     * L identifiant qu un participant porte dans le JSON du script et dans la valeur de son option.
     *
     * Le script officiel lit une option `nom|id1:id2` et decoupe les identifiants sur le deux-points
     * (`loadDataBySelectedRound`) : une clef de participant (`fleet:12`) en porte un, et le decoupage rendait
     * une clef vide puis une TypeError au premier clic sur un round (journal §165).
     */
    private static function scriptId(string $key): string
    {
        return str_replace(':', '_', $key);
    }

    /**
     * @param array<string, int> $units
     */
    private static function collection(array $units): UnitCollection
    {
        $collection = new UnitCollection();
        foreach ($units as $machine => $nombre) {
            if ((int)$nombre > 0) {
                $collection->addUnit(ObjectService::getUnitObjectByMachineName((string)$machine), (int)$nombre);
            }
        }

        return $collection;
    }

    /**
     * Les rounds partages du script (round de depart puis un par round joue) : vaisseaux par participant.
     *
     * @param array<int, BattleResultRound> $rounds
     * @param array<int, array<string, mixed>> $attaquants
     * @param array<int, array<string, mixed>> $defenseurs
     * @param array<int, array{losses: array<string, array<string, int>>}> $frozenRounds
     * @return array<int, array<string, mixed>>
     */
    private static function sharedRounds(array $rounds, array $attaquants, array $defenseurs, array $frozenRounds): array
    {
        $resultat = [[
            'statistics' => null,
            'attackerLosses' => null,
            'defenderLosses' => null,
            'attackerShips' => self::shipsAtRound($attaquants, $frozenRounds, -1),
            'defender' => (object)[],
            'defenderShips' => self::shipsAtRound($defenseurs, $frozenRounds, -1),
        ]];
        foreach ($rounds as $i => $round) {
            $resultat[] = [
                'statistics' => [
                    ['side' => 'attacker', 'strength' => $round->fullStrengthAttacker, 'hits' => $round->hitsAttacker, 'absorbedDamage' => $round->absorbedDamageAttacker],
                    ['side' => 'defender', 'strength' => $round->fullStrengthDefender, 'hits' => $round->hitsDefender, 'absorbedDamage' => $round->absorbedDamageDefender],
                ],
                'attackerLosses' => self::lossesAtRound($attaquants, $frozenRounds, $i, false),
                'defenderLosses' => self::lossesAtRound($defenseurs, $frozenRounds, $i, false),
                'attackerShips' => self::shipsAtRound($attaquants, $frozenRounds, $i),
                'defenderShips' => self::shipsAtRound($defenseurs, $frozenRounds, $i),
            ];
        }

        return $resultat;
    }

    /**
     * La moitie du script pour un camp : membres et rounds, chacun sous sa clef.
     *
     * @param array<int, array<string, mixed>> $membres
     * @param array<int, BattleResultRound> $rounds
     * @param array<int, array{losses: array<string, array<string, int>>}> $frozenRounds
     * @return array<string, mixed>
     */
    private static function sideJson(array $membres, array $rounds, array $frozenRounds, bool $attaquant): array
    {
        $member = [];
        foreach ($membres as $m) {
            $details = [];
            foreach ($m['units_start']->units as $unite) {
                // Les caracteristiques gelees quand le rapport les porte ; un rapport ancien n en invente aucune.
                $c = $m['unit_characteristics'][$unite->unitObject->machine_name] ?? null;
                $details[(string)$unite->unitObject->id] = $c === null ? ['count' => $unite->amount] : [
                    'armor' => (int)($c['armor'] ?? 0),
                    'weapon' => (int)($c['weapon'] ?? 0),
                    'shield' => (int)($c['shield'] ?? 0),
                    'count' => $unite->amount,
                ];
            }
            $member[$m['script_id']] = [
                'ownerName' => $m['name'],
                'ownerCharacterClassName' => (string)($m['character_class'] ?? ''),
                'ownerID' => $m['player_id'],
                'ownerCoordinates' => $m['coords'],
                'ownerPlanetType' => $m['planet_type'],
                'fleetID' => $m['script_id'],
                'armorPercentage' => $m['armor'],
                'weaponPercentage' => $m['weapons'],
                'shieldPercentage' => $m['shields'],
                'classLevels' => $m['class_levels'],
                'shipDetails' => (object)$details,
            ];
        }

        $combatRounds = [[
            'lossesInThisRound' => null,
            'statistic' => ['hits' => 0, 'absorbedDamage' => 0, 'fullStrength' => 0],
            'losses' => null,
            'ships' => self::shipsAtRound($membres, $frozenRounds, -1),
        ]];
        foreach ($rounds as $i => $round) {
            $combatRounds[] = [
                'lossesInThisRound' => self::lossesAtRound($membres, $frozenRounds, $i, true),
                'statistic' => [
                    'hits' => $attaquant ? $round->hitsAttacker : $round->hitsDefender,
                    'absorbedDamage' => $attaquant ? $round->absorbedDamageAttacker : $round->absorbedDamageDefender,
                    'fullStrength' => $attaquant ? $round->fullStrengthAttacker : $round->fullStrengthDefender,
                ],
                'losses' => self::lossesAtRound($membres, $frozenRounds, $i, false),
                'ships' => self::shipsAtRound($membres, $frozenRounds, $i),
            ];
        }

        return ['member' => (object)$member, 'combatRounds' => $combatRounds];
    }

    /**
     * Les vaisseaux d un membre a la fin d un round (−1 : au depart) : le depart moins les pertes cumulees.
     *
     * @param array<int, array<string, mixed>> $membres
     * @param array<int, array{losses: array<string, array<string, int>>}> $frozenRounds
     * @return object
     */
    private static function shipsAtRound(array $membres, array $frozenRounds, int $round): object
    {
        $resultat = [];
        foreach ($membres as $m) {
            $restants = [];
            foreach ($m['units_start']->units as $unite) {
                $perdus = 0;
                for ($r = 0; $r <= $round && $r < count($frozenRounds); $r++) {
                    $perdus += (int)($frozenRounds[$r]['losses'][$m['key']][$unite->unitObject->machine_name] ?? 0);
                }
                $restants[(string)$unite->unitObject->id] = max(0, $unite->amount - $perdus);
            }
            $resultat[$m['script_id']] = (object)$restants;
        }

        return (object)$resultat;
    }

    /**
     * Les pertes d un membre a un round, de ce round seul ou cumulees depuis le premier.
     *
     * @param array<int, array<string, mixed>> $membres
     * @param array<int, array{losses: array<string, array<string, int>>}> $frozenRounds
     * @return object
     */
    private static function lossesAtRound(array $membres, array $frozenRounds, int $round, bool $inThisRoundOnly): object
    {
        $resultat = [];
        foreach ($membres as $m) {
            $pertes = [];
            foreach ($m['units_start']->units as $unite) {
                $total = 0;
                for ($r = $inThisRoundOnly ? $round : 0; $r <= $round && $r < count($frozenRounds); $r++) {
                    $total += (int)($frozenRounds[$r]['losses'][$m['key']][$unite->unitObject->machine_name] ?? 0);
                }
                if ($total > 0) {
                    $pertes[(string)$unite->unitObject->id] = $total;
                }
            }
            $resultat[$m['script_id']] = (object)$pertes;
        }

        return (object)$resultat;
    }

    /**
     * Un rapport anterieur au bloc : un membre par camp, l effectif additionne, les identifiants reels du rapport et
     * rien d invente — ni coordonnees, ni alliance, ni caracteristiques de vaisseaux (le script ne les lit pas).
     *
     * @param array<int, BattleResultRound> $rounds
     * @param array{name: string, player_id: int, coords: string, planet_type: int, planet_name: string, character_class: string|null, weapons: int, shields: int, armor: int, units: UnitCollection} $attacker
     * @param array{name: string, player_id: int, coords: string, planet_type: int, planet_name: string, character_class: string|null, weapons: int, shields: int, armor: int, units: UnitCollection} $defender
     * @return array{frozen: bool, attackers: array<int, array<string, mixed>>, defenders: array<int, array<string, mixed>>, combat_data: array<string, mixed>}
     */
    private static function fromAggregate(array $rounds, array $attacker, array $defender): array
    {
        $membre = static fn (array $camp, string $clef, string $genre): array => [
            'key' => $clef,
            'script_id' => self::scriptId($clef),
            'kind' => $genre,
            'player_id' => $camp['player_id'],
            'name' => $camp['name'],
            'coords' => $camp['coords'],
            'planet_type' => $camp['planet_type'],
            'label' => $camp['name'] . ' [' . $camp['coords'] . ']',
            'character_class' => $camp['character_class'],
            'weapons' => $camp['weapons'],
            'shields' => $camp['shields'],
            'armor' => $camp['armor'],
            'class_levels' => 0,
            'units_start' => $camp['units'],
            'units_lost' => new UnitCollection(),
            'unit_characteristics' => [],
            'lifeform_lines' => [],
        ];
        $attaquants = [$membre($attacker, 'attacker', 'fleet')];
        $defenseurs = [$membre($defender, BattleReportParticipants::GARRISON_KEY, 'garrison')];

        // Les pertes de chaque round, additionnees, portees par l unique membre de chaque camp.
        $frozenRounds = [];
        foreach ($rounds as $round) {
            $frozenRounds[] = ['losses' => [
                'attacker' => $round->attackerLossesInRound->toArray(),
                BattleReportParticipants::GARRISON_KEY => $round->defenderLossesInRound->toArray(),
            ]];
        }

        return [
            'frozen' => false,
            'attackers' => $attaquants,
            'defenders' => $defenseurs,
            'combat_data' => [
                'combatRounds' => self::sharedRounds($rounds, $attaquants, $defenseurs, $frozenRounds),
                'isExpedition' => false,
                'attackerJSON' => self::sideJson($attaquants, $rounds, $frozenRounds, true),
                'defenderJSON' => self::sideJson($defenseurs, $rounds, $frozenRounds, false),
            ],
        ];
    }
}
