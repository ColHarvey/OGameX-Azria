<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Services\AllianceService;
use OGame\Services\ChatService;
use Tests\AccountTestCase;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * La migration pose les curseurs d alliance sur **une** borne, capturee une fois.
 *
 * L historique existant ne devient pas un tas de non-lus : chaque membre actuel recoit
 * `last_read_message_id = max(chat_messages.id)` lu au debut de la migration. Tout message publie apres cette
 * borne — y compris pendant qu elle tourne — porte un identifiant superieur et reste non lu. Le temoin
 * **execute** la migration sur une base qui a des membres et des messages : lire son texte ne suffirait pas.
 */
final class ChatAllianceCursorMigrationTest extends AccountTestCase
{
    use DetachesFromAnyAlliance;

    private const string MIGRATION = 'database/migrations/2026_09_21_120000_create_chat_alliance_reads_table.php';

    /** L alliance du montage ; zero tant qu il n y en a pas (le demontage ignore zero). */
    private int $alliance = 0;

    protected function tearDown(): void
    {
        $this->dissolveTheBenchAlliances($this->alliance);
        $this->alliance = 0;

        parent::tearDown();
    }

    public function testRunningTheMigrationPosesEveryCursorOnTheSingleBoundAndLeavesWhatFollowsUnread(): void
    {
        $fondateur = $this->currentUserId;
        $this->createAndLoginUser();
        $membre = $this->currentUserId;
        $service = resolve(AllianceService::class);
        $chat = resolve(ChatService::class);
        $this->alliance = (int)$service->createAlliance($fondateur, 'B' . str_pad(substr((string)$fondateur, -4), 4, '0', STR_PAD_LEFT), 'Borne ' . $fondateur)->id;
        $candidature = $service->applyToAlliance($membre, $this->alliance);
        $service->acceptApplication((int)$candidature->id, $fondateur);

        // L historique d avant la migration : pour tous, il ne doit PAS devenir un non-lu.
        $chat->sendAllianceMessage($fondateur, $this->alliance, 'ancien un');
        $dernierAvant = (int)$chat->sendAllianceMessage($fondateur, $this->alliance, 'ancien deux')->id;
        $borneGlobale = (int)DB::table('chat_messages')->max('id');

        // Le monde d avant la migration : la table n existe pas encore, et le migrateur ne l a pas encore vue.
        // **Par le vrai migrateur**, pas par un appel direct : c est lui qui tournera sur le serveur.
        Schema::dropIfExists('chat_alliance_reads');
        DB::table('migrations')->where('migration', pathinfo(self::MIGRATION, PATHINFO_FILENAME))->delete();
        $this->assertFalse(Schema::hasTable('chat_alliance_reads'));

        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]), 'Le migrateur a refuse la migration.');

        $this->assertTrue(Schema::hasTable('chat_alliance_reads'));
        foreach ([$fondateur, $membre] as $joueur) {
            $curseur = DB::table('chat_alliance_reads')->where('user_id', $joueur)->where('alliance_id', $this->alliance)->value('last_read_message_id');
            $this->assertNotNull($curseur, "Le membre $joueur n a pas recu de curseur.");
            $this->assertSame($borneGlobale, (int)$curseur, 'La borne est l unique max(id) capture au debut, pour tous les membres.');
            $this->assertGreaterThanOrEqual($dernierAvant, (int)$curseur);
            $this->assertSame(0, $chat->unreadSnapshot($joueur)['total'], "Au lendemain de la migration, le membre $joueur n a aucun non-lu d alliance.");
        }

        // Ce qui suit la borne reste non lu, pour le membre qui ne l a pas ecrit.
        $chat->sendAllianceMessage($fondateur, $this->alliance, 'apres la borne');
        $this->assertSame(1, $chat->unreadSnapshot($membre)['total']);
        $this->assertSame(0, $chat->unreadSnapshot($fondateur)['total']);
    }

    /**
     * La garde de source : la borne est lue **une** fois, **avant** la boucle d ecriture. Une borne recalculee
     * par membre, ou lue apres, laisserait un message publie pendant la migration passer pour lu.
     */
    public function testTheBoundIsCapturedOnceBeforeTheRowsAreWritten(): void
    {
        $source = (string)file_get_contents(base_path(self::MIGRATION));

        $this->assertSame(1, substr_count($source, "max('id')"), 'La borne est lue une seule fois.');
        $borne = strpos($source, "max('id')");
        $boucle = strpos($source, 'foreach');
        $this->assertNotFalse($borne);
        $this->assertNotFalse($boucle);
        $this->assertLessThan($boucle, $borne, 'La borne est lue avant la premiere ecriture.');
        $this->assertStringContainsString('insertOrIgnore', $source, 'Une ligne deja presente n est jamais ecrasee.');
    }
}
