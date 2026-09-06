<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\StarterAidClaim;
use OGame\Services\OfficerService;
use OGame\Services\PlayerService;
use OGame\Services\StarterAidService;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * Le septieme jour du pack de bienvenue : un officier, choisi par le joueur, pour sept jours.
 *
 * ## La regle, telle que Keven l'a arretee
 *
 * Le jeu d'origine offre l'etat-major entier pendant trois jours. Ici, **un seul officier mais plus
 * longtemps**, et c'est le joueur qui decide lequel. Ce qui suit epingle les quatre points ou cette
 * regle peut se perdre : le choix est exige, il est le seul honore, sa duree est de sept jours, et
 * un officier deja en poste voit son terme repousse au lieu d'etre remplace.
 *
 * ## Ce qu'un essai plus naif laisserait passer
 *
 * Verifier « un officier est actif » suffirait a passer meme si les cinq l'etaient. Chaque essai
 * compare donc **l'ensemble** des officiers actifs, jamais un seul.
 */
class StarterAidOfficerRewardTest extends AccountTestCase
{
    private StarterAidService $pack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pack = resolve(StarterAidService::class);

        // La recompense se debloque six jours apres l'inscription : on avance le compte, pas
        // l'horloge, pour ne pas deplacer les autres essais du meme processus.
        DB::table('users')->where('id', $this->currentUserId)->update([
            'created_at' => now()->subDays(30),
        ]);

        StarterAidClaim::where('user_id', $this->currentUserId)->delete();
    }

    /**
     * Sans choix, rien n'est donne — et rien n'est marque comme pris.
     *
     * C'est le point qui compte : la creance est inscrite des l'entree dans la transaction, et sa
     * contrainte d'unicite est definitive. Un refus tardif laisserait la recompense encaissee et
     * l'officier jamais nomme.
     */
    public function testWithoutAChoiceNothingIsGivenAndNothingIsMarkedAsTaken(): void
    {
        $joueur = $this->joueur();

        try {
            $this->pack->claim($joueur, 7);
            $this->fail('The reward was handed out without the player choosing an officer.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('t_ingame.rewards.error_officer_required'), $e->getMessage());
        }

        $this->assertSame([], $this->activeOfficers(), 'An officer took post although the choice was refused.');
        $this->assertDatabaseMissing('starter_aid_claims', ['user_id' => $this->currentUserId, 'day' => 7]);
    }

    /**
     * Un nom qui n'est pas un officier est refuse comme une absence de choix.
     */
    public function testAnUnknownOfficerIsRefused(): void
    {
        $joueur = $this->joueur();

        $this->expectException(RuntimeException::class);

        try {
            $this->pack->claim($joueur, 7, 'grand_amiral_supreme');
        } finally {
            $this->assertSame([], $this->activeOfficers(), 'An unknown name still put someone in post.');
            $this->assertDatabaseMissing('starter_aid_claims', ['user_id' => $this->currentUserId, 'day' => 7]);
        }
    }

    /**
     * L'officier choisi entre en poste pour sept jours — et lui seul.
     */
    public function testTheChosenOfficerAndOnlyThatOneTakesPostForSevenDays(): void
    {
        $joueur = $this->joueur();

        $this->pack->claim($joueur, 7, 'geologist');

        $this->assertSame(['geologist'], $this->activeOfficers(), 'The reward did not put exactly the chosen officer in post.');

        $terme = resolve(OfficerService::class)->getExpiry($this->joueur()->getUser(), 'geologist');
        $this->assertNotNull($terme);

        // Sept jours, a la minute pres : la duree est la regle, pas une approximation.
        $this->assertEqualsWithDelta(
            now()->addDays(7)->timestamp,
            $terme->timestamp,
            60,
            'The chosen officer was not put in post for seven days.'
        );

        $this->assertDatabaseHas('starter_aid_claims', ['user_id' => $this->currentUserId, 'day' => 7]);
    }

    /**
     * Un officier deja en poste voit son terme repousse, jamais remplace.
     *
     * Sans cette regle, offrir sept jours a un joueur qui vient d'en acheter quatre-vingt-dix lui en
     * couterait quatre-vingt-trois : le cadeau serait une punition.
     */
    public function testAnOfficerAlreadyInPostHasTheTermPushedBackNotReplaced(): void
    {
        $joueur = $this->joueur();
        $officiers = resolve(OfficerService::class);

        $officiers->grant($joueur, 'admiral', 90);
        $termeAvant = $officiers->getExpiry($this->joueur()->getUser(), 'admiral');
        $this->assertNotNull($termeAvant);

        $this->pack->claim($this->joueur(), 7, 'admiral');

        $termeApres = $officiers->getExpiry($this->joueur()->getUser(), 'admiral');
        $this->assertNotNull($termeApres);

        $this->assertEqualsWithDelta(
            $termeAvant->copy()->addDays(7)->timestamp,
            $termeApres->timestamp,
            60,
            'The reward replaced the running term instead of extending it.'
        );
    }

    /**
     * La recompense ne se prend qu'une fois, meme en changeant d'officier.
     */
    public function testASecondClaimIsRefusedEvenWithAnotherOfficer(): void
    {
        $joueur = $this->joueur();

        $this->pack->claim($joueur, 7, 'commander');

        try {
            $this->pack->claim($this->joueur(), 7, 'technocrat');
            $this->fail('The seventh-day reward was claimed twice.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('t_ingame.rewards.error_already_claimed'), $e->getMessage());
        }

        $this->assertSame(['commander'], $this->activeOfficers(), 'The refused second claim still put a second officer in post.');
    }

    /**
     * Le resume de la recompense annonce le choix, pas l'etat-major entier.
     */
    public function testTheSummaryAnnouncesOneOfficerOfTheirChoice(): void
    {
        $apercu = $this->pack->getOverview($this->joueur());

        $this->assertSame(
            OfficerService::OFFICERS,
            $apercu[7]['officer_choices'],
            'The page would offer a set of officers the service does not accept.'
        );

        $this->assertSame(
            [],
            $apercu[1]['officer_choices'],
            'A reward without an officer still offered a choice.'
        );

        $this->assertStringContainsString(
            trans_choice('t_ingame.rewards.gain_officers', 7, ['days' => 7]),
            $apercu[7]['summary'],
            'The summary does not name what the reward gives.'
        );
    }

    /**
     * Les officiers actifs de ce joueur, par ordre de la liste du jeu.
     *
     * @return array<int, string>
     */
    private function activeOfficers(): array
    {
        $officiers = resolve(OfficerService::class);
        $utilisateur = $this->joueur()->getUser();

        return array_values(array_filter(
            OfficerService::OFFICERS,
            static fn (string $officier): bool => $officiers->isActive($utilisateur, $officier)
        ));
    }

    /**
     * Le joueur relu depuis la base : la fabrique garde ses instances, et un octroi ecrit sur la
     * ligne. Relire est le seul moyen de lire ce que le service vient d'ecrire.
     */
    private function joueur(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }
}
