<?php

namespace OGame\Lifeforms\Discovery;

use InvalidArgumentException;

/**
 * Les cotes des artefacts d un vol de decouverte : la chance qu un vol en trouve, et les trois
 * tailles de trouvaille avec leur repartition. Reglage d administration (journal §159), valeurs de
 * depart = le comportement d avant (45 % ; 8 dans 90 %, 25 dans 8 %, 50 dans 2 % — 4,59 par vol).
 *
 * ## Ce qu un changement de chance change, et ce qu il ne change pas
 *
 * Les quatre issues d un vol se partagent cent pour cent. La chance d artefacts ne prend et ne rend
 * qu a « rien » : l experience (22 %) et l espece nouvelle (3 %) gardent exactement leur part, quelle
 * que soit la chance choisie — les changer serait une autre decision, qui n a pas de reglage ici.
 * La chance vaut donc au plus 75 (rien tombe alors a zero), jamais plus.
 *
 * ## Ce qui est refuse
 *
 * Rien de negatif ; une chance au-dela de 75 ; une quantite nulle (une trouvaille « artefacts » qui
 * ne donne rien n est pas une trouvaille) ou au-dela de la reserve de 3 600 ; une petite trouvaille
 * plus grande qu une moyenne, une moyenne plus grande qu une grande ; des parts moyenne + grande
 * au-dela de cent. Une valeur refusee est une faute, jamais ramenee en silence : la porte
 * d administration refuse le formulaire, `SettingsService` refuse un reglage stocke illisible.
 *
 * ## Scelle au lancement
 *
 * Le vol photographie les cotes sous lesquelles il a ete tire (`lifeform_discoveries.odds`) ; un vol
 * ancien sans photographie a ete tire sous les valeurs de depart. Le reglement ne relit jamais les
 * cotes : il credite l issue scellee.
 */
final readonly class LifeformDiscoveryOdds
{
    public const int MAX_ARTIFACT_CHANCE = 100 - LifeformDiscoveryRules::EXPERIENCE_WEIGHT - LifeformDiscoveryRules::SPECIES_WEIGHT;

    public function __construct(
        public int $artifactChance,
        public int $small,
        public int $medium,
        public int $large,
        public int $mediumChance,
        public int $largeChance,
    ) {
        if ($artifactChance < 0 || $artifactChance > self::MAX_ARTIFACT_CHANCE) {
            throw new InvalidArgumentException("La chance d artefacts vaut $artifactChance et non un entier de 0 a " . self::MAX_ARTIFACT_CHANCE . '.');
        }
        foreach (['small' => $small, 'medium' => $medium, 'large' => $large] as $nom => $quantite) {
            if ($quantite < 1 || $quantite > LifeformDiscoveryRules::ARTIFACT_CAP) {
                throw new InvalidArgumentException("La trouvaille $nom vaut $quantite et non un entier de 1 a " . LifeformDiscoveryRules::ARTIFACT_CAP . '.');
            }
        }
        if ($small > $medium || $medium > $large) {
            throw new InvalidArgumentException("Les trouvailles ne sont pas ordonnees ($small, $medium, $large).");
        }
        if ($mediumChance < 0 || $largeChance < 0 || $mediumChance + $largeChance > 100) {
            throw new InvalidArgumentException("Les parts des trouvailles moyenne ($mediumChance) et grande ($largeChance) ne tiennent pas dans cent.");
        }
    }

    /**
     * Les valeurs de depart : exactement le comportement d avant le reglage.
     */
    public static function defaults(): self
    {
        return new self(LifeformDiscoveryRules::WEIGHTS[LifeformDiscoveryOutcome::ARTIFACTS], 8, 25, 50, 8, 2);
    }

    /**
     * La part de « rien » : ce que la chance d artefacts laisse, une fois l experience et l espece servies.
     */
    public function nothingChance(): int
    {
        return 100 - $this->artifactChance - LifeformDiscoveryRules::EXPERIENCE_WEIGHT - LifeformDiscoveryRules::SPECIES_WEIGHT;
    }

    public function smallChance(): int
    {
        return 100 - $this->mediumChance - $this->largeChance;
    }

    /**
     * Les poids des quatre issues, en pour cent, dans l ordre du tirage.
     *
     * @return array<string, int>
     */
    public function weights(): array
    {
        return [
            LifeformDiscoveryOutcome::NOTHING => $this->nothingChance(),
            LifeformDiscoveryOutcome::ARTIFACTS => $this->artifactChance,
            LifeformDiscoveryOutcome::EXPERIENCE => LifeformDiscoveryRules::EXPERIENCE_WEIGHT,
            LifeformDiscoveryOutcome::SPECIES => LifeformDiscoveryRules::SPECIES_WEIGHT,
        ];
    }

    /**
     * Les artefacts d une trouvaille selon un tirage de 0 a 99 : la grande d abord, puis la moyenne, sinon la petite.
     */
    public function artifactsFound(int $roll): int
    {
        if ($roll < 0 || $roll > 99) {
            throw new InvalidArgumentException("Tirage $roll hors de 0 a 99.");
        }
        if ($roll < $this->largeChance) {
            return $this->large;
        }
        if ($roll < $this->largeChance + $this->mediumChance) {
            return $this->medium;
        }

        return $this->small;
    }

    /**
     * L esperance d artefacts par vol, pour la page d administration.
     */
    public function meanPerFlight(): float
    {
        $quantite = ($this->small * $this->smallChance() + $this->medium * $this->mediumChance + $this->large * $this->largeChance) / 100;

        return round($this->artifactChance / 100 * $quantite, 2);
    }

    public function equals(self $other): bool
    {
        return $this->toStorage() === $other->toStorage();
    }

    /**
     * @return array{artifact_chance: int, small: int, medium: int, large: int, medium_chance: int, large_chance: int}
     */
    public function toStorage(): array
    {
        return [
            'artifact_chance' => $this->artifactChance,
            'small' => $this->small,
            'medium' => $this->medium,
            'large' => $this->large,
            'medium_chance' => $this->mediumChance,
            'large_chance' => $this->largeChance,
        ];
    }

    /**
     * Relit une photographie ; un champ absent ou qui n est pas un entier est une faute.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromStorage(array $stored): self
    {
        $valeurs = [];
        foreach (['artifact_chance', 'small', 'medium', 'large', 'medium_chance', 'large_chance'] as $clef) {
            $valeur = $stored[$clef] ?? null;
            if (!is_int($valeur)) {
                throw new InvalidArgumentException("Cotes de decouverte illisibles : $clef.");
            }
            $valeurs[] = $valeur;
        }

        return new self(...$valeurs);
    }
}
