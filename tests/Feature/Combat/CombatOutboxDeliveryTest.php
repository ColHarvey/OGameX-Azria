<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Services\CombatOutboxDelivery;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Factories\GameMessageFactory;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatPresentationEvent;
use OGame\Models\Message;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Les avis de la boite d'envoi deviennent des messages du jeu — une fois, au bon joueur, a l'heure.
 *
 * ## Ce que ces essais prouvent
 *
 * Qu'un avis disponible devient exactement un message pour le joueur que la clef designe, et qu'un
 * second passage n'en cree pas un second ; qu'un avis pas encore disponible attend ; que la
 * garnison recoit son avis par **l'inscription**, pas par le proprietaire vivant du corps — un corps
 * reattribue apres la cloture ne detourne rien ; qu'un avis sans destinataire est garde, compte,
 * et laisse a l'exploitation apres cinq tentatives ; que chaque code de raison et de cause a sa
 * traduction dans les deux langues, et que le lecteur la lit dans la sienne.
 */
class CombatOutboxDeliveryTest extends FleetDispatchTestCase
{
    use EngagesAPersistentCombat;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function setUp(): void
    {
        parent::setUp();

        CombatPresentationEvent::query()->delete();
        CombatOutboxMessage::query()->delete();
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        App::setLocale('en');

        parent::tearDown();
    }

    public function testARefusalNoticeBecomesExactlyOneMessageForTheFleetOwner(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;
        $avant = Message::query()->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->count();

        $this->aNotice($combat, CombatParticipantKey::forFleet((int)$combat->mission_id), CombatOutboxKind::RallyRefused, [
            'reason' => CombatReasonCode::RallyClosed->value,
            'target_body_id' => (int)$combat->target_planet_id,
            'galaxy' => (int)$combat->galaxy,
            'system' => (int)$combat->system,
            'position' => (int)$combat->position,
        ], $maintenant);

        $livreur = new CombatOutboxDelivery();

        $this->assertSame(1, $livreur->deliver($maintenant), 'The available notice was not delivered.');

        $messages = Message::query()->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->get();
        $this->assertCount($avant + 1, $messages, 'The fleet owner did not receive exactly one message.');

        $message = $messages->last();
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(CombatReasonCode::RallyClosed->value, $message->params['reason_code']);
        $this->assertStringContainsString($combat->galaxy . ':' . $combat->system . ':' . $combat->position, (string)$message->params['coordinates']);

        $avis = CombatOutboxMessage::query()->where('combat_instance_id', $combat->id)->firstOrFail();
        $this->assertSame($maintenant, (int)$avis->dispatched_at, 'The notice was not marked as dispatched at the delivery instant.');

        // **Un second passage ne livre rien de plus.**
        $this->assertSame(0, $livreur->deliver($maintenant + 60));
        $this->assertSame($avant + 1, Message::query()->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->count(), 'A second passage delivered the same notice again.');
    }

    public function testANoticeNotYetAvailableWaitsForItsInstant(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;

        $this->aNotice($combat, CombatParticipantKey::forFleet((int)$combat->mission_id), CombatOutboxKind::RallyRefused, [
            'reason' => CombatReasonCode::FleetLimitReached->value,
            'galaxy' => (int)$combat->galaxy,
            'system' => (int)$combat->system,
            'position' => (int)$combat->position,
        ], $maintenant + 600);

        $livreur = new CombatOutboxDelivery();

        $this->assertSame(0, $livreur->deliver($maintenant), 'A notice readable only later was delivered now.');
        $this->assertSame(0, $livreur->deliver($maintenant + 599));
        $this->assertSame(1, $livreur->deliver($maintenant + 600), 'The notice was not delivered once its instant came.');
    }

    /**
     * La garnison recoit son avis par l'inscription : un corps reattribue apres la cloture ne le
     * detourne pas vers le nouveau proprietaire.
     */
    public function testTheGarrisonNoticeFollowsTheEnrolmentNotTheLivingBody(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;
        $inscrit = (int)DB::table('combat_participants')
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forPlanet((int)$combat->target_planet_id))
            ->value('player_id');
        $this->assertGreaterThan(0, $inscrit, 'The garrison is not enrolled: the scenario would prove nothing.');

        // Le corps change de mains apres la cloture.
        $tiers = (int)DB::table('users')->whereNotIn('id', [$inscrit, $this->currentUserId])->orderByDesc('id')->value('id');
        $this->assertGreaterThan(0, $tiers);
        DB::table('planets')->where('id', $combat->target_planet_id)->update(['user_id' => $tiers]);

        $this->aNotice($combat, CombatParticipantKey::forPlanet((int)$combat->target_planet_id), CombatOutboxKind::CombatCancelled, [
            'cause' => CombatCancellationCause::AdministrativeDecision->value,
            'note' => 'essai',
            'cancelled_at' => $maintenant,
            'abandoned_fingerprint' => 'abcdef0123456789',
            'target_body_id' => (int)$combat->target_planet_id,
            'galaxy' => (int)$combat->galaxy,
            'system' => (int)$combat->system,
            'position' => (int)$combat->position,
        ], $maintenant);

        $this->assertSame(1, (new CombatOutboxDelivery())->deliver($maintenant));

