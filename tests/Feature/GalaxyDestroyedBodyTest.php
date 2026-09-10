<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use Tests\AccountTestCase;

/**
 * La Galaxie s ouvre sur un systeme qui porte un corps detruit.
 *
 * ## Le defaut signale en jeu
 *
 * Un joueur a detruit un corps en 1:122 ; aller a ces coordonnees donne un **chargement infini**.
 * Un chargement qui ne finit pas veut dire que la reponse n arrive pas, ou qu elle arrive dans une
 * forme que la carte ne sait pas lire — dans les deux cas, le serveur est le premier endroit ou
 * regarder.
 *
 * Ce temoin ouvre le systeme d un corps detruit et exige une reponse complete. Il ne suppose pas la
 * cause : il la fait apparaitre.
 */
class GalaxyDestroyedBodyTest extends AccountTestCase
{
    /**
     * **Le defaut vu en jeu : `activity` valait `null`, et le rendu tombait dessus.**
     *
     * Un code 200 ne prouvait rien — le serveur repondait bien, et c est le navigateur qui
     * s arretait :
     *
     * ```
     * Uncaught TypeError: Cannot read properties of null (reading 'showActivity')
     *     at getActivityStar → renderPlanet → renderContentGalaxy
     * ```
     *
     * Le temoin regarde donc la **forme** de la charge utile, la ou la vue lit. Un corps detruit
     * porte une activite inerte, jamais rien.
     */
    public function testNoBodyEverCarriesANullActivity(): void
    {
        $planete = Planet::query()->where('user_id', $this->currentUserId)->firstOrFail();

        DB::table('planets')->where('id', $planete->id)->update(['destroyed' => (int)Date::now()->timestamp]);

        $lune = Planet::factory()->create([
            'user_id' => $this->currentUserId,
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
            'planet' => $planete->planet,
            'planet_type' => PlanetType::Moon->value,
            'destroyed' => (int)Date::now()->timestamp,
        ]);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);

        $reponse->assertStatus(200);

        $lignes = $reponse->json('system.galaxyContent');
        $this->assertIsArray($lignes);

        $corpsVus = 0;

        foreach ($lignes as $ligne) {
            foreach (($ligne['planets'] ?? []) as $corps) {
                if (!array_key_exists('activity', $corps)) {
                    continue;
                }

                $corpsVus++;

                $this->assertIsArray(
                    $corps['activity'],
                    'A body carries a null activity: the galaxy renderer reads showActivity on it and stops, '
                    . 'and the loading spinner never goes away.'
                );

                $this->assertArrayHasKey('showActivity', $corps['activity'], 'The activity has no showActivity at all.');
            }
        }

        $this->assertGreaterThan(0, $corpsVus, 'No body carried an activity: the witness looked at nothing.');

        Planet::query()->whereKey($lune->id)->delete();
    }

    public function testASystemHoldingADestroyedPlanetStillLoads(): void
    {
        $planete = Planet::query()->where('user_id', $this->currentUserId)->firstOrFail();

        DB::table('planets')->where('id', $planete->id)->update(['destroyed' => (int)Date::now()->timestamp]);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('success', true);

        $lignes = $reponse->json('system.galaxyContent');
        $this->assertIsArray($lignes, 'The galaxy payload carries no rows at all.');
        $this->assertNotSame([], $lignes, 'The system came back empty although it holds a body.');
    }

    /**
     * **Le cas signale : le corps detruit est le SEUL de son proprietaire.**
     *
     * C est la situation d une base pirate : la faction n a que celle-la dans ce systeme. Le depot
     * connait deja ce piege sous une autre forme — `permanentlyDeletePlanet()` demandait
     * `getCurrentPlanetId()`, qui leve « Player has no planets » quand le corps detruit est le
     * dernier du compte.
     */
    public function testASystemHoldingTheLastDestroyedBodyOfItsOwnerStillLoads(): void
    {
        $planete = Planet::query()->where('user_id', $this->currentUserId)->firstOrFail();

        $voisin = $this->createUserWithASingleBody($planete->galaxy, $planete->system, $this->aFreePositionIn((int)$planete->galaxy, (int)$planete->system));

        DB::table('planets')->where('id', $voisin)->update(['destroyed' => (int)Date::now()->timestamp]);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('success', true);
    }

    /**
     * **La forme exacte du cas signale : une base pirate detruite.**
     *
     * Un compte de faction (`is_npc`), une seule base, detruite — et la Galaxie doit s ouvrir. La
     * vignette d une base hostile passe par `galaxyImageFor()`, qui interroge le proprietaire meme
     * pour un corps detruit : c est le seul chemin de cette vue qui distingue un pirate d un joueur.
     */
    public function testASystemHoldingADestroyedPirateBaseStillLoads(): void
    {
        $planete = Planet::query()->where('user_id', $this->currentUserId)->firstOrFail();

        $pirate = \OGame\Models\User::factory()->create(['is_npc' => 1]);

        $base = Planet::factory()->create([
            'user_id' => $pirate->id,
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
            'planet' => $this->aFreePositionIn((int)$planete->galaxy, (int)$planete->system),
            'planet_type' => PlanetType::Planet->value,
            'destroyed' => (int)Date::now()->timestamp,
        ]);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('success', true);

        Planet::query()->whereKey($base->id)->delete();
    }

    /**
     * Une position libre de ce systeme — **choisie, pas supposee**.
     *
     * La base d un processus garde les corps des essais precedents, et la repartition des classes
     * entre processus change des qu on en ajoute une. Une position ecrite en dur finit donc par se
     * heurter a l unicite `(galaxie, systeme, position, type)`, et l essai rougit pour une raison
     * qui n est pas la sienne — c est arrive le 10 septembre 2026, sur la position 12.
     */
    private function aFreePositionIn(int $galaxie, int $systeme): int
    {
        $prises = Planet::query()
            ->where('galaxy', $galaxie)
            ->where('system', $systeme)
            ->where('planet_type', PlanetType::Planet->value)
            ->pluck('planet')
            ->map(static fn (mixed $p): int => (int)$p)
            ->all();

        for ($position = 4; $position <= 12; $position++) {
            if (!in_array($position, $prises, true)) {
                return $position;
            }
        }

        $this->fail('No free position was left in ' . $galaxie . ':' . $systeme . ' for this witness.');
    }

    /**
     * Un compte voisin qui ne possede qu un seul corps, a cette position.
     */
    private function createUserWithASingleBody(int $galaxie, int $systeme, int $position): int
    {
        $utilisateur = \OGame\Models\User::factory()->create();

        $corps = Planet::factory()->create([
            'user_id' => $utilisateur->id,
            'galaxy' => $galaxie,
            'system' => $systeme,
            'planet' => $position,
            'planet_type' => PlanetType::Planet->value,
        ]);

        $this->assertSame(1, Planet::query()->where('user_id', $utilisateur->id)->count(), 'The neighbour has more than one body: the case would not be the one reported.');

        return (int)$corps->id;
    }

    /**
     * La lune detruite d une planete vivante : l autre moitie du cas.
     */
    public function testASystemHoldingADestroyedMoonStillLoads(): void
    {
        $planete = Planet::query()->where('user_id', $this->currentUserId)->firstOrFail();

        $lune = Planet::factory()->create([
            'user_id' => $this->currentUserId,
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
            'planet' => $planete->planet,
            'planet_type' => PlanetType::Moon->value,
            'destroyed' => (int)Date::now()->timestamp,
        ]);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('success', true);

        Planet::query()->whereKey($lune->id)->delete();
    }
}
