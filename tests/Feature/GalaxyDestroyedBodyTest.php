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

    /**
     * **La ligne d un corps detruit porte les memes clefs qu une ligne vivante.**
     *
     * ## Pourquoi un temoin de famille, et pas une assertion de plus
     *
     * Le meme defaut s est produit deux fois en une soiree, sur deux champs differents :
     * `activity` valait `null`, puis `player` etait reduit a deux clefs. A chaque fois, la vue
     * heritee lisait une forme qu elle croyait constante :
     *
     * ```
     * at getActivityStar  → Cannot read properties of null (reading 'showActivity')
     * at getActions       → Cannot read properties of undefined (reading 'message')
     * ```
     *
     * Et a chaque fois, la suivante attendait derriere — `actions.buddies.available` apres
     * `actions.message`. Verifier champ par champ, c est courir apres. Ce temoin compare donc les
     * **structures** : tout ce qu une ligne vivante porte, une ligne detruite le porte aussi, jusque
     * dans les sous-blocs. Les valeurs, elles, ont le droit d etre inertes.
     *
     * ## Pourquoi une position vide ne suffisait pas a le voir
     *
     * Elle porte le meme `player` reduit — et elle passe, parce que sans planete dans la ligne la vue
     * n appelle jamais `getActions()`. Seul un corps **detruit** declenche la lecture. Le temoin
     * compare donc bien deux lignes **avec un corps**.
     */
    public function testADestroyedRowCarriesTheSameShapeAsALivingOne(): void
    {
        $vivante = Planet::query()->where('user_id', $this->currentUserId)->firstOrFail();

        $voisin = \OGame\Models\User::factory()->create();
        $detruite = Planet::factory()->create([
            'user_id' => $voisin->id,
            'galaxy' => $vivante->galaxy,
            'system' => $vivante->system,
            'planet' => $this->aFreePositionIn((int)$vivante->galaxy, (int)$vivante->system),
            'planet_type' => PlanetType::Planet->value,
            'destroyed' => (int)Date::now()->timestamp,
        ]);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $vivante->galaxy,
            'system' => $vivante->system,
        ]);

        $reponse->assertStatus(200);

        $lignes = $reponse->json('system.galaxyContent');
        $this->assertIsArray($lignes);

        $ligneVivante = $this->rowAt($lignes, (int)$vivante->planet);
        $ligneDetruite = $this->rowAt($lignes, (int)$detruite->planet);

        $this->assertSame(
            $this->shapeOf($ligneVivante['player'] ?? []),
            $this->shapeOf($ligneDetruite['player'] ?? []),
            'The player block of a destroyed body has not the same shape as a living one: the galaxy '
            . 'renderer destructures it and stops on the first missing key.'
        );

        $this->assertSame(
            $this->shapeOf($ligneVivante['actions'] ?? []),
            $this->shapeOf($ligneDetruite['actions'] ?? []),
            'The actions of a destroyed body have not the same shape as a living one.'
        );

        // Et les valeurs disent bien « rien a faire ici ».
        $this->assertFalse($ligneDetruite['player']['actions']['message']['available'], 'A destroyed body offers to write to its owner.');
        $this->assertFalse($ligneDetruite['actions']['canEspionage'], 'A destroyed body offers to be spied on.');

        Planet::query()->whereKey($detruite->id)->delete();
    }

    /**
     * Une position libre de ce systeme — **choisie, pas supposee**.
     *
     * La base d un processus garde les corps des essais precedents : une position ecrite en dur
     * finit par se heurter a l unicite, et l essai rougit pour une raison qui n est pas la sienne.
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

        $this->fail('No free position in the system: the fixture cannot be built.');
    }

    /**
     * La ligne de cette position.
     *
     * @param array<int, mixed> $lignes
     * @return array<string, mixed>
     */
    private function rowAt(array $lignes, int $position): array
    {
        foreach ($lignes as $ligne) {
            if ((int)($ligne['position'] ?? 0) === $position) {
                return $ligne;
            }
        }

        $this->fail('No row at position ' . $position . ': the fixture is not the one this witness needs.');
    }

    /**
     * Les clefs d une structure, en profondeur et triees — jamais les valeurs.
     *
     * @param mixed $valeur
     * @return mixed
     */
    private function shapeOf(mixed $valeur): mixed
    {
        if (!is_array($valeur)) {
            return '?';
        }

        $forme = [];

        foreach ($valeur as $clef => $sous) {
            $forme[$clef] = $this->shapeOf($sous);
        }

        ksort($forme);

        return $forme;
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

        $voisin = $this->createUserWithASingleBody((int)$planete->galaxy, (int)$planete->system, $this->aFreePositionIn((int)$planete->galaxy, (int)$planete->system));

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
