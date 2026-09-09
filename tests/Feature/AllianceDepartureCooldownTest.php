<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * Les trois departs d une alliance posent la meme echeance, et elle ne se recalcule pas.
 *
 * ## Ce que la mesure a trouve
 *
 * Le delai existait — mais **pour le seul depart volontaire**. `kickMember()` et
 * `disbandAlliance()` remettaient `alliance_id` a null sans rien poser : un joueur exclu, ou dont
 * l alliance etait dissoute, rejoignait une autre alliance a la seconde suivante. Se faire exclure
 * valait donc mieux que partir. Le plan approuve du 9 septembre 2026 nomme les trois cas ensemble.
 *
 * ## Pourquoi une echeance persistee
 *
 * Elle se calculait a chaque verification, par `alliance_left_at + reglage`. Changer le reglage
 * deplacait donc **retroactivement** l echeance de tous les joueurs deja partis. Le plan demande une
 * echeance autoritative : elle est ecrite au depart, et relue telle quelle.
 */
class AllianceDepartureCooldownTest extends AccountTestCase
{
    use DetachesFromAnyAlliance;

    /** @var array<int, int> */
    private array $alliances = [];

    /** @var array<int, int> */
    private array $comptes = [];

    protected function tearDown(): void
    {
        if ($this->alliances !== []) {
            DB::table('users')->whereIn('alliance_id', $this->alliances)->update(['alliance_id' => null]);
            AllianceMember::query()->whereIn('alliance_id', $this->alliances)->delete();
            Alliance::query()->whereIn('id', $this->alliances)->delete();
            $this->alliances = [];
        }

        if ($this->comptes !== []) {
            DB::table('users')->whereIn('id', $this->comptes)->update([
                'alliance_id' => null,
                'alliance_left_at' => null,
                'alliance_cooldown_until' => null,
            ]);
            $this->comptes = [];
        }

        Date::setTestNow();

        parent::tearDown();
    }

    private function service(): AllianceService
    {
        return resolve(AllianceService::class);
    }

    /**
     * Une alliance dont le joueur de l essai est fondateur, et un second membre.
     */
    private function uneAllianceAvec(int $second): int
    {
        // Le second membre est l etranger voisin, partage par les classes du processus : il peut
        // porter l alliance ou l echeance de depart qu une voisine lui a laissee.
        $this->detachFromAnyAlliance($this->currentUserId, $second);

        $alliance = $this->service()->createAlliance(
            $this->currentUserId,
            'D' . substr((string)$this->currentUserId, -3) . substr((string)$second, -3),
            'Depart ' . $this->currentUserId . '-' . $second
        );

        $this->alliances[] = (int)$alliance->id;
        $this->comptes[] = $this->currentUserId;
        $this->comptes[] = $second;

        $candidature = $this->service()->applyToAlliance($second, (int)$alliance->id);
        $this->service()->acceptApplication((int)$candidature->id, $this->currentUserId);

        return (int)$alliance->id;
    }

    /**
     * Un joueur etranger, qui servira de second membre.
     */
    private function unEtranger(): int
    {
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();

        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        return $proprietaire->getId();
    }

    private function echeanceDe(int $userId): mixed
    {
        return User::query()->whereKey($userId)->value('alliance_cooldown_until');
    }

    public function testLeavingSetsTheDeadline(): void
    {
        $second = $this->unEtranger();
        $this->uneAllianceAvec($second);

        $this->service()->leaveAlliance($second);

        $this->assertNotNull($this->echeanceDe($second), 'Leaving set no deadline at all.');
    }

    /**
     * **Le cas qui manquait.** Etre exclu ne posait rien.
     */
    public function testBeingKickedSetsTheSameDeadline(): void
    {
        $second = $this->unEtranger();
        $alliance = $this->uneAllianceAvec($second);

        $this->service()->kickMember($alliance, $second, $this->currentUserId);

        $this->assertNotNull(
            $this->echeanceDe($second),
            'A kicked player could join another alliance at once: being kicked was better than leaving.'
        );
    }

