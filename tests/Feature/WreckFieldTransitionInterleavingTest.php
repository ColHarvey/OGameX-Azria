<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use OGame\Models\Planet\Coordinate;
use OGame\Models\WreckField;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use Tests\AccountTestCase;

/**
 * Verifier puis ecrire, sans transaction ni verrou : deux commandes qui se croisent sur la meme epave.
 *
 * ## Le mecanisme, joue pas a pas
 *
 * `startRepairs()` et `burnWreckField()` lisaient l'etat du modele charge, decidaient, puis sauvaient. Entre la lecture
 * et l'ecriture, une autre commande a pu changer l'etat : la seconde ecrivait alors sur une decision perimee — une epave
 * brulee revenait en reparation, des vaisseaux en reparation etaient brules, un second demarrage remettait le minuteur
 * a zero. Ici l'entrelacement est joue en deux etapes, dans un seul processus, sur SQLite : c'est le **mecanisme** qui
 * est reproduit, pas la course. Deux acteurs qui attendent l'un sur l'autre ne se jouent que sur MariaDB
 * (`tests/MariaDb/WreckFieldTransitionRaceTest.php`).
 *
 * Ce que ce banc exige est le meme invariant que la course : **une transition se decide sur l'etat relu au moment
 * d'ecrire**, jamais sur celui du modele en memoire. Chaque commande est un service a part, comme deux requetes.
 */
class WreckFieldTransitionInterleavingTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->wreckFieldsHere()->delete();
    }

    protected function tearDown(): void
    {
        $this->wreckFieldsHere()->delete();
        parent::tearDown();
    }

    public function testAStartDecidedOnAWreckBurnedInTheMeantimeIsRefused(): void
    {
        $champ = $this->anActiveWreck();
        $demarrage = $this->aCommandOn($champ);
        $brulage = $this->aCommandOn($champ);

        $this->assertTrue($brulage->burnWreckField());

        $refus = $this->refusalOf(static fn () => $demarrage->startRepairs(1));
        $this->assertNotNull($refus, 'A start decided on a wreck burned in the meantime was written: the burned wreck came back as repairing.');
        $this->assertSame('Wreck field cannot be repaired', $refus->getMessage());

        $this->assertSame('burned', $this->statusOf($champ));
        $this->assertNull(DB::table('wreck_fields')->where('id', $champ->id)->value('repair_started_at'), 'A burned wreck carries a repair timer.');
    }

    public function testABurnDecidedOnAWreckWhoseRepairsStartedInTheMeantimeIsRefused(): void
    {
        $champ = $this->anActiveWreck();
        $demarrage = $this->aCommandOn($champ);
        $brulage = $this->aCommandOn($champ);

        $this->assertTrue($demarrage->startRepairs(1));

        $refus = $this->refusalOf(static fn () => $brulage->burnWreckField());
        $this->assertNotNull($refus, 'A burn decided on a wreck whose repairs started in the meantime was written: ships under repair were burned.');
        $this->assertSame('Wreck field cannot be burned while repairs are in progress', $refus->getMessage());

        $this->assertSame('repairing', $this->statusOf($champ));
    }

    public function testASecondStartDecidedBeforeTheFirstWasWrittenDoesNotRestartTheTimer(): void
    {
        $champ = $this->anActiveWreck();
        $premier = $this->aCommandOn($champ);
        $second = $this->aCommandOn($champ);

        $this->assertTrue($premier->startRepairs(1));
        $debut = (string)DB::table('wreck_fields')->where('id', $champ->id)->value('repair_started_at');
        $this->travel(10)->minutes();

        $refus = $this->refusalOf(static fn () => $second->startRepairs(1));
        $this->assertNotNull($refus, 'A second start on a wreck already under repair was written: the timer restarted.');
        $this->assertSame('Wreck field cannot be repaired', $refus->getMessage());

        $this->assertSame($debut, (string)DB::table('wreck_fields')->where('id', $champ->id)->value('repair_started_at'), 'The repair timer moved: the second start was written over the first.');
    }

    /**
     * L'exception que la commande leve, ou `null` si elle a ete ecrite.
     *
     * Le refus est rendu plutot qu'attendu par `expectException()` : l'etat final se lit ensuite, et un `fail()` dans
     * un `try` serait avale par le `catch` qui attend l'exception du service.
     *
     * @param callable(): mixed $commande
     */
    private function refusalOf(callable $commande): Exception|null
    {
        try {
            $commande();
        } catch (Exception $refus) {
            return $refus;
        }

        return null;
    }

    /**
     * Une commande : un service neuf, l'epave chargee — l'etat de son modele est celui de cet instant.
     */
    private function aCommandOn(WreckField $champ): WreckFieldService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $commande = new WreckFieldService($joueur, resolve(SettingsService::class));
        $this->assertTrue($commande->loadForCoordinates($this->here()), 'The command could not load the wreck.');
        $charge = $commande->getWreckField();
        $this->assertNotNull($charge);
        $this->assertSame($champ->id, $charge->id, 'The command loaded another wreck than the one of this bench.');

        return $commande;
    }

    private function anActiveWreck(): WreckField
    {
        $ici = $this->here();

        /** @var WreckField $champ */
        $champ = WreckField::factory()->create([
            'galaxy' => $ici->galaxy,
            'system' => $ici->system,
            'planet' => $ici->position,
            'owner_player_id' => $this->currentUserId,
            'status' => 'active',
            'created_at' => now()->subHour(),
            'expires_at' => now()->addHours(72),
            'ship_data' => [
                ['machine_name' => 'light_fighter', 'quantity' => 10, 'repair_progress' => 0],
            ],
        ]);

        return $champ;
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
