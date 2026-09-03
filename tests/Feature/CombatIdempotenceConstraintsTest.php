<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatEffectReceipt;
use OGame\Models\CombatInstance;
use OGame\Models\CombatLootReservation;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatSnapshotInclusion;
use Tests\TestCase;

/**
 * Les garanties du schema, eprouvees contre la base plutot que lues dans la migration.
 *
 * ## Pourquoi ces essais existent
 *
 * Une contrainte d'unicite ecrite dans une migration n'est pas une garantie tant que personne ne l'a
 * vue refuser quelque chose. Un `unique()` mal place, une colonne mal nommee, un index qui porte sur
 * deux colonnes au lieu de trois : tout cela se lit tres bien et ne protege rien.
 *
 * Ces essais provoquent donc chaque doublon que le systeme doit refuser, et **exigent que la base
 * leve**. Ils portent sur les cinq garanties qui tiennent le combat persistant :
 *
 *     un seul combat par corps celeste
 *     un effet applique une seule fois au monde
 *     un evenement inclus une seule fois par photographie — mais plusieurs fois entre combats
 *     une seule reservation de butin par combat
 *     un seul message par destinataire et par genre
 *
 * ## Deux vocabulaires d identite qu il ne faut pas melanger
 *
 * Une **cle de participant** — `fleet:123` — dit qui se bat. Une **identite d evenement** dit ce
 * qui s est produit. Les deux se ressemblaient dans une premiere version de ce fichier, et la garde
 * de `CombatSchemaShapeTest` l a refuse : une identite qui commence par `fleet:` se lit comme une
 * cle de participant ecrite a la main, et deux orthographes d une meme source rouvrent le doublon
 * que l unicite existe pour fermer.
 *
 * Les identites d evenement portent donc un prefixe a elles.
 *
 * ## Le cas qui compte le plus
 *
 * `testTheSameEventEntersTwoDifferentCombats` est celui qu'une unicite naive aurait casse. Deux
 * combats successifs sur la meme planete lisent tous deux la garnison : une unicite portant sur le
 * seul evenement aurait fait disparaitre la garnison du second. L'essai fige que le triplet est bien
 * un triplet.
 */
class CombatIdempotenceConstraintsTest extends TestCase
{
    /**
     * Le nombre de corps deja utilises, pour en donner un different a chaque essai.
     */
    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Un corps celeste ne porte qu'un combat a la fois.
     *
     * C'est la garantie que le code seul ne pouvait pas donner : deux flottes arrivant a la meme
     * seconde lisaient toutes les deux « libre ».
     */
    public function testACelestialBodyCarriesOnlyOneCombat(): void
    {
        $corps = $this->aBodyId();
        $premier = $this->aCombat();
        $second = $this->aCombat();

        CelestialBodyCombatBarrier::create($this->barrierOn($corps, $premier->id));

        $this->assertRefused(
            fn () => CelestialBodyCombatBarrier::create($this->barrierOn($corps, $second->id)),
            'A second combat opened on a body that was already held: the loser of the race should learn it joins.'
        );
    }

    /**
     * Un effet n'est applique qu'une fois au monde.
     */
    public function testAnEffectIsAppliedToTheWorldOnlyOnce(): void
    {
        $identite = 'transport:9001:delivery';

        CombatEffectReceipt::create($this->receipt($identite));

        $this->assertRefused(
            fn () => CombatEffectReceipt::create($this->receipt($identite)),
            'The same effect was applied to the world twice.'
        );
    }

    /**
     * Un evenement n'entre qu'une fois dans une photographie donnee.
     */
    public function testAnEventEntersOneSnapshotOnlyOnce(): void
    {
        $combat = $this->aCombat();
        $identite = 'event:arrival:4242';

        CombatSnapshotInclusion::create($this->inclusion($combat->id, $identite));

        $this->assertRefused(
            fn () => CombatSnapshotInclusion::create($this->inclusion($combat->id, $identite)),
            'The same event was counted twice in one snapshot.'
        );
    }

    /**
     * Le meme evenement entre dans deux combats differents, et une seule fois dans chacun.
     *
     * **Les deux moities comptent.** Une unicite sur le seul evenement aurait fait disparaitre
     * la garnison du second combat ; une unicite qui inclut la projection aurait laisse le meme
     * evenement entrer deux fois dans le meme combat, sous deux versions.
     *
     * C'est `combat_instance_id` qui separe les combats, et lui seul.
     */
    public function testTheSameEventEntersTwoDifferentCombats(): void
    {
        $premier = $this->aCombat();
        $second = $this->aCombat();
        $identite = 'event:garrison:77';

        CombatSnapshotInclusion::create($this->inclusion($premier->id, $identite));
        CombatSnapshotInclusion::create($this->inclusion($second->id, $identite));

        $this->assertSame(
            2,
            CombatSnapshotInclusion::where('event_identity', $identite)->count(),
            'The garrison could not be read by a second combat on the same planet.'
        );

        // **Deux projections du meme evenement dans un meme combat sont refusees**, et cet essai
        // affirmait autrefois le contraire.
        //
        // Le raisonnement d'alors : « les deux formes doivent coexister le temps d'une bascule ».
        // Il etait faux, parce qu'une **instance** n'a qu'une projection gelee. Les versions
        // coexistent entre deux combats, grace a `combat_instance_id` — jamais a l'interieur
        // d'une meme photographie. Avec l'ancienne clef, un defaut qui aurait ecrit v2 dans un
        // combat v1 aurait insere l'evenement une seconde fois sans que rien ne s'y oppose.
        $this->assertRefused(
            fn () => CombatSnapshotInclusion::create(array_merge(
                $this->inclusion($premier->id, $identite),
                ['projection_version' => 'v2']
            )),
            'A second projection of the same event slipped into one snapshot.'
        );

        $this->assertSame(
            1,
            CombatSnapshotInclusion::where('combat_instance_id', $premier->id)->count(),
            'One combat holds more than one inclusion for the same event.'
        );
    }

