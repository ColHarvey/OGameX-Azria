<?php

namespace OGame\Combat\Replay;

use InvalidArgumentException;
use OGame\GameMissions\BattleEngine\Draws\BattleDraws;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\BattleUnit;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;

/**
 * L etat d un champ de bataille, ecrit puis relu — a l identique.
 *
 * ## Ce que cette piece doit garantir, et rien de moins
 *
 * Une bataille progressive s arrete entre deux rounds. Entre les deux, le processus peut mourir :
 * une reprise doit donc repartir d une **ecriture**, pas d un objet en memoire. Ce codec est la
 * frontiere ou l etat cesse d etre des objets et devient des nombres — et ou il redevient des objets
 * sans que la bataille change d un tir.
 *
 * ## L ordre des unites est porteur, et un regroupement naif le perdrait
 *
 * La conception disait « les unites se regroupent en triplets (type, coque, nombre) ». **C est vrai
 * d un ensemble, faux d une file.** Une cible se tire par sa **position** parmi les unites
 * restantes : deux unites du meme type aux coques differentes ne sont pas interchangeables, et
 * l ordre dans lequel elles se suivent decide de qui est touche.
 *
 * L encodage est donc un **compte de repetitions consecutives** : la liste est parcourue dans son
 * ordre, et chaque suite d unites identiques devient une entree. Une flotte intacte tient toujours
 * en une entree par type ; une flotte entamee se fragmente, et c est le prix exact de l exactitude.
 *
 * ## Ce qui est ecrit avec chaque unite
 *
 * Ses caracteristiques calculees — coque d origine, bouclier, puissance — et **pas** de quoi les
 * recalculer. Une reprise qui les recalculerait depuis le joueur donnerait a une flotte engagee les
 * technologies qu il a apprises **pendant** la bataille. Les caracteristiques sont figees a l entree
 * dans la bataille, et cet encodage est ce qui les fige.
 *
 * ## La bande de tirages doit etre reproductible
 *
 * Un etat sans sa bande ne se reprend pas. `SystemDraws` — le hasard du systeme, celui que le jeu
 * emploie aujourd hui — n a ni etat ni journal : une bataille progressive **ne peut pas** en tirer.
 * Le codec le dit au lieu de l ecrire a moitie. **C est une consequence a porter au dossier** : un
 * combat progressif devra recevoir une graine a son ouverture, et la persister avec lui.
 */
final class BattleFieldStateCodec
{
    /**
     * La version du format. Un etat ecrit sous une autre version ne se relit pas en devinant.
     */
    public const int SCHEMA = 1;

    /**
     * @return array<string, mixed>
     */
    public static function toStorage(BattleFieldState $etat): array
    {
        return [
            'schema' => self::SCHEMA,
            'rounds_played' => $etat->roundsPlayed,
            'attacker_units' => self::runsOf($etat->attackerUnits),
            'defender_units' => self::runsOf($etat->defenderUnits),
            'draws' => self::bandOf($etat->roundDraws, 'the rounds'),
            'battle_draws' => self::bandOf($etat->battleDraws, 'the battle'),
            'attacker_remaining' => self::countsOf($etat->attackerRemainingShips),
            'defender_remaining' => self::countsOf($etat->defenderRemainingShips),
            'attacker_losses' => self::countsOf($etat->attackerLosses),
            'defender_losses' => self::countsOf($etat->defenderLosses),
            'attacker_losses_per_fleet' => self::perFleet($etat->attackerLossesPerFleet),
            'attacker_ships_per_fleet' => self::perFleet($etat->attackerShipsPerFleet),
        ];
    }

    /**
     * @param array<string, mixed> $stocke
     */
    public static function fromStorage(array $stocke): BattleFieldState
    {
        $schema = $stocke['schema'] ?? null;

        if (!is_int($schema) || $schema !== self::SCHEMA) {
            throw new InvalidArgumentException('A battle field state is read at schema ' . self::SCHEMA . ', got ' . var_export($schema, true) . '.');
        }

        return new BattleFieldState(
            self::readUnits($stocke, 'attacker_units'),
            self::readUnits($stocke, 'defender_units'),
            self::readDraws(self::readArray($stocke, 'draws')),
            self::readDraws(self::readArray($stocke, 'battle_draws')),
            self::readInteger($stocke, 'rounds_played'),
            self::readCollection($stocke, 'attacker_remaining'),
            self::readCollection($stocke, 'defender_remaining'),
            self::readCollection($stocke, 'attacker_losses'),
            self::readCollection($stocke, 'defender_losses'),
            self::readPerFleet($stocke, 'attacker_losses_per_fleet'),
            self::readPerFleet($stocke, 'attacker_ships_per_fleet'),
        );
    }

    /**
     * Une bande de tirages, telle qu on peut la reprendre.
     *
     * @return array<string, int>
     */
    private static function bandOf(BattleDraws $bande, string $laquelle): array
    {
        if (!$bande instanceof SeededDraws) {
            throw new InvalidArgumentException(
                'Only a seeded band can be written, and the band of ' . $laquelle . ' is not one: '
                . 'a battle whose draws cannot be replayed cannot be resumed.'
            );
        }

        $graine = $bande->seed();
        $mot = $bande->rawState();

        if ($graine === null || $mot === null) {
            throw new InvalidArgumentException('A dictated raw sequence has no state to write: it is a list, not a generator.');
        }

        $journal = $bande->journal();

        return [
            'seed' => $graine,
            'state' => $mot,
            'count' => $journal->count(),
            'raw_count' => $journal->rawCount(),
            'digest' => $journal->digestAsInteger(),
        ];
    }

