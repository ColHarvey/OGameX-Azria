<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Military\MilitaryTallyAggregator;
use OGame\Military\MilitaryTallyPublisher;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryValue;
use OGame\Models\AllianceHighscore;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\HighscoreService;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\AccountTestCase;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * **Les trois cumuls militaires au classement : publiés d'un seul état, servis seulement une fois publiés.**
 *
 * Ce que ces essais tiennent, du plus visible au plus profond :
 *
 * 1. **Avant l'activation**, et **avant la première publication**, les boutons restent inertes et une demande
 *    directe reçoit « Statistiques non encore disponibles » — jamais un tableau de zéros qui passerait pour complet.
 * 2. **Après la publication**, les boutons envoient les trois types, et chaque classement rend **sa** valeur.
 * 3. La page dit **depuis quand** le cumul couvre, **quand** il a été actualisé, et avertit **seulement** tant que des
 *    événements attendent.
 * 4. La publication convertit les demi-unités en points **une seule fois**, et range selon les règles de la tâche des
 *    rangs, départage compris.
 * 5. **Valeurs, rangs et date viennent d'un seul état** : une publication qui échoue laisse la précédente entière, et
 *    une ligne que la dernière publication n'a pas rangée n'est pas montrée.
 * 6. La somme d'une alliance suit ses **membres actuels**, peut donc diminuer, et la page le dit.
 * 7. **Chaque type de classement a ses colonnes dans les deux tables.**
 *
 * ## Pourquoi des valeurs toutes différentes
 *
 * La ligne du joueur porte 7 777 en militaire, 1 111 construits, 2 222 détruits et 3 333 perdus : lire 2 222, c'est
 * savoir **quel** classement a été rendu. On lit la cellule de la ligne du joueur, jamais la page.
 */
class MilitaryTalliesRankingTest extends AccountTestCase
{
    use DetachesFromAnyAlliance;

    /** @var list<int> Les comptes créés par l'essai, dont les compteurs partent avec lui. */
    private array $comptes = [];

    protected function setUp(): void
    {
        parent::setUp();

        // La base d'un processus est partagée : l'essai établit lui-même l'état de la collecte qu'il suppose.
        DB::table('settings')->whereIn('key', [MilitaryTallyRecorder::SINCE_KEY, MilitaryTallyPublisher::PUBLISHED_KEY])->delete();

        foreach (HighscoreTypeEnum::cases() as $type) {
            HighscoreService::forgetCachedPagesOf($type);
        }
    }

    protected function tearDown(): void
    {
        DB::table('settings')->whereIn('key', [MilitaryTallyRecorder::SINCE_KEY, MilitaryTallyPublisher::PUBLISHED_KEY])->delete();
        DB::table('military_tally_events')->where('event_key', 'like', 'essai-classement:%')->delete();
        DB::table('military_tallies')->whereIn('player_id', [$this->currentUserId, ...$this->comptes])->delete();

        parent::tearDown();
    }

    public function testBeforeActivationTheThreeTalliesAreUnavailableAndTheirButtonsInert(): void
    {
        $this->assertFalse(resolve(MilitaryTallyPublisher::class)->publish(), 'Une publication a eu lieu avant l’activation.');
        $this->assertNull(MilitaryTallyPublisher::publishedAt());

        $this->assertLesBoutonsSontInertes();
        $this->assertLesCumulsSontIndisponibles();
    }

    /**
     * **Activée ne veut pas dire publiée.** Avant la première publication, aucune valeur ni aucun rang ne vient d'un
     * état agrégé.
     */
    public function testAnActivatedCollectionIsNotServedBeforeItsFirstPublication(): void
    {
        $this->activer((int)Date::now()->timestamp);
        $this->uneLigne($this->currentUserId);

        $this->assertLesBoutonsSontInertes();
        $this->assertLesCumulsSontIndisponibles();
    }

