<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Facades\DB;
use OGame\Alliance\AllianceMembershipChangeGuard;
use OGame\Combat\Enums\CombatState;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Une adhesion ne reunit jamais deux adversaires d une bataille en cours — et n annule rien.
 *
 * ## Les deux moities de la regle
 *
 * Plan approuve du 9 septembre 2026 : « Interdire un changement d alliance qui reunirait dans une
 * meme alliance des adversaires d une bataille active jusqu a son reglement. **Ne jamais annuler une
 * bataille pour ce motif.** »
 *
 * La seconde compte autant que la premiere : sans elle, rejoindre l alliance de son attaquant
 * deviendrait une sortie de secours pour qui est en train de perdre. C est l adhesion qui attend,
 * jamais la bataille — et un temoin le verifie explicitement.
 */
class AllianceMembershipDuringBattleTest extends AccountTestCase
{
    /** @var array<int, int> */
    private array $alliances = [];

    /** @var array<int, int> */
    private array $combats = [];

    /** @var array<int, int> */
    private array $missions = [];

    protected function tearDown(): void
    {
        if ($this->combats !== []) {
            CombatParticipant::query()->whereIn('combat_instance_id', $this->combats)->delete();
            CombatInstance::query()->whereIn('id', $this->combats)->delete();
            $this->combats = [];
        }

        if ($this->missions !== []) {
            FleetMission::query()->whereIn('id', $this->missions)->delete();
            $this->missions = [];
        }

        if ($this->alliances !== []) {
            DB::table('users')->whereIn('alliance_id', $this->alliances)->update(['alliance_id' => null]);
            DB::table('users')->update(['alliance_cooldown_until' => null, 'alliance_left_at' => null]);
            AllianceMember::query()->whereIn('alliance_id', $this->alliances)->delete();
            Alliance::query()->whereIn('id', $this->alliances)->delete();
            $this->alliances = [];
        }

        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 0);

