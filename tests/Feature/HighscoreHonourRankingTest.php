<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Models\AllianceHighscore;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\HighscoreService;
use OGame\Services\InitialUserDataService;
use Tests\AccountTestCase;

/**
 * **Le classement par points d honneur, de la photographie a la page.**
 *
 * Quatre maillons, chacun avec son temoin :
 *
 * 1. la photographie d un joueur lit l honneur de son compte, signe compris ;
 * 2. une alliance cumule celui de ses membres ;
 * 3. la tache des rangs classe l honneur pour les joueurs **et** pour les alliances — elle parcourt toutes les
 *    valeurs du type de classement, et tomberait au premier passage si une des deux tables n avait pas sa colonne ;
 * 4. **entre le deploiement et le premier passage de cette tache**, `honor_rank` vaut NULL pour tout le monde, et un
 *    tri ascendant range NULL en tete, sous SQLite comme sous MariaDB. Une ligne sans rang doit venir apres les
 *    lignes classees.
 *
 * ## Pourquoi on exige que la premiere ligne porte un rang
 *
 * La base d un processus de la suite garde plusieurs centaines de lignes de classement. Chercher la ligne non
 * classee sur la premiere page ne prouverait rien : dans l ordre juste, elle tombe souvent sur une page suivante.
 * En revanche, des qu une ligne classee et une ligne non classee **visibles** existent — l essai cree les deux —,
 * la premiere ligne affichee porte un rang si et seulement si les NULL passent apres, quelle que soit la taille de
 * la base.
 */
