<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use Tests\AccountTestCase;

/**
 * La suppression en attente d'un compte se voit sur chaque page du jeu.
 *
 * ## Pourquoi
 *
 * Un compte qui renforce le combat d'un autre joueur passe en suppression en attente : il ne lance
 * plus rien, et sa suppression reprend d'elle-meme quand ces combats sont finaux. C'est une
 * decision de Keven, et elle a une contrepartie : **l'attente et sa raison doivent etre visibles**.
 * Un joueur qui ne comprendrait pas pourquoi ses flottes ne partent plus conclurait a une panne.
 */
class PendingDeletionNoticeTest extends AccountTestCase
{
    public function testAnOrdinaryAccountSeesNoNotice(): void
    {
        $vue = $this->get('/overview');

        $vue->assertStatus(200);
        $vue->assertDontSee(__('t_ingame.layout.deletion_pending_title'));
        $vue->assertDontSee('id="deletionPendingNotice"', false);
    }

    public function testAnAccountWhoseDeletionIsPendingSeesItOnEveryPageWithTheInstant(): void
    {
        $depuis = (int)now()->timestamp - 3_600;
        DB::table('users')->where('id', $this->currentUserId)->update(['deletion_pending_since' => $depuis]);

        foreach (['/overview', '/fleet', '/galaxy', '/messages'] as $page) {
            $vue = $this->get($page);

            $vue->assertStatus(200);
            $vue->assertSee(__('t_ingame.layout.deletion_pending_title'));
            $vue->assertSee(date('d.m.Y H:i', $depuis));
            $vue->assertSee('id="deletionPendingNotice"', false);
        }

        // **Le temoin qui discrimine** : une fois l'attente levee, la banniere disparait.
        DB::table('users')->where('id', $this->currentUserId)->update(['deletion_pending_since' => null]);

        $this->get('/overview')->assertDontSee(__('t_ingame.layout.deletion_pending_title'));
    }
}
