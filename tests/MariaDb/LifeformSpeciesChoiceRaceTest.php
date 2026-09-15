<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Species;
use OGame\Models\Planet;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;
use Throwable;

/**
 * Deux choix d espece reellement simultanes pour un meme compte : **une seule espece, une seule
 * ligne par planete**.
 *
 * `LifeformInstallationService::chooseSpecies()` tient la ligne du compte et laisse la contrainte
 * unique de `lifeform_accounts.user_id` trancher ce qu un `exists()` laisserait passer. Sous SQLite
 * `lockForUpdate()` ne compile a rien : seul le bac montre la course.
 */
#[Group('mariadb')]
final class LifeformSpeciesChoiceRaceTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        resolve(SettingsService::class)->set('lifeforms_enabled', '0');
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        DB::table('lifeform_planets')->whereIn('planet_id', $planetes)->delete();
        DB::table('lifeform_species_progress')->where('user_id', $this->currentUserId)->delete();
        DB::table('lifeform_accounts')->where('user_id', $this->currentUserId)->delete();
        parent::tearDown();
    }

    public function testTwoSimultaneousChoicesLeaveOneSpeciesAndOneRowPerPlanet(): void
    {
        $utilisateur = $this->currentUserId;
        $maintenant = (int)now()->timestamp;

        $issues = $this->inParallel(2, static function (int $rang) use ($utilisateur, $maintenant): string {
            $espece = $rang === 0 ? Species::Humans : Species::Rocktal;
            try {
                resolve(LifeformInstallationService::class)->chooseSpecies($utilisateur, $espece, $maintenant);

                return 'choisi:' . $espece->name;
            } catch (LifeformRefused $refus) {
                return 'refuse:' . $refus->reason;
            } catch (Throwable $e) {
                return 'erreur:' . $e->getMessage();
            }
        });

        $choisis = array_values(array_filter($issues, static fn (string $i): bool => str_starts_with($i, 'choisi:')));
        $refuses = array_values(array_filter($issues, static fn (string $i): bool => $i === 'refuse:' . LifeformRefused::ALREADY_CHOSEN));
        $this->assertCount(1, $choisis, implode(' | ', $issues));
        $this->assertCount(1, $refuses, 'Le perdant est refuse « deja choisi », jamais autre chose : ' . implode(' | ', $issues));

        $this->assertSame(1, DB::table('lifeform_accounts')->where('user_id', $utilisateur)->count());
        $especeChoisie = (int)DB::table('lifeform_accounts')->where('user_id', $utilisateur)->value('species');
        $this->assertSame('choisi:' . Species::from($especeChoisie)->name, $choisis[0]);

        $planetes = Planet::query()->where('user_id', $utilisateur)->where('planet_type', 1)->pluck('id')->all();
        $this->assertNotSame([], $planetes);
        foreach ($planetes as $planetId) {
            $this->assertSame(1, DB::table('lifeform_planets')->where('planet_id', $planetId)->count(), "Planete $planetId : une ligne, exactement.");
            $this->assertSame($especeChoisie, (int)DB::table('lifeform_planets')->where('planet_id', $planetId)->value('species'));
        }
        $this->assertSame(1, DB::table('lifeform_species_progress')->where('user_id', $utilisateur)->count());
    }
}
