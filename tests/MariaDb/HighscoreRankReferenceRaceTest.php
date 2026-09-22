<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Highscore\RankReferenceOutcome;
use OGame\Highscore\RankReferencePublisher;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;
use Throwable;

/**
 * Deux generateurs de classement reellement simultanes : **une seule reference publiee pour la journee**.
 *
 * `RankReferencePublisher::publishIfDue()` prend la ligne d etat en `lockForUpdate()` avant toute lecture ;
 * le second attend, relit `published_day`, constate que la journee est faite et ne publie rien. Sous SQLite
 * `lockForUpdate()` ne compile a rien : les deux verraient la journee due, les deux publieraient, et la
 * variation d une journee entiere serait calculee depuis la photographie du perdant. Seul le bac le montre.
 *
 * Le montage renumerote les classements existants en une suite complete de 1 a N — la base du bac est
 * partagee, et la publication refuse par construction un classement troue. Les lignes hors classement
 * (rang nul) et les categories jamais publiees (rang absent) sont laissees telles quelles : ce sont
 * precisement les deux cas que la publication doit continuer d ecarter.
 */
#[Group('mariadb')]
final class HighscoreRankReferenceRaceTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();

        $this->renumeroterLesClassements();

        DB::table('highscore_rank_references')->delete();
        DB::table('highscore_rank_reference_state')->where('id', 1)->update([
            'published_at' => null,
            'published_day' => null,
            'covered' => null,
            'generation' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::table('highscore_rank_references')->delete();
        DB::table('highscore_rank_reference_state')->where('id', 1)->update([
            'published_at' => null,
            'published_day' => null,
            'covered' => null,
            'generation' => 0,
        ]);

        parent::tearDown();
    }

    /**
     * **Deux publications simultanees : une seule passe, l autre constate la journee faite.**
     *
     * L assertion porte sur la generation, pas seulement sur les issues rendues : deux publications
     * successives rendraient toutes deux `Published` sans que rien ne le dise, et la seconde ecraserait
     * la photographie de la premiere.
     */
    public function testTwoSimultaneousGeneratorsPublishTheReferenceOnlyOnce(): void
    {
        $issues = $this->inParallel(2, static function (int $rang): string {
            try {
                return 'issue:' . resolve(RankReferencePublisher::class)->publishIfDue()->name;
            } catch (Throwable $e) {
                return 'erreur:' . $e->getMessage();
            }
        });

        $publications = array_values(array_filter($issues, static fn (string $i): bool => $i === 'issue:' . RankReferenceOutcome::Published->name));
        $abstentions = array_values(array_filter($issues, static fn (string $i): bool => $i === 'issue:' . RankReferenceOutcome::NotDue->name));

        $this->assertCount(1, $publications, 'Une seule publication : ' . implode(' | ', $issues));
        $this->assertCount(1, $abstentions, 'Le perdant constate la journee faite, il n echoue pas : ' . implode(' | ', $issues));

        $etat = DB::table('highscore_rank_reference_state')->where('id', 1)->first();

        $this->assertNotNull($etat);
        $this->assertSame(1, (int)$etat->generation, 'La reference a ete publiee deux fois.');
        $this->assertNotNull($etat->published_at);
        $this->assertSame(now()->format('Y-m-d'), (string)$etat->published_day);

        // Et la photographie est bien celle du classement, pas une table a moitie remplie.
        $this->assertGreaterThan(0, DB::table('highscore_rank_references')->count());
        $this->assertSame(
            DB::table('highscores')->where('general_rank', '>', 0)->count(),
            DB::table('highscore_rank_references')->where('scope', RankReferencePublisher::SCOPE_PLAYER)->where('type', HighscoreTypeEnum::general->value)->count(),
            'La reference du classement general ne couvre pas exactement les joueurs classes.',
        );
    }

    /**
     * **Une seconde course le lendemain republie une fois, et une seule.** La rotation suit la journee
     * serveur : sans cela, un verrou qui tiendrait pour toujours passerait aussi cet essai.
     */
    public function testTheNextDayTheRaceHappensAgainAndStillPublishesOnce(): void
    {
        $this->assertSame(RankReferenceOutcome::Published, resolve(RankReferencePublisher::class)->publishIfDue());

        // La veille : la journee courante redevient due, sans toucher aux lignes deja photographiees.
        DB::table('highscore_rank_reference_state')->where('id', 1)->update([
            'published_day' => now()->subDay()->format('Y-m-d'),
        ]);

        $issues = $this->inParallel(2, static function (int $rang): string {
            try {
                return 'issue:' . resolve(RankReferencePublisher::class)->publishIfDue()->name;
            } catch (Throwable $e) {
                return 'erreur:' . $e->getMessage();
            }
        });

        $publications = array_values(array_filter($issues, static fn (string $i): bool => $i === 'issue:' . RankReferenceOutcome::Published->name));

        $this->assertCount(1, $publications, 'Une seule publication le lendemain : ' . implode(' | ', $issues));
        $this->assertSame(2, (int)DB::table('highscore_rank_reference_state')->where('id', 1)->value('generation'));
    }

    /**
     * Renumerote chaque categorie deja classee en une suite complete de 1 a N, par score decroissant.
     *
     * Les rangs nuls (compte systeme, PNJ, administrateur cache) et les categories sans aucun rang
     * restent tels quels.
     */
    private function renumeroterLesClassements(): void
    {
        foreach ([['highscores', 'id'], ['alliance_highscores', 'id']] as [$table, $clef]) {
            $colonnes = Schema::getColumnListing($table);

            foreach (HighscoreTypeEnum::cases() as $type) {
                $colonne = $type->name . '_rank';

                if (!in_array($colonne, $colonnes, true) || !in_array($type->name, $colonnes, true)) {
                    continue;
                }

                $classes = DB::table($table)
                    ->where($colonne, '>', 0)
                    ->orderByDesc($type->name)
                    ->orderBy($clef)
                    ->pluck($clef);

                $rang = 0;
                foreach ($classes as $identifiant) {
                    DB::table($table)->where($clef, $identifiant)->update([$colonne => ++$rang]);
                }
            }
        }
    }
}
