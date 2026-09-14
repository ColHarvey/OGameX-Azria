<?php

namespace Tests\Feature\Combat;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * **Une fermeture immédiate qui ne ferme pas dit pourquoi, au moment où elle le décide.**
 *
 * L'ouverture d'un combat tente de fermer tout de suite, pour qu'une fenêtre nulle ne retienne pas le corps une
 * minute. Son issue était jetée : un combat resté en ralliement — l'échec intermittent du 14 septembre 2026 — ne
 * disait plus ni l'instant de la tentative, ni l'échéance trouvée, ni la raison. L'ouverture la journalise désormais :
 * en information quand la fenêtre court, en avertissement quand une fenêtre nulle ne ferme pas.
 *
 * Le second cas — une fenêtre nulle qui ne ferme pas — n'est atteint par aucun scénario ordinaire : c'est une anomalie.
 * Cet essai tient le premier, qui éprouve la même composition du journal.
 */
final class RallyOpeningClosureOutcomeTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

    public function testAnOpeningWhoseWindowRunsLogsTheOutcomeWithTheInstantAndTheDeadlineItRead(): void
    {
        [$ouvreuse, $cible, $ouverture] = $this->aRallyAboutToOpen();

        // Le montage ci-dessus a reconstruit le conteneur en envoyant les flottes : l'écoute se pose après lui.
        $journal = new class () {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $lignes = [];
        };
        Event::listen(MessageLogged::class, static function (MessageLogged $message) use ($journal): void {
            $journal->lignes[] = ['level' => $message->level, 'message' => $message->message, 'context' => $message->context];
        });

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);

        $echeance = (int)DB::table('celestial_body_combat_barriers')->where('combat_instance_id', $combat->id)->value('owned_through_effect_at');
        $this->assertGreaterThan($ouverture, $echeance, 'Prémisse : la seconde vague tient la fenêtre ouverte.');

        $lignes = array_values(array_filter($journal->lignes, static fn (array $ligne): bool => ($ligne['context']['combat'] ?? null) === $combat->id));
        $this->assertCount(1, $lignes, 'L’ouverture n’a pas dit, une fois, l’issue de sa fermeture immédiate.');

        [$ligne] = $lignes;
        $this->assertSame('info', $ligne['level'], 'Une fenêtre qui court est ordinaire : elle ne s’écrit pas en avertissement.');
        $this->assertSame([
            'combat' => $combat->id,
            'corps' => $cible,
            'ouverture' => $ouverture,
            'echeance' => $echeance,
            'issue' => 'trop tot',
            'detail' => 'maintenant ' . $ouverture . ', echeance ' . $echeance,
        ], $ligne['context'], 'Le journal ne garde pas l’état qui permet de relire la fermeture.');
    }
}
