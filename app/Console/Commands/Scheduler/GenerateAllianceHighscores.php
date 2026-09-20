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
                // **Les trois cumuls militaires ne sont pas sommes ici** : `MilitaryTallyPublisher` les publie avec leurs
                // rangs, depuis un seul etat agrege, et additionne lui aussi les membres actuels.
                $memberScores = Highscore::query()
                    ->join('users', 'highscores.player_id', '=', 'users.id')
                    ->where('users.alliance_id', $alliance->id)
                    ->selectRaw('
                        COALESCE(SUM(highscores.general), 0) as total_general,
                        COALESCE(SUM(highscores.economy), 0) as total_economy,
                        COALESCE(SUM(highscores.research), 0) as total_research,
                        COALESCE(SUM(highscores.military), 0) as total_military,
                        COALESCE(SUM(highscores.honor), 0) as total_honor,
                        COALESCE(SUM(highscores.lifeform_economy), 0) as total_lifeform_economy,
                        COALESCE(SUM(highscores.lifeform_technology), 0) as total_lifeform_technology,
                        COALESCE(SUM(highscores.lifeform), 0) as total_lifeform
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
                        // Les trois classements des formes de vie s additionnent comme les autres : la somme
                        // des membres actuels. `lifeform` est deja la somme des deux autres chez chaque joueur.
                        'lifeform_economy' => $memberScores->total_lifeform_economy ?? 0,
                        'lifeform_technology' => $memberScores->total_lifeform_technology ?? 0,
                        'lifeform' => $memberScores->total_lifeform ?? 0,
                    ]
                );

                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nAlliance highscores generated successfully!");
    }
}
