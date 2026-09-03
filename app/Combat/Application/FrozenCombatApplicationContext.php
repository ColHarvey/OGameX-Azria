<?php

namespace OGame\Combat\Application;

use OGame\Combat\Exceptions\CorruptedFrozenApplicationContext;
use OGame\Combat\Services\CombatRoster;
use OGame\Enums\CharacterClass;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Les faits d'application, photographies a la cloture et relus tels quels.
 *
 * ## Ce qui est fige, et pourquoi chacun
 *
 * | fait | ce qu'il changerait s'il etait relu vivant |
 * | --- | --- |
 * | classe General d'un attaquant | un champ d'epaves apparait ou disparait |
 * | niveau de chantier spatial | la taille de ce champ |
 * | part ramassee par un Faucheur | ce que la flotte rapporte des debris |
 * | classe des deux camps | ce que le rapport nomme |
 * | deux reglages de champ d'epaves | le seuil au-dessous duquel il n'existe pas |
 *
 * Un combat dure des heures. Chacun de ces faits peut changer entre le moment ou la bataille est
 * calculee et celui ou elle est appliquee : un joueur change de classe, un chantier monte d'un
 * niveau, un administrateur ajuste un seuil. **Aucun ne doit changer l'issue d'une bataille deja
 * engagee** — sans quoi deux rejeux du meme combat ne donneraient pas le meme rapport, et personne
 * ne saurait dire lequel est le bon.
 *
 * ## Ce qui n'est pas ici
 *
 * Les unites, les manches, les debris et le butin potentiel : ils vivent dans le resultat fige. Ce
 * contexte ne porte que ce dont **l'application** depend et que le resultat ne contient pas.
 *
 * ## Une porte de relecture
 *
 * `fromStorage()` refuse tout ce qui n'a pas la forme ecrite : clef inconnue, joueur ou corps dont
 * l'identifiant n'est pas entier, drapeau qui n'est pas booleen. Un fait gele relu autrement
 * qu'ecrit rendrait un rejeu different de l'original.
 */