    /**
     * Un combat n'immobilise ses ressources qu'une fois.
     */
    public function testACombatReservesItsLootOnlyOnce(): void
    {
        $combat = $this->aCombat();

        CombatLootReservation::create($this->reservation($combat->id));

        $this->assertRefused(
            fn () => CombatLootReservation::create($this->reservation($combat->id)),
            'The same resources were reserved twice, so the resolution would hand out double.'
        );
    }

    /**
     * Un destinataire ne recoit qu'un message de chaque genre.
     */
    public function testAParticipantGetsOneMessageOfEachKind(): void
    {
        $combat = $this->aCombat();

        CombatOutboxMessage::create($this->message($combat->id, CombatParticipantKey::forFleet(1), 'battle_report'));

        $this->assertRefused(
            fn () => CombatOutboxMessage::create($this->message($combat->id, CombatParticipantKey::forFleet(1), 'battle_report')),
            'A replayed resolution produced two battle reports for the same player.'
        );

        // Un autre genre pour le meme destinataire reste permis : le rapport et la lune detruite
        // sont deux messages, pas un doublon.
        CombatOutboxMessage::create($this->message($combat->id, CombatParticipantKey::forFleet(1), 'moon_destroyed'));

        $this->assertSame(
            2,
            CombatOutboxMessage::where('combat_instance_id', $combat->id)->count(),
            'A second kind of message to the same player was refused as if it were a duplicate.'
        );
    }

    /**
     * Exige que la base refuse cette ecriture.
     *
     * Le point de sauvegarde est explicite : sur PostgreSQL comme sur MariaDB, une erreur laisse la
     * transaction dans un etat dont il faut sortir avant d'ecrire a nouveau. Sans lui, l'essai
     * suivant echouerait pour une raison qui n'est pas la sienne.
     *
     * @param callable(): mixed $ecriture
     */
    private function assertRefused(callable $ecriture, string $message): void
    {
        DB::statement('SAVEPOINT avant_doublon');

        try {
            $ecriture();
        } catch (QueryException $refus) {
            DB::statement('ROLLBACK TO SAVEPOINT avant_doublon');

            // **Refusee comme doublon, pas refusee pour autre chose.** Une cle etrangere absente,
            // une colonne obligatoire vide, une table mal nommee font echouer l ecriture elles
            // aussi — et l essai passerait en pretendant avoir prouve l unicite. SQLite dit
            // « UNIQUE constraint failed », MariaDB « Duplicate entry ».
            $this->assertMatchesRegularExpression(
                '/unique|duplicate/i',
                $refus->getMessage(),
                'The write failed, but not because the database refused a duplicate: ' . $refus->getMessage()
            );

            return;
        }

        DB::statement('ROLLBACK TO SAVEPOINT avant_doublon');

        $this->fail($message);
    }

    /**
     * Un combat minimal, juste assez pour porter une ligne liee.
     */
    private function aCombat(): CombatInstance
    {
        $corps = $this->aBodyId();

        return CombatInstance::create([
            'status' => CombatState::Rallying,
            'mission_id' => $corps,
            'target_planet_id' => $corps,
            'target_type' => 1,
            'galaxy' => 1,
            'system' => 1,
            'position' => ($corps % 15) + 1,
        ]);
    }

    /**
     * Un identifiant de corps a lui, deterministe.
     */
    private function aBodyId(): int
    {
        $this->bodies++;

        return 900_000 + $this->bodies;
    }

    /**
     * @return array<string, int>
     */
    private function barrierOn(int $bodyId, int $combatId): array
    {
        return [
            'target_body_id' => $bodyId,
            'combat_instance_id' => $combatId,
            'opened_at' => 1_000,
            'owned_through_effect_at' => 1_060,
            'revision' => 0,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function receipt(string $identity): array
    {
        return [
            'event_identity' => $identity,
            'kind_version' => 'delivery_v1',
            'effect_fingerprint' => 'sha256:' . substr(hash('sha256', $identity), 0, 32),
            'aggregate_key' => 'aggregate:planet-resources:77',
            'applied_at' => 1_000,
            'receipt_id' => substr(hash('sha256', $identity . ':receipt'), 0, 36),
        ];
    }

    /**
     * @return array<string, string|int|array<int, string>>
     */
    private function inclusion(int $combatId, string $identity): array
    {
        return [
            'combat_instance_id' => $combatId,
            'event_identity' => $identity,
            'projection_version' => 'v1',
            'contributions' => [SnapshotContribution::DefendingFleet->value],
            'included_at' => 1_000,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function reservation(int $combatId): array
    {
        return [
            'combat_instance_id' => $combatId,
            'target_body_id' => 77,
            'metal' => 1_000,
            'crystal' => 500,
            'deuterium' => 250,
            'opened_at' => 1_000,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function message(int $combatId, string $participant, string $kind): array
    {
        return [
            'combat_instance_id' => $combatId,
            'participant_key' => $participant,
            'kind' => $kind,
            'available_at' => 1_000,
        ];
    }
}
