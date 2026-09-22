<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Highscore\RankMovement;
use OGame\Highscore\RankReferenceOutcome;
use OGame\Highscore\RankReferencePublisher;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\HighscoreService;
use OGame\Services\InitialUserDataService;
use Tests\AccountTestCase;

/**
 * **La colonne de variation dit de combien de places on a bouge, et refuse de l inventer.**
 *
 * La reference est publiee une fois par journee serveur, au premier calcul reussi de cette journee, avec son
 * instant REEL. Tant qu aucune n a ete publiee, la colonne l avoue — elle ne declare pas tout le monde « Nouv. ».
 *
 * Les essais posent leur propre classement : la base d un processus garde les lignes des classes voisines, et la
 * publication exige une suite complete de 1 a N sur TOUTE la table. Les lire sans les poser ferait dependre chaque
 * verdict de l ordre des essais.
 */
class HighscoreRankMovementTest extends AccountTestCase
{
    private RankReferencePublisher $references;

    protected function setUp(): void
    {
        parent::setUp();

        $this->references = resolve(RankReferencePublisher::class);

        // **La publication est tout ou rien, sur TOUTES les portees** : un classement d alliances incoherent
        // refuse aussi la reference des joueurs. Or la base d un passage sequentiel porte les alliances de
        // toutes les classes voisines, dont les rangs ne forment aucune suite complete — cinq essais de cette
        // classe sont tombes ainsi, et seul le passage sequentiel pouvait le montrer. Cet essai n affirme donc
        // pas la coherence du monde, il l etablit. Les lignes d alliance sont derivees et la tache les recree.
        DB::table('alliance_highscores')->delete();

        // Une classe voisine a pu publier une reference dans ce meme processus : chaque essai part de rien.
        DB::table('highscore_rank_references')->delete();
        DB::table('highscore_rank_reference_state')->where('id', 1)->update([
            'published_at' => null,
            'published_day' => null,
            'covered' => null,
            'generation' => 0,
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * **Sans reference, personne n a bouge et personne n est neuf.** C est l exigence de Keven : au demarrage, la
     * colonne avoue qu elle ne sait pas, au lieu de marquer tout un classement comme une entree du jour.
     */
    public function testWithoutAnyReferenceEveryRowSaysUnavailableAndNobodyIsNew(): void
    {
        $joueurs = $this->unClassementDeTroisJoueurs();

        $vue = $this->references->referenceFor(RankReferencePublisher::SCOPE_PLAYER, HighscoreTypeEnum::general, $joueurs);

        $this->assertFalse($vue->covered, 'Aucune reference ne devrait couvrir la categorie.');

        foreach ($joueurs as $position => $joueur) {
            $mouvement = $vue->movementOf($joueur, $position + 1);

            $this->assertNotNull($mouvement);
            $this->assertSame(RankMovement::STATE_UNAVAILABLE, $mouvement->state, "Joueur $joueur.");
            $this->assertNull($mouvement->referenceAt, "Joueur $joueur.");
        }
    }

    /**
     * **Montee, baisse et stabilite, sur les rangs publies.** La variation est l ancien rang moins le nouveau :
     * un cinquieme devenu troisieme gagne deux places.
     */
    public function testAPublishedReferenceThenARankChangeGivesUpDownAndStable(): void
    {
        [$premier, $deuxieme, $troisieme] = $this->unClassementDeTroisJoueurs();

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        // Le premier tombe troisieme, le troisieme monte premier, le deuxieme ne bouge pas.
        $this->poserLeClassement([$troisieme => 1, $deuxieme => 2, $premier => 3]);

        $vue = $this->references->referenceFor(
            RankReferencePublisher::SCOPE_PLAYER,
            HighscoreTypeEnum::general,
            [$premier, $deuxieme, $troisieme],
        );

        $baisse = $vue->movementOf($premier, 3);
        $stable = $vue->movementOf($deuxieme, 2);
        $montee = $vue->movementOf($troisieme, 1);

        $this->assertNotNull($baisse);
        $this->assertNotNull($stable);
        $this->assertNotNull($montee);

        $this->assertSame(RankMovement::STATE_DOWN, $baisse->state);
        $this->assertSame(2, $baisse->places, 'Un premier devenu troisieme perd deux places.');

        $this->assertSame(RankMovement::STATE_STABLE, $stable->state);
        $this->assertSame(0, $stable->places);

        $this->assertSame(RankMovement::STATE_UP, $montee->state);
        $this->assertSame(2, $montee->places, 'Un troisieme devenu premier gagne deux places.');
    }

    /**
     * **Une entree neuve est neuve, pas stable.** Elle n etait pas dans la reference ; lui donner « 0 place » la
     * dirait immobile, ce qui serait faux.
     */
    public function testASubjectAbsentFromTheReferenceIsNewNotStable(): void
    {
        [$premier, $deuxieme] = $this->unClassementDeTroisJoueurs(2);

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $nouveau = User::factory()->create()->id;
        $this->poserLeClassement([$premier => 1, $deuxieme => 2, $nouveau => 3]);

        $vue = $this->references->referenceFor(
            RankReferencePublisher::SCOPE_PLAYER,
            HighscoreTypeEnum::general,
            [$premier, $deuxieme, $nouveau],
        );

        $mouvement = $vue->movementOf($nouveau, 3);

        $this->assertNotNull($mouvement);
        $this->assertSame(RankMovement::STATE_NEW, $mouvement->state);
        $this->assertNotNull($mouvement->referenceAt, 'Une entree neuve nomme quand meme la reference qu elle manque.');
    }

    /**
     * **Un second passage dans la meme journee serveur ne republie rien.** Les rangs, eux, continuent de tourner
     * toutes les cinq minutes ; la reference, non.
     */
    public function testASecondPassOnTheSameDayChangesNothing(): void
    {
        $this->unClassementDeTroisJoueurs();

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $apresLaPremiere = $this->etat();

        $this->assertSame(RankReferenceOutcome::NotDue, $this->references->publishIfDue());

        $this->assertEquals($apresLaPremiere, $this->etat(), 'Le second passage a touche a la reference.');
    }

    /**
     * **La rotation suit la journee serveur, et garde l instant reel.** Un passage qui aboutit a 00 h 05 affiche
     * 00 h 05 — pas minuit, et pas vingt-quatre heures apres le precedent, ce qui decalerait la bascule chaque jour.
     */
    public function testTheReferenceRotatesOnTheServerDayChangeAndKeepsItsRealInstant(): void
    {
        $this->unClassementDeTroisJoueurs();

        $this->travelTo(Date::parse('2026-09-22 23:59:10'));
        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $veille = $this->etat();
        $this->assertSame('2026-09-22', $veille['published_day']);

        // Une minute plus tard, toujours la meme journee : rien ne tourne.
        $this->travelTo(Date::parse('2026-09-22 23:59:59'));
        $this->assertSame(RankReferenceOutcome::NotDue, $this->references->publishIfDue());

        $aboutissement = Date::parse('2026-09-23 00:05:37');
        $this->travelTo($aboutissement);
        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $lendemain = $this->etat();

        $this->assertSame('2026-09-23', $lendemain['published_day']);
        $this->assertSame($aboutissement->timestamp, $lendemain['published_at'], 'La reference doit porter son instant reel.');
        $this->assertSame($veille['generation'] + 1, $lendemain['generation']);
    }

    /**
     * **Un classement incomplet est refuse, et la derniere reference valide survit entiere.** Un doublon de rang est
     * la trace d un passage interrompu ou de deux generateurs qui se sont croises ; le figer pour une journee entiere
     * serait pire que ne rien publier.
     */
    public function testAnIncompleteRankingIsRefusedAndTheLastValidReferenceSurvives(): void
    {
        [$premier, $deuxieme, $troisieme] = $this->unClassementDeTroisJoueurs();

        $this->travelTo(Date::parse('2026-09-22 04:00:00'));
        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $valide = $this->etat();
        $lignesValides = $this->lignesDeReference();

        // Un passage interrompu : deux joueurs portent le rang 2, personne ne porte le 3.
        $this->poserLeClassement([$premier => 1, $deuxieme => 2, $troisieme => 2]);

        $this->travelTo(Date::parse('2026-09-23 04:00:00'));
        $this->assertSame(RankReferenceOutcome::RefusedIncoherentRanking, $this->references->publishIfDue());

        $this->assertEquals($valide, $this->etat(), 'Un refus a touche a l etat de la reference.');
        $this->assertEquals($lignesValides, $this->lignesDeReference(), 'Un refus a touche aux lignes de la reference.');

        // Et la journee reste due : le passage suivant, sur un classement complet, publie.
        $this->poserLeClassement([$premier => 1, $deuxieme => 2, $troisieme => 3]);

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());
        $this->assertSame('2026-09-23', $this->etat()['published_day']);
    }

    /**
     * **Un trou dans la suite est refuse comme un doublon.** Les deux sont la meme faute vue de deux cotes.
     */
    public function testARankingWithAHoleIsRefusedToo(): void
    {
        [$premier, $deuxieme, $troisieme] = $this->unClassementDeTroisJoueurs();

        $this->poserLeClassement([$premier => 1, $deuxieme => 2, $troisieme => 4]);

        $this->assertSame(RankReferenceOutcome::RefusedIncoherentRanking, $this->references->publishIfDue());
        $this->assertNull($this->etat()['published_day'], 'La journee doit rester due.');
    }

    /**
     * **Un classement d alliances incoherent refuse aussi la reference des joueurs.** La publication est tout ou
     * rien, sur les deux portees : c est ce que Keven a demande, et c est ce qui a fait tomber cinq essais de
     * cette classe au passage sequentiel avant que le montage n etablisse sa propre coherence.
     */
    public function testAnIncompleteAllianceRankingRefusesThePlayerReferenceToo(): void
    {
        $this->unClassementDeTroisJoueurs();

        $alliances = resolve(AllianceService::class);
        $premiere = $alliances->createAlliance(User::factory()->create()->id, 'RM' . Str::random(4), 'Variation ' . Str::random(6));
        $seconde = $alliances->createAlliance(User::factory()->create()->id, 'RM' . Str::random(4), 'Variation ' . Str::random(6));

        // Deux alliances au rang 1 : la suite n est pas complete, et rien ne doit etre publie.
        foreach ([$premiere->id, $seconde->id] as $alliance) {
            DB::table('alliance_highscores')->insert([
                'alliance_id' => $alliance,
                'general' => 10,
                'economy' => 10,
                'research' => 10,
                'military' => 10,
                'general_rank' => 1,
                'economy_rank' => 1,
                'research_rank' => 1,
                'military_rank' => 1,
                'created_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);
        }

        $this->assertSame(RankReferenceOutcome::RefusedIncoherentRanking, $this->references->publishIfDue());
        $this->assertNull($this->etat()['published_day'], 'La journee doit rester due.');
        $this->assertSame([], $this->lignesDeReference(), 'Un refus ne doit ecrire aucune ligne de reference.');
    }

    /**
     * **Rien a photographier n est pas une publication.** La journee reste due, et le passage suivant retentera.
     */
    public function testNothingToPublishLeavesTheDayDue(): void
    {
        DB::table('highscores')->delete();
        DB::table('alliance_highscores')->delete();

        $this->assertSame(RankReferenceOutcome::RefusedNothingToPublish, $this->references->publishIfDue());
        $this->assertNull($this->etat()['published_day']);
        $this->assertNull($this->etat()['published_at']);
    }

    /**
     * **Chaque categorie porte sa propre reference.** Changer le classement general ne fait pas bouger le militaire.
     */
    public function testEachCategoryCarriesItsOwnReference(): void
    {
        [$premier, $deuxieme, $troisieme] = $this->unClassementDeTroisJoueurs();

        // Le militaire est l inverse du general.
        $this->poserLeClassement(
            [$premier => 1, $deuxieme => 2, $troisieme => 3],
            [$premier => 3, $deuxieme => 2, $troisieme => 1],
        );

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        // Le general s inverse ; le militaire ne bouge pas.
        $this->poserLeClassement(
            [$premier => 3, $deuxieme => 2, $troisieme => 1],
            [$premier => 3, $deuxieme => 2, $troisieme => 1],
        );

        $general = $this->references
            ->referenceFor(RankReferencePublisher::SCOPE_PLAYER, HighscoreTypeEnum::general, [$premier])
            ->movementOf($premier, 3);
        $militaire = $this->references
            ->referenceFor(RankReferencePublisher::SCOPE_PLAYER, HighscoreTypeEnum::military, [$premier])
            ->movementOf($premier, 3);

        $this->assertNotNull($general);
        $this->assertNotNull($militaire);

        $this->assertSame(RankMovement::STATE_DOWN, $general->state);
        $this->assertSame(2, $general->places);
        $this->assertSame(RankMovement::STATE_STABLE, $militaire->state);
    }

    /**
     * **Une categorie jamais publiee reste indisponible**, meme quand les autres ont leur reference : les cumuls
     * militaires n ont de rang qu une fois la collecte activee, et avant cela tout le monde y serait « Nouv. ».
     */
    public function testACategoryWithoutAnyRankStaysUnavailableWhileOthersArePublished(): void
    {
        [$premier] = $this->unClassementDeTroisJoueurs();

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $cumul = $this->references->referenceFor(
            RankReferencePublisher::SCOPE_PLAYER,
            HighscoreTypeEnum::military_built,
            [$premier],
        );

        $this->assertFalse($cumul->covered);

        $mouvement = $cumul->movementOf($premier, 1);
        $this->assertNotNull($mouvement);
        $this->assertSame(RankMovement::STATE_UNAVAILABLE, $mouvement->state);
    }

    /**
     * **Une ligne sans rang ne porte aucune variation.** Les lignes de faction sont dans ce cas : elles n existent
     * pas dans la table du classement et ne portent pas de rang.
     */
    public function testAnUnrankedRowHasNoMovementAtAll(): void
    {
        [$premier] = $this->unClassementDeTroisJoueurs();

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $vue = $this->references->referenceFor(RankReferencePublisher::SCOPE_PLAYER, HighscoreTypeEnum::general, [$premier]);

        $this->assertNull($vue->movementOf($premier, null));
        $this->assertNull($vue->movementOf($premier, 0));
    }

    /**
     * **Lire la page ne touche jamais la reference.** Exigence de Keven : la reference est ecrite par la tache, et
     * par elle seule.
     */
    public function testAPageReadNeverTouchesTheReference(): void
    {
        $this->uneLigneDeClassementPourLeJoueurCourant(rang: 1);

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $avantEtat = $this->etat();
        $avantLignes = $this->lignesDeReference();

        for ($lecture = 0; $lecture < 3; $lecture++) {
            Cache::flush();
            $this->post('/ajax/highscore', ['category' => 1, 'type' => 0])->assertStatus(200);
        }

        $this->assertEquals($avantEtat, $this->etat());
        $this->assertEquals($avantLignes, $this->lignesDeReference());
    }

    /**
     * **La ligne du joueur montre son icone de montee et son nombre de places.** On lit sa ligne, jamais la page :
     * la base du processus porte les classements des essais voisins.
     */
    public function testThePlayerRowShowsTheUpIconAndTheNumberOfPlaces(): void
    {
        $this->uneLigneDeClassementPourLeJoueurCourant(rang: 3);

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        $this->uneLigneDeClassementPourLeJoueurCourant(rang: 1);
        Cache::flush();

        $cellule = $this->celluleDeVariation();

        $this->assertStringContainsString('1c7545144452ec3e38c9fba216c4f9.gif', $cellule, 'L icone de montee manque.');
        $this->assertStringContainsString('(2)', $cellule, 'Le nombre de places manque.');
        $this->assertStringNotContainsString('7e6b4e65bec62ac2f10ea24ba76c51.gif', $cellule, 'L icone de baisse ne doit pas y etre.');
    }

    /**
     * **Sans reference, la cellule porte un symbole discret et son libelle**, jamais « Nouv. » : quarante pixels ne
     * peuvent pas afficher la phrase, mais l infobulle et le libelle accessible la portent.
     */
    public function testWithoutAReferenceTheCellShowsADiscreetSymbolAndItsLabel(): void
    {
        $this->uneLigneDeClassementPourLeJoueurCourant(rang: 1);
        Cache::flush();

        $cellule = $this->celluleDeVariation();

        $this->assertStringContainsString('&ndash;', $cellule, 'Le symbole discret manque.');
        $this->assertStringContainsString('aria-label="' . __('t_ingame.highscore.rank_movement_unavailable') . '"', $cellule);
        $this->assertStringNotContainsString(__('t_ingame.highscore.rank_movement_new'), $cellule, 'Un classement sans reference ne contient aucune entree neuve.');
        $this->assertStringNotContainsString('.gif', $cellule, 'Aucune fleche ne doit paraitre sans reference.');
    }

    /**
     * **La pagination ne perd pas la variation** : chaque page interroge la reference pour ses seules lignes.
     */
    public function testEachPageCarriesTheMovementOfItsOwnRows(): void
    {
        // Trois comptes qui se rendent vraiment. Un compte de fabrique nu n a pas de technologies, et la
        // requete de la page l ecarte AVANT de paginer : les pages se decaleraient, et l essai prouverait
        // le contraire de ce qu il annonce.
        $premier = $this->unJoueurComplet();
        $second = $this->unJoueurComplet();
        $this->poserLeClassement([$premier => 1, $this->currentUserId => 2, $second => 3]);

        $this->assertSame(RankReferenceOutcome::Published, $this->references->publishIfDue());

        // Il recule d une place, donc d une page : sa variation doit voyager avec lui.
        $this->poserLeClassement([$premier => 1, $second => 2, $this->currentUserId => 3]);
        Cache::flush();

        $service = resolve(HighscoreService::class);
        $service->setHighscoreType(0);

        $troisiemePage = $service->getHighscorePlayers(perPage: 1, pageOn: 3);
        $identifiants = array_map(static fn (array $ligne): int => (int)($ligne['id'] ?? 0), $troisiemePage);

        $this->assertContains($this->currentUserId, $identifiants, 'La troisieme page ne porte pas la ligne du joueur.');

        $ligne = $troisiemePage[array_search($this->currentUserId, $identifiants, true)];

        $this->assertSame(RankMovement::STATE_DOWN, $ligne['movement']['state']);
        $this->assertSame(1, $ligne['movement']['places']);

        // Et la premiere page ne porte pas cette ligne : chaque page interroge la reference pour les siennes.
        $premierePage = $service->getHighscorePlayers(perPage: 1, pageOn: 1);
        $this->assertNotContains(
            $this->currentUserId,
            array_map(static fn (array $autre): int => (int)($autre['id'] ?? 0), $premierePage),
        );
    }

    /**
     * **La tache des rangs publie la reference, et une seule fois par journee.** C est le raccordement reel : si
     * l appel manquait, tout le reste serait vrai et la colonne resterait vide a jamais.
     */
    public function testTheRankCommandPublishesTheReferenceOncePerDay(): void
    {
        $this->uneLigneDeClassementPourLeJoueurCourant(rang: 1);

        $this->travelTo(Date::parse('2026-09-22 00:03:00'));
        Artisan::call('ogamex:scheduler:generate-highscore-ranks');

        $apres = $this->etat();

        $this->assertSame('2026-09-22', $apres['published_day']);
        $this->assertNotNull($apres['published_at']);
        $this->assertSame(1, $apres['generation']);
        $this->assertNotSame([], $this->lignesDeReference(), 'La tache n a ecrit aucune ligne de reference.');

        $this->travelTo(Date::parse('2026-09-22 00:08:00'));
        Artisan::call('ogamex:scheduler:generate-highscore-ranks');

        $this->assertSame(1, $this->etat()['generation'], 'La tache a republie dans la meme journee.');
    }

    /**
     * Un compte que la page rend vraiment : technologies et corps celeste, comme un joueur inscrit.
     *
     * Le crochet du modele promeut administrateur le premier compte d une transaction ; un administrateur
     * serait ecarte du classement quand le reglage les cache, et l essai deviendrait dependant de ce reglage.
     */
    private function unJoueurComplet(): int
    {
        $compte = User::factory()->create(['username' => 'variation_' . Str::random(16)]);

        if ($compte->hasRole('admin')) {
            $compte->removeRole('admin');
            $compte->username = 'variation_' . Str::random(16);
            $compte->save();
        }

        resolve(InitialUserDataService::class)->createFor($compte);

        return $compte->id;
    }

    /**
     * Trois comptes classes 1, 2, 3 dans toutes les categories ordinaires.
     *
     * @return array<int, int> Les identifiants, dans l ordre des rangs.
     */
    private function unClassementDeTroisJoueurs(int $combien = 3): array
    {
        $joueurs = [];
        for ($index = 0; $index < $combien; $index++) {
            $joueurs[] = User::factory()->create()->id;
        }

        $rangs = [];
        foreach ($joueurs as $position => $joueur) {
            $rangs[$joueur] = $position + 1;
        }

        $this->poserLeClassement($rangs);

        return $joueurs;
    }

    /**
     * Ecrit un classement entier, en remplacant celui de la base : la publication exige une suite complete de 1 a N
     * sur toute la table, donc un essai qui ajouterait ses lignes a celles des voisines refuserait toujours.
     *
     * @param array<int, int> $rangs Identifiant de compte => rang.
     * @param array<int, int>|null $rangsMilitaires Pour eprouver qu une categorie ne suit pas une autre.
     */
    private function poserLeClassement(array $rangs, array|null $rangsMilitaires = null): void
    {
        DB::table('highscores')->delete();

        foreach ($rangs as $joueur => $rang) {
            $militaire = $rangsMilitaires[$joueur] ?? $rang;

            DB::table('highscores')->insert([
                'player_id' => $joueur,
                'general' => 10_000 - $rang,
                'economy' => 10_000 - $rang,
                'research' => 10_000 - $rang,
                'military' => 10_000 - $militaire,
                'honor' => 10_000 - $rang,
                'general_rank' => $rang,
                'economy_rank' => $rang,
                'research_rank' => $rang,
                'military_rank' => $militaire,
                'honor_rank' => $rang,
                'created_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);
        }
    }

    /**
     * Le joueur courant, seul classe : sa ligne est celle que les essais de page lisent.
     */
    private function uneLigneDeClassementPourLeJoueurCourant(int $rang): void
    {
        $lignes = [];
        for ($autre = 1; $autre < $rang; $autre++) {
            $lignes[User::factory()->create()->id] = $autre;
        }
        $lignes[$this->currentUserId] = $rang;

        $this->poserLeClassement($lignes);
    }

    /**
     * La cellule de variation de la ligne du joueur courant, dans le classement general.
     */
    private function celluleDeVariation(): string
    {
        $page = (string)$this->post('/ajax/highscore', ['category' => 1, 'type' => 0])->assertStatus(200)->getContent();

        $debut = strpos($page, 'id="position' . $this->currentUserId . '"');
        $this->assertNotFalse($debut, 'Le joueur n a pas de ligne dans ce classement.');

        $fin = strpos($page, '</tr>', $debut);
        $ligne = substr($page, $debut, $fin === false ? null : $fin - $debut);

        if (preg_match('#<td class="movement">(.*?)</td>#s', $ligne, $cellule) !== 1) {
            $this->fail('La ligne du joueur n a pas de cellule de variation.');
        }

        return $cellule[1];
    }

    /**
     * L etat de la reference, en valeurs typees : deux essais le comparent d un bloc pour etablir qu un
     * refus n a touche a rien.
     *
     * @return array{published_at: int|null, published_day: string|null, covered: string|null, generation: int}
     */
    private function etat(): array
    {
        $etat = DB::table('highscore_rank_reference_state')->where('id', 1)->first();

        $this->assertNotNull($etat, 'La ligne d etat de la reference est absente.');

        $ligne = (array)$etat;

        return [
            'published_at' => $this->unEntierOuRien($ligne['published_at'] ?? null),
            'published_day' => is_string($ligne['published_day'] ?? null) ? (string)$ligne['published_day'] : null,
            'covered' => is_string($ligne['covered'] ?? null) ? (string)$ligne['covered'] : null,
            'generation' => $this->unEntierOuRien($ligne['generation'] ?? null) ?? 0,
        ];
    }

    /**
     * Le pilote rend un entier ou une chaine de chiffres selon le moteur ; un cast seul accepterait
     * n importe quoi.
     */
    private function unEntierOuRien(mixed $valeur): int|null
    {
        if (is_int($valeur)) {
            return $valeur;
        }

        if (is_string($valeur) && $valeur !== '' && ctype_digit($valeur)) {
            return (int)$valeur;
        }

        return null;
    }

    /**
     * @return array<int, object>
     */
    private function lignesDeReference(): array
    {
        return DB::table('highscore_rank_references')
            ->orderBy('scope')
            ->orderBy('type')
            ->orderBy('subject_id')
            ->get()
            ->all();
    }
}
