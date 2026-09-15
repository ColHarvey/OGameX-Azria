<?php

namespace OGame\Military;

use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;

/**
 * Ce qu une bataille reglee d un bloc donne aux cumuls militaires : ni le monde, ni le moteur — une photographie.
 *
 * ## Pourquoi une forme a part, versionnee et serialisable
 *
 * L evaluation des « detruits » et des « perdus » se calcule round par round sur le resultat que les deux moteurs
 * figent de la meme facon. Quand elle ne peut pas conclure — unite hors catalogue, manoeuvre de Hamill sans victime
 * nommee, incoherence — l evenement attend, **entier**, et sa reprise doit retrouver exactement les memes faits et les
 * memes prix : le resultat gele d un combat durable vit dans son instance, mais celui du chemin instantane n est ecrit
 * nulle part. Les faits portent donc tout ce que l evaluation lit, et les **prix bruts** du moment, comme la tranche
 * de construction garde le sien.
 *
 * ## Ce que les faits ne disent pas
 *
 * Aucun proprietaire n est invente : il vient du resultat (`playerId` de chaque flotte attaquante, `ownerId` de chaque
 * flotte defensive, la garnison comprise). Aucun n est perdu : un participant sans proprietaire fait attendre toute la
 * bataille. Les pertes anterieures au premier round (`preRoundLosses`) sont un emplacement pour la manoeuvre de Hamill
 * le jour ou le moteur nommera sa victime ; aujourd hui il est vide, et « depart moins pertes des rounds precedents »
 * n est deja plus la definition des forces d un round : c est « depart moins pertes anterieures moins pertes des
 * rounds precedents ».
 */
