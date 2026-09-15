<?php

namespace OGame\GameMissions\BattleEngine\Models;

use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\Combat\Exceptions\CorruptedBattleResult;
use OGame\Combat\Support\CombatParticipantKey;

/**
 * Ce que la manoeuvre de Hamill a fait a cette bataille, enregistre a l instant du retrait de l Etoile.
 *
 * ## Nommee, non nommee, ou aucune
 *
 * - **Aucune** : la manoeuvre n a pas eu lieu.
 * - **Nommee** : l Etoile a ete prise a une flotte connue (la victime, clef de participant — la garnison est
 *   le corps) par la manoeuvre d une flotte connue (l auteur : la flotte dont le Général a été consulté, la
 *   première dans l ordre canonique ; convention Azria, voir `BattleEngine::checkHamillManoeuvre()`), sous une
 *   regle qui retire l Etoile d une flotte.
 * - **Non nommee** : la manoeuvre s est declenchee mais aucune flotte n est identifiable — sous la regle telle
 *   que livree (`v1`, qui ne retire l Etoile d aucune flotte), ou dans un document fige avant que l information
 *   existe (schemas 4 et 5). Lisible pour le reglement, **insuffisante pour les cumuls**, et dite telle : rien
 *   ne devine la victime apres coup.
 *
 * Les deux moteurs l ecrivent au meme point, sans changer ni la cible, ni les tirages, ni le resultat.
 */
final readonly class HamillManoeuvre
{
    private const array KEYS = ['triggered', 'victim', 'author', 'rule'];

    private function __construct(
        public bool $triggered,
        public string|null $victim,
        public string|null $author,
        public string|null $rule,
    ) {
    }

    public static function none(): self
    {
        return new self(false, null, null, null);
    }

    /**
     * Declenchee sans flotte identifiable : la regle ne retire l Etoile d aucune flotte.
     */
    public static function unnamed(HamillManoeuvreRule $rule): self
    {
        return new self(true, null, null, $rule->value);
    }

    public static function named(string $victim, string $author, HamillManoeuvreRule $rule): self
    {
        return new self(true, $victim, $author, $rule->value);
    }

    /**
     * Ce qu un document fige avant le schema 6 permet de dire : le drapeau, rien d autre.
     */
    public static function legacy(bool $triggered): self
    {
        return new self($triggered, null, null, null);
    }

    public function isNamed(): bool
    {
        return $this->triggered && $this->victim !== null && $this->author !== null;
    }

    /**
     * @return array{triggered: bool, victim: string|null, author: string|null, rule: string|null}
     */
    public function toStorage(): array
    {
        return ['triggered' => $this->triggered, 'victim' => $this->victim, 'author' => $this->author, 'rule' => $this->rule];
    }

    /**
     * Une porte de relecture : chaque champ exige sa forme, et une manoeuvre nommee exige sa regle.
     */
    public static function fromStorage(mixed $document, string $path): self
    {
        if (!is_array($document)) {
            throw new CorruptedBattleResult($path . ' est un ' . get_debug_type($document) . ' et non une structure', $document);
        }

        $clefs = array_keys($document);
        sort($clefs);
        $attendues = self::KEYS;
        sort($attendues);

        if ($clefs !== $attendues) {
            throw new CorruptedBattleResult($path . ' ne porte pas exactement les champs ' . implode(', ', self::KEYS), $document);
        }

        $declenchee = $document['triggered'];
        $victime = $document['victim'];
        $auteur = $document['author'];
        $regle = $document['rule'];

        if (!is_bool($declenchee)) {
            throw new CorruptedBattleResult($path . '.triggered n est pas un booleen', $document);
        }

        foreach (['victim' => $victime, 'author' => $auteur] as $champ => $clef) {
            if ($clef !== null && (!is_string($clef) || !CombatParticipantKey::isWellFormed($clef))) {
                throw new CorruptedBattleResult($path . '.' . $champ . ' ne nomme aucun participant', $document);
            }
        }

        if ($regle !== null && (!is_string($regle) || HamillManoeuvreRule::tryFrom($regle) === null)) {
            throw new CorruptedBattleResult($path . '.rule n est pas une regle de manoeuvre connue', $document);
        }

        if (!$declenchee && ($victime !== null || $auteur !== null)) {
            throw new CorruptedBattleResult($path . ' nomme une victime ou un auteur sans manoeuvre', $document);
        }

        if (($victime === null) !== ($auteur === null)) {
            throw new CorruptedBattleResult($path . ' nomme la victime sans l auteur, ou l inverse', $document);
        }

        if ($victime !== null && $regle === null) {
            throw new CorruptedBattleResult($path . ' nomme une manoeuvre sans sa regle', $document);
        }

        return new self($declenchee, $victime, $auteur, $regle);
    }
}
