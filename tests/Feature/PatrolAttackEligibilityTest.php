<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Models\Patrol;
use OGame\Models\Planet;
use OGame\Models\SurveillanceContact;
use OGame\Models\User;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\PatrolAttackEligibility;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Qui peut viser une patrouille, et ce que le refus a le droit de dire.
 *
 * ## Le monde est fabrique, jamais emprunte
 *
 * La base d un processus est partagee : un corps ou une patrouille laisses par un voisin
 * rendraient ces temoins dependants de l ordre d execution. Chacun pose ce qu il exige et le
 * retire.
 */
class PatrolAttackEligibilityTest extends AccountTestCase
{
    /** @var array<int, int> */
    private array $corpsPoses = [];

    /** @var array<int, int> */
    private array $patrouillesPosees = [];

    protected function tearDown(): void
    {
        if ($this->patrouillesPosees !== []) {
            SurveillanceContact::query()->whereIn('patrol_id', $this->patrouillesPosees)->delete();
            Patrol::query()->whereIn('id', $this->patrouillesPosees)->delete();
            $this->patrouillesPosees = [];
        }

        if ($this->corpsPoses !== []) {
            SurveillanceContact::query()->whereIn('observer_planet_id', $this->corpsPoses)->delete();
            Planet::query()->whereIn('id', $this->corpsPoses)->delete();
            $this->corpsPoses = [];
        }

        resolve(SettingsService::class)->set('patrols_enabled', 0);

        parent::tearDown();
    }