        $this->assertSame(1, Message::query()->where('user_id', $inscrit)->where('key', 'combat_cancelled')->count(), 'The enrolled defender did not receive the cancellation.');
        $this->assertSame(0, Message::query()->where('user_id', $tiers)->where('key', 'combat_cancelled')->count(), 'The new owner of the body received a notice for a battle it never fought.');

        $message = Message::query()->where('user_id', $inscrit)->where('key', 'combat_cancelled')->firstOrFail();
        $this->assertSame('abcdef0123456789', $message->params['fingerprint']);
        $this->assertSame(CombatCancellationCause::AdministrativeDecision->value, $message->params['cause_code']);
        $this->assertArrayNotHasKey('note', $message->params, 'The administrative note reached the player.');
    }

    public function testAnUndeliverableNoticeIsKeptCountedAndLeftAsideAfterFiveAttempts(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;

        $this->aNotice($combat, CombatParticipantKey::forFleet(999_999_999), CombatOutboxKind::RallyRefused, [
            'reason' => CombatReasonCode::RallyClosed->value,
            'galaxy' => 1,
            'system' => 1,
            'position' => 1,
        ], $maintenant);

        $livreur = new CombatOutboxDelivery();
        $avantMessages = Message::query()->count();

        for ($passage = 1; $passage <= 7; $passage++) {
            $this->assertSame(0, $livreur->deliver($maintenant + $passage), 'A notice without a recipient was delivered.');
        }

        $avis = CombatOutboxMessage::query()->where('combat_instance_id', $combat->id)->firstOrFail();
        $this->assertNull($avis->dispatched_at);
        $this->assertSame(CombatOutboxDelivery::MAX_ATTEMPTS, (int)$avis->attempts, 'The notice was retried beyond the limit, or not counted.');
        $this->assertStringContainsString('destinataire', (string)$avis->last_error);
        $this->assertSame($avantMessages, Message::query()->count(), 'A message was created for nobody.');
    }

    public function testTheAdvancerDeliversTheNoticesOfItsPassage(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;

        $this->aNotice($combat, CombatParticipantKey::forFleet((int)$combat->mission_id), CombatOutboxKind::RallyRefused, [
            'reason' => CombatReasonCode::RallyClosed->value,
            'galaxy' => (int)$combat->galaxy,
            'system' => (int)$combat->system,
            'position' => (int)$combat->position,
        ], $maintenant);

        $avance = (new PersistentCombatAdvancer())->advance($maintenant);

        $this->assertSame(1, $avance->delivered, 'The passage did not deliver the notice.');
        $this->assertTrue($avance->didSomething());
        $this->assertSame(1, Message::query()->where('user_id', $this->currentUserId)->where('key', 'combat_rally_refused')->count());
    }

    /**
     * Chaque code a sa traduction dans les deux langues : une clef manquante retomberait en
     * silence sur son propre nom, et le joueur lirait « rally_closed ».
     */
    public function testEveryReasonAndCauseHasItsTranslationInBothLanguages(): void
    {
        foreach (['fr', 'en'] as $langue) {
            App::setLocale($langue);

            foreach (CombatReasonCode::cases() as $raison) {
                $clef = 't_messages.combat_rally_refused.reasons.' . $raison->value;
                $this->assertNotSame($clef, __($clef), "No {$langue} translation for the refusal reason {$raison->value}.");
            }

            foreach (CombatCancellationCause::cases() as $cause) {
                $clef = 't_messages.combat_cancelled.causes.' . $cause->value;
                $this->assertNotSame($clef, __($clef), "No {$langue} translation for the cancellation cause {$cause->value}.");
            }
        }
    }

    /**
     * Le message garde le code et le traduit a la lecture, dans la langue du lecteur.
     */
    public function testTheMessageTranslatesTheCodeInTheReaderLanguage(): void
    {
        $message = new Message();
        $message->key = 'combat_rally_refused';
        $message->params = [
            'coordinates' => '[coordinates]1:2:3[/coordinates]',
            'reason_code' => CombatReasonCode::RallyClosed->value,
        ];

        $lecture = GameMessageFactory::createGameMessage($message);

        App::setLocale('fr');
        $francais = __('t_messages.combat_rally_refused.reasons.rally_closed');
        $this->assertStringContainsString($francais, $lecture->getBody());
        $this->assertStringNotContainsString('rally_closed', $lecture->getBody(), 'The raw code leaked into the French body.');

        App::setLocale('en');
        $anglais = __('t_messages.combat_rally_refused.reasons.rally_closed');
        $this->assertNotSame($francais, $anglais, 'French and English read the same: the language switch could not be observed.');
        $this->assertStringContainsString($anglais, $lecture->getBody());
    }

    /**
     * @param array<string, mixed> $contenu
     */
    private function aNotice(CombatInstance $combat, string $clef, CombatOutboxKind $genre, array $contenu, int $lisibleDes): void
    {
        CombatOutboxMessage::query()->create([
            'combat_instance_id' => $combat->id,
            'participant_key' => $clef,
            'kind' => $genre->value,
            'payload' => $contenu,
            'available_at' => $lisibleDes,
        ]);
    }
}
