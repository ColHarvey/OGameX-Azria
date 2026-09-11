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

        // **Meme forme ne veut pas dire memes types.** `shapeOf()` reduit toute valeur simple a `?` :
        // il verrait passer un `missileAttackLink` devenu `false` ou un `playerName` devenu tableau.
        // « Tout a faux » ne concerne que les indicateurs de disponibilite ; les autres champs
        // gardent le type que la vue attend d eux.
        $this->assertSame(
            [],
            $this->typeMismatchesBetween($ligneVivante['player'] ?? [], $ligneDetruite['player'] ?? []),
            'A field of the player block changed type between a living body and a destroyed one.'
        );

        $this->assertSame(
            [],
            $this->typeMismatchesBetween($ligneVivante['actions'] ?? [], $ligneDetruite['actions'] ?? []),
            'A field of the actions block changed type between a living body and a destroyed one.'
        );

        // Et les valeurs disent bien « rien a faire ici ».
        $this->assertFalse($ligneDetruite['player']['actions']['message']['available'], 'A destroyed body offers to write to its owner.');
        $this->assertFalse($ligneDetruite['actions']['canEspionage'], 'A destroyed body offers to be spied on.');

        Planet::query()->whereKey($detruite->id)->delete();
    }

    /**
     * Un corps detruit montre ses ruines, pas la planete qui n existe plus.
     *
     * ## Decision de Keven, 9 septembre 2026
     *
     * « Quand la planete est detruite, l image change en celle-la » — une planete eventree au
     * milieu de ses debris, le temps que la purge quotidienne libere la position.
     *
     * ## Pourquoi le nom de classe est le vrai sujet
     *
     * Le serveur n envoie pas une image : il envoie un **nom**, dont deux consommateurs
     * dependent. La liste en fait une classe CSS ; la carte tactique en fait le chemin
     * `/img/planets/medium/<nom>.png`. Un nom qui change d un cote sans l autre laisse une
     * vignette vide, sans erreur nulle part — c est pourquoi l essai suivant verifie que les
     * deux fichiers existent bien sous ce nom.
     */
    public function testADestroyedBodyShowsItsRuins(): void
    {
        $planete = Planet::query()->where('user_id', $this->currentUserId)->firstOrFail();

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);
        $vivante = $this->rowAt($reponse->json('system.galaxyContent'), (int)$planete->planet);

        $this->assertNotSame(
            'destroyed_debris',
            $vivante['planets'][0]['imageInformation'],
            'A living body already shows the ruins, so the destroyed case proves nothing.'
        );

        DB::table('planets')->where('id', $planete->id)->update(['destroyed' => (int)Date::now()->timestamp]);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);
        $detruite = $this->rowAt($reponse->json('system.galaxyContent'), (int)$planete->planet);

        $this->assertSame(
            'destroyed_debris',
            $detruite['planets'][0]['imageInformation'],
            'A destroyed body still shows the planet it used to be.'
        );
    }

    /**
     * Les deux fichiers que ce nom promet existent reellement.
     *
     * Un nom d image est une promesse faite a deux consommateurs qui ne se parlent pas. Aucun
     * d eux ne se plaint d un fichier manquant : la liste peint un fond vide, la carte affiche
     * une image cassee. Cet essai est le seul endroit ou le manque devient visible.
     *
     * Les tailles sont celles mesurees sur l existant, pas choisies : 76x66 comme
     * `pirate_base.png` (rendu en 38x33, source doublee), 48x48 comme les soixante-dix images de
     * `medium/`. Une vignette a la mauvaise taille s affiche quand meme, de travers.
     */
    public function testTheRuinsThumbnailsExistInBothSizes(): void
    {
        $racine = dirname(__DIR__, 2) . '/public/img/planets/';

        $attendus = [
            'destroyed/destroyed_debris.png' => [76, 66],
            'medium/destroyed_debris.png' => [48, 48],
        ];

        foreach ($attendus as $relatif => [$largeur, $hauteur]) {
            $chemin = $racine . $relatif;

            $this->assertFileExists($chemin, 'The galaxy asks for ' . $relatif . ', which does not exist.');

            $mesure = getimagesize($chemin);
            $this->assertIsArray($mesure, $relatif . ' is not a readable image.');
            $this->assertSame(
                [$largeur, $hauteur],
                [$mesure[0], $mesure[1]],
                $relatif . ' does not have the size its consumer draws it at.'
            );
        }

        // Et la regle qui l habille dans la liste existe, sous ce nom exact. Un motif de code —
        // le selecteur avec sa classe — jamais le mot seul, qui apparaitrait aussi dans un
        // commentaire.
        $feuille = file_get_contents(dirname(__DIR__, 2) . '/resources/css/ingame/azria.css');

        $this->assertIsString($feuille);
        $this->assertStringContainsString(
            '.microplanet.destroyed_debris {',
            $feuille,
            'No CSS rule dresses the destroyed body, so its cell stays empty in the list view.'
        );
    }

    /**
     * Une position libre garde les debris de ce qui s y trouvait.
     *
     * ## Le trou que cet essai ferme
     *
     * Un champ de debris vit sur les **coordonnees**, pas sur la planete : quand la purge
     * quotidienne efface un corps detruit, les debris restent. Mais le serveur ne les envoyait
     * que sur une ligne portant une planete — une position libre partait avec `planets: []`. Les
     * debris disparaissaient donc de la vue a l instant precis ou la position devenait libre,
     * c est-a-dire au moment ou ils devenaient interessants.
     *
     * Le rendu, lui, savait deja faire : une ligne qui ne porte qu un champ marque la position
     * libre et dessine les debris.
     */
    public function testAFreePositionStillShowsTheDebrisLeftBehind(): void
    {
        $planete = Planet::query()->where('user_id', $this->currentUserId)->firstOrFail();
        $position = $this->aFreePositionIn((int)$planete->galaxy, (int)$planete->system);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);
        $avant = $this->rowAt($reponse->json('system.galaxyContent'), $position);

        $this->assertSame([], $avant['planets'], 'The free position already carried something.');

        DB::table('debris_fields')->insert([
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
            'planet' => $position,
            'metal' => 1500,
            'crystal' => 700,
            'deuterium' => 0,
        ]);

        $reponse = $this->post('/ajax/galaxy', [
            'galaxy' => $planete->galaxy,
            'system' => $planete->system,
        ]);
        $apres = $this->rowAt($reponse->json('system.galaxyContent'), $position);

        $this->assertCount(1, $apres['planets'], 'The debris field above a free position is not sent.');
        $this->assertSame(2, $apres['planets'][0]['planetType'], 'The row carries something that is not a debris field.');
        $this->assertSame(
            'empty_filter',
            $apres['positionFilters'],
            'A position holding only debris stopped being reported as free, so it would no longer be colonisable.'
        );

        DB::table('debris_fields')
            ->where('galaxy', $planete->galaxy)
            ->where('system', $planete->system)
            ->where('planet', $position)
            ->delete();
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
    /**
     * Les champs dont le type a change entre une ligne vivante et une ligne detruite.
     *
     * ## Pourquoi `null` ne compte pas pour un ecart
     *
     * Une ligne vivante porte deja `null` sur plusieurs champs — `allianceId` sans union,
     * `highscore.rank` sans classement, `idleTime` au-dela d une heure d inactivite. « Pas de
     * valeur » est donc une valeur legitime des deux cotes, et l exiger identique ferait rougir ce
     * temoin sur un joueur sans union plutot que sur une degradation.
     *
     * Ce qui reste refuse : un booleen la ou la vue attend une chaine, un tableau la ou elle attend
     * un nombre, une chaine la ou elle attend un bloc. C est exactement ce que `shapeOf()` ne voit
     * pas, puisqu il reduit toute valeur simple au meme jeton.
     *
     * @return array<int, string> Les chemins en faute, avec les deux types, vides si tout concorde.
     */
    private function typeMismatchesBetween(mixed $vivante, mixed $detruite, string $chemin = ''): array
    {
        if ($vivante === null || $detruite === null) {
            return [];
        }

        if (is_array($vivante) && is_array($detruite)) {
            $ecarts = [];

            foreach ($vivante as $clef => $sous) {
                if (!array_key_exists($clef, $detruite)) {
                    continue; // L absence est le sujet de shapeOf(), pas celui-ci.
                }

                $ecarts = array_merge(
                    $ecarts,
                    $this->typeMismatchesBetween($sous, $detruite[$clef], $chemin === '' ? (string)$clef : $chemin . '.' . $clef)
                );
            }

            return $ecarts;
        }

        if (gettype($vivante) === gettype($detruite)) {
            return [];
        }

        return [$chemin . ' : ' . gettype($vivante) . ' vivant, ' . gettype($detruite) . ' detruit'];
    }

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