    private function armer(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 1);
    }

    private function porte(): PatrolAttackEligibility
    {
        return resolve(PatrolAttackEligibility::class);
    }

    /**
     * Un joueur qui n est ni celui de l essai ni le compte systeme.
     */
    private function unEtranger(): int
    {
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();

        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        return $proprietaire->getId();
    }

    private function unePatrouille(int $userId, PatrolState $etat = PatrolState::Stationed): Patrol
    {
        $patrouille = Patrol::query()->create([
            'user_id' => $userId,
            'home_planet_id' => null,
            'state' => $etat->value,
            'galaxy' => 1,
            'system' => 1,
            'x' => $etat->isParked() ? 620 : null,
            'y' => $etat->isParked() ? 480 : null,
            'fuel_reserve' => 1000,
            'order_version' => 1,
        ]);

        $this->patrouillesPosees[] = (int)$patrouille->id;

        return $patrouille;
    }

    /**
     * Un contact du joueur de l essai sur cette patrouille.
     *
     * Les contacts sont ecrits directement : ce qui est juge ici est la porte de l attaque, pas la
     * veille qui les ouvre — elle a ses propres temoins.
     */
    private function unContact(Patrol $cible, int $visibleDepuis, int|null $revoqueLe = null): void
    {
        $corps = Planet::factory()->create([
            'user_id' => $this->currentUserId,
            'galaxy' => 9,
            'system' => 499,
            'planet' => count($this->corpsPoses) + 1,
            'surveillance_network' => 2,
        ]);

        $this->corpsPoses[] = (int)$corps->id;

        SurveillanceContact::query()->create([
            'observer_planet_id' => (int)$corps->id,
            'observer_user_id' => $this->currentUserId,
            'patrol_id' => (int)$cible->id,
            'entered_system_at' => $visibleDepuis,
            'acquisition_from' => $visibleDepuis,
            'visible_from' => $visibleDepuis,
            'revoked_at' => $revoqueLe,
        ]);
    }

    public function testUnePatrouilleDetecteeEtPoseeEstUneCible(): void
    {
        $this->armer();
        $cible = $this->unePatrouille($this->unEtranger());
        $this->unContact($cible, 1000);

        $gelee = $this->porte()->frozenTargetFor($this->currentUserId, $cible, 2000);

        $this->assertSame((int)$cible->id, $gelee->patrolId);
        $this->assertSame((int)$cible->user_id, $gelee->ownerId);
        $this->assertSame(620, $gelee->point()->x);
        $this->assertSame(480, $gelee->point()->y);
    }

    public function testLInterrupteurEteintRefuseTout(): void
    {
        $cible = $this->unePatrouille($this->unEtranger());
        $this->unContact($cible, 1000);

        $this->attendreLeRefus('disabled', $cible);
    }

    public function testOnNAttaquePasSaProprePatrouille(): void
    {
        $this->armer();
        $cible = $this->unePatrouille($this->currentUserId);
        $this->unContact($cible, 1000);

        $this->attendreLeRefus('target_is_your_own_patrol', $cible);
    }

    public function testSansContactOnNeVisePasUnePatrouille(): void
    {
        $this->armer();
        $cible = $this->unePatrouille($this->unEtranger());

        $this->attendreLeRefus('target_not_detected', $cible);
    }

    /**
     * Un contact revoque n est plus un contact : le detecteur a ete demoli, ou la patrouille a
     * quitte le systeme.
     */
    public function testUnContactRevoqueNeSuffitPas(): void
    {
        $this->armer();
        $cible = $this->unePatrouille($this->unEtranger());
        $this->unContact($cible, 1000, 1500);

        $this->attendreLeRefus('target_not_detected', $cible);
    }

    /**
     * Une acquisition en cours ne donne rien : le delai du palier n est pas passe.
     */
    public function testUneAcquisitionEnCoursNeSuffitPas(): void
    {
        $this->armer();
        $cible = $this->unePatrouille($this->unEtranger());
        $this->unContact($cible, 9000);

        $this->attendreLeRefus('target_not_detected', $cible);
    }

    public function testUnProprietaireEnVacancesEstProtege(): void
    {
        $this->armer();
        $etranger = $this->unEtranger();
        $cible = $this->unePatrouille($etranger);
        $this->unContact($cible, 1000);

        DB::table('users')->where('id', $etranger)->update(['vacation_mode' => 1]);

        try {
            $this->attendreLeRefus('target_owner_on_vacation', $cible);
        } finally {
            DB::table('users')->where('id', $etranger)->update(['vacation_mode' => 0]);
        }
    }

    public function testLaPatrouilleDuCompteSystemeEstProtegee(): void
    {
        $this->armer();
        $systeme = User::where('username', User::SYSTEM_ACCOUNT_USERNAME)->value('id');

        if ($systeme === null) {
            $this->markTestSkipped('Cette base n a pas de compte systeme.');
        }

        $cible = $this->unePatrouille((int)$systeme);
        $this->unContact($cible, 1000);

        $this->attendreLeRefus('target_is_protected', $cible);
    }

    /**
     * **Le refus ne doit rien apprendre.** Une patrouille qu on ne detecte pas, dont le
     * proprietaire est en vacances : repondre « ce joueur est en vacances » dirait qu il y a
     * quelqu un la, et lequel. Le refus reste « non detectee ».
     */
    public function testUnRefusNeReveleJamaisUneProtectionSurUneCibleNonDetectee(): void
    {
        $this->armer();
        $etranger = $this->unEtranger();
        $cible = $this->unePatrouille($etranger);

        DB::table('users')->where('id', $etranger)->update(['vacation_mode' => 1]);

        try {
            $this->attendreLeRefus('target_not_detected', $cible);
        } finally {
            DB::table('users')->where('id', $etranger)->update(['vacation_mode' => 0]);
        }
    }

    /**
     * Detectee, mais repartie entre le devis et l ordre.
     */
    public function testUneCibleQuiNEstPlusPoseeEstRefuseeEnDernier(): void
    {
        $this->armer();
        $cible = $this->unePatrouille($this->unEtranger(), PatrolState::EnRoute);
        $this->unContact($cible, 1000);

        $this->attendreLeRefus('target_not_parked', $cible);
    }

    private function attendreLeRefus(string $raison, Patrol $cible): void
    {
        try {
            $this->porte()->frozenTargetFor($this->currentUserId, $cible, 2000);
            $this->fail('La porte a laisse passer une cible qu elle devait refuser (' . $raison . ').');
        } catch (PatrolOrderRefused $refus) {
            $this->assertSame($raison, $refus->reason);
        }
    }
}