    /**
     * Les suites d unites identiques, dans l ordre du champ.
     *
     * @param array<int, BattleUnit> $unites
     * @return array<int, array<string, int>>
     */
    private static function runsOf(array $unites): array
    {
        $suites = [];
        $courante = null;

        foreach ($unites as $unite) {
            $signature = [
                'fleet' => $unite->fleetMissionId,
                'owner' => $unite->ownerId,
                'unit' => $unite->unitObject->id,
                'hull' => $unite->originalHullPlating,
                'current_hull' => $unite->currentHullPlating,
                'shield' => $unite->originalShieldPoints,
                'attack' => $unite->attackPower,
            ];

            if ($courante !== null && array_slice($courante, 0, 7) === $signature) {
                $suites[count($suites) - 1]['count']++;
                $courante['count']++;

                continue;
            }

            $courante = $signature + ['count' => 1];
            $suites[] = $courante;
        }

        return $suites;
    }

    /**
     * @return array<int, BattleUnit>
     */
    private static function readUnits(array $stocke, string $clef): array
    {
        $unites = [];

        foreach (self::readArray($stocke, $clef) as $suite) {
            if (!is_array($suite)) {
                throw new InvalidArgumentException('A run of units is an array, got ' . gettype($suite) . ' in ' . $clef . '.');
            }

            $nombre = self::readInteger($suite, 'count');

            if ($nombre < 1) {
                throw new InvalidArgumentException('A run of units counts at least one, got ' . $nombre . '.');
            }

            $objet = ObjectService::getUnitObjectById(self::readInteger($suite, 'unit'));

            $modele = new BattleUnit(
                $objet,
                // La coque d origine est ecrite telle que le moteur la porte ; le constructeur
                // divise par dix, on lui rend donc son dividende exact.
                self::readInteger($suite, 'hull') * 10,
                self::readInteger($suite, 'shield'),
                self::readInteger($suite, 'attack'),
                self::readInteger($suite, 'fleet'),
                self::readInteger($suite, 'owner'),
            );

            $modele->currentHullPlating = self::readInteger($suite, 'current_hull');

            for ($rang = 0; $rang < $nombre; $rang++) {
                $unites[] = clone $modele;
            }
        }

        return $unites;
    }

    private static function readDraws(array $tirages): SeededDraws
    {
        return SeededDraws::resumedFrom(
            self::readInteger($tirages, 'seed'),
            self::readInteger($tirages, 'state'),
            self::readInteger($tirages, 'count'),
            self::readInteger($tirages, 'raw_count'),
            self::readInteger($tirages, 'digest'),
        );
    }

    /**
     * @return array<int, int>
     */
    private static function countsOf(UnitCollection $unites): array
    {
        $comptes = [];

        foreach ($unites->units as $entree) {
            $comptes[$entree->unitObject->id] = $entree->amount;
        }

        ksort($comptes);

        return $comptes;
    }

    private static function readCollection(array $stocke, string $clef): UnitCollection
    {
        $unites = new UnitCollection();

        foreach (self::readArray($stocke, $clef) as $identifiant => $montant) {
            if (!is_int($identifiant) || !is_int($montant)) {
                throw new InvalidArgumentException('A unit count is a pair of integers, in ' . $clef . '.');
            }

            $unites->addUnit(ObjectService::getUnitObjectById($identifiant), $montant);
        }

        return $unites;
    }

    /**
     * @param array<int, UnitCollection> $parFlotte
     * @return array<int, array<int, int>>
     */
    private static function perFleet(array $parFlotte): array
    {
        $ecrit = [];

        foreach ($parFlotte as $flotte => $unites) {
            $ecrit[$flotte] = self::countsOf($unites);
        }

        ksort($ecrit);

        return $ecrit;
    }

    /**
     * @return array<int, UnitCollection>
     */
    private static function readPerFleet(array $stocke, string $clef): array
    {
        $parFlotte = [];

        foreach (self::readArray($stocke, $clef) as $flotte => $comptes) {
            if (!is_int($flotte) || !is_array($comptes)) {
                throw new InvalidArgumentException('A per-fleet entry is an integer keying an array, in ' . $clef . '.');
            }

            $parFlotte[$flotte] = self::readCollection([$clef => $comptes], $clef);
        }

        return $parFlotte;
    }

    /**
     * **Un entier, jamais une chaine numerique ni un flottant.**
     *
     * Aucun fichier du depot ne declare `strict_types` : une signature `int` accepterait « 42 » et
     * 42.0 en les convertissant, et un cast accepterait n importe quoi. Une relecture de faits
     * persistes est une porte de confiance : elle refuse au lieu de convertir.
     *
     * @param array<string, mixed> $source
     */
    private static function readInteger(array $source, string $clef): int
    {
        $valeur = $source[$clef] ?? null;

        if (!is_int($valeur)) {
            throw new InvalidArgumentException('The field ' . $clef . ' is an integer, got ' . var_export($valeur, true) . '.');
        }

        return $valeur;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<mixed>
     */
    private static function readArray(array $source, string $clef): array
    {
        $valeur = $source[$clef] ?? null;

        if (!is_array($valeur)) {
            throw new InvalidArgumentException('The field ' . $clef . ' is an array, got ' . gettype($valeur) . '.');
        }

        return $valeur;
    }
}
