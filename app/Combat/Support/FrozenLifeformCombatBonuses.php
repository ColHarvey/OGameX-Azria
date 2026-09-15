<?php

namespace OGame\Combat\Support;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Enums\GameObjectType;

/**
 * Ce que les formes de vie apportent a un combattant ou a un corps, **gele** (journal §155.6).
 *
 * - `unitStats` : le bonus en pour cent sur attaque, bouclier et coque, par nom machine de vaisseau
 *   (les « Mk II ») et sous la clef `defence` pour toutes les defenses (Renforcement des boucliers
 *   d obsidienne). Une flotte le porte a son admission ; la garnison, a l ouverture.
 * - `protectedShare` : la part de la population du corps que le Bouclier planetaire protege quand une
 *   attaque reussit ; **null quand le corps n a pas de forme de vie** (lune, compte sans espece) — absent
 *   plutot que zero, qui ferait mourir une population qui n existe pas.
 * - `moonChance`, `debrisRecovery`, `wreckRecovery` : les fractions des batiments du corps (Supra-refracteur,
 *   Usine de recyclage avancee, Nano-robots de reparation), zero pour un attaquant.
 *
 * Une flotte ne porte que ses unites ; les faits du corps restent au corps (`withUnitStatsOf()`).
 */
final readonly class FrozenLifeformCombatBonuses
{
    public const string DEFENCE = 'defence';

    /**
     * @param array<string, float> $unitStats
     */
    public function __construct(
        public array $unitStats,
        public float|null $protectedShare,
        public float $moonChance,
        public float $debrisRecovery,
        public float $wreckRecovery,
    ) {
    }

    public static function none(): self
    {
        return new self([], null, 0.0, 0.0, 0.0);
    }

    public function isNone(): bool
    {
        return $this->unitStats === [] && $this->protectedShare === null && $this->moonChance === 0.0 && $this->debrisRecovery === 0.0 && $this->wreckRecovery === 0.0;
    }

    /**
     * Le bonus, en pour cent, sur les caracteristiques de combat de cette unite.
     */
    public function unitStatsPercent(GameObject $object): float
    {
        return match ($object->type) {
            GameObjectType::Ship => $this->unitStats[$object->machine_name] ?? 0.0,
            GameObjectType::Defense => $this->unitStats[self::DEFENCE] ?? 0.0,
            default => 0.0,
        };
    }

    /**
     * Les unites d un autre releve, les faits du corps gardes.
     */
    public function withUnitStatsOf(self $other): self
    {
        return new self($other->unitStats, $this->protectedShare, $this->moonChance, $this->debrisRecovery, $this->wreckRecovery);
    }

    /**
     * Les faits relus, ou un refus : une porte de confiance ne transtype pas.
     *
     * @param array<string, mixed> $facts
     */
    public static function fromFrozenFacts(array $facts): self
    {
        $unites = [];
        foreach (FrozenFact::array($facts, 'unit_stats') as $nom => $pourcent) {
            if (!is_string($nom) || $nom === '') {
                throw new CorruptedFrozenMoonPlan('le fait « unit_stats » porte une clef ' . get_debug_type($nom) . ' et non un nom d unite', $facts);
            }
            $unites[$nom] = self::fraction($facts, 'unit_stats[' . $nom . ']', $pourcent, 10000.0);
        }

        if (!array_key_exists('protected_share', $facts)) {
            throw new CorruptedFrozenMoonPlan('le fait « protected_share » manque', $facts);
        }
        $part = $facts['protected_share'] === null ? null : self::fraction($facts, 'protected_share', $facts['protected_share'], 1.0);

        return new self(
            $unites,
            $part,
            self::fraction($facts, 'moon_chance', $facts['moon_chance'] ?? null, 10.0),
            self::fraction($facts, 'debris_recovery', $facts['debris_recovery'] ?? null, 10.0),
            self::fraction($facts, 'wreck_recovery', $facts['wreck_recovery'] ?? null, 10.0),
        );
    }

    /**
     * @return array{unit_stats: array<string, float>, protected_share: float|null, moon_chance: float, debris_recovery: float, wreck_recovery: float}
     */
    public function toFrozenFacts(): array
    {
        return [
            'unit_stats' => $this->unitStats,
            'protected_share' => $this->protectedShare,
            'moon_chance' => $this->moonChance,
            'debris_recovery' => $this->debrisRecovery,
            'wreck_recovery' => $this->wreckRecovery,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function fraction(array $facts, string $field, mixed $valeur, float $max): float
    {
        if (!is_int($valeur) && !is_float($valeur)) {
            throw new CorruptedFrozenMoonPlan('le fait « ' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un nombre', $facts);
        }
        $nombre = (float)$valeur;
        if (!is_finite($nombre) || $nombre < 0.0 || $nombre > $max) {
            throw new CorruptedFrozenMoonPlan('le fait « ' . $field . ' » vaut ' . $nombre . ' : hors de 0 a ' . $max, $facts);
        }

        return $nombre;
    }
}
