<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryValue;
use OGame\Models\AllianceHighscore;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\HighscoreService;
use Tests\AccountTestCase;

/**
 * **Les trois cumuls militaires au classement : servis seulement une fois la collecte activée, et chacun le sien.**
 *
 * Ce que ces essais tiennent, du plus visible au plus profond :
 *
 * 1. **Avant l'activation**, les boutons construits, détruits et perdus restent inertes, et une demande directe
 *    reçoit « Statistiques non encore disponibles », jamais un tableau de zéros qui passerait pour complet.
 * 2. **Après l'activation**, les boutons envoient les trois types, et chaque classement rend **sa** valeur.
 * 3. La page dit **depuis quand** le cumul couvre, et avertit **seulement** tant que des événements attendent.
 * 4. La photographie convertit les demi-unités en points **une seule fois**.
 * 5. La somme d'une alliance suit ses **membres actuels**, peut donc diminuer, et la page le dit.
 * 6. La tâche des rangs classe les trois cumuls, joueurs et alliances.
 * 7. **Chaque type de classement a ses colonnes dans les deux tables** — sans quoi la tâche des rangs tomberait au
 *    premier passage.
 *
 * ## Pourquoi des valeurs toutes différentes
 *
 * La ligne du joueur porte 7 777 en militaire, 1 111 construits, 2 222 détruits et 3 333 perdus : lire 2 222, c'est
 * savoir **quel** classement a été rendu. On lit la cellule de la ligne du joueur, jamais la page.
 */
class MilitaryTalliesRankingTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // La base d'un processus est partagée : l'essai établit lui-même l'état de la collecte qu'il suppose.
        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
    }

    protected function tearDown(): void
    {
        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        DB::table('military_tally_events')->where('event_key', 'like', 'essai-classement:%')->delete();

        parent::tearDown();
    }

    /**
     * **Avant l'activation, rien n'est servi** : boutons inertes, et une demande directe reçoit le message.
     */
    public function testBeforeActivationTheThreeTalliesAreUnavailableAndTheirButtonsInert(): void
    {
        $boutons = $this->boutons((string)$this->get('/highscore')->assertStatus(200)->getContent());

        foreach (['built', 'destroyed', 'lost'] as $nom) {
            $this->assertSame('span', $boutons[$nom]['balise'], "Avant l'activation, le bouton $nom est un lien : il enverrait une demande.");
            $this->assertStringNotContainsString('rel=', $boutons[$nom]['attributs']);
            $this->assertStringContainsString('aria-disabled="true"', $boutons[$nom]['attributs']);
        }

        $this->uneLigne($this->currentUserId);

        foreach ([1, 2] as $categorie) {
            foreach ([HighscoreTypeEnum::military_built, HighscoreTypeEnum::military_destroyed, HighscoreTypeEnum::military_lost] as $type) {
                $page = (string)$this->post('/ajax/highscore', ['category' => $categorie, 'type' => $type->value])->assertStatus(200)->getContent();

                $this->assertStringContainsString(__('t_ingame.highscore.statistics_not_yet_available'), $page, "Catégorie $categorie, $type->name : le message manque.");
                $this->assertStringNotContainsString('id="position', $page, "Catégorie $categorie, $type->name : un classement est servi avant l'activation.");
            }
        }
    }

    /**
     * **Après l'activation, les trois boutons envoient les trois types**, et celui de l'honneur ne bouge pas.
     */
    public function testAfterActivationTheButtonsSendTheThreeTallyTypes(): void
    {
        $this->activer((int)Date::now()->timestamp);

        $boutons = $this->boutons((string)$this->get('/highscore')->assertStatus(200)->getContent());

        foreach (['built' => HighscoreTypeEnum::military_built, 'destroyed' => HighscoreTypeEnum::military_destroyed, 'lost' => HighscoreTypeEnum::military_lost, 'honor' => HighscoreTypeEnum::honor] as $nom => $type) {
            $this->assertSame('a', $boutons[$nom]['balise'], "Après l'activation, le bouton $nom n'est pas un lien.");
            $this->assertStringContainsString('rel="' . $type->value . '"', $boutons[$nom]['attributs'], "Le bouton $nom n'envoie pas la valeur de $type->name.");
            $this->assertStringNotContainsString('aria-disabled', $boutons[$nom]['attributs']);
        }
    }

    /**
     * **Chaque cumul rend sa propre valeur**, pour les joueurs — jamais le classement militaire actuel.
     */
    public function testEachTallyShowsItsOwnScore(): void
    {
        $this->activer((int)Date::now()->timestamp);
        $this->uneLigne($this->currentUserId);

        $this->assertSame('1,111', $this->scoreAffiche(HighscoreTypeEnum::military_built));
        $this->assertSame('2,222', $this->scoreAffiche(HighscoreTypeEnum::military_destroyed));
        $this->assertSame('3,333', $this->scoreAffiche(HighscoreTypeEnum::military_lost));
        $this->assertSame('7,777', $this->scoreAffiche(HighscoreTypeEnum::military), 'Prémisse : le classement militaire actuel reste le sien.');

        // La note de cumul appartient aux trois cumuls, et à eux seuls : présente sur l'un, absente du militaire.
        $cumul = (string)$this->post('/ajax/highscore', ['category' => 1, 'type' => HighscoreTypeEnum::military_built->value])->assertStatus(200)->getContent();
        $militaire = (string)$this->post('/ajax/highscore', ['category' => 1, 'type' => HighscoreTypeEnum::military->value])->assertStatus(200)->getContent();

        $this->assertStringContainsString('class="military-tally-note"', $cumul, 'Le classement des construits ne porte pas sa note de cumul.');
        $this->assertStringNotContainsString('class="military-tally-note"', $militaire, 'La note de cumul apparaît sur le classement militaire, qui n’est pas un cumul.');
    }

    /**
     * **La photographie convertit les demi-unités en points une seule fois**, vers le bas.
     */
    public function testThePhotographConvertsHalfUnitsIntoPointsOnce(): void
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);

        $compte = $joueur->getUser();
        $compte->military_value_built = 1_111 * MilitaryValue::POINT + (MilitaryValue::POINT - 1);
        $compte->military_value_destroyed = 2_222 * MilitaryValue::POINT;
        $compte->military_value_lost = MilitaryValue::POINT - 1;
        $compte->save();

        $scores = resolve(HighscoreService::class)->getPlayerScores($joueur);

        $this->assertSame(['general', 'economy', 'research', 'military', 'honor', 'military_built', 'military_destroyed', 'military_lost'], array_keys($scores));
        $this->assertSame(1_111, $scores['military_built'], 'La photographie arrondit vers le haut, ou ne convertit pas les demi-unités.');
        $this->assertSame(2_222, $scores['military_destroyed']);
        $this->assertSame(0, $scores['military_lost'], 'Moins d\'un point vaut un point : la conversion arrondit vers le haut.');
    }

    /**
     * **La page dit depuis quand le cumul couvre**, et n'avertit que tant que des événements attendent.
     */
    public function testThePageSaysSinceWhenAndWarnsOnlyWhilePending(): void
    {
        $this->activer((int)Date::create(2026, 9, 20, 12)->timestamp);
        $this->uneLigne($this->currentUserId);

        // Prémisse établie, pas supposée : aucun événement n'attend dans cette base.
        DB::table('military_tally_events')->where('status', 'en_attente')->delete();

        $demande = ['category' => 1, 'type' => HighscoreTypeEnum::military_lost->value];
        $avertissement = __('t_ingame.highscore.data_temporarily_incomplete');

        $page = (string)$this->post('/ajax/highscore', $demande)->assertStatus(200)->getContent();
        $this->assertStringContainsString(__('t_ingame.highscore.cumulative_since', ['date' => '20.09.2026']), $page, 'La page ne dit pas depuis quand le cumul couvre.');
        $this->assertStringNotContainsString($avertissement, $page, 'La page avertit alors qu\'aucun événement n\'attend.');
        $this->assertStringNotContainsString(__('t_ingame.highscore.alliance_sum_current_members'), $page, 'La note des alliances apparaît au classement des joueurs.');

        DB::table('military_tally_events')->insert([
            'event_key' => 'essai-classement:attente:' . $this->currentUserId,
            'player_id' => $this->currentUserId,
            'status' => 'en_attente',
            'reason' => 'unknown_unit_family',
            'payload' => json_encode(['lost' => ['unite_hors_catalogue' => 1]]),
            'weighting_version' => MilitaryValue::WEIGHTING_VERSION,
            'built_value' => 0,
            'destroyed_value' => 0,
            'lost_value' => 0,
            'recorded_at' => (int)Date::now()->timestamp,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        $page = (string)$this->post('/ajax/highscore', $demande)->assertStatus(200)->getContent();
        $this->assertStringContainsString($avertissement, $page, 'Un événement attend, et la page présente ses données comme complètes.');
    }

    /**
     * **La somme d'une alliance suit ses membres actuels** : elle diminue quand un membre part, et la page le dit.
     */
    public function testTheAllianceSumFollowsItsCurrentMembersAndSaysItCanDecrease(): void
    {
        $alliances = resolve(AllianceService::class);

        $fondateur = $this->unCompteOrdinaire();
        $alliance = $alliances->createAlliance($fondateur->id, $this->uneEtiquette(), $this->unNom());

        $membre = $this->unCompteOrdinaire();
        $candidature = $alliances->applyToAlliance($membre->id, $alliance->id);
        $alliances->acceptApplication($candidature->id, $fondateur->id);

        Highscore::updateOrCreate(['player_id' => $fondateur->id], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => 0, 'military_built' => 1_000, 'military_destroyed' => 0, 'military_lost' => 0]);
        Highscore::updateOrCreate(['player_id' => $membre->id], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => 0, 'military_built' => 500, 'military_destroyed' => 0, 'military_lost' => 0]);

        Artisan::call('ogamex:scheduler:generate-alliance-highscores');
        $this->assertSame(1_500, (int)AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('military_built'));

        // Le membre part. Le raccourci par la colonne est voulu : la somme se lit sur `users.alliance_id`, et c'est cela
        // seul que l'essai éprouve, pas le parcours de départ d'une alliance.
        DB::table('users')->where('id', $membre->id)->update(['alliance_id' => null]);

        Artisan::call('ogamex:scheduler:generate-alliance-highscores');
        $this->assertSame(1_000, (int)AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('military_built'), 'La somme ne suit pas les membres actuels.');

        $this->activer((int)Date::now()->timestamp);
        $page = (string)$this->post('/ajax/highscore', ['category' => 2, 'type' => HighscoreTypeEnum::military_built->value])->assertStatus(200)->getContent();

        $this->assertStringContainsString(__('t_ingame.highscore.alliance_sum_current_members'), $page, 'La page des alliances ne dit pas que la somme peut diminuer.');
    }

    /**
     * **La tâche des rangs classe les trois cumuls**, du plus haut au plus bas, joueurs et alliances.
     */
    public function testTheRankTaskRanksTheThreeTalliesForPlayersAndAlliances(): void
    {
        $haut = $this->unCompteOrdinaire();
        $bas = $this->unCompteOrdinaire();
        $alliances = resolve(AllianceService::class);
        $allianceHaute = $alliances->createAlliance($this->unCompteOrdinaire()->id, $this->uneEtiquette(), $this->unNom());
        $allianceBasse = $alliances->createAlliance($this->unCompteOrdinaire()->id, $this->uneEtiquette(), $this->unNom());

        $valeurs = fn (int $v): array => ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => 0, 'military_built' => $v, 'military_destroyed' => $v, 'military_lost' => $v];

        Highscore::updateOrCreate(['player_id' => $haut->id], $valeurs(900_000_001));
        Highscore::updateOrCreate(['player_id' => $bas->id], $valeurs(1));
        AllianceHighscore::updateOrCreate(['alliance_id' => $allianceHaute->id], $valeurs(900_000_001));
        AllianceHighscore::updateOrCreate(['alliance_id' => $allianceBasse->id], $valeurs(1));

        Artisan::call('ogamex:scheduler:generate-highscore-ranks');

        foreach (['military_built', 'military_destroyed', 'military_lost'] as $colonne) {
            $rangHaut = (int)Highscore::query()->where('player_id', $haut->id)->value($colonne . '_rank');
            $rangBas = (int)Highscore::query()->where('player_id', $bas->id)->value($colonne . '_rank');
            $this->assertGreaterThan(0, $rangHaut, "Le joueur le plus haut n'a pas de rang en $colonne.");
            $this->assertLessThan($rangBas, $rangHaut, "Les joueurs sont mal classés en $colonne.");

            $rangAllianceHaute = (int)AllianceHighscore::query()->where('alliance_id', $allianceHaute->id)->value($colonne . '_rank');
            $rangAllianceBasse = (int)AllianceHighscore::query()->where('alliance_id', $allianceBasse->id)->value($colonne . '_rank');
            $this->assertGreaterThan(0, $rangAllianceHaute, "L'alliance la plus haute n'a pas de rang en $colonne.");
            $this->assertLessThan($rangAllianceBasse, $rangAllianceHaute, "Les alliances sont mal classées en $colonne.");
        }
    }

    /**
     * **Chaque type de classement a sa colonne et son rang dans les deux tables.**
     *
     * La tâche des rangs parcourt toutes les valeurs du type et écrit `<nom>_rank` des deux côtés : un type ajouté sans
     * ses colonnes ferait tomber cette tâche planifiée au premier passage, en production.
     */
    public function testEveryRankingTypeHasItsColumnsInBothTables(): void
    {
        foreach (HighscoreTypeEnum::cases() as $type) {
            foreach (['highscores', 'alliance_highscores'] as $table) {
                $this->assertTrue(Schema::hasColumns($table, [$type->name, $type->name . '_rank']), "La table $table n'a pas les colonnes du classement $type->name.");
            }
        }
    }

    private function activer(int $instant): void
    {
        DB::table('settings')->insert([
            'key' => MilitaryTallyRecorder::SINCE_KEY,
            'value' => (string)$instant,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }

    /**
     * La ligne de classement d'un joueur, avec des valeurs toutes différentes et tous les rangs à 1.
     */
    private function uneLigne(int $joueurId): void
    {
        $ligne = Highscore::query()->firstOrNew(['player_id' => $joueurId]);

        foreach (['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 7_777, 'honor' => 4_242, 'military_built' => 1_111, 'military_destroyed' => 2_222, 'military_lost' => 3_333] as $colonne => $valeur) {
            $ligne->{$colonne} = $valeur;
            $ligne->{$colonne . '_rank'} = 1;
        }

        $ligne->save();
    }

    /**
     * Le score qu'affiche la ligne du joueur courant, pour ce classement.
     */
    private function scoreAffiche(HighscoreTypeEnum $type): string
    {
        $page = (string)$this->post('/ajax/highscore', ['category' => 1, 'type' => $type->value])->assertStatus(200)->getContent();

        $debut = strpos($page, 'id="position' . $this->currentUserId . '"');
        $this->assertNotFalse($debut, "Le joueur n'a pas de ligne au classement $type->name.");

        $fin = strpos($page, '</tr>', $debut);
        $ligne = substr($page, $debut, $fin === false ? null : $fin - $debut);

        if (preg_match('#<td class="score">(.*?)</td>#s', $ligne, $cellule) !== 1) {
            $this->fail("La ligne du joueur n'a pas de cellule de score au classement $type->name.");
        }

        return trim(strip_tags($cellule[1]));
    }

    /**
     * Les quatre sous-boutons du classement militaire : leur balise et leurs attributs, par nom.
     *
     * @return array<string, array{balise: string, attributs: string}>
     */
    private function boutons(string $page): array
    {
        preg_match_all('#<(\w+)\b([^>]*)\bclass="subnavButton subnavButton_(\w+)\b[^"]*"([^>]*)>#', $page, $trouves, PREG_SET_ORDER);

        $parNom = [];

        foreach ($trouves as $bouton) {
            $parNom[$bouton[3]] = ['balise' => $bouton[1], 'attributs' => $bouton[2] . ' ' . $bouton[4]];
        }

        $this->assertSame(['built', 'destroyed', 'lost', 'honor'], array_keys($parNom), 'Les quatre sous-boutons ne sont pas tous là, ou pas dans cet ordre.');

        return $parNom;
    }

    /**
     * Un compte qui n'est pas administrateur : la tâche des rangs et la page écartent les administrateurs.
     */
    private function unCompteOrdinaire(): User
    {
        $compte = User::factory()->create(['username' => 'cumul_' . Str::random(12)]);

        if ($compte->hasRole('admin')) {
            $compte->removeRole('admin');
            $compte->username = 'cumul_' . Str::random(12);
            $compte->save();
        }

        return $compte;
    }

    private function uneEtiquette(): string
    {
        return 'CUM' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5);
    }

    private function unNom(): string
    {
        return 'Cumul ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
}
