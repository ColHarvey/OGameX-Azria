<?php

namespace OGame\Lifeforms\Discovery;

use InvalidArgumentException;
use OGame\Lifeforms\Species;

/**
 * L issue scellee d un vol de decouverte : rien, des artefacts, de l experience pour une espece, ou
 * une espece nouvelle (avec son experience de bienvenue).
 */
final readonly class LifeformDiscoveryOutcome
{
    public const string NOTHING = 'nothing';
    public const string ARTIFACTS = 'artifacts';
    public const string EXPERIENCE = 'experience';
    public const string SPECIES = 'species';

    public function __construct(
        public string $kind,
        public Species|null $species,
        public int $artifacts,
        public int $experience,
    ) {
        if (!in_array($kind, [self::NOTHING, self::ARTIFACTS, self::EXPERIENCE, self::SPECIES], true)) {
            throw new InvalidArgumentException("Issue inconnue : $kind.");
        }
        if ($artifacts < 0 || $experience < 0) {
            throw new InvalidArgumentException('Une issue ne retire rien.');
        }
        if (($kind === self::EXPERIENCE || $kind === self::SPECIES) && $species === null) {
            throw new InvalidArgumentException("Une issue $kind nomme une espece.");
        }
    }

    /**
     * @return array{kind: string, species: int|null, artifacts: int, experience: int}
     */
    public function toStorage(): array
    {
        return ['kind' => $this->kind, 'species' => $this->species?->value, 'artifacts' => $this->artifacts, 'experience' => $this->experience];
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromStorage(array $stored): self
    {
        $kind = $stored['kind'] ?? null;
        $species = $stored['species'] ?? null;
        $artifacts = $stored['artifacts'] ?? 0;
        $experience = $stored['experience'] ?? 0;
        if (!is_string($kind) || !is_int($artifacts) || !is_int($experience) || ($species !== null && !is_int($species))) {
            throw new InvalidArgumentException('Issue de decouverte illisible.');
        }

        return new self($kind, $species === null ? null : Species::from($species), $artifacts, $experience);
    }
}
