<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * La fermeture du ralliement : la photographie se prend, une fois.
 *
 * ## Ce que ces essais protegent
 *
 * Le ralliement est une phase d'admission, pas un combat commence. A sa fermeture, les candidates
 * sont arbitrees et plus rien ne bouge. Trois choses doivent tenir :
 *
 *     fermer avant l'echeance exclurait des flottes qu'on avait promis d'attendre
 *     fermer deux fois ne doit rien faire de plus
 *     ce qui est admis vient des faits geles, pas du monde courant
 *
 * ## Ce qu'ils ne prouvent pas encore
 *
 * Ni les inclusions dans la photographie, ni les messages aux joueurs, ni la reservation de butin :
 * ces trois-la viennent apres. Le dire evite de croire la fermeture terminee.
 */
class RallyClosureServiceTest extends TestCase
{
    private const int OPENING = 1_700_000_000;

    private CombatOpeningService $ouverture;

    private RallyClosureService $fermeture;

    /**
     * Le nombre de corps deja crees, pour en donner un different a chacun.
     */
    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ouverture = new CombatOpeningService();
        $this->fermeture = new RallyClosureService();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Fermer avant l'echeance ne ferme rien.
     *
     * L'echeance a ete calculee a l'ouverture, sur les flottes qui seraient admises. Fermer avant
     * elle exclurait celles qu'on avait promis d'attendre.
     */
    public function testClosingBeforeTheDeadlineDoesNothing(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 30, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $issue = $this->fermeture->close($combat->id, self::OPENING + 10);

        $this->assertFalse($issue->closed);
        $this->assertSame('trop tot', $issue->reason);

        $combat->refresh();
        $this->assertSame(CombatState::Rallying, $combat->status);
    }

    /**
     * A l'echeance, la photographie se prend et les vagues admises deviennent participantes.
     */
    public function testAtTheDeadlineTheAdmittedWavesBecomeParticipants(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $vague = $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $issue = $this->fermeture->close($combat->id, self::OPENING + 19);

        $this->assertTrue($issue->closed, 'The rally did not close at its own deadline.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);

        $cles = CombatParticipant::where('combat_instance_id', $combat->id)
            ->pluck('participant_key')
            ->all();

        $this->assertContains(
            CombatParticipantKey::forFleet($vague->id),
            $cles,
            'A wave admitted by the selector was not registered as a participant.'
        );
    }

    /**
     * Fermer deux fois ne fait rien de plus.
     *
     * Un message de file peut etre livre deux fois, un worker reprendre apres un redemarrage. La
     * seconde tentative doit constater et s'arreter, sans lever ni dupliquer.
     */
    public function testClosingTwiceDoesNothingMore(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $premiere = $this->fermeture->close($combat->id, self::OPENING + 19);
        $avant = CombatParticipant::where('combat_instance_id', $combat->id)->count();

        $seconde = $this->fermeture->close($combat->id, self::OPENING + 30);

        $this->assertTrue($premiere->closed);
        $this->assertFalse($seconde->closed, 'A second closure claimed to have closed an already closed rally.');
        $this->assertSame('deja fermee', $seconde->reason);

        $this->assertSame(
            $avant,
            CombatParticipant::where('combat_instance_id', $combat->id)->count(),
            'Closing twice registered the same participants a second time.'
        );
    }

    /**
     * Les budgets consommes sont ecrits avec la photographie.
     */
    public function testTheConsumedBudgetsAreWrittenWithTheSnapshot(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->fermeture->close($combat->id, self::OPENING + 19);

        $combat->refresh();

        $this->assertGreaterThan(
            0,
            $combat->fleets_admitted,
            'The closure wrote no fleet count: nothing would know how full the side is.'
        );

        $this->assertSame(
            1,
            $combat->players_admitted,
            'Several waves of one player were counted as several players.'
        );
    }

    /**
     * Un combat inconnu ne fait pas lever la fermeture.
     */
    public function testAnUnknownCombatIsReportedRatherThanThrown(): void
    {
        $issue = $this->fermeture->close(999_999, self::OPENING);

        $this->assertFalse($issue->closed);
        $this->assertSame('combat introuvable', $issue->reason);
    }

    /**
     * Une attaque en vol vers ce corps.
     */
    private function anAttackAt(int $targetBodyId, int $arrivesAt, User|null $owner = null): FleetMission
    {
        $proprietaire = $owner ?? $this->aPlayer();

        return FleetMission::forceCreate([
            'user_id' => $proprietaire->id,
            'planet_id_to' => $targetBodyId,
            'mission_type' => 1,
            'time_departure' => self::OPENING - 600,
            'time_arrival' => $arrivesAt,
            'galaxy_to' => 6,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);
    }

    /**
     * Un joueur, avec une planete.
     */
    private function aPlayer(): User
    {
        $utilisateur = User::factory()->create();

        $this->aPlanetOwnedBy($utilisateur);

        return $utilisateur;
    }

    /**
     * Un corps celeste reel : `planet_id_to` porte une cle etrangere.
     */
    private function aBodyId(): int
    {
        return $this->aPlanetOwnedBy(User::factory()->create())->id;
    }

    /**
     * Une planete a des coordonnees libres, deterministes.
     */
    private function aPlanetOwnedBy(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 6,
            'system' => 500 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }
}
