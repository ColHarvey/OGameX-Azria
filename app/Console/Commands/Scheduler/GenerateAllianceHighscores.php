<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Models\Alliance;
use OGame\Models\AllianceHighscore;
use OGame\Models\Highscore;

#[Description('Generates Alliance Highscore data by aggregating member scores')]
#[Signature('ogamex:scheduler:generate-alliance-highscores')]
class GenerateAllianceHighscores extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Generating alliance highscores...');

        $alliances = Alliance::query();
        $bar = $this->output->createProgressBar();
        $bar->start($alliances->count());

        $alliances->chunk(50, function ($allianceChunk) use (&$bar) {
            foreach ($allianceChunk as $alliance) {
                // Aggregate scores from all alliance members.
                //
                // **La somme suit les membres actuels.** Elle peut donc diminuer quand un membre part, meme pour les trois
                // cumuls militaires, dont les compteurs de chaque joueur ne redescendent jamais ; la page le dit.
                $memberScores = Highscore::query()
                    ->join('users', 'highscores.player_id', '=', 'users.id')
                    ->where('users.alliance_id', $alliance->id)
                    ->selectRaw('
                        COALESCE(SUM(highscores.general), 0) as total_general,
                        COALESCE(SUM(highscores.economy), 0) as total_economy,
                        COALESCE(SUM(highscores.research), 0) as total_research,
                        COALESCE(SUM(highscores.military), 0) as total_military,
                        COALESCE(SUM(highscores.honor), 0) as total_honor,
                        COALESCE(SUM(highscores.military_built), 0) as total_military_built,
                        COALESCE(SUM(highscores.military_destroyed), 0) as total_military_destroyed,
                        COALESCE(SUM(highscores.military_lost), 0) as total_military_lost
                    ')
                    ->first();

                // Update or create alliance highscore record
                AllianceHighscore::updateOrCreate(
                    ['alliance_id' => $alliance->id],
                    [
                        'general' => $memberScores->total_general ?? 0,
                        'economy' => $memberScores->total_economy ?? 0,
                        'research' => $memberScores->total_research ?? 0,
                        'military' => $memberScores->total_military ?? 0,
                        'honor' => $memberScores->total_honor ?? 0,
                        'military_built' => $memberScores->total_military_built ?? 0,
                        'military_destroyed' => $memberScores->total_military_destroyed ?? 0,
                        'military_lost' => $memberScores->total_military_lost ?? 0,
                    ]
                );

                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nAlliance highscores generated successfully!");
    }
}