    public function testAfterPublicationTheButtonsSendTheThreeTallyTypes(): void
    {
        $this->activer((int)Date::now()->timestamp);
        $this->publier();

        $boutons = $this->boutons((string)$this->get('/highscore')->assertStatus(200)->getContent());

        foreach (['built' => HighscoreTypeEnum::military_built, 'destroyed' => HighscoreTypeEnum::military_destroyed, 'lost' => HighscoreTypeEnum::military_lost, 'honor' => HighscoreTypeEnum::honor] as $nom => $type) {
            $this->assertSame('a', $boutons[$nom]['balise'], "Après la publication, le bouton $nom n’est pas un lien.");
            $this->assertStringContainsString('rel="' . $type->value . '"', $boutons[$nom]['attributs'], "Le bouton $nom n’envoie pas la valeur de $type->name.");
            $this->assertStringNotContainsString('aria-disabled', $boutons[$nom]['attributs']);
        }
    }

    public function testEachTallyShowsItsOwnPublishedScore(): void
    {
        $this->activer((int)Date::now()->timestamp);
        $this->uneLigne($this->currentUserId);
        $this->compteur($this->currentUserId, 1_111 * MilitaryValue::POINT, 2_222 * MilitaryValue::POINT, 3_333 * MilitaryValue::POINT);
        $this->publier();

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

    public function testThePublicationConvertsHalfUnitsIntoPointsOnce(): void
    {
        $this->activer((int)Date::now()->timestamp);
        $this->uneLigne($this->currentUserId);
        $this->compteur($this->currentUserId, 1_111 * MilitaryValue::POINT + (MilitaryValue::POINT - 1), 2_222 * MilitaryValue::POINT, MilitaryValue::POINT - 1);
        $this->publier();

        $ligne = Highscore::query()->where('player_id', $this->currentUserId)->firstOrFail();

        $this->assertSame(1_111, (int)$ligne->military_built, 'La publication arrondit vers le haut, ou ne convertit pas les demi-unités.');
        $this->assertSame(2_222, (int)$ligne->military_destroyed);
        $this->assertSame(0, (int)$ligne->military_lost, 'Moins d’un point vaut un point : la conversion arrondit vers le haut.');
    }

    public function testThePageSaysSinceWhenWhenItWasRefreshedAndWarnsOnlyWhilePending(): void
    {
        $this->activer((int)Date::create(2026, 9, 20, 12)->timestamp);
        $this->uneLigne($this->currentUserId);

        $this->travelTo(Date::create(2026, 9, 21, 8, 30, 5));
        $this->publier();

        // Prémisse établie, pas supposée : aucun événement n'attend dans cette base.
        DB::table('military_tally_events')->where('status', MilitaryTallyRecorder::PENDING)->delete();

        $demande = ['category' => 1, 'type' => HighscoreTypeEnum::military_lost->value];
        $avertissement = __('t_ingame.highscore.data_temporarily_incomplete');

        $page = (string)$this->post('/ajax/highscore', $demande)->assertStatus(200)->getContent();
        $this->assertStringContainsString(__('t_ingame.highscore.cumulative_since', ['date' => '20.09.2026']), $page, 'La page ne dit pas depuis quand le cumul couvre.');
        $this->assertStringContainsString(__('t_ingame.highscore.refreshed_at', ['date' => '21.09.2026 08:30:05']), $page, 'La page ne dit pas quand le classement a été actualisé.');
        $this->assertStringNotContainsString($avertissement, $page, 'La page avertit alors qu’aucun événement n’attend.');
        $this->assertStringNotContainsString(__('t_ingame.highscore.alliance_sum_current_members'), $page, 'La note des alliances apparaît au classement des joueurs.');

        DB::table('military_tally_events')->insert([
            'event_key' => 'essai-classement:attente:' . $this->currentUserId,
            'player_id' => $this->currentUserId,
            'status' => MilitaryTallyRecorder::PENDING,
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

        $this->compteur($fondateur->id, 1_000 * MilitaryValue::POINT, 0, 0);
        $this->compteur($membre->id, 500 * MilitaryValue::POINT, 0, 0);

        Artisan::call('ogamex:scheduler:generate-alliance-highscores');
        $this->activer((int)Date::now()->timestamp);

        $this->publier();
        $this->assertSame(1_500, (int)AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('military_built'));

        // Le membre part, colonne et ligne d’historique ensemble. La somme se lit sur `users.alliance_id`, et c’est cela
        // seul que l’essai éprouve, pas le parcours de départ d’une alliance — mais une colonne écrite seule ferait
        // suspendre le prochain combat durable qui gèlerait ce compte.
        $this->detachFromAnyAlliance((int)$membre->id);

        $this->publier();
        $this->assertSame(1_000, (int)AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('military_built'), 'La somme ne suit pas les membres actuels.');

        $page = (string)$this->post('/ajax/highscore', ['category' => 2, 'type' => HighscoreTypeEnum::military_built->value])->assertStatus(200)->getContent();

        $this->assertStringContainsString(__('t_ingame.highscore.alliance_sum_current_members'), $page, 'La page des alliances ne dit pas que la somme peut diminuer.');

        // **Une alliance que la dernière publication n'a pas rangée n'est pas montrée**, comme un joueur. Ses autres rangs
        // sont posés pour que la page puisse la montrer : sans eux, son absence ne prouverait rien.
        AllianceHighscore::query()->where('alliance_id', $alliance->id)->update(['general_rank' => 1, 'economy_rank' => 1, 'military_rank' => 1, 'research_rank' => 1]);
        HighscoreService::forgetCachedPagesOf(HighscoreTypeEnum::military_built);
        $montree = (string)$this->post('/ajax/highscore', ['category' => 2, 'type' => HighscoreTypeEnum::military_built->value])->assertStatus(200)->getContent();
        $this->assertStringContainsString('id="position' . $alliance->id . '"', $montree, 'Prémisse : l’alliance publiée est montrée.');

        AllianceHighscore::query()->where('alliance_id', $alliance->id)->update(['military_built_rank' => null]);
        HighscoreService::forgetCachedPagesOf(HighscoreTypeEnum::military_built);
        $cachee = (string)$this->post('/ajax/highscore', ['category' => 2, 'type' => HighscoreTypeEnum::military_built->value])->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('id="position' . $alliance->id . '"', $cachee, 'Une alliance sans rang publié est montrée au classement des construits.');
    }

    /**
     * **La tâche des rangs publie les trois cumuls selon ses propres règles** : valeur décroissante, ancienneté du
     * compte, identifiant ; les comptes pilotés par le serveur au rang 0 ; les alliances par valeur.
     */
    public function testTheRankTaskPublishesTheTalliesWithTheRankingRules(): void
    {
        $haut = $this->unCompteOrdinaire();
        $bas = $this->unCompteOrdinaire();
        $pnj = $this->unCompteOrdinaire();
        DB::table('users')->where('id', $pnj->id)->update(['is_npc' => true]);

        // **Le plus récent est créé en premier** : son identifiant est plus petit. Sans ce croisement, un départage par
        // identifiant seul donnerait le même ordre que l'ancienneté, et l'essai ne verrait pas l'ancienneté manquer.
        $recent = $this->unCompteOrdinaire();
        $ancien = $this->unCompteOrdinaire();
        DB::table('users')->where('id', $ancien->id)->update(['created_at' => '2020-01-01 00:00:00']);
        DB::table('users')->where('id', $recent->id)->update(['created_at' => '2021-01-01 00:00:00']);

        $premierJumeau = $this->unCompteOrdinaire();
        $secondJumeau = $this->unCompteOrdinaire();
        DB::table('users')->whereIn('id', [$premierJumeau->id, $secondJumeau->id])->update(['created_at' => '2022-01-01 00:00:00']);

        // **La ligne du second jumeau est écrite avant celle du premier** : un tri stable sans départage par identifiant
        // suivrait l'ordre des lignes, et placerait le second devant.
        foreach ([$haut->id => 900_001, $bas->id => 1, $pnj->id => 999_999, $ancien->id => 500, $recent->id => 500, $secondJumeau->id => 400, $premierJumeau->id => 400] as $joueur => $points) {
            $this->uneLigneVide($joueur);
            $this->compteur($joueur, $points * MilitaryValue::POINT, $points * MilitaryValue::POINT, $points * MilitaryValue::POINT);
        }

        $alliances = resolve(AllianceService::class);
        $allianceHaute = $alliances->createAlliance($haut->id, $this->uneEtiquette(), $this->unNom());
        $allianceBasse = $alliances->createAlliance($bas->id, $this->uneEtiquette(), $this->unNom());
        Artisan::call('ogamex:scheduler:generate-alliance-highscores');

        $this->activer((int)Date::now()->timestamp);
        $this->assertSame(0, Artisan::call('ogamex:scheduler:generate-highscore-ranks'));

        $this->assertNotNull(MilitaryTallyPublisher::publishedAt(), 'La tâche des rangs n’a pas publié les cumuls.');

        foreach (['military_built', 'military_destroyed', 'military_lost'] as $colonne) {
            $rang = fn (User $compte): int => (int)Highscore::query()->where('player_id', $compte->id)->value($colonne . '_rank');

            $this->assertGreaterThan(0, $rang($haut), "Le joueur le plus haut n’a pas de rang en $colonne.");
            $this->assertLessThan($rang($bas), $rang($haut), "Les joueurs sont mal classés en $colonne.");
            $this->assertSame(0, $rang($pnj), "Un compte piloté par le serveur est classé en $colonne.");
            $this->assertLessThan($rang($recent), $rang($ancien), "À valeur égale, le compte le plus ancien ne passe pas devant en $colonne.");
            $this->assertSame($rang($premierJumeau) + 1, $rang($secondJumeau), "À valeur et ancienneté égales, l’identifiant ne départage pas en $colonne.");

            $rangAlliance = fn (int $alliance): int => (int)AllianceHighscore::query()->where('alliance_id', $alliance)->value($colonne . '_rank');
            $this->assertGreaterThan(0, $rangAlliance($allianceHaute->id), "L’alliance la plus haute n’a pas de rang en $colonne.");
            $this->assertLessThan($rangAlliance($allianceBasse->id), $rangAlliance($allianceHaute->id), "Les alliances sont mal classées en $colonne.");
        }
    }

    /**
     * **Tant que rien n'est publié, la tâche des rangs ne range pas les cumuls elle-même** : elle mêlerait des valeurs
     * écrites par un autre passage.
     */
    public function testTheRankTaskLeavesTheTalliesAloneWhileNothingIsPublished(): void
    {
        $compte = $this->unCompteOrdinaire();
        Highscore::query()->updateOrCreate(['player_id' => $compte->id], ['general' => 5, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => 0, 'military_built' => 5, 'military_destroyed' => 5, 'military_lost' => 5]);

        $this->assertSame(0, Artisan::call('ogamex:scheduler:generate-highscore-ranks'));

        $ligne = Highscore::query()->where('player_id', $compte->id)->firstOrFail();
        $this->assertNotNull($ligne->general_rank, 'Prémisse : la tâche des rangs est passée.');

        foreach (['military_built_rank', 'military_destroyed_rank', 'military_lost_rank'] as $colonne) {
            $this->assertNull($ligne->{$colonne}, "La tâche des rangs a rangé $colonne sans publication.");
        }

        $this->assertNull(MilitaryTallyPublisher::publishedAt());
    }

    /**
     * **Une publication qui échoue avant sa validation laisse la précédente entière** : valeurs, rangs et date.
     */
    public function testAFailedPublicationLeavesThePreviousOneWhole(): void
    {
        $this->activer((int)Date::now()->timestamp);
        $this->uneLigne($this->currentUserId);
        $this->compteur($this->currentUserId, 10 * MilitaryValue::POINT, 20 * MilitaryValue::POINT, 30 * MilitaryValue::POINT);
        $this->publier();

        $avant = $this->etatPublie();
        $dateAvant = MilitaryTallyPublisher::publishedAt();

        $this->compteur($this->currentUserId, 90 * MilitaryValue::POINT, 80 * MilitaryValue::POINT, 70 * MilitaryValue::POINT);
        $this->travel(10)->minutes();

        $interrompue = new MilitaryTallyPublisher(
            resolve(MilitaryTallyAggregator::class),
            resolve(MilitaryTallyRecorder::class),
            resolve(SettingsService::class),
            static function (): void {
                throw new RuntimeException('interruption avant la validation');
            }
        );

        try {
            $interrompue->publish();
            $this->fail('La couture d’interruption n’a pas été atteinte : l’essai ne prouverait rien.');
        } catch (RuntimeException $interruption) {
            $this->assertSame('interruption avant la validation', $interruption->getMessage());
        }

        $this->assertSame($avant, $this->etatPublie(), 'Une publication interrompue a laissé une partie de ses valeurs ou de ses rangs.');
        $this->assertSame($dateAvant, MilitaryTallyPublisher::publishedAt(), 'Une publication interrompue a déplacé la date d’actualisation.');
    }

    /**
     * **Une ligne que la dernière publication n'a pas rangée n'est pas montrée** : sa valeur ne vient d'aucun état
     * publié.
     */
    public function testARowTheLastPublicationDidNotRankIsNotShown(): void
    {
        $this->activer((int)Date::now()->timestamp);
        $this->uneLigne($this->currentUserId);
        $this->compteur($this->currentUserId, 1_111 * MilitaryValue::POINT, 0, 0);
        $this->publier();

        // **La ligne est rangée en dernier, et c'est la dernière page qui est lue.** La base d'un processus garde les
        // classements des essais voisins : lue en première page, une ligne sans rang serait absente même si le filtre
        // manquait — une ligne sans rang se range en dernier —, et l'essai ne prouverait rien.
        Highscore::query()->where('player_id', $this->currentUserId)->update(['military_built_rank' => 999_999_999]);
        $page = $this->dernierePageDesConstruits();
        HighscoreService::forgetCachedPagesOf(HighscoreTypeEnum::military_built);

        $this->assertStringContainsString('id="position' . $this->currentUserId . '"', $this->pageDesConstruits($page), 'Prémisse : la dernière page montre la ligne rangée en dernier.');

        Highscore::query()->where('player_id', $this->currentUserId)->update(['military_built_rank' => null]);
        HighscoreService::forgetCachedPagesOf(HighscoreTypeEnum::military_built);

        $this->assertStringNotContainsString('id="position' . $this->currentUserId . '"', $this->pageDesConstruits($page), 'Une ligne sans rang publié est montrée au classement des construits.');
    }

    /**
     * La page où se trouve la dernière ligne rangée du classement des construits, avec les filtres de la page.
     */
    private function dernierePageDesConstruits(): int
    {
        $requete = Highscore::query()->whereHas('player.tech')->validRanks()->whereNotNull('military_built_rank');

        if (!resolve(HighscoreService::class)->isAdminVisibleInHighscore()) {
            $requete->whereHas('player', function ($joueur): void {
                $joueur->whereDoesntHave('roles', function ($role): void {
                    $role->where('name', 'admin');
                });
            });
        }

        return max(1, (int)ceil($requete->count() / 100));
    }

    private function pageDesConstruits(int $page): string
    {
        return (string)$this->post('/ajax/highscore', ['category' => 1, 'type' => HighscoreTypeEnum::military_built->value, 'page' => $page])->assertStatus(200)->getContent();
    }

    /**
     * **Chaque type de classement a sa colonne et son rang dans les deux tables.**
     */
    public function testEveryRankingTypeHasItsColumnsInBothTables(): void
    {
        foreach (HighscoreTypeEnum::cases() as $type) {
            foreach (['highscores', 'alliance_highscores'] as $table) {
                $this->assertTrue(Schema::hasColumns($table, [$type->name, $type->name . '_rank']), "La table $table n’a pas les colonnes du classement $type->name.");
            }
        }
    }

    private function assertLesBoutonsSontInertes(): void
    {
        $boutons = $this->boutons((string)$this->get('/highscore')->assertStatus(200)->getContent());

        foreach (['built', 'destroyed', 'lost'] as $nom) {
            $this->assertSame('span', $boutons[$nom]['balise'], "Le bouton $nom est un lien : il enverrait une demande.");
            $this->assertStringNotContainsString('rel=', $boutons[$nom]['attributs']);
            $this->assertStringContainsString('aria-disabled="true"', $boutons[$nom]['attributs']);
        }
    }

    private function assertLesCumulsSontIndisponibles(): void
    {
        foreach ([1, 2] as $categorie) {
            foreach ([HighscoreTypeEnum::military_built, HighscoreTypeEnum::military_destroyed, HighscoreTypeEnum::military_lost] as $type) {
                $page = (string)$this->post('/ajax/highscore', ['category' => $categorie, 'type' => $type->value])->assertStatus(200)->getContent();

                $this->assertStringContainsString(__('t_ingame.highscore.statistics_not_yet_available'), $page, "Catégorie $categorie, $type->name : le message manque.");
                $this->assertStringNotContainsString('id="position', $page, "Catégorie $categorie, $type->name : un classement est servi.");
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

    private function publier(): void
    {
        $this->assertTrue(resolve(MilitaryTallyPublisher::class)->publish(), 'La publication n’a pas eu lieu.');
    }

    /**
     * Le compteur d'un compte, écrit directement : ces essais éprouvent la publication, pas l'agrégation.
     */
    private function compteur(int $joueur, int $construits, int $detruits, int $perdus): void
    {
        DB::table('military_tallies')->updateOrInsert(
            ['player_id' => $joueur],
            ['built_value' => $construits, 'destroyed_value' => $detruits, 'lost_value' => $perdus, 'created_at' => Date::now(), 'updated_at' => Date::now()]
        );
    }

    /**
     * La ligne de classement d'un joueur, avec des valeurs toutes différentes et les rangs des autres classements à 1.
     */
    private function uneLigne(int $joueurId): void
    {
        $ligne = Highscore::query()->firstOrNew(['player_id' => $joueurId]);

        foreach (['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 7_777, 'honor' => 4_242] as $colonne => $valeur) {
            $ligne->{$colonne} = $valeur;
            $ligne->{$colonne . '_rank'} = 1;
        }

        $ligne->save();
    }

    private function uneLigneVide(int $joueurId): void
    {
        Highscore::query()->updateOrCreate(['player_id' => $joueurId], ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 0, 'honor' => 0]);
    }

    /**
     * Les six colonnes publiées de la ligne du joueur courant.
     *
     * @return array<string, int|null>
     */
    private function etatPublie(): array
    {
        $ligne = DB::table('highscores')->where('player_id', $this->currentUserId)->first(['military_built', 'military_destroyed', 'military_lost', 'military_built_rank', 'military_destroyed_rank', 'military_lost_rank']);
        $this->assertNotNull($ligne);

        $etat = [];
        foreach ((array)$ligne as $colonne => $valeur) {
            $etat[(string)$colonne] = $valeur === null ? null : (int)$valeur;
        }

        return $etat;
    }

    /**
     * Le score qu'affiche la ligne du joueur courant, pour ce classement.
     */
    private function scoreAffiche(HighscoreTypeEnum $type): string
    {
        $page = (string)$this->post('/ajax/highscore', ['category' => 1, 'type' => $type->value])->assertStatus(200)->getContent();

        $debut = strpos($page, 'id="position' . $this->currentUserId . '"');
        $this->assertNotFalse($debut, "Le joueur n’a pas de ligne au classement $type->name.");

        $fin = strpos($page, '</tr>', $debut);
        $ligne = substr($page, $debut, $fin === false ? null : $fin - $debut);

        if (preg_match('#<td class="score">(.*?)</td>#s', $ligne, $cellule) !== 1) {
            $this->fail("La ligne du joueur n’a pas de cellule de score au classement $type->name.");
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

        $this->comptes[] = (int)$compte->id;

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
