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
        // echouer l'insertion. `planet_moves` d'abord, seule table qui les reference.
        $systeme = User::where('username', User::SYSTEM_ACCOUNT_USERNAME)->value('id');

        $etrangeres = Planet::where('galaxy', 1)
            ->where('system', 1)
            ->when($systeme !== null, fn ($requete) => $requete->where('user_id', '!=', $systeme))
            ->pluck('id');

        DB::table('planet_moves')->whereIn('planet_id', $etrangeres)->delete();
        Planet::whereIn('id', $etrangeres)->delete();
    }

    public function testAucunePlaneteNeSePoseSurLaCaseVoisineDUneAutre(): void
    {
        $this->registerAccounts(15);

        foreach ($this->positionsBySystem() as $systeme => $positions) {
            for ($index = 1; $index < count($positions); $index++) {
                $ecart = $positions[$index] - $positions[$index - 1];

                $this->assertGreaterThanOrEqual(
                    self::MINIMUM_GAP,
                    $ecart,
                    'Deux planetes se touchent en ' . $systeme . ' : cases ' . $positions[$index - 1] . ' et ' . $positions[$index] . '.'
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
     * Les positions occupees, par systeme, triees.
     *
     * @return array<string, array<int, int>>
     */
    private function positionsBySystem(): array
    {
        $parSysteme = [];

        foreach (Planet::query()->orderBy('planet')->get() as $planete) {
            $parSysteme[$planete->galaxy . ':' . $planete->system][] = (int)$planete->planet;
        }

        foreach ($parSysteme as $systeme => $positions) {
            $triees = array_values(array_unique($positions));
            sort($triees);
            $parSysteme[$systeme] = $triees;
        }

        return $parSysteme;
    }
}
