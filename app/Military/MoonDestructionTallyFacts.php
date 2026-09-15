<?php

namespace OGame\Military;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;

/**
 * Les faits d une tentative de destruction de lune, tels que les cumuls militaires les lisent.
 *
 * Ce qui s est passe apres la bataille — qui est comptee a part, comme toute bataille — : quelles tentatives ont ete
 * jouees, combien d Etoiles chacune a perdues dans la catastrophe, laquelle a detruit la lune, et ce que la lune
 * emportait avec elle. Les prix bruts du moment sont gardes.
 *
 * Deux espaces : `mission` sur le chemin instantane, `combat` quand la tentative est le plan gele d un combat durable.
 */
final class MoonDestructionTallyFacts
{
    public const int SCHEMA = 1;

    /** @var array<int, int> */
    private const array READABLE_SCHEMAS = [1];

    public const string DEATHSTAR = 'deathstar';

    /**
     * @param string $space `BattleTallyFacts::SPACE_MISSION` ou `BattleTallyFacts::SPACE_COMBAT`.
     * @param int $id La mission, ou le combat.
     * @param int $echeance L arrivee sur le chemin instantane, l echeance du combat durable.
     * @param array{key: string, owner: int|null, npc: bool} $moon La lune : la clef de son corps, son proprietaire.
     * @param list<array{key: string, owner: int|null, npc: bool, deathstars_lost: int, destroyed_the_moon: bool}> $attempts
     * @param array<string, int> $destroyedWithMoon Les unites que la lune emportait, par nom ; vide si elle tient.
     * @param array<string, int> $prices Le prix brut de chaque unite nommee, Etoile comprise, au moment du fait.
     */
    public function __construct(
        public string $space,
        public int $id,
        public int $echeance,
        public array $moon,
        public array $attempts,
        public array $destroyedWithMoon,
        public array $prices,
    ) {
    }

    /**
     * @param array{key: string, owner: int|null, npc: bool} $moon
     * @param list<array{key: string, owner: int|null, npc: bool, deathstars_lost: int, destroyed_the_moon: bool}> $attempts
     */
    public static function of(string $space, int $id, int $echeance, array $moon, array $attempts, UnitCollection $destroyedWithMoon): self
    {
        $emportees = [];

        foreach ($destroyedWithMoon->toArray() as $nom => $nombre) {
            if ((int)$nombre > 0) {
                $emportees[(string)$nom] = (int)$nombre;
            }
        }

        ksort($emportees);
        $prix = [];

        foreach (array_merge(array_keys($emportees), [self::DEATHSTAR]) as $nom) {
            $prix[$nom] = (int)ObjectService::getObjectRawPrice($nom)->sum();
        }

        ksort($prix);

        return new self($space, $id, $echeance, $moon, array_values($attempts), $emportees, $prix);
    }

    public function eventKeyPrefix(): string
    {
        return 'moon:' . $this->space . ':' . $this->id . ':';
    }

    public function eventKeyFor(string $participant): string
    {
        return $this->eventKeyPrefix() . $participant;
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
            'echeance' => $this->echeance,
            'moon' => $this->moon,
            'attempts' => $this->attempts,
            'destroyed_with_moon' => $this->destroyedWithMoon,
            'prices' => $this->prices,
        ];
    }

    public static function fromStorage(mixed $document): self|null
    {
        if (!is_array($document) || !in_array($document['schema'] ?? null, self::READABLE_SCHEMAS, true)) {
            return null;
        }

        $space = $document['space'] ?? null;
        $id = $document['id'] ?? null;
        $echeance = $document['echeance'] ?? null;
        $lune = MissileTallyFacts::participantFrom($document['moon'] ?? null);
        $tentatives = self::attemptsFrom($document['attempts'] ?? null);
        $emportees = MissileTallyFacts::countsFrom($document['destroyed_with_moon'] ?? null);
        $prix = MissileTallyFacts::countsFrom($document['prices'] ?? null, true);

        if (!in_array($space, [BattleTallyFacts::SPACE_MISSION, BattleTallyFacts::SPACE_COMBAT], true) || !is_int($id) || $id < 1
            || !is_int($echeance) || $lune === null || $tentatives === null || $emportees === null || $prix === null) {
            return null;
        }

        return new self($space, $id, $echeance, $lune, $tentatives, $emportees, $prix);
    }

    /**
     * @return list<array{key: string, owner: int|null, npc: bool, deathstars_lost: int, destroyed_the_moon: bool}>|null
     */
    private static function attemptsFrom(mixed $document): array|null
    {
        if (!is_array($document) || !array_is_list($document)) {
            return null;
        }

        $tentatives = [];

        foreach ($document as $tentative) {
            if (!is_array($tentative)) {
                return null;
            }

            $participant = MissileTallyFacts::participantFrom(['key' => $tentative['key'] ?? null, 'owner' => $tentative['owner'] ?? null, 'npc' => $tentative['npc'] ?? null]);
            $perdues = $tentative['deathstars_lost'] ?? null;
            $detruite = $tentative['destroyed_the_moon'] ?? null;

            if ($participant === null || !is_int($perdues) || $perdues < 0 || !is_bool($detruite)
                || count(array_diff_key($tentative, ['key' => 0, 'owner' => 0, 'npc' => 0, 'deathstars_lost' => 0, 'destroyed_the_moon' => 0])) !== 0) {
                return null;
            }

            $tentatives[] = $participant + ['deathstars_lost' => $perdues, 'destroyed_the_moon' => $detruite];
        }

        return $tentatives;
    }
}