final readonly class FrozenCombatApplicationContext implements CombatApplicationContext
{
    private const array KEYS = ['schema', 'players', 'space_docks', 'wreck_field', 'npc_narrative'];

    private const array NARRATIVE_KEYS = ['motive', 'variation'];

    private const array PLAYER_KEYS = ['is_general', 'reaper_debris_percentage', 'character_class'];

    private const array WRECK_FIELD_KEYS = ['min_resources_loss', 'min_fleet_percentage'];

    public const int SCHEMA = 1;

    /**
     * @param array<int, array{is_general: bool, reaper_debris_percentage: float, character_class: int|null}> $players
     * @param array<int, int> $spaceDocks Niveau de chantier spatial, par identifiant de corps d'origine.
     */
    private function __construct(
        private array $players,
        private array $spaceDocks,
        private int $minResourcesLoss,
        private int $minFleetPercentage,
        private string|null $npcMotive,
        private int $npcVariation,
    ) {
    }

    /**
     * La photographie prise a la cloture, depuis l'effectif et le monde courant.
     *
     * Tous les joueurs de l'effectif y figurent — les deux camps —, et tous les corps d'origine des
     * flottes attaquantes. Un fait demande plus tard pour quelqu'un d'absent est un refus, pas un
     * repli sur le monde vivant : c'est ce qui rend la photographie complete par construction.
     */
    public static function photograph(CombatRoster $roster, CombatApplicationContext $live, int $narrativeVariations): self
    {
        $joueurs = [];
        $chantiers = [];

        $inscrire = static function (PlayerService $joueur) use (&$joueurs, $live): void {
            $joueurs[$joueur->getId()] = [
                'is_general' => $live->isGeneral($joueur),
                'reaper_debris_percentage' => $live->reaperDebrisCollectionPercentage($joueur),
                'character_class' => $live->characterClassOf($joueur)?->value,
            ];
        };

        foreach ($roster->attackers as $flotte) {
            $inscrire($flotte->player);
        }

        foreach ($roster->defenders as $flotte) {
            $inscrire($flotte->player);
        }

        $inscrire($roster->targetOwner);
        $inscrire($roster->initiatorOwner);

        $chantiers[$roster->target->getPlanetId()] = $live->spaceDockLevelFor($roster->target);

        foreach ($roster->originBodies as $corps) {
            $chantiers[$corps->getPlanetId()] = $live->spaceDockLevelFor($corps);
        }

        return new self(
            $joueurs,
            $chantiers,
            $live->wreckFieldMinResourcesLoss(),
            $live->wreckFieldMinFleetPercentage(),
            // **Le recit se fige ici aussi.** Le motif lu a l'echeance expliquerait un raid par une
            // provocation survenue pendant la bataille ; la variante tiree a l'echeance donnerait
            // une histoire differente a chaque rejeu. Ils sont photographies meme quand l'attaquant
            // n'est pas une faction : le rapport ne les lira simplement pas.
            $live->npcMotiveAgainst($roster->targetOwner),
            $live->npcNarrativeVariation($narrativeVariations)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'schema' => self::SCHEMA,
            'players' => $this->players,
            'space_docks' => $this->spaceDocks,
            'wreck_field' => [
                'min_resources_loss' => $this->minResourcesLoss,
                'min_fleet_percentage' => $this->minFleetPercentage,
            ],
            'npc_narrative' => [
                'motive' => $this->npcMotive,
                'variation' => $this->npcVariation,
            ],
        ];
    }

    public static function fromStorage(mixed $stored): self
    {
        if (!is_array($stored)) {
            throw new CorruptedFrozenApplicationContext('le document est un ' . get_debug_type($stored) . ' et non une structure', $stored);
        }

        self::refuseUnknownKeys($stored, self::KEYS, 'contexte');

        $schema = self::int($stored, 'schema', 'contexte');

        if ($schema !== self::SCHEMA) {
            throw new CorruptedFrozenApplicationContext('le schema ' . $schema . ' est inconnu, seul le schema ' . self::SCHEMA . ' se relit', $stored);
        }

        $joueurs = [];
        foreach (self::structure($stored, 'players', 'contexte') as $identifiant => $fait) {
            if (!is_int($identifiant)) {
                throw new CorruptedFrozenApplicationContext('« players » porte un joueur dont l identifiant est un ' . get_debug_type($identifiant), $stored);
            }

            $chemin = 'contexte.players[' . $identifiant . ']';

            if (!is_array($fait)) {
                throw new CorruptedFrozenApplicationContext('« ' . $chemin . ' » est un ' . get_debug_type($fait) . ' et non une structure', $stored);
            }

            self::refuseUnknownKeys($fait, self::PLAYER_KEYS, $chemin);

            $classe = self::present($fait, 'character_class', $chemin);

            if ($classe !== null && !is_int($classe)) {
                throw new CorruptedFrozenApplicationContext('« ' . $chemin . '.character_class » est un ' . get_debug_type($classe) . ' et non un entier ni null', $stored);
            }

            $joueurs[$identifiant] = [
                'is_general' => self::bool($fait, 'is_general', $chemin),
                'reaper_debris_percentage' => self::number($fait, 'reaper_debris_percentage', $chemin),
                'character_class' => $classe,
            ];
        }

        $chantiers = [];
        foreach (self::structure($stored, 'space_docks', 'contexte') as $corps => $niveau) {
            if (!is_int($corps)) {
                throw new CorruptedFrozenApplicationContext('« space_docks » porte un corps dont l identifiant est un ' . get_debug_type($corps), $stored);
            }

            if (!is_int($niveau)) {
                throw new CorruptedFrozenApplicationContext('« space_docks[' . $corps . '] » est un ' . get_debug_type($niveau) . ' et non un entier', $stored);
            }

            $chantiers[$corps] = $niveau;
        }

        $epaves = self::structure($stored, 'wreck_field', 'contexte');
        self::refuseUnknownKeys($epaves, self::WRECK_FIELD_KEYS, 'contexte.wreck_field');

        $recit = self::structure($stored, 'npc_narrative', 'contexte');
        self::refuseUnknownKeys($recit, self::NARRATIVE_KEYS, 'contexte.npc_narrative');

        $motif = self::present($recit, 'motive', 'contexte.npc_narrative');

        if ($motif !== null && !is_string($motif)) {
            throw new CorruptedFrozenApplicationContext('« contexte.npc_narrative.motive » est un ' . get_debug_type($motif) . ' et non un texte ni null', $stored);
        }

        return new self(
            $joueurs,
            $chantiers,
            self::int($epaves, 'min_resources_loss', 'contexte.wreck_field'),
            self::int($epaves, 'min_fleet_percentage', 'contexte.wreck_field'),
            $motif,
            self::int($recit, 'variation', 'contexte.npc_narrative')
        );
    }

    public function isGeneral(PlayerService $player): bool
    {
        return $this->factsOf($player->getId())['is_general'];
    }

    public function reaperDebrisCollectionPercentage(PlayerService $player): float
    {
        return $this->factsOf($player->getId())['reaper_debris_percentage'];
    }

    public function characterClassOf(PlayerService $player): CharacterClass|null
    {
        $valeur = $this->factsOf($player->getId())['character_class'];

        return $valeur === null ? null : CharacterClass::tryFrom($valeur);
    }

    public function spaceDockLevelFor(PlanetService $originBody): int
    {
        $corps = $originBody->getPlanetId();

        if (!array_key_exists($corps, $this->spaceDocks)) {
            throw new CorruptedFrozenApplicationContext(
                'le corps ' . $corps . ' n a pas ete photographie : aucun niveau de chantier spatial '
                . 'n a ete fige pour lui, et le lire dans le monde courant ferait dependre le champ '
                . 'd epaves de ce qui a ete construit pendant la bataille',
                array_keys($this->spaceDocks)
            );
        }

        return $this->spaceDocks[$corps];
    }

    public function wreckFieldMinResourcesLoss(): int
    {
        return $this->minResourcesLoss;
    }

    public function wreckFieldMinFleetPercentage(): int
    {
        return $this->minFleetPercentage;
    }

    public function npcMotiveAgainst(PlayerService $defender): string|null
    {
        return $this->npcMotive;
    }

    /**
     * La variante tiree a la cloture.
     *
     * Le nombre de variantes est celui de l'applicateur ; si un deploiement en ajoute pendant qu'une
     * bataille dure, la variante figee reste dans l'ancienne plage — et c'est voulu : le rapport
     * raconte l'histoire qui a ete tiree, pas une autre.
     */
    public function npcNarrativeVariation(int $variations): int
    {
        return $this->npcVariation;
    }

    /**
     * @return array{is_general: bool, reaper_debris_percentage: float, character_class: int|null}
     */
    private function factsOf(int $playerId): array
    {
        if (!array_key_exists($playerId, $this->players)) {
            throw new CorruptedFrozenApplicationContext(
                'le joueur ' . $playerId . ' n a pas ete photographie : il n etait pas dans l effectif '
                . 'du combat, et lire sa classe dans le monde courant ferait dependre l issue de ce '
                . 'qu il est devenu depuis',
                array_keys($this->players)
            );
        }

        return $this->players[$playerId];
    }

    /**
     * @param array<mixed, mixed> $document
     * @param array<int, string> $keys
     */
    private static function refuseUnknownKeys(array $document, array $keys, string $path): void
    {
        $inconnues = array_diff(array_keys($document), $keys);

        if ($inconnues !== []) {
            throw new CorruptedFrozenApplicationContext(
                '« ' . $path . ' » porte des clefs inconnues (' . implode(', ', array_map('strval', $inconnues)) . ')',
                $document
            );
        }
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function present(array $document, string $field, string $path): mixed
    {
        if (!array_key_exists($field, $document)) {
            throw new CorruptedFrozenApplicationContext('le champ « ' . $path . '.' . $field . ' » manque', $document);
        }

        return $document[$field];
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function int(array $document, string $field, string $path): int
    {
        $valeur = self::present($document, $field, $path);

        if (!is_int($valeur)) {
            throw new CorruptedFrozenApplicationContext('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un entier', $document);
        }

        return $valeur;
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function bool(array $document, string $field, string $path): bool
    {
        $valeur = self::present($document, $field, $path);

        if (!is_bool($valeur)) {
            throw new CorruptedFrozenApplicationContext('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un booleen', $document);
        }

        return $valeur;
    }

    /**
     * Une part ecrite `0` revient entiere du decodeur JSON, pas `0.0` : entier ou flottant, jamais une chaine.
     *
     * @param array<mixed, mixed> $document
     */
    private static function number(array $document, string $field, string $path): float
    {
        $valeur = self::present($document, $field, $path);

        if (!is_int($valeur) && !is_float($valeur)) {
            throw new CorruptedFrozenApplicationContext('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non un nombre', $document);
        }

        return (float)$valeur;
    }

    /**
     * @param array<mixed, mixed> $document
     * @return array<mixed, mixed>
     */
    private static function structure(array $document, string $field, string $path): array
    {
        $valeur = self::present($document, $field, $path);

        if (!is_array($valeur)) {
            throw new CorruptedFrozenApplicationContext('le champ « ' . $path . '.' . $field . ' » est un ' . get_debug_type($valeur) . ' et non une structure', $document);
        }

        return $valeur;
    }
}
