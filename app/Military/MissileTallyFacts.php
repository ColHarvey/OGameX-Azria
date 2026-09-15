<?php

namespace OGame\Military;

use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;

/**
 * Les faits d une frappe de missiles, tels que les cumuls militaires les lisent, serialisables et relisibles.
 *
 * Ce que la frappe a fait : combien de missiles ont ete interceptes, quelles defenses ont ete detruites, par qui et
 * contre qui. Ce qu elle a consomme — les missiles tires, les antimissiles — n est jamais un fait militaire : une
 * consommation normale ne compte pas. Les prix bruts du moment sont gardes pour que la reprise retrouve les memes
 * valeurs.
 *
 * Un participant : `{key, owner, npc}` — la clef de sa flotte (le tir) ou de son corps (la cible), son compte ou
 * rien, et s il est PNJ.
 */
final class MissileTallyFacts
{
    public const int SCHEMA = 1;

    /** @var array<int, int> */
    private const array READABLE_SCHEMAS = [1];

    public const string MISSILE = 'interplanetary_missile';

    /**
     * @param int $id L identifiant de la mission de missiles.
     * @param int $echeance L arrivee des missiles : l instant logique du fait.
     * @param array{key: string, owner: int|null, npc: bool} $attacker
     * @param array{key: string, owner: int|null, npc: bool} $defender
     * @param int $intercepted Les missiles interceptes par les antimissiles.
     * @param array<string, int> $destroyed Les defenses detruites par la frappe, par nom.
     * @param array<string, int> $prices Le prix brut de chaque unite nommee, missile compris, au moment du fait.
     */
    public function __construct(
        public int $id,
        public int $echeance,
        public array $attacker,
        public array $defender,
        public int $intercepted,
        public array $destroyed,
        public array $prices,
    ) {
    }

    /**
     * @param array{key: string, owner: int|null, npc: bool} $attacker
     * @param array{key: string, owner: int|null, npc: bool} $defender
     */
    public static function of(int $id, int $echeance, array $attacker, array $defender, int $intercepted, UnitCollection $destroyed): self
    {
        $detruites = [];

        foreach ($destroyed->toArray() as $nom => $nombre) {
            if ((int)$nombre > 0) {
                $detruites[(string)$nom] = (int)$nombre;
            }
        }

        ksort($detruites);
        $prix = [];

        foreach (array_merge(array_keys($detruites), [self::MISSILE]) as $nom) {
            $prix[$nom] = (int)ObjectService::getObjectRawPrice($nom)->sum();
        }

        ksort($prix);

        return new self($id, $echeance, $attacker, $defender, $intercepted, $detruites, $prix);
    }

    public function eventKeyPrefix(): string
    {
        return 'missile:mission:' . $this->id . ':';
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
            'id' => $this->id,
            'echeance' => $this->echeance,
            'attacker' => $this->attacker,
            'defender' => $this->defender,
            'intercepted' => $this->intercepted,
            'destroyed' => $this->destroyed,
            'prices' => $this->prices,
        ];
    }

    /**
     * Relit des faits gardes : une porte de confiance, chaque champ exige sa forme, et un document qui ne la respecte
     * pas ne donne rien.
     */
    public static function fromStorage(mixed $document): self|null
    {
        if (!is_array($document) || !in_array($document['schema'] ?? null, self::READABLE_SCHEMAS, true)) {
            return null;
        }

        $id = $document['id'] ?? null;
        $echeance = $document['echeance'] ?? null;
        $interceptes = $document['intercepted'] ?? null;
        $attaquant = self::participantFrom($document['attacker'] ?? null);
        $defenseur = self::participantFrom($document['defender'] ?? null);
        $detruites = self::countsFrom($document['destroyed'] ?? null);
        $prix = self::countsFrom($document['prices'] ?? null, true);

        if (!is_int($id) || $id < 1 || !is_int($echeance) || !is_int($interceptes) || $interceptes < 0
            || $attaquant === null || $defenseur === null || $detruites === null || $prix === null) {
            return null;
        }

        return new self($id, $echeance, $attaquant, $defenseur, $interceptes, $detruites, $prix);
    }

    /**
     * @return array{key: string, owner: int|null, npc: bool}|null
     */
    public static function participantFrom(mixed $participant): array|null
    {
        if (!is_array($participant) || array_diff_key($participant, ['key' => 0, 'owner' => 0, 'npc' => 0]) !== []) {
            return null;
        }

        $clef = $participant['key'] ?? null;
        $proprietaire = $participant['owner'] ?? null;
        $npc = $participant['npc'] ?? null;

        if (!is_string($clef) || !CombatParticipantKey::isWellFormed($clef) || !is_bool($npc)
            || ($proprietaire !== null && (!is_int($proprietaire) || $proprietaire < 1))) {
            return null;
        }

        return ['key' => $clef, 'owner' => $proprietaire, 'npc' => $npc];
    }

    /**
     * Une carte nom → entier, strictement positive sauf pour les prix, qui peuvent valoir zero.
     *
     * @return array<string, int>|null
     */
    public static function countsFrom(mixed $document, bool $zeroAllowed = false): array|null
    {
        if (!is_array($document)) {
            return null;
        }

        $carte = [];

        foreach ($document as $nom => $nombre) {
            if (!is_string($nom) || $nom === '' || !is_int($nombre) || $nombre < ($zeroAllowed ? 0 : 1)) {
                return null;
            }

            $carte[$nom] = $nombre;
        }

        return $carte;
    }
}
