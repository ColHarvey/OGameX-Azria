<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Presentation\CombatBroadcasterLease;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le bail du diffuseur bat encore quand rien ne change — mesure que SQLite ne peut pas faire.
 *
 * ## Le defaut, tel qu'il s'est presente en production
 *
 * `heartbeat()` deduisait la possession du nombre de lignes qu'un `UPDATE` rend. **MariaDB compte
 * les lignes changees, pas les lignes trouvees** : le premier battement suit la prise dans la meme
 * seconde, reecrit `heartbeat_at` a l'identique, ne change donc aucune ligne, et `=== 1` devenait
 * faux. Le diffuseur annoncait « un autre diffuseur a pris la releve », rendait son bail et sortait
 * — a chaque minute, sans une ligne de journal, pendant que la suite d'essais restait verte.
 *
 * ## Pourquoi cet essai vit ici et nulle part ailleurs
 *
 * Sous SQLite, `update()` rend le nombre de lignes **trouvees** : le code fautif et le code correct
 * y donnent la meme reponse. Le juste et le faux coincidaient, et aucun essai de la suite ne pouvait
 * les separer. Seul le moteur de production tranche, donc l'epreuve est au bac.
 */
#[Group('mariadb')]
final class BroadcasterLeaseHeartbeatTest extends TestCase
{
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresMariaDb();

        DB::table('combat_broadcaster_leases')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('combat_broadcaster_leases')->delete();

        parent::tearDown();
    }

    /**
     * Battre dans la seconde de la prise ne perd pas le bail.
     *
     * C'est le cas exact de la production : `acquire()` ecrit `heartbeat_at = $now`, et la premiere
     * garde de la boucle rebat avec le meme instant. Rien ne change dans la ligne — et pourtant le
     * detenteur la tient toujours.
     */
    public function testTheHolderKeepsTheLeaseWhenTheBeatChangesNothing(): void
    {
        $bail = new CombatBroadcasterLease('essai:1');
        $instant = 1_700_000_000;

        $this->assertTrue($bail->acquire($instant), 'The lease could not be taken on an empty table.');

        // Le meme instant, donc la meme valeur : c'est ici que MariaDB rendait zero.
        $this->assertTrue(
            $bail->heartbeat($instant),
            'The holder lost its own lease by writing the value it had just written.'
        );

        // Et une seconde plus tard, quand la valeur change vraiment.
        $this->assertTrue($bail->heartbeat($instant + 1), 'The holder lost its lease on a beat that did change the row.');

        $this->assertSame(
            'essai:1',
            (string)DB::table('combat_broadcaster_leases')->where('name', CombatBroadcasterLease::NAME)->value('holder'),
            'The lease no longer names its holder.'
        );
    }

    /**
     * Celui qui ne tient plus le bail le sait, meme en battant a l'identique.
     *
     * Sans ce temoin, la correction pourrait rendre `true` a tout le monde — le faux deviendrait
     * invisible, et deux diffuseurs emettraient en meme temps.
     */
    public function testAHolderThatLostTheLeaseIsToldSo(): void
    {
        $premier = new CombatBroadcasterLease('essai:1');
        $instant = 1_700_000_000;

        $this->assertTrue($premier->acquire($instant));

        // Le bail est perime : un second le reprend, comme une releve apres panne.
        $second = new CombatBroadcasterLease('essai:2');
        $this->assertTrue(
            $second->acquire($instant + CombatBroadcasterLease::TOLERANCE + 1),
            'The stale lease could not be taken over.'
        );

        $this->assertFalse(
            $premier->heartbeat($instant + CombatBroadcasterLease::TOLERANCE + 1),
            'The dispossessed holder believed it still held the lease.'
        );

        $this->assertTrue($second->heartbeat($instant + CombatBroadcasterLease::TOLERANCE + 1), 'The new holder lost the lease it had just taken.');
    }

    /**
     * Un bail rendu par son detenteur ne l'est pas par un autre.
     */
    public function testOnlyTheHolderReleasesTheLease(): void
    {
        $premier = new CombatBroadcasterLease('essai:1');
        $this->assertTrue($premier->acquire(1_700_000_000));

        (new CombatBroadcasterLease('essai:2'))->release();

        $this->assertTrue(
            DB::table('combat_broadcaster_leases')->where('name', CombatBroadcasterLease::NAME)->exists(),
            'A stranger released a lease it did not hold.'
        );

        $premier->release();

        $this->assertFalse(
            DB::table('combat_broadcaster_leases')->where('name', CombatBroadcasterLease::NAME)->exists(),
            'The holder could not release its own lease.'
        );
    }
}
