<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use OGame\Models\Planet\Coordinate;
use OGame\Models\WreckField;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use Tests\AccountTestCase;

/**
 * Le deblocage de l'epave suivante : quand la reparation en cours se termine vraiment, et seulement alors.
 *
 * ## La regle
 *
 * Une seule epave d'un proprietaire est en reparation a la fois a une position ; une bataille survenue pendant une
 * reparation laisse une epave **bloquee**, qui attend. Avant cette tranche, seul un brulage (et `completeRepairs()`,
 * sans appelant) debloquait la suivante : la recuperation finale et le deploiement automatique effacaient l'epave en
 * reparation sans rien liberer, et l'epave suivante restait bloquee jusqu'a ce que le joueur la lance lui-meme.
 *
 * Desormais : une **recuperation finale** (plus rien ne reste) et un **deploiement automatique** activent la plus
 * ancienne epave bloquee, **dans la meme transaction** que l'effacement ; une **recuperation partielle** ne libere
 * rien ; et le deblocage n'active jamais une epave tant qu'une autre est encore en reparation — une garde que les
 * etats du jeu n'atteignent pas par eux-memes, verifiee ici directement.
 */
class WreckFieldUnblockingTest extends AccountTestCase
{
    private const int SHIPS = 40;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wreckFieldsHere()->delete();
        DB::table('planets')->where('id', $this->planetService->getPlanetId())->update(['space_dock' => 1]);
    }

    protected function tearDown(): void
    {
        $this->wreckFieldsHere()->delete();
        parent::tearDown();
    }

    public function testAFinalCollectionActivatesTheOldestBlockedWreckAndOnlyIt(): void
    {
        $terminee = $this->aCompletedWreck();
        $plusAncienne = $this->aBlockedWreck(2);
        $plusRecente = $this->aBlockedWreck(1);
        $avant = $this->lightFightersOnThePlanet();

        $reponse = $this->postJson(route('facilities.completerepairs'));

        $reponse->assertStatus(200);
        $reponse->assertJson(['success' => true]);
        $this->assertSame($avant + self::SHIPS, $this->lightFightersOnThePlanet(), 'The repaired ships did not all reach the planet.');
        $this->assertSame('', $this->statusOf($terminee), 'The collected wreck should be gone.');
        $this->assertSame('active', $this->statusOf($plusAncienne), 'The oldest blocked wreck was not activated by the final collection.');
        $this->assertSame('blocked', $this->statusOf($plusRecente), 'More than one blocked wreck was activated.');
    }

    public function testAPartialCollectionKeepsTheNextWreckBlocked(): void
    {
        $enCours = $this->aHalfRepairedWreck();
        $suivante = $this->aBlockedWreck(1);
        $avant = $this->lightFightersOnThePlanet();

        $reponse = $this->postJson(route('facilities.completerepairs'));

        $reponse->assertStatus(200);
        $reponse->assertJson(['success' => true]);
        $this->assertSame($avant + self::SHIPS / 2, $this->lightFightersOnThePlanet(), 'A half-repaired wreck should hand over half its ships.');
        $this->assertSame('repairing', $this->statusOf($enCours), 'The partially collected wreck should still be repairing.');
        $this->assertSame(self::SHIPS / 2, $this->shipsIn($enCours), 'The remaining ships should stay in the wreck.');
        $this->assertSame('blocked', $this->statusOf($suivante), 'A partial collection released the next wreck prematurely.');
    }

    public function testAnAutoDeploymentActivatesTheNextBlockedWreck(): void
    {
        $echue = $this->anOverdueRepairingWreck();
        $suivante = $this->aBlockedWreck(1);
        $avant = $this->lightFightersOnThePlanet();

        $issue = $this->aService()->autoDeployWreckFieldAtomic($echue->id);

        $this->assertIsArray($issue);
        $this->assertSame(self::SHIPS, $issue['total_deployed']);
        $this->assertSame($avant + self::SHIPS, $this->lightFightersOnThePlanet());
        $this->assertSame('', $this->statusOf($echue), 'The auto-deployed wreck should be gone.');
        $this->assertSame('active', $this->statusOf($suivante), 'The auto-deployment did not activate the next blocked wreck.');
    }

    public function testUnblockingNeverActivatesAWreckWhileAnotherIsStillRepairing(): void
    {
        $enCours = $this->aHalfRepairedWreck();
        $suivante = $this->aBlockedWreck(1);
        $service = $this->aService();

        $service->unblockNextWreckField($this->here(), $this->currentUserId);
        $this->assertSame('blocked', $this->statusOf($suivante), 'A blocked wreck was activated while another was still repairing: two repairs at once.');

        DB::table('wreck_fields')->where('id', $enCours->id)->delete();
        $service->unblockNextWreckField($this->here(), $this->currentUserId);
        $this->assertSame('active', $this->statusOf($suivante), 'Once nothing is repairing, the blocked wreck should be activated.');
    }

    /**
     * Le deblocage s'ecrit dans la transaction de la recuperation : une panne apres l'effacement ne laisserait pas la
     * suivante bloquee pour toujours, ni une suivante active a cote d'une epave encore la.
     */
    public function testTheUnblockingIsWrittenInsideTheCollectionTransaction(): void
    {
        $this->aCompletedWreck();
        $suivante = $this->aBlockedWreck(1);

        $niveaux = [];
        DB::listen(static function (QueryExecuted $requete) use (&$niveaux): void {
            $sql = str_replace('`', '"', $requete->sql);
            if (str_starts_with($sql, 'update "wreck_fields"') && in_array('active', $requete->bindings, true)) {
                $niveaux[] = DB::transactionLevel();
            }
        });

        $this->postJson(route('facilities.completerepairs'))->assertStatus(200);

        $this->assertSame('active', $this->statusOf($suivante));
        $this->assertCount(1, $niveaux, 'The activation of the next wreck was written once, or not at all: ' . count($niveaux) . ' write(s).');
        $this->assertGreaterThanOrEqual(1, $niveaux[0], 'The next wreck was activated outside any transaction.');
    }

    private function aService(): WreckFieldService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);

        return new WreckFieldService($joueur, resolve(SettingsService::class));
    }

    private function aCompletedWreck(): WreckField
    {
        return $this->aWreck([
            'status' => 'completed',
            'created_at' => now()->subHours(3),
            'repair_started_at' => now()->subHours(2),
            'repair_completed_at' => now()->subHour(),
            'space_dock_level' => 1,
            'ship_data' => [['machine_name' => 'light_fighter', 'quantity' => self::SHIPS, 'repair_progress' => 100]],
        ]);
    }

    /**
     * En reparation depuis une heure, pour une heure encore : la moitie des vaisseaux est prete.
     */
    private function aHalfRepairedWreck(): WreckField
    {
        return $this->aWreck([
            'status' => 'repairing',
            'created_at' => now()->subHours(3),
            'repair_started_at' => now()->subHour(),
            'repair_completed_at' => now()->addHour(),
            'space_dock_level' => 1,
            'ship_data' => [['machine_name' => 'light_fighter', 'quantity' => self::SHIPS, 'repair_progress' => 0]],
        ]);
    }

    private function anOverdueRepairingWreck(): WreckField
    {
        return $this->aWreck([
            'status' => 'repairing',
            'created_at' => now()->subHours(80),
            'repair_started_at' => now()->subHours(73),
            'repair_completed_at' => now()->subHours(72),
            'space_dock_level' => 1,
            'ship_data' => [['machine_name' => 'light_fighter', 'quantity' => self::SHIPS, 'repair_progress' => 0]],
        ]);
    }

    private function aBlockedWreck(int $ageEnHeures): WreckField
    {
        return $this->aWreck([
            'status' => 'blocked',
            'created_at' => now()->subHours($ageEnHeures),
            'ship_data' => [['machine_name' => 'light_fighter', 'quantity' => 5, 'repair_progress' => 0]],
        ]);
    }

    /**
     * @param array<string, mixed> $etat
     */
    private function aWreck(array $etat): WreckField
    {
        $ici = $this->here();

        /** @var WreckField $champ */
        $champ = WreckField::factory()->create(array_merge([
            'galaxy' => $ici->galaxy,
            'system' => $ici->system,
            'planet' => $ici->position,
            'owner_player_id' => $this->currentUserId,
            'expires_at' => now()->addHours(72),
        ], $etat));

        return $champ;
    }

    private function lightFightersOnThePlanet(): int
    {
        return (int)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('light_fighter');
    }

    private function shipsIn(WreckField $champ): int
    {
        $frais = WreckField::query()->find($champ->id);

        return $frais === null ? 0 : $frais->getTotalShips();
    }

    private function statusOf(WreckField $champ): string
    {
        return (string)DB::table('wreck_fields')->where('id', $champ->id)->value('status');
    }

    private function here(): Coordinate
    {
        return $this->planetService->getPlanetCoordinates();
    }

    /**
     * @return Builder<WreckField>
     */
    private function wreckFieldsHere()
    {
        $ici = $this->here();

        return WreckField::query()->where('galaxy', $ici->galaxy)->where('system', $ici->system)->where('planet', $ici->position);
    }
}
