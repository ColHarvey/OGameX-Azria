<?php

namespace OGame\Combat\Presentation;

use InvalidArgumentException;
use OGame\Combat\Application\CombatApplicationContext;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\PlayerService;

/**
 * Ce que le rapport de combat gele **par participant et par type d unite**, au moment ou la bataille se regle
 * (revue de Codex du 18 septembre 2026, journal §161).
 *
 * ## Ce que le rapport disait, et ce qu il taisait
 *
 * Le rapport ne portait qu un attaquant et un defenseur, l effectif additionne, les niveaux de technologie du
 * proprietaire de la planete pour toute la defense, et un gabarit qui passait au navigateur des caracteristiques
 * inventees (la meme coque, la meme arme, le meme bouclier pour tout type de vaisseau attaquant). Un combat groupe
 * — plusieurs flottes attaquantes, une garnison et des renforts — se lisait comme un duel.
 *
 * ## Ce qui est gele ici
 *
 * Pour chaque flotte des deux camps (la garnison est un participant comme un autre) : qui la possede, d ou elle
 * vient, sa classe, ses trois niveaux de technologie et les niveaux de combat que ses classes ajoutent, ses unites
 * au depart, survivantes et perdues, et pour chaque type d unite les caracteristiques **que la bataille a
 * employees** — arme, bouclier, coque — avec la part des formes de vie a part, en pour cent et en points. Les
 * valeurs viennent des memes services de proprietes que le moteur (`toBattleUnits()`), lus sur le meme joueur —
 * vivant pour une attaque instantanee, gele a l admission pour un combat durable —, au meme instant : rien n est
 * recalcule plus tard, et un rapport ancien, sans ce bloc, ne recoit jamais les bonus d aujourd hui.
 *
 * Les pertes de chaque round par participant viennent des cartes que les deux moteurs produisent
 * (`attackerLossesInRoundPerFleet`, `defenderLossesInRoundPerFleet`, la garnison sous `0`).
 */
final class BattleReportParticipants
{
    public const int SCHEMA = 1;

    public const string GARRISON_KEY = 'garrison';

    /**
     * @param array<int, AttackerFleet> $attackerFleets
     * @param array<int, DefenderFleet> $defenderFleets
     * @return array<string, mixed>
     */
    public static function freeze(array $attackerFleets, array $defenderFleets, BattleResult $result, CombatApplicationContext $context): array
    {
        $attaquants = [];
        foreach ($attackerFleets as $flotte) {
            $resultat = self::resultOf($result->attackerFleetResults, $flotte->fleetMissionId);
            $mission = $flotte->fleetMission;
            $attaquants[] = self::participant(
                self::keyOfFleet($flotte->fleetMissionId),
                'fleet',
                $flotte->fleetMissionId > 0 ? $flotte->fleetMissionId : null,
                $flotte->player,
                $context,
                $mission === null ? null : [
                    'galaxy' => (int)$mission->galaxy_from,
                    'system' => (int)$mission->system_from,
                    'position' => (int)$mission->position_from,
                    'type' => (int)$mission->type_from,
                ],
                $flotte->units,
                $resultat === null ? new UnitCollection() : $resultat->unitsResult,
                $resultat === null ? self::lost($flotte->units, new UnitCollection()) : $resultat->unitsLost,
            );
        }

        $defenseurs = [];
        foreach ($defenderFleets as $flotte) {
            $garnison = $flotte->fleetMissionId === 0;
            $resultat = self::resultOf($result->defenderFleetResults, $flotte->fleetMissionId);
            $mission = $flotte->fleetMission;
            $defenseurs[] = self::participant(
                $garnison ? self::GARRISON_KEY : self::keyOfFleet($flotte->fleetMissionId),
                $garnison ? 'garrison' : 'fleet',
                $garnison ? null : $flotte->fleetMissionId,
                $flotte->player,
                $context,
                $mission === null ? null : [
                    'galaxy' => (int)$mission->galaxy_from,
                    'system' => (int)$mission->system_from,
                    'position' => (int)$mission->position_from,
                    'type' => (int)$mission->type_from,
                ],
                $flotte->units,
                $resultat === null ? new UnitCollection() : $resultat->unitsResult,
                $resultat === null ? self::lost($flotte->units, new UnitCollection()) : $resultat->unitsLost,
            );
        }

        $rounds = [];
        foreach ($result->rounds as $round) {
            $pertes = [];
            foreach ($round->attackerLossesInRoundPerFleet as $missionId => $unites) {
                $pertes[self::keyOfFleet((int)$missionId)] = $unites->toArray();
            }
            foreach ($round->defenderLossesInRoundPerFleet as $missionId => $unites) {
                $pertes[(int)$missionId === 0 ? self::GARRISON_KEY : self::keyOfFleet((int)$missionId)] = $unites->toArray();
            }
            $rounds[] = ['losses' => $pertes];
        }

        return ['schema' => self::SCHEMA, 'attackers' => $attaquants, 'defenders' => $defenseurs, 'rounds' => $rounds];
    }

    /**
     * La clef d une flotte dans le bloc : celle des inscriptions au combat (`CombatParticipantKey`), une flotte sans
     * mission (attaquante ephemere des bancs, contre-espionnage) portant la clef reservee.
     */
    public static function keyOfFleet(int $fleetMissionId): string
    {
        return $fleetMissionId > 0 ? CombatParticipantKey::forFleet($fleetMissionId) : CombatParticipantKey::EPHEMERAL_ATTACKER;
    }

