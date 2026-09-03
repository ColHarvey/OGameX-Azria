<?php

use OGame\Console\Commands\Combat\AdvancePersistentCombats;
use OGame\Console\Commands\Npc\NpcTick;
use OGame\Console\Commands\Scheduler\CleanupDestroyedPlanets;
use OGame\Console\Commands\Scheduler\CleanupWreckFields;
use OGame\Console\Commands\Scheduler\DarkMatterRegenerateCommand;
use OGame\Console\Commands\Scheduler\DeleteOldMessages;
use OGame\Console\Commands\Scheduler\GenerateAllianceHighscores;
use OGame\Console\Commands\Scheduler\GenerateHighscoreRanks;
use OGame\Console\Commands\Scheduler\GenerateHighscores;
use OGame\Console\Commands\Scheduler\ResetDebrisFields;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Schedule::command(GenerateHighscores::class)->everyFiveMinutes();
// Alliance highscores should run after player highscores since they depend on them
Schedule::command(GenerateAllianceHighscores::class)->everyFiveMinutes();
// Generates ranks for both player and alliance highscores
Schedule::command(GenerateHighscoreRanks::class)->everyFiveMinutes();

// Reset empty debris fields weekly on Monday at 1:00 AM
Schedule::command(ResetDebrisFields::class)->weeklyOn(1, '1:00');

// Clean up wreck fields hourly
Schedule::command(CleanupWreckFields::class)->hourly()->withoutOverlapping();

// Delete messages once they have aged out of the seven-day retention window
Schedule::command(DeleteOldMessages::class)->hourly()->withoutOverlapping();

// Permanently delete destroyed planets/moons flagged for at least 24 hours (official 3:00 cycle)
Schedule::command(CleanupDestroyedPlanets::class)->dailyAt('03:00')->withoutOverlapping();

// Process Dark Matter regeneration every 5 minutes
Schedule::command(DarkMatterRegenerateCommand::class)->everyFiveMinutes()->withoutOverlapping();

// Combats durables : fermer les ralliements echus, regler les combats termines.
// Sans effet tant qu'aucune attaque n'ouvre de combat durable — la table reste vide, et le
// passage ne fait rien. La minute est la granularite du systeme : une bataille se termine a
// l'echeance calculee a sa cloture, et l'attente ne doit pas s'y ajouter.
Schedule::command(AdvancePersistentCombats::class)->everyMinute()->withoutOverlapping();

// Factions hostiles : croissance des bases, releve des bases detruites, decision de raid.
// Sans effet tant que npc_enabled est a non, et n envoie aucune flotte tant que
// npc_simulation est a oui.
Schedule::command(NpcTick::class)->everyFifteenMinutes()->withoutOverlapping();