final readonly class BattleTallyFacts
{
    public const int SCHEMA = 1;

    public const string SPACE_COMBAT = 'combat';

    public const string SPACE_MISSION = 'mission';

    public const string SIDE_ATTACKER = 'attaquant';

    public const string SIDE_DEFENDER = 'defenseur';

    /**
     * @param string $space L espace de clefs : combat durable ou mission instantanee, sans collision possible.
     * @param int $id L identifiant du combat, ou de la mission initiatrice.
     * @param string $bodyKey La clef de participant du corps vise (sa garnison).
     * @param int $echeance L instant logique du fait : l echeance du combat, l arrivee sur le chemin instantane.
     * @param bool $hamillTriggered Si la manoeuvre de Hamill a retire une Etoile avant le premier round.
     * @param list<array{key: string, side: string, owner: int|null, npc: bool, start: array<string, int>, lost: array<string, int>}> $participants
     * @param array<string, int> $repaired Les defenses reparees apres la bataille (garnison seule).
     * @param list<array<string, array<string, int>>> $rounds Les pertes de chaque round, par participant.
     * @param list<array<string, array<string, int>>> $attackerShipsPerRound Les restants en fin de chaque round, par flotte attaquante.
     * @param array<string, array<string, int>> $preRoundLosses Les pertes anterieures au premier round, par participant.
     * @param array<string, int> $prices Le prix brut de chaque unite nommee, au moment du fait.
     */
    public function __construct(
        public string $space,
        public int $id,
        public string $bodyKey,
        public int $echeance,
        public bool $hamillTriggered,
        public array $participants,
        public array $repaired,
        public array $rounds,
        public array $attackerShipsPerRound,
        public array $preRoundLosses,
        public array $prices,
    ) {
    }

    /**
     * @param list<int> $npcOwners Les comptes PNJ parmi les proprietaires : calcules, jamais credites.
     */
    public static function fromBattleResult(BattleResult $result, string $bodyKey, string $space, int $id, int $echeance, array $npcOwners): self
    {
        $participants = [];
        $noms = [];

        foreach ($result->attackerFleetResults as $flotte) {
            $clef = $flotte->fleetMissionId === 0 ? CombatParticipantKey::EPHEMERAL_ATTACKER : CombatParticipantKey::forFleet($flotte->fleetMissionId);
            $participants[] = self::participant($clef, self::SIDE_ATTACKER, $flotte->playerId, $npcOwners, $flotte->unitsStart, $flotte->unitsLost, $noms);
        }

        foreach ($result->defenderFleetResults as $flotte) {
            $clef = $flotte->fleetMissionId === 0 ? $bodyKey : CombatParticipantKey::forFleet($flotte->fleetMissionId);
            $participants[] = self::participant($clef, self::SIDE_DEFENDER, $flotte->ownerId, $npcOwners, $flotte->unitsStart, $flotte->unitsLost, $noms);
        }

        $rounds = [];
        $restants = [];

        foreach ($result->rounds as $round) {
            $pertes = [];

            foreach ($round->lossesInRoundByParticipant as $clef => $unites) {
                $pertes[(string)$clef] = self::units($unites, $noms);
            }

            ksort($pertes);
            $rounds[] = $pertes;

            $apres = [];

            foreach ($round->attackerShipsPerFleet as $mission => $unites) {
                $apres[$mission === 0 ? CombatParticipantKey::EPHEMERAL_ATTACKER : CombatParticipantKey::forFleet((int)$mission)] = self::units($unites, $noms);
            }

            ksort($apres);
            $restants[] = $apres;
        }

        $reparees = self::units($result->repairedDefenses, $noms);

        $prix = [];

        foreach (array_keys($noms) as $nom) {
            $prix[$nom] = (int)ObjectService::getObjectRawPrice($nom)->sum();
        }

        ksort($prix);

        return new self($space, $id, $bodyKey, $echeance, $result->hamillManoeuvreTriggered, $participants, $reparees, $rounds, $restants, [], $prix);
    }

    /**
     * La clef de l evenement d un participant : l espace, l identifiant, puis la clef de participant.
     */
    public function eventKeyFor(string $participant): string
    {
        return 'battle:' . $this->space . ':' . $this->id . ':' . $participant;
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'schema' => self::SCHEMA,
            'space' => $this->space,
            'id' => $this->id,
            'body_key' => $this->bodyKey,
            'echeance' => $this->echeance,
            'hamill_triggered' => $this->hamillTriggered,
            'participants' => $this->participants,
            'repaired' => $this->repaired,
            'rounds' => $this->rounds,
            'attacker_ships_per_round' => $this->attackerShipsPerRound,
            'pre_round_losses' => $this->preRoundLosses,
            'prices' => $this->prices,
        ];
    }

    /**
     * Relit des faits gardes : une porte de confiance, chaque champ exige sa forme, et un document qui ne la respecte
     * pas ne donne rien — l evenement reste en attente plutot que d etre evalue sur des faits deformes.
     */
    public static function fromStorage(mixed $document): self|null
    {
        if (!is_array($document) || ($document['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }

        $space = $document['space'] ?? null;
        $id = $document['id'] ?? null;
        $corps = $document['body_key'] ?? null;
        $echeance = $document['echeance'] ?? null;
        $hamill = $document['hamill_triggered'] ?? null;

        if (!in_array($space, [self::SPACE_COMBAT, self::SPACE_MISSION], true) || !is_int($id) || $id < 1
            || !is_string($corps) || !CombatParticipantKey::isWellFormed($corps) || !is_int($echeance) || !is_bool($hamill)) {
            return null;
        }

        $participants = self::participantsFrom($document['participants'] ?? null);
        $reparees = self::unitsFrom($document['repaired'] ?? null);
        $rounds = self::roundsFrom($document['rounds'] ?? null);
        $restants = self::roundsFrom($document['attacker_ships_per_round'] ?? null);
        $anterieures = self::unitsByKeyFrom($document['pre_round_losses'] ?? null);
        $prix = self::unitsFrom($document['prices'] ?? null);

        if ($participants === null || $reparees === null || $rounds === null || $restants === null || $anterieures === null || $prix === null) {
            return null;
        }

        return new self($space, $id, $corps, $echeance, $hamill, $participants, $reparees, $rounds, $restants, $anterieures, $prix);
    }

    /**
     * @param list<int> $npcOwners
     * @param array<string, true> $noms
     * @return array{key: string, side: string, owner: int|null, npc: bool, start: array<string, int>, lost: array<string, int>}
     */
    private static function participant(string $clef, string $camp, int $proprietaire, array $npcOwners, UnitCollection $depart, UnitCollection $pertes, array &$noms): array
    {
        return [
            'key' => $clef,
            'side' => $camp,
            'owner' => $proprietaire > 0 ? $proprietaire : null,
            'npc' => $proprietaire > 0 && in_array($proprietaire, $npcOwners, true),
            'start' => self::units($depart, $noms),
            'lost' => self::units($pertes, $noms),
        ];
    }

    /**
     * @param array<string, true> $noms
     * @return array<string, int>
     */
    private static function units(UnitCollection $unites, array &$noms): array
    {
        $document = [];

        foreach ($unites->toArray() as $nom => $nombre) {
            if ($nombre <= 0) {
                continue;
            }

            $document[(string)$nom] = (int)$nombre;
            $noms[(string)$nom] = true;
        }

        ksort($document);

        return $document;
    }

    /**
     * @return array<string, int>|null
     */
    private static function unitsFrom(mixed $document): array|null
    {
        if (!is_array($document)) {
            return null;
        }

        $unites = [];

        foreach ($document as $nom => $nombre) {
            if (!is_string($nom) || $nom === '' || !is_int($nombre) || $nombre < 0) {
                return null;
            }

            $unites[$nom] = $nombre;
        }

        return $unites;
    }

    /**
     * @return array<string, array<string, int>>|null
     */
    private static function unitsByKeyFrom(mixed $document): array|null
    {
        if (!is_array($document)) {
            return null;
        }

        $parClef = [];

        foreach ($document as $clef => $unites) {
            $lues = self::unitsFrom($unites);

            if (!is_string($clef) || !CombatParticipantKey::isWellFormed($clef) || $lues === null) {
                return null;
            }

            $parClef[$clef] = $lues;
        }

        return $parClef;
    }

    /**
     * @return list<array<string, array<string, int>>>|null
     */
    private static function roundsFrom(mixed $document): array|null
    {
        if (!is_array($document) || !array_is_list($document)) {
            return null;
        }

        $rounds = [];

        foreach ($document as $round) {
            $lu = self::unitsByKeyFrom($round);

            if ($lu === null) {
                return null;
            }

            $rounds[] = $lu;
        }

        return $rounds;
    }

    /**
     * @return list<array{key: string, side: string, owner: int|null, npc: bool, start: array<string, int>, lost: array<string, int>}>|null
     */
    private static function participantsFrom(mixed $document): array|null
    {
        if (!is_array($document) || !array_is_list($document)) {
            return null;
        }

        $participants = [];

        foreach ($document as $participant) {
            if (!is_array($participant)) {
                return null;
            }

            $clef = $participant['key'] ?? null;
            $camp = $participant['side'] ?? null;
            $proprietaire = $participant['owner'] ?? null;
            $npc = $participant['npc'] ?? null;
            $depart = self::unitsFrom($participant['start'] ?? null);
            $pertes = self::unitsFrom($participant['lost'] ?? null);

            if (!is_string($clef) || !CombatParticipantKey::isWellFormed($clef)
                || !in_array($camp, [self::SIDE_ATTACKER, self::SIDE_DEFENDER], true)
                || ($proprietaire !== null && (!is_int($proprietaire) || $proprietaire < 1))
                || !is_bool($npc) || $depart === null || $pertes === null) {
                return null;
            }

            $participants[] = ['key' => $clef, 'side' => $camp, 'owner' => $proprietaire, 'npc' => $npc, 'start' => $depart, 'lost' => $pertes];
        }

        return $participants;
    }
}