    /**
     * @param array{galaxy: int, system: int, position: int, type: int}|null $origin
     * @return array<string, mixed>
     */
    private static function participant(string $key, string $kind, int|null $fleetMissionId, PlayerService $player, CombatApplicationContext $context, array|null $origin, UnitCollection $start, UnitCollection $result, UnitCollection $lost): array
    {
        $caracteristiques = [];
        foreach ($start->units as $unite) {
            $objet = $unite->unitObject;
            $arme = $objet->properties->attack->calculate($player);
            $bouclier = $objet->properties->shield->calculate($player);
            $coque = $objet->properties->structural_integrity->calculate($player);
            $caracteristiques[$objet->machine_name] = [
                'weapon' => $arme->totalValue,
                'shield' => $bouclier->totalValue,
                'armor' => intdiv($coque->totalValue, 10),
                'lifeform_percent' => round($player->getLifeformUnitStatsPercent($objet), 6),
                'lifeform_points' => [
                    'weapon' => self::lifeformLine($arme->breakdown),
                    'shield' => self::lifeformLine($bouclier->breakdown),
                    'armor' => intdiv(self::lifeformLine($coque->breakdown), 10),
                ],
            ];
        }

        return [
            'key' => $key,
            'kind' => $kind,
            'fleet_mission_id' => $fleetMissionId,
            'player_id' => $player->getId(),
            'player_name' => $player->getUsername(false),
            'character_class' => $context->characterClassOf($player)?->getName(),
            'origin' => $origin,
            'weapon_technology' => $player->getResearchLevel('weapon_technology'),
            'shielding_technology' => $player->getResearchLevel('shielding_technology'),
            'armor_technology' => $player->getResearchLevel('armor_technology'),
            'class_combat_levels' => $player->getCombatResearchBonusLevels(),
            'units_start' => $start->toArray(),
            'units_result' => $result->toArray(),
            'units_lost' => $lost->toArray(),
            'unit_characteristics' => $caracteristiques,
        ];
    }

    /**
     * La ligne « formes de vie » d une decomposition de propriete, en points ; zero si elle n y est pas.
     *
     * @param array<string, mixed> $breakdown
     */
    private static function lifeformLine(array $breakdown): int
    {
        foreach ($breakdown['bonuses'] ?? [] as $ligne) {
            if (($ligne['type'] ?? '') === 't_ingame.techtree.tooltip_lifeform_bonus') {
                return (int)($ligne['value'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Le resultat d une flotte parmi ceux du camp (une liste, chacun nomme sa mission) ; null si le moteur n en a pas rendu.
     *
     * @param array<int, AttackerFleetResult|DefenderFleetResult> $results
     */
    private static function resultOf(array $results, int $fleetMissionId): AttackerFleetResult|DefenderFleetResult|null
    {
        foreach ($results as $resultat) {
            if ($resultat->fleetMissionId === $fleetMissionId) {
                return $resultat;
            }
        }

        return null;
    }

    private static function lost(UnitCollection $start, UnitCollection $result): UnitCollection
    {
        $perdues = clone $start;
        $perdues->subtractCollection($result);

        return $perdues;
    }

    /**
     * Relit un bloc gele ; un bloc d un schema inconnu ou sans ses deux camps est une faute, jamais un repli.
     *
     * @param array<string, mixed>|null $stored
     * @return array<string, mixed>|null null quand le rapport est anterieur au bloc
     */
    public static function fromStorage(array|null $stored): array|null
    {
        if ($stored === null) {
            return null;
        }
        if (($stored['schema'] ?? null) !== self::SCHEMA || !is_array($stored['attackers'] ?? null) || !is_array($stored['defenders'] ?? null) || !is_array($stored['rounds'] ?? null)) {
            throw new InvalidArgumentException('Le bloc des participants du rapport de combat est illisible.');
        }
        foreach (['attackers', 'defenders'] as $camp) {
            foreach ($stored[$camp] as $participant) {
                if (!is_array($participant) || !is_string($participant['key'] ?? null) || !is_int($participant['player_id'] ?? null)) {
                    throw new InvalidArgumentException('Un participant du rapport de combat est illisible.');
                }
                foreach (['weapon_technology', 'shielding_technology', 'armor_technology', 'class_combat_levels'] as $niveau) {
                    if (!is_int($participant[$niveau] ?? null)) {
                        throw new InvalidArgumentException("Le niveau $niveau d un participant du rapport de combat n est pas un entier.");
                    }
                }
                foreach (['units_start', 'units_result', 'units_lost'] as $effectif) {
                    foreach ((array)($participant[$effectif] ?? []) as $nombre) {
                        if (!is_int($nombre)) {
                            throw new InvalidArgumentException("Un effectif $effectif d un participant du rapport de combat n est pas un entier.");
                        }
                    }
                }
                foreach ((array)($participant['unit_characteristics'] ?? []) as $caracteristiques) {
                    foreach (['weapon', 'shield', 'armor'] as $nom) {
                        if (!is_int($caracteristiques[$nom] ?? null)) {
                            throw new InvalidArgumentException("La caracteristique $nom d un participant du rapport de combat n est pas un entier.");
                        }
                    }
                }
            }
        }

        return $stored;
    }
}