    /**
     * **L autre cas qui manquait.** La dissolution est un depart pour chacun.
     */
    public function testDisbandingSetsTheDeadlineForEveryMember(): void
    {
        $second = $this->unEtranger();
        $alliance = $this->uneAllianceAvec($second);

        $this->service()->disbandAlliance($alliance, $this->currentUserId);

        $this->assertNotNull($this->echeanceDe($second), 'A member of a disbanded alliance kept no deadline.');
        $this->assertNotNull($this->echeanceDe($this->currentUserId), 'The founder who disbanded kept no deadline.');
    }

    /**
     * L echeance retient, et son message dit la date autant que les jours.
     */
    public function testTheDeadlineRefusesJoiningAndSaysUntilWhen(): void
    {
        $second = $this->unEtranger();
        $alliance = $this->uneAllianceAvec($second);

        $this->service()->kickMember($alliance, $second, $this->currentUserId);

        try {
            $this->service()->applyToAlliance($second, $alliance);
            $this->fail('A kicked player applied again while the deadline was still running.');
        } catch (Exception $refus) {
            $this->assertMatchesRegularExpression(
                '/\d{2}\/\d{2}\/\d{4}/',
                $refus->getMessage(),
                'The refusal does not say until when: the player has to count.'
            );
        }
    }

    /**
     * **L echeance ne bouge plus quand le reglage change — et c est la DECISION qui le prouve.**
     *
     * ## Ce que la premiere version de ce temoin ne voyait pas
     *
     * Elle relisait la colonne et constatait qu elle n avait pas bouge. Evidemment : la mutation qui
     * fait recalculer l echeance ne touche pas la colonne, elle change **la facon dont le refus la
     * lit**. Le temoin passait donc sur le code juste comme sur le code faux, et la mutation
     * survivait en le disant.
     *
     * Ici le reglage **descend** apres coup. Avec l echeance persistee, le joueur reste retenu ;
     * avec un recalcul, `alliance_left_at + 0 jour` est deja passe et il entre. Le juste et le faux
     * cessent de coincider.
     */
    public function testChangingTheSettingDoesNotMoveAnExistingDeadline(): void
    {
        $reglages = resolve(SettingsService::class);
        $reglages->set('alliance_cooldown_days', 30);

        $second = $this->unEtranger();
        $alliance = $this->uneAllianceAvec($second);
        $this->service()->kickMember($alliance, $second, $this->currentUserId);

        $avant = (string)$this->echeanceDe($second);
        $this->assertNotSame('', $avant, 'No deadline was written: the scenario proves nothing.');

        // Le reglage descend a zero : un recalcul libererait tout le monde a l instant.
        $reglages->set('alliance_cooldown_days', 0);

        $this->assertSame(
            $avant,
            (string)$this->echeanceDe($second),
            'Lowering the setting moved a deadline that was already decided.'
        );

        try {
            $this->service()->applyToAlliance($second, $alliance);
            $this->fail('Lowering the setting released a player whose deadline had already been decided.');
        } catch (Exception $refus) {
            $this->assertStringContainsString('30', $refus->getMessage(), 'The refusal no longer counts from the decided deadline.');
        } finally {
            $reglages->set('alliance_cooldown_days', 3);
        }
    }

    /**
     * Passee l echeance, plus rien ne retient.
     */
    public function testOnceThePeriodHasPassedTheDeadlineNoLongerRefuses(): void
    {
        $second = $this->unEtranger();
        $alliance = $this->uneAllianceAvec($second);
        $this->service()->kickMember($alliance, $second, $this->currentUserId);

        $echeance = $this->echeanceDe($second);
        $this->assertNotNull($echeance);

        Date::setTestNow(Date::parse((string)$echeance)->addSecond());

        $candidature = $this->service()->applyToAlliance($second, $alliance);

        $this->assertNotNull($candidature->id, 'The deadline still refused after it had passed.');
    }
}
