<?php

namespace OGame\Combat\Application;

use Closure;
use OGame\Combat\Exceptions\CorruptedFrozenApplicationContext;
use OGame\Combat\Services\CombatRoster;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Enums\CharacterClass;
use OGame\Models\Resources;
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
 * | part des vaisseaux detruits qui devient debris | la taille de ce champ, encore |
 * | part ramassee par un Faucheur | ce que la flotte rapporte des debris |
 * | classe des deux camps | ce que le rapport nomme |
 * | deux seuils de champ d'epaves | le seuil au-dessous duquel il n'existe pas |
 * | duree de vie d'un champ d'epaves | quand il disparait |
 * | instant d'application | la date de naissance du champ, donc sa fin |
 * | motif et variante d'un raid de faction | l'histoire que le rapport raconte |
 *
 * Un combat dure des heures. Chacun de ces faits peut changer entre le moment ou la bataille est
 * calculee et celui ou elle est appliquee : un joueur change de classe, un chantier monte d'un
 * niveau, un administrateur ajuste un reglage, un travailleur en retard applique plus tard que
 * l'echeance. **Aucun ne doit changer l'issue d'une bataille deja engagee** — sans quoi deux rejeux
 * du meme combat ne donneraient pas le meme rapport, et personne ne saurait dire lequel est le bon.
 *
 * ## Ce qui reste vivant, et pourquoi
 *
 * Les reglages de reparation d'un champ d'epaves (`wreck_field_repair_max_hours`,
 * `wreck_field_repair_min_minutes`) ne s'appliquent qu'a une action future du joueur, bien apres la
 * bataille : ils n'appartiennent pas a ce combat. La part des defenses qui devient debris
 * (`debris_field_from_defense`) entre dans le calcul de la bataille, donc dans le resultat fige —
 * pas dans l'application.
 *
 * ## Ce qui n'est pas ici
 *
 * Les unites, les manches, les debris et le butin potentiel : ils vivent dans le resultat fige. Ce
 * contexte ne porte que ce dont **l'application** depend et que le resultat ne contient pas.
 *
 * ## Une porte de relecture, et une liaison a l'effectif
 *
 * `fromStorage()` refuse tout ce qui n'a pas la forme ecrite — clef inconnue, identifiant qui n'est
 * pas un entier positif, classe que le jeu ne connait pas, part hors de sa plage, variante hors de
 * la sienne. `assertCovers()` refuse ensuite une photographie qui ne porte pas **exactement** les
 * joueurs et les corps de l'effectif : une de trop est celle d'un autre combat, ou une reparation a
 * la main ; une de moins ferait relire le monde vivant.
 */