        parent::tearDown();
    }

    private function service(): AllianceService
    {
        return resolve(AllianceService::class);
    }

    private function garde(): AllianceMembershipChangeGuard
    {
        return resolve(AllianceMembershipChangeGuard::class);
    }

    /**
     * Un joueur etranger, et sa planete.
     *
     * @return array{int, int}
     */
    private function unEtranger(): array
    {
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();

        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        return [$proprietaire->getId(), $etrangere->getPlanetId()];
    }

    /**
     * Une bataille active ou le joueur de l essai attaque le corps de l etranger.
     *
     * Les lignes sont ecrites directement : ce qui est juge ici est la porte de l adhesion, pas
     * l ouverture d un combat, qui a ses propres temoins.
     */
    private function uneBatailleOuJAttaque(int $corpsVise): CombatInstance
    {
        // L instance exige la mission qui l a ouverte : elle est ecrite avec, minimale.
        $initiatrice = FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'planet_id_to' => $corpsVise,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 1,
            'processed' => 1,
        ]);

        $this->missions[] = (int)$initiatrice->id;

        $combat = CombatInstance::query()->create([
            'mission_id' => $initiatrice->id,
            'target_type' => 1,
            'galaxy' => 1,
            'system' => 1,
            'position' => 1,
            'target_planet_id' => $corpsVise,
            'status' => CombatState::Active->value,
            'started_at' => 1_700_000_000,
            'ends_at' => 1_700_003_600,
        ]);

        $this->combats[] = (int)$combat->id;

        CombatParticipant::query()->create([
            'combat_instance_id' => $combat->id,
            'player_id' => $this->currentUserId,
            'fleet_mission_id' => null,
            'participant_key' => 'test:' . $this->currentUserId,
            'side' => CombatParticipant::SIDE_ATTACKER,
            'participant_type' => 'fleet',
        ]);

        return $combat;
    }

    /**
     * L alliance de l etranger, qu il fonde seul.
     */
    private function uneAllianceDe(int $fondateur): int
    {
        $alliance = $this->service()->createAlliance(
            $fondateur,
            'B' . substr((string)$fondateur, -3) . substr((string)$this->currentUserId, -3),
            'Bataille ' . $fondateur
        );

        $this->alliances[] = (int)$alliance->id;

        return (int)$alliance->id;
    }

    public function testJoiningIsRefusedWhileTheTwoAreFightingEachOther(): void
    {
        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 1);

        [$etranger, $corps] = $this->unEtranger();
        $combat = $this->uneBatailleOuJAttaque($corps);
        $alliance = $this->uneAllianceDe($etranger);

        $this->assertTrue(
            $this->garde()->reunitesAdversaries($this->currentUserId, $alliance),
            'The guard did not see that the two are on opposite sides of a running battle.'
        );

        $candidature = $this->service()->applyToAlliance($this->currentUserId, $alliance);

        try {
            $this->service()->acceptApplication((int)$candidature->id, $etranger);
            $this->fail('An attacker joined the alliance of the player he is attacking.');
        } catch (Exception $refus) {
            $this->assertSame(__('t_ingame.alliance.err_adversaries_of_an_active_battle'), $refus->getMessage());
        }

        // **La bataille n est jamais annulee pour ce motif** : c est l adhesion qui attend.
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The battle was cancelled to let the membership through.');

        // Et rien n a ete ecrit a moitie.
        $this->assertNull(
            DB::table('users')->where('id', $this->currentUserId)->value('alliance_id'),
            'The applicant entered the alliance although the acceptance was refused.'
        );
        $this->assertSame(
            0,
            AllianceMember::query()->where('alliance_id', $alliance)->where('user_id', $this->currentUserId)->count(),
            'A membership row survived a refused acceptance.'
        );
    }

    /**
     * Une bataille finale ne retient plus rien.
     */
    public function testASettledBattleNoLongerRefuses(): void
    {
        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 1);

        [$etranger, $corps] = $this->unEtranger();
        $combat = $this->uneBatailleOuJAttaque($corps);
        $alliance = $this->uneAllianceDe($etranger);

        $combat->status = CombatState::Resolved;
        $combat->save();

        $this->assertFalse(
            $this->garde()->reunitesAdversaries($this->currentUserId, $alliance),
            'A settled battle still refused the membership.'
        );
    }

    /**
     * Du meme cote, rien ne s y oppose : la regle vise les adversaires, pas les participants.
     */
    public function testTwoPlayersOnTheSameSideMayShareAnAlliance(): void
    {
        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 1);

        [$etranger, $corps] = $this->unEtranger();
        $combat = $this->uneBatailleOuJAttaque($corps);
        $alliance = $this->uneAllianceDe($etranger);

        // L etranger cesse d etre le defenseur : le corps vise n est plus le sien.
        $combat->target_planet_id = null;
        $combat->save();

        // Il attaque a nos cotes.
        CombatParticipant::query()->create([
            'combat_instance_id' => $combat->id,
            'player_id' => $etranger,
            'fleet_mission_id' => null,
            'participant_key' => 'test:' . $etranger,
            'side' => CombatParticipant::SIDE_ATTACKER,
            'participant_type' => 'fleet',
        ]);

        $this->assertFalse(
            $this->garde()->reunitesAdversaries($this->currentUserId, $alliance),
            'Two players attacking the same target were treated as adversaries.'
        );
    }

    /**
     * **Une donnee ambigue ne donne jamais plus de droits.**
     *
     * Un joueur inscrit des DEUX cotes d un meme combat est une incoherence. La premiere version de
     * la porte passait au combat suivant, si bien que l adhesion se faisait *parce que* l etat etait
     * douteux — une ouverture par le defaut. Elle refuse desormais, et laisse la bataille intacte.
     */
    public function testAnUndecidableSideRefusesInsteadOfLettingThrough(): void
    {
        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 1);

        [$etranger, $corps] = $this->unEtranger();
        $combat = $this->uneBatailleOuJAttaque($corps);
        $alliance = $this->uneAllianceDe($etranger);

        // Le meme joueur, inscrit aussi du cote defenseur : l etat devient indecidable.
        CombatParticipant::query()->create([
            'combat_instance_id' => $combat->id,
            'player_id' => $this->currentUserId,
            'fleet_mission_id' => null,
            'participant_key' => 'test:defenseur:' . $this->currentUserId,
            'side' => CombatParticipant::SIDE_DEFENDER,
            'participant_type' => 'fleet',
        ]);

        $this->assertTrue(
            $this->garde()->reunitesAdversaries($this->currentUserId, $alliance),
            'An undecidable side let the membership through: ambiguity granted more permission.'
        );

        // Et la bataille n a pas bouge : la regle n annule jamais un combat.
        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The battle was touched by an ambiguity.');
    }

    /**
     * L interrupteur eteint, la regle n existe pas.
     */
    public function testTheRuleDoesNotExistWhileTheSwitchIsOff(): void
    {
        [$etranger, $corps] = $this->unEtranger();
        $this->uneBatailleOuJAttaque($corps);
        $alliance = $this->uneAllianceDe($etranger);

        $this->assertFalse(
            $this->garde()->reunitesAdversaries($this->currentUserId, $alliance),
            'The rule applied although its switch is off.'
        );
    }
}
