<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\InitialUserDataService;
use OGame\Services\SettingsService;
use Tests\TestCase;

/**
 * Deux planetes creees a l'inscription ne se touchent jamais.
 *
 * Constat en jeu, puis mesure : sur douze inscriptions successives, trois paires de planetes se
 * retrouvaient sur des cases voisines du meme systeme — 10 et 11, 8 et 9, 5 et 6. Le tirage
 * melangeait les positions 4 a 12 et retenait la premiere case libre venue, sans jamais regarder
 * ses voisines.
 *
 * Une case vide est desormais laissee entre deux planetes. Ces tests passent par le chemin reel
 * de l'inscription, celui qui produisait le defaut.
 */
class PlanetSpacingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ecart minimum attendu entre deux planetes d'un meme systeme.
     *
     * Deux cases d'ecart, c'est-a-dire au moins une case vide entre elles.
     */
    private const int MINIMUM_GAP = 2;

    /**
     * Aucune planete ne se pose sur la case voisine d'une autre.
     */
    /**
     * Le systeme que ces essais emploient est vide avant qu'ils le remplissent.
     *
     * ## Le defaut ferme
     *
     * Les trois essais posent leurs planetes a des coordonnees **ecrites en dur** dans le systeme
     * 1:1, en supposant qu'il ne contient qu'Arakis. La base est partagee entre les classes d'un
     * meme processus, et l'ordre de repartition change des qu'on ajoute un fichier d'essais : en
     * integration continue, une planete occupait deja 1:1:8, et l'insertion violait la contrainte
     * d'unicite des coordonnees.
     *
     * **Un essai etablit ce qu'il exige.** Le systeme est donc vide ici, plutot que suppose vide.
     * `RefreshDatabase` annule ce nettoyage a la fin du test, comme le reste.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // **Arakis reste, le reste part.** La planete du compte systeme est posee par une
        // migration et referencee ailleurs : la supprimer bute sur une clef etrangere — deux
        // premiers jets s'y sont casses. Les essais la connaissent deja et comparent avant/apres.
        //
        // Ce qui doit partir, ce sont les planetes laissees par les classes voisines du meme
        // processus : leurs coordonnees sont ecrites en dur ici, et une seule collision fait
        // echouer l'insertion.
        //
        // **Le nettoyage prend la portee de ce qui le bloque.** Ce commentaire affirmait que
        // `planet_moves` etait « la seule table qui les reference » : c'etait faux, et le banc l'a
        // dit en rougissant sur `users.planet_current`, qu'un voisin laissait pointer vers une lune
        // de 1:1. Inventaire des clefs etrangeres vers `planets` : huit references en `NO ACTION`
        // bloquent une suppression — `users.planet_current`, `messages.action_planet_id`, les trois
        // files (batiments, recherche, unites), `planet_moves`, et les deux bouts de
        // `fleet_missions`. Deux autres se gerent seules : `patrols.home_planet_id` (SET NULL) et
        // `surveillance_contacts.observer_planet_id` (CASCADE).
        //
        // Les liens sont **denoues** la ou la ligne garde un sens sans sa planete — un message reste
        // lisible, une mission garde ses coordonnees, c'est ce que fait le jeu lui-meme quand il
        // supprime un corps — et **effaces** la ou elle n'en a plus : une file est le travail d'une
        // planete, et rien d'autre.
        $systeme = User::where('username', User::SYSTEM_ACCOUNT_USERNAME)->value('id');

        $etrangeres = Planet::where('galaxy', 1)
            ->where('system', 1)
            ->when($systeme !== null, fn ($requete) => $requete->where('user_id', '!=', $systeme))
            ->pluck('id');

        DB::table('users')->whereIn('planet_current', $etrangeres)->update(['planet_current' => null]);
        DB::table('messages')->whereIn('action_planet_id', $etrangeres)->update(['action_planet_id' => null]);
        DB::table('fleet_missions')->whereIn('planet_id_from', $etrangeres)->update(['planet_id_from' => null]);
        DB::table('fleet_missions')->whereIn('planet_id_to', $etrangeres)->update(['planet_id_to' => null]);

        foreach (['planet_moves', 'building_queues', 'research_queues', 'unit_queues'] as $file) {
            DB::table($file)->whereIn('planet_id', $etrangeres)->delete();
        }

        Planet::whereIn('id', $etrangeres)->delete();
    }

    public function testAucunePlaneteNeSePoseSurLaCaseVoisineDUneAutre(): void
    {
        /*
         * **Ce que l'inscription a pose, et rien d'autre.**
         *
         * Ce temoin lisait toutes les planetes de la base et exigeait l'ecart partout. Les classes
         * voisines du meme processus posent les leurs a des coordonnees ecrites en dur, hors du
         * systeme 1:1 que le montage nettoie : deux d'entre elles collees suffisaient a le faire
         * rougir pour un placement dont l'inscription n'est pas l'auteur — ce qui est arrive en
         * integration continue, sur un commit qui ne touchait ni l'inscription ni la Galaxie.
         *
         * La regle eprouvee est celle du **placement** : une planete que l'inscription pose n'est
         * jamais voisine d'une autre. Une paire dont aucun des deux membres n'est neuf ne dit donc
         * rien de cette regle, et n'est pas comptee. Une paire dont l'un des deux est neuf l'est —
         * le temoin garde toute sa force contre le defaut qu'il a ferme.
         */
        /*
         * **La portee du temoin, rendue observable.** Deux planetes collees que l'inscription n'a
         * pas posees — comme celles qu'une classe voisine laisse — ne doivent rien lui faire dire.
         * Arakis, la planete du compte systeme, occupe 1:1:2 ; une planete posee a la main en 1:1:1
         * lui est donc voisine. Les deux cases sont hors des positions habitables (4 a 12), donc
         * aucune inscription ne peut venir s'y coller et ce montage ne masque rien de la regle
         * eprouvee. Si quelqu'un rend un jour au temoin sa portee d'origine, cette paire le fera
         * rougir tout de suite.
         */
        $voisin = User::factory()->create();

        Planet::factory()->create([
            'user_id' => $voisin->id,
            'galaxy' => 1,
            'system' => 1,
            'planet' => 1,
        ]);

        $this->assertSame(
            [1, 2],
            array_column($this->positionsBySystem()['1:1'], 'position'),
            'The scenario needs exactly one adjacent pair outside the habitable range, and it does not have it.'
        );

        $avant = Planet::query()->pluck('id')->map(static fn (mixed $id): int => (int)$id)->all();

        $this->registerAccounts(15);

        $neuves = array_flip(array_values(array_diff(
            Planet::query()->pluck('id')->map(static fn (mixed $id): int => (int)$id)->all(),
            $avant
        )));

        $this->assertNotSame([], $neuves, 'The registration created no planet: the witness would prove nothing.');

        foreach ($this->positionsBySystem() as $systeme => $positions) {
            for ($index = 1; $index < count($positions); $index++) {
                $paire = [$positions[$index - 1], $positions[$index]];

                if (!isset($neuves[$paire[0]['id']]) && !isset($neuves[$paire[1]['id']])) {
                    continue;
                }

                $this->assertGreaterThanOrEqual(
                    self::MINIMUM_GAP,
                    $paire[1]['position'] - $paire[0]['position'],
                    'Deux planetes se touchent en ' . $systeme . ' : cases ' . $paire[0]['position'] . ' et ' . $paire[1]['position'] . '.'
                );
            }
        }
    }

    /**
     * L'espacement ne prive personne de planete.
     *
     * Le garde-fou du garde-fou : une regle d'espacement trop stricte se traduirait par des
     * inscriptions sans monde, ou par une exception. Les quinze comptes doivent avoir chacun le
     * leur, aux positions habitables.
     */
    public function testChaqueCompteRecoitBienSaPlanete(): void
    {
        $this->registerAccounts(15);

        $planetes = Planet::query()
            ->whereIn('user_id', User::query()->pluck('id'))
            ->where('planet_type', 1)
            ->get();

        $this->assertGreaterThanOrEqual(15, $planetes->count(), 'Des comptes sont restes sans planete.');

        foreach ($planetes as $planete) {
            $this->assertGreaterThanOrEqual(1, (int)$planete->planet);
            $this->assertLessThanOrEqual(15, (int)$planete->planet);
        }
    }

    /**
     * Un systeme dont toutes les cases libres sont collees est passe, pas garni.
     *
     * **Verification deterministe, et elle demande une precaution.** Au palier de densite 1, un
     * systeme est ecarte des qu'il porte deux ou trois planetes : le palier masquerait la regle
     * d'espacement et le test ne prouverait rien. Le palier maximal est donc force, pour que
     * seule la regle d'espacement puisse decider.
     *
     * Les cases 4, 6, 8, 10 et 12 sont prises. Il reste 5, 7, 9 et 11 — toutes voisines d'une
     * planete. Le systeme doit etre passe entierement.
     */
    public function testUnSystemeSansCaseEspaceeEstPasse(): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'planet_density_tier'], ['value' => '3']);
        DB::table('settings')->updateOrInsert(['key' => 'last_assigned_galaxy'], ['value' => '1']);
        DB::table('settings')->updateOrInsert(['key' => 'last_assigned_system'], ['value' => '1']);
        $this->app->forgetInstance(SettingsService::class);

        $occupant = User::factory()->create();

        foreach ([4, 6, 8, 10, 12] as $position) {
            Planet::factory()->create([
                'user_id' => $occupant->id,
                'galaxy' => 1,
                'system' => 1,
                'planet' => $position,
            ]);
        }

        // On compare avant et apres, plutot qu'en absolu : le systeme 1:1 abrite deja Arakis,
        // la planete du compte systeme, posee par une migration bien anterieure.
        $avant = $this->planetIdsInFirstSystem();

        $this->registerAccounts(1);

        $ajoutees = array_values(array_diff($this->planetIdsInFirstSystem(), $avant));

        $this->assertSame(
            [],
            $ajoutees,
            'Une planete a ete posee en 1:1 alors que toutes ses cases libres touchent une planete existante.'
        );
    }

    /**
     * Les identifiants des planetes du systeme 1:1.
     *
     * @return array<int, int>
     */
    private function planetIdsInFirstSystem(): array
    {
        return Planet::query()
            ->where('galaxy', 1)
            ->where('system', 1)
            ->pluck('id')
            ->map(fn ($id): int => (int)$id)
            ->all();
    }

    /**
     * Cree des comptes par le service d'inscription, celui qui produisait le defaut.
     *
     * @param int $nombre
     * @return void
     */
    private function registerAccounts(int $nombre): void
    {
        for ($index = 0; $index < $nombre; $index++) {
            $utilisateur = User::factory()->create();

            resolve(InitialUserDataService::class)->createFor($utilisateur);
        }
    }

    /**
     * Les positions occupees, par systeme, triees — chacune avec le corps qui l'occupe.
     *
     * Une lune partage la case de sa planete : la case n'est gardee qu'une fois, et c'est le plus
     * petit identifiant qui la porte, pour que le tri soit le meme d'un passage a l'autre.
     *
     * @return array<string, array<int, array{position: int, id: int}>>
     */
    private function positionsBySystem(): array
    {
        $parSysteme = [];

        foreach (Planet::query()->orderBy('planet')->orderBy('id')->get() as $planete) {
            $systeme = $planete->galaxy . ':' . $planete->system;
            $position = (int)$planete->planet;

            if (!isset($parSysteme[$systeme][$position])) {
                $parSysteme[$systeme][$position] = ['position' => $position, 'id' => (int)$planete->id];
            }
        }

        foreach ($parSysteme as $systeme => $cases) {
            ksort($cases);
            $parSysteme[$systeme] = array_values($cases);
        }

        return $parSysteme;
    }
}