final readonly class FrozenCombatApplicationContext implements CombatApplicationContext
{
    private const array KEYS = ['schema', 'applied_at', 'players', 'space_docks', 'held_fleet_cargo', 'return_durations', 'wreck_field', 'npc_narrative'];

    private const array NARRATIVE_KEYS = ['motive', 'variation', 'variations'];

    private const array PLAYER_KEYS = ['is_general', 'reaper_debris_percentage', 'character_class'];

    private const array WRECK_FIELD_KEYS = ['min_resources_loss', 'min_fleet_percentage', 'debris_field_from_ships', 'lifetime_hours'];

    private const array CARGO_KEYS = ['metal', 'crystal', 'deuterium'];

    /**
     * Le schema 2 ajoute l'instant d'application, la part de debris et la duree de vie des epaves,
     * et ne tire une variante narrative que pour un raid de faction. Le schema 3 y ajoute la
     * cargaison des renforts retenus, que l'application relisait vivante. Aucun document des
     * schemas anterieurs n'a jamais ete ecrit hors des essais : ils se refusent, ils ne se
     * convertissent pas.
     */
    public const int SCHEMA = 4;

    /**
     * @param array<int, array{is_general: bool, reaper_debris_percentage: float, character_class: int|null}> $players
     * @param array<int, int> $spaceDocks Niveau de chantier spatial, par identifiant de corps d'origine.
     * @param array<int, array{metal: int, crystal: int, deuterium: int}> $heldFleetCargo La cargaison
     *        de chaque renfort retenu, par identifiant de mission.
     */
    private function __construct(
        private int $appliedAt,
        private array $players,
        private array $spaceDocks,
        private array $heldFleetCargo,
        private array $returnDurations,
        private int $minResourcesLoss,
        private int $minFleetPercentage,
        private int $debrisFieldFromShips,
        private int $lifetimeHours,
        private string|null $npcMotive,
        private int|null $npcVariation,
        private int|null $npcVariations,
    ) {
    }

    /**
     * La photographie prise a la cloture, depuis l'effectif et le monde courant.
     *
     * Tous les joueurs de l'effectif y figurent — les deux camps —, et tous les corps d'origine des
     * flottes attaquantes. Un fait demande plus tard pour quelqu'un d'absent est un refus, pas un
     * repli sur le monde vivant : c'est ce qui rend la photographie complete par construction.
     *
     * @param int $appliedAt L'instant auquel l'application sera datee : l'echeance du combat, fixee
     *                       ici meme. Un travailleur en retard ne date rien a sa propre heure.
     */
    /**
     * @param array<int, int> $returnDurations La duree du retour naturel de chaque flotte attaquante,
     *                                        calculee a la cloture sur les survivants ; zero pour une
     *                                        flotte detruite, qui n'a pas de retour.
     */
    public static function photograph(CombatRoster $roster, CombatApplicationContext $live, int $narrativeVariations, int $appliedAt, array $returnDurations = []): self
    {
        $joueurs = [];

        foreach (self::playersOf($roster) as $identifiant => $joueur) {
            $joueurs[$identifiant] = [
                'is_general' => $live->isGeneral($joueur),
                'reaper_debris_percentage' => $live->reaperDebrisCollectionPercentage($joueur),
                'character_class' => $live->characterClassOf($joueur)?->value,
            ];
        }

        $chantiers = [];

        foreach (self::bodiesOf($roster) as $identifiant => $corps) {
            $chantiers[$identifiant] = $live->spaceDockLevelFor($corps);
        }

        // **La cargaison des renforts retenus entre dans la photographie.** L'application la
        // relisait sur la mission au moment ou elle ecrivait : des heures apres le calcul, ce
        // n'etait plus la valeur sur laquelle la bataille avait ete faite, et deux rejeux du meme
        // combat ne rendaient pas la meme cargaison.
        //
        // Elle passe par la frontiere economique canonique : une colonne fractionnaire arrete la
        // cloture au lieu d'etre gelee de travers, exactement comme pour un retour refuse.
        $cargaisons = [];

        foreach ($roster->defenders as $renfort) {
            $identifiant = (int)$renfort->fleetMissionId;

            if ($identifiant < 1) {
                // La garnison du corps n'a pas de mission, donc pas de cargaison a geler.
                continue;
            }

            $portee = $live->heldFleetCargo($identifiant);
            $cargaisons[$identifiant] = [
                'metal' => self::wholeUnits($portee->metal->get(), 'metal', $identifiant),
                'crystal' => self::wholeUnits($portee->crystal->get(), 'crystal', $identifiant),
                'deuterium' => self::wholeUnits($portee->deuterium->get(), 'deuterium', $identifiant),
            ];
        }

        // **Le recit ne se tire que pour un raid de faction.** Le motif lu a l'echeance
        // expliquerait un raid par une provocation survenue pendant la bataille ; la variante tiree
        // a l'echeance donnerait une histoire differente a chaque rejeu. Pour un combat entre
        // joueurs, le rapport n'a rien a raconter : une absence explicite, pas un hasard inutilise.
        $raid = $roster->initiatorOwner->getUser()->is_npc;

        return new self(
            $appliedAt,
            $joueurs,
            $chantiers,
            $cargaisons,
            self::returnDurationsOf($roster, $returnDurations),
            $live->wreckFieldMinResourcesLoss(),
            $live->wreckFieldMinFleetPercentage(),
            $live->debrisFieldFromShips(),
            $live->wreckFieldLifetimeHours(),
            $raid ? $live->npcMotiveAgainst($roster->targetOwner) : null,
            $raid ? $live->npcNarrativeVariation($narrativeVariations) : null,
            $raid ? $narrativeVariations : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'schema' => self::SCHEMA,
            'applied_at' => $this->appliedAt,
            'players' => $this->players,
            'space_docks' => $this->spaceDocks,
            'held_fleet_cargo' => $this->heldFleetCargo,
            'return_durations' => $this->returnDurations,
            'wreck_field' => [
                'min_resources_loss' => $this->minResourcesLoss,
                'min_fleet_percentage' => $this->minFleetPercentage,
                'debris_field_from_ships' => $this->debrisFieldFromShips,
                'lifetime_hours' => $this->lifetimeHours,
            ],
            'npc_narrative' => [
                'motive' => $this->npcMotive,
                'variation' => $this->npcVariation,
                'variations' => $this->npcVariations,
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

        $instant = self::int($stored, 'applied_at', 'contexte');

        if ($instant < 1) {
            throw new CorruptedFrozenApplicationContext('« contexte.applied_at » vaut ' . $instant . ' : aucune application ne se date avant l epoque', $stored);
        }

        $joueurs = [];
        foreach (self::structure($stored, 'players', 'contexte') as $identifiant => $fait) {
            if (!is_int($identifiant) || $identifiant < 1) {
                throw new CorruptedFrozenApplicationContext('« players » porte un joueur dont l identifiant est ' . self::describe($identifiant) . ' et non un entier positif', $stored);
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

            if ($classe !== null && CharacterClass::tryFrom($classe) === null) {
                throw new CorruptedFrozenApplicationContext('« ' . $chemin . '.character_class » vaut ' . $classe . ', une classe que le jeu ne connait pas', $stored);
            }

            $part = self::number($fait, 'reaper_debris_percentage', $chemin);

            if (!is_finite($part) || $part < 0.0 || $part > 1.0) {
                throw new CorruptedFrozenApplicationContext('« ' . $chemin . '.reaper_debris_percentage » vaut ' . self::describe($part) . ' : une part est un nombre fini entre 0 et 1', $stored);
            }

            $joueurs[$identifiant] = [
                'is_general' => self::bool($fait, 'is_general', $chemin),
                'reaper_debris_percentage' => $part,
                'character_class' => $classe,
            ];
        }

        $chantiers = [];
        foreach (self::structure($stored, 'space_docks', 'contexte') as $corps => $niveau) {
            if (!is_int($corps) || $corps < 1) {
                throw new CorruptedFrozenApplicationContext('« space_docks » porte un corps dont l identifiant est ' . self::describe($corps) . ' et non un entier positif', $stored);
            }

            if (!is_int($niveau) || $niveau < 1) {
                throw new CorruptedFrozenApplicationContext('« space_docks[' . $corps . '] » est ' . self::describe($niveau) . ' et non un niveau entier d au moins 1', $stored);
            }

            $chantiers[$corps] = $niveau;
        }

        $cargaisons = [];
        foreach (self::structure($stored, 'held_fleet_cargo', 'contexte') as $flotte => $portee) {
            if (!is_int($flotte) || $flotte < 1) {
                throw new CorruptedFrozenApplicationContext('« held_fleet_cargo » porte une flotte dont l identifiant est ' . self::describe($flotte) . ' et non un entier positif', $stored);
            }

            $chemin = 'contexte.held_fleet_cargo[' . $flotte . ']';

            if (!is_array($portee)) {
                throw new CorruptedFrozenApplicationContext('« ' . $chemin . ' » est un ' . get_debug_type($portee) . ' et non une structure', $stored);
            }

            self::refuseUnknownKeys($portee, self::CARGO_KEYS, $chemin);

            // Les trois champs nommes un par un : une boucle rendrait une structure dont personne
            // ne peut affirmer qu'elle porte les trois.
            $cargaisons[$flotte] = [
                'metal' => self::cargoField($portee, 'metal', $chemin, $stored),
                'crystal' => self::cargoField($portee, 'crystal', $chemin, $stored),
                'deuterium' => self::cargoField($portee, 'deuterium', $chemin, $stored),
            ];
        }

        $durees = [];
        foreach (self::structure($stored, 'return_durations', 'contexte') as $flotte => $duree) {
            if (!is_int($flotte) || $flotte < 1) {
                throw new CorruptedFrozenApplicationContext('« return_durations » porte une flotte dont l identifiant est ' . self::describe($flotte) . ' et non un entier positif', $stored);
            }
            if (!is_int($duree) || $duree < 0) {
                throw new CorruptedFrozenApplicationContext('« return_durations[' . $flotte . '] » est ' . self::describe($duree) . ' et non une duree entiere en secondes', $stored);
            }
            $durees[$flotte] = $duree;
        }
        $epaves = self::structure($stored, 'wreck_field', 'contexte');
        self::refuseUnknownKeys($epaves, self::WRECK_FIELD_KEYS, 'contexte.wreck_field');

        $perteMinimale = self::int($epaves, 'min_resources_loss', 'contexte.wreck_field');
        $partMinimale = self::int($epaves, 'min_fleet_percentage', 'contexte.wreck_field');
        $partDebris = self::int($epaves, 'debris_field_from_ships', 'contexte.wreck_field');
        $dureeDeVie = self::int($epaves, 'lifetime_hours', 'contexte.wreck_field');

        if ($perteMinimale < 0) {
            throw new CorruptedFrozenApplicationContext('« contexte.wreck_field.min_resources_loss » vaut ' . $perteMinimale . ' : un seuil ne se compte pas en negatif', $stored);
        }

        if ($partMinimale < 0 || $partMinimale > 100) {
            throw new CorruptedFrozenApplicationContext('« contexte.wreck_field.min_fleet_percentage » vaut ' . $partMinimale . ' : un pourcentage tient entre 0 et 100', $stored);
        }

        if ($partDebris < 0 || $partDebris > 100) {
            throw new CorruptedFrozenApplicationContext('« contexte.wreck_field.debris_field_from_ships » vaut ' . $partDebris . ' : un pourcentage tient entre 0 et 100', $stored);
        }

        if ($dureeDeVie < 1) {
            throw new CorruptedFrozenApplicationContext('« contexte.wreck_field.lifetime_hours » vaut ' . $dureeDeVie . ' : un champ d epaves vit au moins une heure', $stored);
        }

        $recit = self::structure($stored, 'npc_narrative', 'contexte');
        self::refuseUnknownKeys($recit, self::NARRATIVE_KEYS, 'contexte.npc_narrative');

        $motif = self::present($recit, 'motive', 'contexte.npc_narrative');

        if ($motif !== null && !is_string($motif)) {
            throw new CorruptedFrozenApplicationContext('« contexte.npc_narrative.motive » est un ' . get_debug_type($motif) . ' et non un texte ni null', $stored);
        }

        $variante = self::present($recit, 'variation', 'contexte.npc_narrative');
        $variantes = self::present($recit, 'variations', 'contexte.npc_narrative');

        if ($variante !== null && !is_int($variante)) {
            throw new CorruptedFrozenApplicationContext('« contexte.npc_narrative.variation » est un ' . get_debug_type($variante) . ' et non un entier ni null', $stored);
        }

        if ($variantes !== null && !is_int($variantes)) {
            throw new CorruptedFrozenApplicationContext('« contexte.npc_narrative.variations » est un ' . get_debug_type($variantes) . ' et non un entier ni null', $stored);
        }

        // **Un raid a une variante et sa plage ; un combat entre joueurs n'a ni l'une ni l'autre.**
        // L'une sans l'autre est une photographie a moitie ecrite.
        if (($variante === null) !== ($variantes === null)) {
            throw new CorruptedFrozenApplicationContext('« contexte.npc_narrative » porte une variation sans sa plage, ou une plage sans variation', $stored);
        }

        if ($variante === null && $motif !== null) {
            throw new CorruptedFrozenApplicationContext('« contexte.npc_narrative » porte un motif sans variation : un recit ne se raconte qu a un raid de faction', $stored);
        }

        if ($variante !== null && $variantes !== null && ($variantes < 1 || $variante < 1 || $variante > $variantes)) {
            throw new CorruptedFrozenApplicationContext('« contexte.npc_narrative.variation » vaut ' . $variante . ' pour une plage de ' . $variantes . ' : la variante tiree tient entre 1 et sa plage', $stored);
        }

        return new self(
            $instant,
            $joueurs,
            $chantiers,
            $cargaisons,
            $durees,
            $perteMinimale,
            $partMinimale,
            $partDebris,
            $dureeDeVie,
            $motif,
            $variante,
            $variantes
        );
    }

    /**
     * La photographie porte-t-elle exactement les joueurs et les corps de cet effectif ?
     *
     * « Chaque fait demande finit par exister » ne suffit pas : une photographie d'un autre combat,
     * ou reparee a la main avec un joueur de trop, y satisferait. L'egalite des deux ensembles est
     * ce qui lie ce contexte a cet effectif — et l'effectif est relu sous verrou, depuis les
     * participants inscrits.
     */
    public function assertCovers(CombatRoster $roster): void
    {
        $joueursAttendus = array_keys(self::playersOf($roster));
        $joueursFiges = array_keys($this->players);
        sort($joueursAttendus);
        sort($joueursFiges);

        if ($joueursFiges !== $joueursAttendus) {
            throw new CorruptedFrozenApplicationContext(
                'la photographie porte les joueurs ' . implode(', ', $joueursFiges)
                . ' alors que l effectif compte les joueurs ' . implode(', ', $joueursAttendus),
                $this->players
            );
        }

        $renfortsAttendus = [];

        foreach ($roster->defenders as $renfort) {
            if ((int)$renfort->fleetMissionId > 0) {
                $renfortsAttendus[] = (int)$renfort->fleetMissionId;
            }
        }

        $renfortsFiges = array_keys($this->heldFleetCargo);
        sort($renfortsAttendus);
        sort($renfortsFiges);

        if ($renfortsFiges !== $renfortsAttendus) {
            throw new CorruptedFrozenApplicationContext(
                'la photographie porte les cargaisons des flottes ' . implode(', ', $renfortsFiges)
                . ' alors que l effectif compte les renforts ' . implode(', ', $renfortsAttendus),
                $this->heldFleetCargo
            );
        }

        $attaquantesAttendues = [];
        foreach ($roster->attackers as $attaquante) {
            if ((int)$attaquante->fleetMissionId > 0) {
                $attaquantesAttendues[] = (int)$attaquante->fleetMissionId;
            }
        }
        $attaquantesFigees = array_keys($this->returnDurations);
        sort($attaquantesAttendues);
        sort($attaquantesFigees);
        if ($attaquantesFigees !== $attaquantesAttendues) {
            throw new CorruptedFrozenApplicationContext(
                'la photographie porte les durees de retour des flottes ' . implode(', ', $attaquantesFigees)
                . ' alors que l effectif compte les attaquantes ' . implode(', ', $attaquantesAttendues),
                $this->returnDurations
            );
        }
        $corpsAttendus = array_keys(self::bodiesOf($roster));
        $corpsFiges = array_keys($this->spaceDocks);
        sort($corpsAttendus);
        sort($corpsFiges);

        if ($corpsFiges !== $corpsAttendus) {
            throw new CorruptedFrozenApplicationContext(
                'la photographie porte les corps ' . implode(', ', $corpsFiges)
                . ' alors que l effectif touche les corps ' . implode(', ', $corpsAttendus),
                $this->spaceDocks
            );
        }
    }

    /**
     * La cargaison figee de ce renfort, ou un refus s'il n'est pas dans la photographie.
     *
     * Un fait demande pour une flotte absente n'est pas un repli sur le monde vivant : ce serait
     * exactement le defaut que cette photographie existe pour fermer.
     */
    public function heldFleetCargo(int $fleetMissionId): Resources
    {
        if (!array_key_exists($fleetMissionId, $this->heldFleetCargo)) {
            throw new CorruptedFrozenApplicationContext(
                'la photographie ne porte pas la cargaison de la flotte ' . $fleetMissionId,
                $this->heldFleetCargo
            );
        }

        $portee = $this->heldFleetCargo[$fleetMissionId];

        return new Resources((float)$portee['metal'], (float)$portee['crystal'], (float)$portee['deuterium'], 0);
    }

    /**
     * Un champ de cargaison relu, entier et jamais negatif.
     *
     * @param array<mixed> $portee
     * @param mixed $stored
     */
    private static function cargoField(array $portee, string $champ, string $chemin, mixed $stored): int
    {
        $valeur = self::int($portee, $champ, $chemin);

        if ($valeur < 0) {
            throw new CorruptedFrozenApplicationContext(
                '« ' . $chemin . '.' . $champ . ' » vaut ' . $valeur . ' : une cargaison ne se compte pas en negatif',
                $stored
            );
        }

        return $valeur;
    }

    /**
     * Une cargaison en unites entieres, par la frontiere economique canonique.
     */
    private static function wholeUnits(float $montant, string $champ, int $fleetMissionId): int
    {
        return ResourceBoundary::wholeUnitsOfCarriedCargo(
            $montant,
            $champ,
            'held_fleet_cargo_photograph',
            'mission ' . $fleetMissionId
        )->units;
    }

    /**
     * @return array<int, int>
     */
    private static function returnDurationsOf(CombatRoster $roster, array $returnDurations): array
    {
        $durees = [];
        foreach ($roster->attackers as $attaquante) {
            $identifiant = (int)$attaquante->fleetMissionId;
            if ($identifiant < 1) {
                continue;
            }
            if (!array_key_exists($identifiant, $returnDurations)) {
                throw new CorruptedFrozenApplicationContext('la duree du retour de la flotte ' . $identifiant . ' n a pas ete calculee a la cloture', $returnDurations);
            }
            $durees[$identifiant] = max(0, (int)$returnDurations[$identifiant]);
        }

        return $durees;
    }

    public function returnDurationOf(int $fleetMissionId, Closure $computeLive): int
    {
        if (!array_key_exists($fleetMissionId, $this->returnDurations)) {
            throw new CorruptedFrozenApplicationContext(
                'la photographie ne porte pas la duree du retour de la flotte ' . $fleetMissionId,
                $this->returnDurations
            );
        }

        return max(1, $this->returnDurations[$fleetMissionId]);
    }

    public function applicationInstant(): int
    {
        return $this->appliedAt;
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

        return $valeur === null ? null : CharacterClass::from($valeur);
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

    public function debrisFieldFromShips(): int
    {
        return $this->debrisFieldFromShips;
    }

    public function wreckFieldLifetimeHours(): int
    {
        return $this->lifetimeHours;
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
     * raconte l'histoire qui a ete tiree, pas une autre. Un combat entre joueurs n'en a aucune, et
     * la demander est un defaut d'integration, pas une occasion d'en tirer une.
     */
    public function npcNarrativeVariation(int $variations): int
    {
        if ($this->npcVariation === null) {
            throw new CorruptedFrozenApplicationContext(
                'aucune variante narrative n a ete tiree : ce combat n etait pas un raid de faction, et le rapport n a rien a raconter',
                ['motive' => $this->npcMotive, 'variations' => $this->npcVariations]
            );
        }

        return $this->npcVariation;
    }

    /**
     * Les joueurs que l'application interroge : les deux camps, le proprietaire de la cible, celui de
     * l'initiatrice. La photographie et la liaison a l'effectif passent par la meme liste.
     *
     * @return array<int, PlayerService>
     */
    private static function playersOf(CombatRoster $roster): array
    {
        $joueurs = [];

        foreach ($roster->attackers as $flotte) {
            $joueurs[$flotte->player->getId()] = $flotte->player;
        }

        foreach ($roster->defenders as $flotte) {
            $joueurs[$flotte->player->getId()] = $flotte->player;
        }

        $joueurs[$roster->targetOwner->getId()] = $roster->targetOwner;
        $joueurs[$roster->initiatorOwner->getId()] = $roster->initiatorOwner;

        return $joueurs;
    }

    /**
     * Les corps dont l'application lit le chantier spatial : la cible et les origines.
     *
     * @return array<int, PlanetService>
     */
    private static function bodiesOf(CombatRoster $roster): array
    {
        $corps = [$roster->target->getPlanetId() => $roster->target];

        foreach ($roster->originBodies as $origine) {
            $corps[$origine->getPlanetId()] = $origine;
        }

        return $corps;
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

    private static function describe(mixed $valeur): string
    {
        return is_scalar($valeur) ? var_export($valeur, true) : 'un ' . get_debug_type($valeur);
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
