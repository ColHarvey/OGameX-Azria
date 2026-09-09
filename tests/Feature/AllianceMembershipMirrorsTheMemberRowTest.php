<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Services\AllianceService;
use Tests\AccountTestCase;

/**
 * `users.alliance_id` et la ligne d `alliance_members` disent la meme chose, sur les cinq routes.
 *
 * ## Pourquoi cette equivalence porte une decision
 *
 * La decision d alliance prise sous le rendez-vous des joueurs
 * (`AllianceOffensiveGuard::forbidsUnderTheRendezvous()`) lit `alliance_members`, et **pas**
 * `users.alliance_id`. Ce n est pas un gout : sous `REPEATABLE READ` une lecture ordinaire rend le
 * monde du debut de la transaction, donc la relecture doit etre verrouillante — et verrouiller la
 * ligne du compte fermerait le cycle que `PlayerCoordinationBarrier` existe pour eviter.
 *
 * L equivalence des deux sources devient alors une **hypothese de correction**, et une hypothese se
 * prouve. Les cinq routes qui changent une appartenance passent ici : fondation, admission, depart
 * volontaire, exclusion, dissolution. Le jour ou un chemin ecrirait `users.alliance_id` sans sa
 * ligne — ou l inverse — la protection laisserait passer une offensive entre allies, en silence.
 *
 * ## Ce que le temoin regarde, et pourquoi pas le service
 *
 * Les deux tables, directement. `arePlayersInSameAlliance()` ne lit qu une des deux : s en servir ici
 * reviendrait a demander a l une des sources de se porter garante de l autre.
 */
class AllianceMembershipMirrorsTheMemberRowTest extends AccountTestCase
{
    private int|null $alliance = null;

    protected function tearDown(): void
    {
        if ($this->alliance !== null) {
            DB::table('users')->where('alliance_id', $this->alliance)->update(['alliance_id' => null, 'alliance_left_at' => null]);
            AllianceMember::query()->where('alliance_id', $this->alliance)->delete();
            Alliance::query()->whereKey($this->alliance)->delete();
            $this->alliance = null;
        }

        parent::tearDown();
    }

    /**
     * Les cinq routes, l une apres l autre, chacune sur un joueur qui lui est propre.
     *
     * Un joueur par route, et ce n est pas une commodite : quitter une alliance pose une echeance de
     * depart, et reutiliser le meme joueur ferait echouer la route suivante pour une raison qui n a
     * rien a voir avec ce que ce temoin etablit.
     */
    public function testTheFiveMembershipRoutesKeepBothSourcesInAgreement(): void
    {
        $fondateur = $this->currentUserId;

        $this->createAndLoginUser();
        $partant = $this->currentUserId;

        $this->createAndLoginUser();
        $exclu = $this->currentUserId;

        $this->createAndLoginUser();
        $dissous = $this->currentUserId;

        $service = resolve(AllianceService::class);

        // 1. Fondation.
        $alliance = (int)$service->createAlliance($fondateur, 'M' . str_pad(substr((string)$fondateur, -4), 4, '0', STR_PAD_LEFT), 'Miroir ' . $fondateur)->id;
        $this->alliance = $alliance;
        $this->assertMirror($fondateur, $alliance, 'founding the alliance');

        // 2. Admission.
        $this->admettre($alliance, $partant);
        $this->assertMirror($partant, $alliance, 'accepting an application');

        // 3. Depart volontaire.
        $service->leaveAlliance($partant);
        $this->assertMirror($partant, null, 'leaving the alliance');

        // 4. Exclusion.
        $this->admettre($alliance, $exclu);
        $service->kickMember($alliance, $exclu, $fondateur);
        $this->assertMirror($exclu, null, 'being kicked out');

        // 5. Dissolution — elle emporte tous les membres a la fois.
        $this->admettre($alliance, $dissous);
        $service->disbandAlliance($alliance, $fondateur);
        $this->assertMirror($dissous, null, 'the alliance being disbanded');
        $this->assertMirror($fondateur, null, 'the alliance being disbanded');

        $this->alliance = null;
    }

    private function admettre(int $alliance, int $joueur): void
    {
        $service = resolve(AllianceService::class);
        $candidature = $service->applyToAlliance($joueur, $alliance);
        $service->acceptApplication((int)$candidature->id, $this->founderOf($alliance));
    }

    /**
     * Le fondateur est le seul a pouvoir accepter : il est le premier membre de l alliance courante.
     */
    private function founderOf(int $alliance): int
    {
        $fondateur = AllianceMember::query()
            ->where('alliance_id', $alliance)
            ->orderBy('id')
            ->value('user_id');

        $this->assertNotNull($fondateur, 'The alliance has no founder: the scenario is broken.');

        return (int)$fondateur;
    }

    /**
     * Les deux sources, comparees champ par champ — jamais l une par l autre.
     */
    private function assertMirror(int $joueur, int|null $alliance, string $apres): void
    {
        $surLeCompte = DB::table('users')->where('id', $joueur)->value('alliance_id');
        $surLaLigne = DB::table('alliance_members')->where('user_id', $joueur)->value('alliance_id');

        $this->assertSame(
            $alliance,
            $surLeCompte === null ? null : (int)$surLeCompte,
            'After ' . $apres . ', users.alliance_id does not hold the expected membership.'
        );

        $this->assertSame(
            $alliance,
            $surLaLigne === null ? null : (int)$surLaLigne,
            'After ' . $apres . ', the alliance_members row does not mirror users.alliance_id: '
            . 'the decision taken under the rendezvous reads that row, and would answer wrongly.'
        );
    }
}