class HighscoreHonourRankingTest extends AccountTestCase
{
    /**
     * **La photographie d un joueur lit l honneur de son compte**, negatif compris : un combat deshonorant en retire.
     */
    public function testThePhotographCarriesTheHonourOfTheAccount(): void
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur, 'The test planet has no owner.');

        $compte = $joueur->getUser();
        $compte->honor_points = -37;
        $compte->save();

        $scores = resolve(HighscoreService::class)->getPlayerScores($joueur);

        // Les trois cumuls militaires n y sont pas : `MilitaryTallyPublisher` les publie avec leurs rangs.
        $this->assertSame(['general', 'economy', 'research', 'military', 'honor'], array_keys($scores));
        $this->assertSame(-37, $scores['honor']);
    }

    /**
     * **Une alliance cumule l honneur de ses membres**, negatifs compris.
     */
    public function testAnAllianceAddsUpTheHonourOfItsMembers(): void
    {
        $alliances = resolve(AllianceService::class);

        $fondateur = User::factory()->create();
        $alliance = $alliances->createAlliance($fondateur->id, $this->uneEtiquette(), $this->unNom());

        $membre = User::factory()->create();
        $candidature = $alliances->applyToAlliance($membre->id, $alliance->id);
        $alliances->acceptApplication($candidature->id, $fondateur->id);

        Highscore::updateOrCreate(['player_id' => $fondateur->id], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => 1_200]);
        Highscore::updateOrCreate(['player_id' => $membre->id], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => -200]);

        Artisan::call('ogamex:scheduler:generate-alliance-highscores');

        $this->assertSame(1_000, (int)AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('honor'));
    }

    /**
     * **La tache des rangs classe aussi l honneur**, du plus haut au plus bas, pour les joueurs et pour les alliances.
     */
    public function testTheRankTaskRanksHonourForPlayersAndAlliances(): void
    {
        $haut = $this->unCompteOrdinaire();
        $bas = $this->unCompteOrdinaire();

        Highscore::updateOrCreate(['player_id' => $haut->id], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => 900_000_001]);
        Highscore::updateOrCreate(['player_id' => $bas->id], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => -900_000_001]);

        $alliances = resolve(AllianceService::class);
        $allianceHaute = $alliances->createAlliance(User::factory()->create()->id, $this->uneEtiquette(), $this->unNom());
        $allianceBasse = $alliances->createAlliance(User::factory()->create()->id, $this->uneEtiquette(), $this->unNom());

        AllianceHighscore::updateOrCreate(['alliance_id' => $allianceHaute->id], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => 900_000_001]);
        AllianceHighscore::updateOrCreate(['alliance_id' => $allianceBasse->id], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => -900_000_001]);

        Artisan::call('ogamex:scheduler:generate-highscore-ranks');

        $rangHaut = (int)Highscore::query()->where('player_id', $haut->id)->value('honor_rank');
        $rangBas = (int)Highscore::query()->where('player_id', $bas->id)->value('honor_rank');
        $this->assertGreaterThan(0, $rangHaut, 'The player with the most honour was given no honour rank.');
        $this->assertLessThan($rangBas, $rangHaut);

        $rangAllianceHaute = (int)AllianceHighscore::query()->where('alliance_id', $allianceHaute->id)->value('honor_rank');
        $rangAllianceBasse = (int)AllianceHighscore::query()->where('alliance_id', $allianceBasse->id)->value('honor_rank');
        $this->assertGreaterThan(0, $rangAllianceHaute, 'The alliance with the most honour was given no honour rank.');
        $this->assertLessThan($rangAllianceBasse, $rangAllianceHaute);
    }

    /**
     * **Un joueur sans rang d honneur vient apres les joueurs classes.**
     */
    public function testAnUnrankedPlayerComesAfterTheRankedOnes(): void
    {
        $this->uneLigneDeJoueur($this->currentUserId, 1);

        $nonClasse = $this->unJoueurVisible();
        $this->uneLigneDeJoueur($nonClasse->id, null);

        $this->assertTrue(
            Highscore::query()->validRanks()->whereNull('honor_rank')->where('player_id', $nonClasse->id)->exists(),
            'The unranked row is not a valid ranking row: the page would skip it and prove nothing.'
        );

        $rangs = $this->rangsDansLOrdre((string)$this->post('/ajax/highscore', ['category' => 1, 'type' => 4])->assertStatus(200)->getContent());

        $this->assertNotSame([], $rangs, 'The honour ranking shows no player row at all.');
        $this->assertNotSame('', $rangs[0], 'The first row of the honour ranking has no rank: unranked players went first.');
    }

    /**
     * **Une alliance sans rang d honneur vient apres les alliances classees.**
     */
    public function testAnUnrankedAllianceComesAfterTheRankedOnes(): void
    {
        $alliances = resolve(AllianceService::class);
        $classee = $alliances->createAlliance(User::factory()->create()->id, $this->uneEtiquette(), $this->unNom());
        $nonClassee = $alliances->createAlliance(User::factory()->create()->id, $this->uneEtiquette(), $this->unNom());

        foreach ([[$classee->id, 1], [$nonClassee->id, null]] as [$allianceId, $rang]) {
            AllianceHighscore::updateOrCreate(['alliance_id' => $allianceId], [
                'general' => 0,
                'general_rank' => 1,
                'economy' => 0,
                'economy_rank' => 1,
                'research' => 0,
                'research_rank' => 1,
                'military' => 0,
                'military_rank' => 1,
                'honor' => 0,
                'honor_rank' => $rang,
            ]);
        }

        $this->assertTrue(
            AllianceHighscore::query()->validRanks()->whereNull('honor_rank')->where('alliance_id', $nonClassee->id)->exists(),
            'The unranked alliance row is not a valid ranking row: the page would skip it and prove nothing.'
        );

        $rangs = $this->rangsDansLOrdre((string)$this->post('/ajax/highscore', ['category' => 2, 'type' => 4])->assertStatus(200)->getContent());

        $this->assertNotSame([], $rangs, 'The alliance honour ranking shows no row at all.');
        $this->assertNotSame('', $rangs[0], 'The first row of the alliance honour ranking has no rank: unranked alliances went first.');
    }

    /**
     * Les rangs affiches, ligne par ligne, dans l ordre de la page. Une ligne sans rang rend une chaine vide.
     *
     * @return list<string>
     */
    private function rangsDansLOrdre(string $page): array
    {
        preg_match_all('#<tr\b[^>]*\bid="position\d+"[^>]*>\s*<td class="position">(.*?)</td>#s', $page, $lignes);

        return array_values(array_map(trim(...), $lignes[1]));
    }

    /**
     * Une ligne de classement valide pour les quatre scores d origine, avec le rang d honneur demande.
     */
    private function uneLigneDeJoueur(int $joueurId, int|null $rangHonneur): void
    {
        $ligne = Highscore::query()->firstOrNew(['player_id' => $joueurId]);

        $ligne->general = 0;
        $ligne->general_rank = 1;
        $ligne->economy = 0;
        $ligne->economy_rank = 1;
        $ligne->research = 0;
        $ligne->research_rank = 1;
        $ligne->military = 0;
        $ligne->military_rank = 1;
        $ligne->honor = 0;
        $ligne->honor_rank = $rangHonneur;
        $ligne->save();
    }

    /**
     * Un joueur que la page du classement montre : technologies, planete, et pas administrateur.
     */
    private function unJoueurVisible(): User
    {
        $compte = $this->unCompteOrdinaire();

        resolve(InitialUserDataService::class)->createFor($compte);

        $this->assertTrue(
            DB::table('planets')->where('user_id', $compte->id)->exists(),
            'The unranked player has no planet: the ranking would skip them and prove nothing.'
        );

        return $compte;
    }

    /**
     * Un compte qui n est pas administrateur.
     *
     * Le crochet `created` du modele promeut le premier utilisateur d une transaction en administrateur, et la tache
     * des rangs comme la page ecartent les administrateurs.
     */
    private function unCompteOrdinaire(): User
    {
        $compte = User::factory()->create(['username' => 'honneur_' . Str::random(12)]);

        if ($compte->hasRole('admin')) {
            $compte->removeRole('admin');
            $compte->username = 'honneur_' . Str::random(12);
            $compte->save();
        }

        return $compte;
    }

    private function uneEtiquette(): string
    {
        return 'HON' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5);
    }

    private function unNom(): string
    {
        return 'Honneur ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
}
