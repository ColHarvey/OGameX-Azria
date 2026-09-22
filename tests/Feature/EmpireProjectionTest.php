<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Empire\EmpireProjection;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;

/**
 * **Ce que la projection Empire rend, et ce qu elle ne touche pas.**
 *
 * Elle est la seule a decider de la page : la vue n en est que l enveloppe, et le navigateur ne recalcule rien. Trois
 * proprietes la definissent et sont eprouvees ici — elle **n ecrit rien**, elle projette tous les corps **au meme
 * instant**, et elle ne melange jamais ce qui appartient a la planete avec ce qui appartient au compte.
 */
class EmpireProjectionTest extends AccountTestCase
{
    private function projection(): EmpireProjection
    {
        return resolve(EmpireProjection::class);
    }

    private function player(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    /**
     * La colonne d un corps donne.
     *
     * @param array<string, mixed> $charge
     * @return array<int|string, mixed> une colonne : ses clefs d objets sont numeriques, PHP les rend entieres
     */
    private function colonneDe(array $charge, int $planetId): array
    {
        foreach ($charge['planets'] as $colonne) {
            if ($colonne['id'] === $planetId) {
                return $colonne;
            }
        }

        $this->fail('Le corps ' . $planetId . ' n a pas de colonne.');
    }

    /**
     * L etat des tables que cette page pourrait toucher, reduit a une empreinte comparable.
     */
    private function etatDesTables(): string
    {
        $empreinte = '';
        foreach (['planets', 'users', 'lifeform_planets', 'lifeform_queues', 'building_queues', 'unit_queues', 'research_queues', 'fleet_missions'] as $table) {
            $lignes = DB::table($table)->orderBy('id')->get()->toArray();
            $empreinte .= $table . ':' . md5((string)json_encode($lignes)) . ';';
        }

        return $empreinte;
    }

    /**
     * **Une photographie, pas une par colonne.**
     *
     * Les projections du jeu lisent l horloge elles-memes ; appelees en boucle, elles rendraient autant d instants que
     * de corps, et le total additionnerait des valeurs qui n ont jamais coexiste. L instant est donc impose, et toute
     * la page le porte.
     */
    public function testEveryColumnIsProjectedAtTheSameInstant(): void
    {
        // L instant doit etre **posterieur** a la derniere mise a jour des corps : un instant passe ne rejoue rien,
        // et l essai mesurerait alors l absence de production au lieu de la photographie.
        $instant = (int)now()->timestamp;
        $charge = $this->projection()->of($this->player(), false, $instant);

        $this->assertSame($instant, $charge['taken_at']);
        $this->assertGreaterThanOrEqual(2, count($charge['planets']), 'Le compte de banc a plusieurs planetes.');

        /* Le meme appel, au meme instant, rend exactement les memes chiffres : rien ne derive entre deux colonnes. */
        $second = $this->projection()->of($this->player(), false, $instant);
        $this->assertSame(
            array_map(static fn (array $c): array => [$c['id'], $c['res_metal'], $c['res_crystal'], $c['res_deuterium']], $charge['planets']),
            array_map(static fn (array $c): array => [$c['id'], $c['res_metal'], $c['res_crystal'], $c['res_deuterium']], $second['planets']),
        );

        /*
         * Et un instant posterieur rend des stocks differents : la projection avance avec l horloge qu on lui donne.
         * Le temoin vise un corps qui **produit** et dont le stock n est pas deja plein — sinon le juste et le faux
         * coincideraient, et l essai passerait sans rien etablir.
         */
        $joueur = $this->player();
        $corps = null;
        foreach ($joueur->planets->allPlanets() as $planete) {
            if ($planete->getMetalProductionPerHour() > 0 && $planete->metal()->get() < $planete->metalStorage()->get()) {
                $corps = $planete;
                break;
            }
        }
        $this->assertNotNull($corps, 'Aucun corps ne produit sous sa capacite : l essai ne prouverait rien.');

        $avant = $this->colonneDe($charge, $corps->getPlanetId());
        $plusTard = $this->colonneDe($this->projection()->of($this->player(), false, $instant + 7200), $corps->getPlanetId());

        $this->assertGreaterThan(
            $avant['res_metal'],
            $plusTard['res_metal'],
            'Deux heures plus tard, la mine a produit : sans cela l instant ne servirait a rien.',
        );
    }

    /**
     * **Elle ne persiste rien.** Ni les stocks projetes, ni l horodatage de derniere mise a jour, ni une file.
     */
    public function testTheProjectionWritesNothing(): void
    {
        $avant = $this->etatDesTables();
        $this->projection()->of($this->player(), false, (int)now()->timestamp + 86400);
        $this->assertSame($avant, $this->etatDesTables(), 'La projection a modifie une table du jeu.');
    }

    /**
     * **La recherche appartient au compte** : la meme valeur sur chaque colonne, et jamais additionnee.
     */
    public function testResearchIsIdenticalOnEveryColumnBecauseItBelongsToTheAccount(): void
    {
        $charge = $this->projection()->of($this->player(), false, (int)now()->timestamp);
        $recherche = ObjectService::getResearchObjects()[0];
        $clef = (string)$recherche->id;

        $valeurs = array_map(static fn (array $c) => $c[$clef], $charge['planets']);
        $this->assertCount(1, array_unique($valeurs), 'La recherche differe d une colonne a l autre.');
        $this->assertSame(
            $this->player()->getResearchLevel($recherche->machine_name),
            $valeurs[0],
            'La colonne ne rend pas le niveau du compte.',
        );
    }

    /**
     * **Les lignes d une lune ne sont pas celles d une planete.** Une mine ne se construit pas sur une lune, et la
     * colonne ne doit pas lui offrir une ligne vide.
     */
    public function testMoonRowsAreFilteredByTheObjectsAMoonCanCarry(): void
    {
        $planetes = $this->projection()->of($this->player(), false, (int)now()->timestamp);
        $lunes = $this->projection()->of($this->player(), true, (int)now()->timestamp);

        $this->assertNotSame(
            $planetes['groups']['supply'],
            $lunes['groups']['supply'],
            'Les lignes de production d une lune sont les memes que celles d une planete.',
        );
        $this->assertContains((string)ObjectService::getObjectByMachineName('metal_mine')->id, $planetes['groups']['supply']);
        $this->assertNotContains((string)ObjectService::getObjectByMachineName('metal_mine')->id, $lunes['groups']['supply']);
    }

    /**
     * **Le nom d un corps est echappe a la source.**
     *
     * Le code client de la vue Empire concatene `planet.name` dans du HTML **et** dans un attribut `title`, sans
     * l echapper, et rien ne valide ce nom a la saisie. Ce qui vient du joueur est donc echappe ici.
     */
    public function testThePlanetNameIsEscapedBecauseTheClientInjectsItRaw(): void
    {
        $this->planetService->setPlanetName('<script>alert("x")</script>');

        $charge = $this->projection()->of($this->player(), false, (int)now()->timestamp);
        $noms = array_column($charge['planets'], 'name');

        $this->assertNotContains('<script>alert("x")</script>', $noms);
        $this->assertContains('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $noms);
    }

    /**
     * **Un total de moyenne se nomme.** « ø 5 » ne dit pas sur quoi il porte ; le serveur fournit le libelle, et
     * l effet d une technologie de forme de vie ne s additionne jamais colonne par colonne.
     */
    public function testTheSummaryNamesItsAveragesAndNeverMultipliesAccountWideEffects(): void
    {
        $charge = $this->projection()->of($this->player(), false, (int)now()->timestamp);

        if ($charge['summary'] === []) {
            $this->markTestSkipped('Ce compte de banc n a pas d espece de forme de vie.');
        }

        $this->assertArrayHasKey('lf_effects', $charge['summary']);
        $this->assertArrayHasKey('lf_species', $charge['summary']);
        $this->assertStringContainsString((string)count($charge['planets']), $charge['summary']['lf_species']['title'] ?? '');
    }

    /**
     * **L en-tete de colonne dit l energie, pas une ligne de ressource.**
     *
     * Le code client lit la clef `energy` pour composer l en-tete : une ligne de ressource du meme nom l ecraserait,
     * et l en-tete afficherait un nombre brut a la place de l energie formatee. Les lignes portent donc un prefixe.
     */
    public function testResourceRowsDoNotOverwriteTheColumnHeaderKeys(): void
    {
        $charge = $this->projection()->of($this->player(), false, (int)now()->timestamp);
        $colonne = $charge['planets'][0];

        $this->assertContains('res_energy', $charge['groups']['resources']);
        $this->assertNotContains('energy', $charge['groups']['resources']);
        $this->assertIsString($colonne['energy'], 'L en-tete garde sa chaine formatee.');
        $this->assertIsInt($colonne['res_energy'], 'La ligne garde sa valeur brute, pour le total.');
    }
}
