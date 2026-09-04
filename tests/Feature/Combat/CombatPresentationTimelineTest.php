<?php

namespace Tests\Feature\Combat;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\ContradictoryPresentationTimeline;
use OGame\Combat\Presentation\CombatPresentationTimelineReader;
use OGame\Combat\Presentation\CombatPresentationTimelineV1;
use OGame\Combat\Presentation\CombatPresentationTimelineWriter;
use OGame\Combat\Presentation\PresentationEvent;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\CombatPresentationEvent;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * La chronologie de presentation : ecrite a la cloture, derivee du resultat gele, lue par le passe seul.
 *
 * ## Ce que ces essais etablissent
 *
 * Qu'une cloture ecrit le fil dans sa transaction, et que ce fil est exactement ce que la regle
 * projette depuis le document persiste — un rejeu depuis la colonne rend les memes lignes. Qu'un
 * second passage de l'ecrivain ne fait rien, et qu'une contradiction se refuse plutot que de se
 * resoudre en silence. Que le lecteur ne rend jamais un evenement dont l'instant n'est pas atteint,
 * ni une perte qui n'est pas celle du joueur, et qu'il reprend apres un rang sans rien redonner.
 */
class CombatPresentationTimelineTest extends FleetDispatchTestCase
{
    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function setUp(): void
    {
        parent::setUp();

        CombatPresentationEvent::query()->delete();
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 60);
        $this->planetAddUnit('light_fighter', 400);
        $this->playerSetResearchLevel('computer_technology', object_level: 2);

        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 8);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('attack_block_until', 0);

        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * La cloture ecrit le fil, et ce fil est la projection du document persiste.
     */
    public function testTheClosureWritesTheTimelineTheFrozenResultProjects(): void
    {
        $combat = $this->anEngagedCombat();

        $this->assertSame(CombatPresentationTimelineV1::VERSION, $combat->presentation_version, 'The instance does not name the presentation rule it was unveiled with.');

        $lignes = CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->orderBy('sequence')->get();
        $this->assertGreaterThan(0, $lignes->count(), 'The closure wrote no event: the garrison lost nothing, or the timeline was not written.');

        // **Rejoue depuis la colonne**, pas depuis le resultat en memoire : c'est le document persiste
        // que la regle doit savoir devoiler a l'identique.
        $attendus = (new CombatPresentationTimelineV1())->project(
            BattleResultCodec::fromStorage($combat->battle_result),
            $this->secondsPerRoundOf($combat),
            (int)$combat->ends_at - (int)$combat->duration_seconds
        );

        $this->assertSame(
            array_map(static fn (PresentationEvent $e): array => $e->toRow(), $attendus),
            $lignes->map(static fn (CombatPresentationEvent $l): array => [
                'sequence' => (int)$l->sequence,
                'visible_at' => (int)$l->visible_at,
                'participant_key' => (string)$l->participant_key,
                'side' => (string)$l->side,
                'unit' => (string)$l->unit,
                'amount' => (int)$l->amount,
            ])->all(),
            'The events written at the closure are not the projection of the persisted result.'
        );

        // Le dernier evenement devient visible a l'echeance, jamais apres.
        $derniere = $lignes->last();
        $this->assertNotNull($derniere);
        $this->assertSame((int)$combat->ends_at, (int)$derniere->visible_at, 'The last loss is unveiled after the combat ends.');
    }

    /**
     * Un second passage de l'ecrivain ne fait rien ; une contradiction se refuse.
     */
    public function testAReplayWritesNothingAndAContradictionIsRefused(): void
    {
        $combat = $this->anEngagedCombat();
        $resultat = BattleResultCodec::fromStorage($combat->battle_result);
        $secondes = $this->secondsPerRoundOf($combat);
        $debut = (int)$combat->ends_at - (int)$combat->duration_seconds;

        $avant = CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->orderBy('sequence')->get()->map(static fn ($l): array => (array)$l->getAttributes())->all();

        (new CombatPresentationTimelineWriter())->write($combat, $resultat, $secondes, $debut);

        $apres = CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->orderBy('sequence')->get()->map(static fn ($l): array => (array)$l->getAttributes())->all();
        $this->assertSame($avant, $apres, 'A replay of the writer changed the timeline.');

        // **La meme bataille, datee autrement** : un instant qui bouge est une contradiction.
        try {
            (new CombatPresentationTimelineWriter())->write($combat, $resultat, $secondes, $debut + 1);
            $this->fail('A timeline that contradicts the one already written was accepted.');
        } catch (ContradictoryPresentationTimeline $refus) {
            $this->assertStringContainsString('visible_at', $refus->getMessage());
        }

        // Et la base tient le meme contrat : un rang ecrit deux fois est refuse.
        $this->expectException(QueryException::class);
        CombatPresentationEvent::query()->create([
            'combat_instance_id' => $combat->id,
            'version' => CombatPresentationTimelineV1::VERSION,
            'sequence' => 1,
            'visible_at' => $debut,
            'participant_key' => CombatParticipantKey::forFleet(1),
            'side' => 'attacker',
            'unit' => 'light_fighter',
            'amount' => 1,
        ]);
    }

    /**
     * Le lecteur ne rend que le passe, et seulement les pertes du joueur qui lit.
     */
    public function testTheReaderRendersOnlyThePastAndOnlyTheReadersOwnLosses(): void
    {
        $combat = $this->anEngagedCombat();
        $lecteur = new CombatPresentationTimelineReader();
        $debut = (int)$combat->ends_at - (int)$combat->duration_seconds;

        $tout = CombatPresentationEvent::query()->where('combat_instance_id', $combat->id)->orderBy('sequence')->get();
        $premierInstant = (int)$tout->min('visible_at');
        $this->assertGreaterThan($debut, $premierInstant, 'The first loss is visible at the very start: nothing could be hidden.');

        $proprietaire = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');

        // Avant le premier instant : rien, pour personne.
        $this->assertSame([], $lecteur->visibleTo($combat, $this->currentUserId, $premierInstant - 1));
        $this->assertSame([], $lecteur->visibleTo($combat, $proprietaire, $premierInstant - 1));

        // A l'echeance : chacun voit exactement ses pertes, et rien de l'autre.
        $attaquant = $lecteur->visibleTo($combat, $this->currentUserId, (int)$combat->ends_at);
        $defenseur = $lecteur->visibleTo($combat, $proprietaire, (int)$combat->ends_at);

        $this->assertNotSame([], $attaquant, 'The attacker sees none of its losses at the deadline.');
        $this->assertNotSame([], $defenseur, 'The target owner sees none of its garrison losses at the deadline.');

        foreach ($attaquant as $evenement) {
            $this->assertSame('attacker', $evenement->side, 'The attacker was shown a defending loss.');
        }
        foreach ($defenseur as $evenement) {
            $this->assertSame(CombatParticipantKey::forPlanet((int)$combat->target_planet_id), $evenement->participantKey, 'The target owner was shown a loss that is not its garrison.');
        }

        $this->assertCount($tout->count(), [...$attaquant, ...$defenseur], 'Some events are visible to nobody, or to both.');

        // Un tiers ne voit rien.
        $this->assertSame([], $lecteur->visibleTo($combat, 999_999, (int)$combat->ends_at));

        // Incremental : reprendre apres le dernier rang rendu ne redonne rien.
        $dernier = end($attaquant);
        $this->assertNotFalse($dernier);
        $this->assertSame([], $lecteur->visibleTo($combat, $this->currentUserId, (int)$combat->ends_at, $dernier->sequence));

        // Et entre les deux, seulement ce qui est deja arrive.
        $partiel = $lecteur->visibleTo($combat, $this->currentUserId, $premierInstant);
        foreach ($partiel as $evenement) {
            $this->assertLessThanOrEqual($premierInstant, $evenement->visibleAt, 'A future event was rendered.');
        }
    }

    /**
     * Le droit de voir les pertes de la garnison vient de l'inscription, pas du corps vivant.
     *
     * ## Ce que la relecture vivante laissait faire
     *
     * Le lecteur demandait au corps son proprietaire pour decider qui voit sa garnison. Un corps
     * reattribue — suppression et restauration administrative, evolution future — aurait retire
     * l'acces au defenseur qui a subi la bataille, et l'aurait donne a un autre. Le fil est fige :
     * son autorisation doit l'etre aussi.
     */
    public function testTheGarrisonAccessComesFromTheEnrolmentNotFromTheLivingBody(): void
    {
        $combat = $this->anEngagedCombat();
        $lecteur = new CombatPresentationTimelineReader();
        $echeance = (int)$combat->ends_at;

        $proprietaire = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');
        $avant = $lecteur->visibleTo($combat, $proprietaire, $echeance);
        $this->assertNotSame([], $avant, 'The photographed defender sees nothing: the scenario would prove nothing.');

        // **Le corps change de proprietaire apres la cloture.**
        $tiers = (int)DB::table('users')->whereNotIn('id', [$proprietaire, $this->currentUserId])->orderByDesc('id')->value('id');
        $this->assertGreaterThan(0, $tiers, 'No third player exists to receive the body.');
        DB::table('planets')->where('id', $combat->target_planet_id)->update(['user_id' => $tiers]);

        $this->assertSame(
            array_map(static fn (PresentationEvent $e): array => $e->toRow(), $avant),
            array_map(static fn (PresentationEvent $e): array => $e->toRow(), $lecteur->visibleTo($combat, $proprietaire, $echeance)),
            'The photographed defender lost access when the body changed hands.'
        );
        $this->assertSame([], $lecteur->visibleTo($combat, $tiers, $echeance), 'The new owner was given the losses of a battle it did not fight.');

        // **Et le corps disparait.** L'inscription, elle, reste.
        DB::table('planets')->where('id', $combat->target_planet_id)->update(['user_id' => $proprietaire]);
        DB::table('combat_instances')->where('id', $combat->id)->update(['target_planet_id' => null]);
        $combat->refresh();

        $this->assertSame(
            array_map(static fn (PresentationEvent $e): array => $e->toRow(), $avant),
            array_map(static fn (PresentationEvent $e): array => $e->toRow(), $lecteur->visibleTo($combat, $proprietaire, $echeance)),
            'The photographed defender lost access when the body vanished.'
        );
    }

    /**
     * Une flotte ecrasante contre une garnison qui perd quelque chose, sur une planete propre.
     */
    private function anEngagedCombat(): CombatInstance
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));

        // Une garnison qui ne fuit pas et qui perd : la chronologie doit avoir quelque chose a dire
        // des deux cotes.
        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
        DB::table('users')->where('id', $proprietaire)->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 200_000,
            'crystal' => 100_000,
            'deuterium' => 20_000,
            'rocket_launcher' => 60,
            'light_laser' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);

        $mission = DB::table('fleet_missions')->where('user_id', $this->currentUserId)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($mission, 'No fleet was dispatched.');

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival));
        $this->get('/overview')->assertStatus(200);

        $combat = CombatInstance::query()->where('mission_id', $mission->id)->first();
        $this->assertNotNull($combat, 'The arrival did not open a combat.');
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not close on arrival: a single fleet closes its window at once.');
        $this->assertNotNull($combat->battle_result);

        return $combat;
    }

    /**
     * @return array<int, int>
     */
    private function secondsPerRoundOf(CombatInstance $combat): array
    {
        $calendrier = $combat->round_schedule;
        $this->assertIsArray($calendrier);

        return array_map(static fn (array $round): int => (int)$round['seconds'], $calendrier);
    }
}
