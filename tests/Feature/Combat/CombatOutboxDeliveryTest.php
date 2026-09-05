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
            'recipient_id' => $this->currentUserId,
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
            'recipient_id' => $this->currentUserId,
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
     * Un avis qui ne nomme pas son destinataire n'est pas livre : il est garde, compte, et laisse
     * a l'exploitation.
     *
     * ## Pourquoi le refus plutot que la devinette
     *
     * Redemander au corps vivant a qui il appartient rouvrirait le defaut que `recipient_id` ferme —
     * et precisement pour les avis les plus anciens, ceux dont le contexte a eu le plus de temps
     * pour changer. Une decision humaine vaut mieux qu'un destinataire suppose.
     *
     * Le montage force le cas : la garnison est bien inscrite, le corps a bien un proprietaire, et
     * pourtant rien ne part — c'est ce qui distingue un refus d'une simple absence de destinataire.
     */
    public function testANoticeWithoutAFrozenRecipientIsRefusedRatherThanGuessed(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;
        $clef = CombatParticipantKey::forPlanet((int)$combat->target_planet_id);

        // **Precondition** : le repli aurait de quoi deviner — l'inscription existe, le corps aussi.
        $inscrit = (int)DB::table('combat_participants')
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', $clef)
            ->value('player_id');
        $this->assertGreaterThan(0, $inscrit, 'The garrison is not enrolled: a refusal would prove nothing.');
        $this->assertGreaterThan(0, (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id'));

        $this->aNotice($combat, $clef, CombatOutboxKind::CombatCancelled, [
            'cause' => CombatCancellationCause::AdministrativeDecision->value,
            'galaxy' => (int)$combat->galaxy,
            'system' => (int)$combat->system,
            'position' => (int)$combat->position,
        ], $maintenant);

        $avantMessages = Message::query()->count();

        $this->assertSame(0, (new CombatOutboxDelivery())->deliver($maintenant), 'A notice without a frozen recipient was delivered to someone.');
        $this->assertSame($avantMessages, Message::query()->count(), 'A message was created for a guessed recipient.');

        $avis = CombatOutboxMessage::query()->where('combat_instance_id', $combat->id)->firstOrFail();
        $this->assertNull($avis->dispatched_at);
        $this->assertSame(1, (int)$avis->attempts, 'The refusal was not counted.');
        $this->assertStringContainsString('destinataire', (string)$avis->last_error);
    }

    /**
     * Le contenu d'un avis d'annulation parvient au joueur qu'il nomme, sans l'empreinte technique.
     */
    public function testTheCancellationReachesThePlayerItNamesWithoutTheInternalFingerprint(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;
        $inscrit = (int)DB::table('combat_participants')
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forPlanet((int)$combat->target_planet_id))
            ->value('player_id');
        $this->assertGreaterThan(0, $inscrit);

        $this->aNotice($combat, CombatParticipantKey::forPlanet((int)$combat->target_planet_id), CombatOutboxKind::CombatCancelled, [
            'recipient_id' => $inscrit,
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

        $message = Message::query()->where('user_id', $inscrit)->where('key', 'combat_cancelled')->firstOrFail();
        // **L'empreinte technique ne parvient pas au joueur** : il recoit une reference d'incident
        // qu'il peut citer — le numero du combat —, et la somme de controle reste dans l'audit.
        $this->assertSame((string)$combat->id, $message->params['reference']);
        $this->assertArrayNotHasKey('fingerprint', $message->params, 'The internal fingerprint reached the player.');
        $this->assertStringNotContainsString('abcdef0123456789', GameMessageFactory::createGameMessage($message)->getBody(), 'The internal fingerprint is readable in the message body.');
        $this->assertSame(CombatCancellationCause::AdministrativeDecision->value, $message->params['cause_code']);
        $this->assertArrayNotHasKey('note', $message->params, 'The administrative note reached the player.');
    }

    public function testAnUndeliverableNoticeIsKeptCountedAndLeftAsideAfterFiveAttempts(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;

        $this->aNotice($combat, CombatParticipantKey::forFleet(999_999_999), CombatOutboxKind::RallyRefused, [
            'recipient_id' => 0,
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
            'recipient_id' => $this->currentUserId,
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
     * Le destinataire est celui que l'avis porte, fige a la decision — pas le proprietaire du jour.
     *
     * ## Le cas que ce montage reproduit
     *
     * Le corps change de mains **avant** que l'avis ne soit livre. Un livreur qui redemanderait au
     * corps vivant a qui il appartient donnerait cet avis au nouveau proprietaire : il apprendrait
     * qu'une bataille qu'il n'a pas subie vient d'etre annulee, et celui qui l'a subie n'en saurait
     * rien. Le destinataire voyage donc avec le fait.
     */
    public function testTheNoticeGoesToThePlayerItNamesEvenWhenTheBodyChangedHands(): void
    {
        $combat = $this->anEngagedCombat();
        $maintenant = (int)now()->timestamp;
        $subi = (int)DB::table('planets')->where('id', $combat->target_planet_id)->value('user_id');
        $tiers = (int)DB::table('users')->whereNotIn('id', [$subi, $this->currentUserId])->orderByDesc('id')->value('id');
        $this->assertGreaterThan(0, $tiers);
        $this->assertNotSame($subi, $tiers);

        // L'avis nomme celui qui subit, et l'inscription est effacee : sans le destinataire fige,
        // le livreur n'aurait plus que le corps vivant pour trancher.
        $this->aNotice($combat, CombatParticipantKey::forPlanet((int)$combat->target_planet_id), CombatOutboxKind::CombatCancelled, [
            'recipient_id' => $subi,
            'cause' => CombatCancellationCause::TargetDisappeared->value,
            'galaxy' => (int)$combat->galaxy,
            'system' => (int)$combat->system,
            'position' => (int)$combat->position,
        ], $maintenant);
        DB::table('combat_participants')->where('combat_instance_id', $combat->id)->delete();

        // **Le corps change de mains avant la livraison.**
        DB::table('planets')->where('id', $combat->target_planet_id)->update(['user_id' => $tiers]);

        $this->assertSame(1, (new CombatOutboxDelivery())->deliver($maintenant));

        $this->assertSame(1, Message::query()->where('user_id', $subi)->where('key', 'combat_cancelled')->count(), 'The player who suffered the cancellation was not told.');
        $this->assertSame(0, Message::query()->where('user_id', $tiers)->where('key', 'combat_cancelled')->count(), 'The new owner of the body was told about a battle it never fought.');
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
