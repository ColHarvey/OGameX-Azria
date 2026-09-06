<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\HonorPolicy;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\HonorService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le systeme d'honneur : sa formule, sa fourchette, ses statuts.
 *
 * ## Ce que ces essais tiennent
 *
 * Trois regles d'OGame officiel, choisies par Keven le 6 septembre 2026, et qui ne se lisent nulle
 * part ailleurs dans le code :
 *
 *     points = (valeur detruite) ^ 0,9 / 1000
 *     combat honorable  <=>  chaque camp pese au moins la moitie de l'autre
 *     bandit  <=>  honneur <= -500
 *
 * L'exposant est le coeur du systeme : il empeche une seule grande bataille d'ecraser tout le
 * reste. Un essai qui ne verifierait que « plus de destruction, plus de points » passerait avec une
 * simple division — c'est pourquoi les valeurs sont epinglees en dur.
 *
 * ## Ce qu'ils ne tiennent pas
 *
 * Le raccordement au reglement d'une bataille reelle : ce service ne lit ni n'ecrit rien pendant le
 * combat, il rend une decision sur des nombres. Le credit lui-meme vit dans le reglement, sous ses
 * verrous, et s'eprouve la-bas.
 */
class HonorSystemTest extends AccountTestCase
{
    private HonorService $honneur;

    private SettingsService $reglages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->honneur = resolve(HonorService::class);
        $this->reglages = resolve(SettingsService::class);
        $this->reglages->set('honor_system_enabled', '1');
    }

    protected function tearDown(): void
    {
        // **L'epreuve rabaisse l'interrupteur qu'elle a leve.** La base d'un processus est partagee,
        // et une classe voisine qui trouverait le systeme arme mesurerait autre chose que son sujet.
        $this->reglages->set('honor_system_enabled', '0');

        parent::tearDown();
    }

    /**
     * La formule officielle, epinglee sur ses valeurs.
     *
     * L'exposant 0,9 est ce qui distingue ce systeme d'une division : detruire cent fois plus ne
     * rapporte que soixante-trois fois plus. Sans ces nombres en dur, un code qui diviserait
     * simplement par mille passerait.
     */
    public function testTheOfficialFormulaIsPinnedToItsValues(): void
    {
        $this->assertSame(0, $this->honneur->magnitudeOf(0), 'A battle that destroyed nothing paid something.');
        $this->assertSame(0, $this->honneur->magnitudeOf(1_000), 'A skirmish already paid a whole point.');
        $this->assertSame(31, $this->honneur->magnitudeOf(100_000));
        $this->assertSame(251, $this->honneur->magnitudeOf(1_000_000));
        $this->assertSame(1_069, $this->honneur->magnitudeOf(5_000_000));
        $this->assertSame(15_848, $this->honneur->magnitudeOf(100_000_000));

        // **Cent fois plus detruit, soixante-trois fois plus de points** : c'est l'amortissement, et
        // c'est exactement ce qu'une division lineaire ne ferait pas.
        $this->assertSame(
            63,
            (int)round($this->honneur->magnitudeOf(100_000_000) / $this->honneur->magnitudeOf(1_000_000)),
            'The exponent does not damp large battles: the system behaves like a plain division.'
        );
    }

    /**
     * Une valeur negative ne rapporte rien, et ne leve pas.
     */
    public function testANegativeValuePaysNothing(): void
    {
        $this->assertSame(0, $this->honneur->magnitudeOf(-1));
        $this->assertSame(0, $this->honneur->magnitudeOf(-1_000_000));
    }

    /**
     * La fourchette du combat honorable joue dans les deux sens.
     *
     * Un petit qui attaque un geant n'est pas plus honorable qu'un geant qui ecrase un petit : c'est
     * l'ecart qui compte, pas sa direction. Un essai qui ne testerait qu'un sens laisserait passer
     * une comparaison unilaterale.
     */
    public function testTheHonorableRangeWorksInBothDirections(): void
    {
        $this->assertTrue($this->honneur->isHonorableFight(1_000, 1_000), 'Two equals did not make an honourable fight.');
        $this->assertTrue($this->honneur->isHonorableFight(1_000, 500), 'The lower bound of the range was refused.');
        $this->assertTrue($this->honneur->isHonorableFight(500, 1_000), 'The range refused the same gap the other way round.');

        $this->assertFalse($this->honneur->isHonorableFight(1_000, 499), 'A far weaker defender still made the fight honourable.');
        $this->assertFalse($this->honneur->isHonorableFight(499, 1_000), 'A far weaker attacker still made the fight honourable.');
    }

    /**
     * Un camp sans points militaires ne rend jamais le combat honorable.
     *
     * Il n'y a rien a mesurer, et une division le dirait mal. C'est le cas d'un compte neuf, ou d'un
     * classement qui n'est pas encore passe.
     */
    public function testASideWithoutMilitaryPointsIsNeverHonorable(): void
    {
        $this->assertFalse($this->honneur->isHonorableFight(0, 1_000));
        $this->assertFalse($this->honneur->isHonorableFight(1_000, 0));
        $this->assertFalse($this->honneur->isHonorableFight(0, 0));
    }

    /**
     * Le seuil du bandit, et sa borne exacte.
     *
     * A -500 pile on est bandit, a -499 on ne l'est pas encore : la borne est fermee, et un essai
     * qui ne l'eprouverait qu'a -1000 laisserait passer un decalage d'un point.
     */
    public function testTheOutlawThresholdIsClosedAtItsBound(): void
    {
        $joueur = $this->aUserWithHonor(-499);
        $this->assertFalse($this->honneur->isOutlaw($joueur), 'A player above the threshold was already an outlaw.');

        $joueur = $this->aUserWithHonor(-500);
        $this->assertTrue($this->honneur->isOutlaw($joueur), 'A player exactly on the threshold was not an outlaw.');

        $joueur = $this->aUserWithHonor(-5_000);
        $this->assertTrue($this->honneur->isOutlaw($joueur));
    }

    /**
     * Zero n'est pas une cible honorable : c'est l'etat de depart de tout le monde.
     */
    public function testZeroIsNotAnHonorableTarget(): void
    {
        $this->assertFalse($this->honneur->isHonorableTarget($this->aUserWithHonor(0)), 'A brand new account was already an honourable target.');
        $this->assertTrue($this->honneur->isHonorableTarget($this->aUserWithHonor(1)));
        $this->assertFalse($this->honneur->isHonorableTarget($this->aUserWithHonor(-1)));
    }

    /**
     * L'interrupteur eteint neutralise tous les statuts.
     *
     * C'est ce qui permet de deployer le systeme sur un univers en cours sans que le pillage bouge
     * d'un point. Sans ce temoin, on pourrait croire l'interrupteur cable et le trouver inerte.
     */
    public function testTheSwitchNeutralisesEveryStatus(): void
    {
        $bandit = $this->aUserWithHonor(-5_000);
        $honorable = $this->aUserWithHonor(5_000);

        $this->reglages->set('honor_system_enabled', '0');

        $this->assertFalse($this->honneur->isOutlaw($bandit), 'The outlaw status survived the switch being off.');
        $this->assertFalse($this->honneur->isHonorableTarget($honorable), 'The honourable status survived the switch being off.');
        $this->assertSame(HonorPolicy::Disabled, $this->honneur->lootPolicyAgainst($bandit));
        $this->assertSame(0, $this->honneur->lootPolicyAgainst($bandit)->minimumRateInBasisPoints(), 'A disabled system still imposed a loot rate.');
    }

    /**
     * Chaque statut impose son taux de pillage, et l'ordre entre eux est celui d'OGame.
     */
    public function testEachStatusImposesItsOwnLootRate(): void
    {
        $this->assertSame(HonorPolicy::Outlaw, $this->honneur->lootPolicyAgainst($this->aUserWithHonor(-5_000)));
        $this->assertSame(HonorPolicy::HonorableTarget, $this->honneur->lootPolicyAgainst($this->aUserWithHonor(5_000)));
        $this->assertSame(HonorPolicy::Neutral, $this->honneur->lootPolicyAgainst($this->aUserWithHonor(0)));

        $this->assertSame(10_000, HonorPolicy::Outlaw->minimumRateInBasisPoints(), 'An outlaw is not looted whole.');
        $this->assertSame(7_500, HonorPolicy::HonorableTarget->minimumRateInBasisPoints(), 'An honourable target is not looted at three quarters.');
        $this->assertSame(0, HonorPolicy::Neutral->minimumRateInBasisPoints(), 'A neutral defender imposed a rate of its own.');

        // **Le bandit passe avant l'honorable.** Un joueur ne peut pas etre les deux, mais l'ordre
        // des controles doit le dire : sans lui, un honneur negatif tres bas pourrait etre lu comme
        // « non positif donc neutre » et perdre son statut.
        $this->assertSame(HonorPolicy::Outlaw, $this->honneur->lootPolicyAgainst($this->aUserWithHonor(-500)));
    }

    /**
     * Les unites detruites valent leur prix de base, deuterium compris.
     */
    public function testDestroyedUnitsAreValuedAtTheirBasePrice(): void
    {
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $prix = ObjectService::getObjectRawPrice('light_fighter');

        $unites = new UnitCollection();
        $unites->addUnit($chasseur, 10);

        $this->assertSame(
            (int)floor($prix->sum()) * 10,
            $this->honneur->valueOf($unites),
            'Ten fighters are not worth ten times one fighter.'
        );

        $this->assertSame(0, $this->honneur->valueOf(new UnitCollection()), 'An empty loss was worth something.');
    }

    /**
     * Les points militaires viennent du classement, et un joueur inconnu du classement vaut zero.
     */
    public function testMilitaryPointsComeFromTheHighscore(): void
    {
        Highscore::query()->updateOrCreate(
            ['player_id' => $this->currentUserId],
            ['general' => 0, 'economy' => 0, 'research' => 0, 'military' => 4_242]
        );

        $this->assertSame(4_242, $this->honneur->militaryPointsOf($this->currentUserId));
        $this->assertSame(0, $this->honneur->militaryPointsOf(9_999_999), 'A player the highscore has never seen was given points.');
    }

    /**
     * Un combat equilibre rapporte, un combat desequilibre coute.
     *
     * C'est la regle entiere en un essai : la meme destruction change de signe selon l'ecart des
     * forces. Un temoin qui ne verifierait que le gain laisserait passer un systeme incapable de
     * punir, donc incapable de produire un seul bandit.
     */
    public function testTheSameDestructionGainsOrCostsDependingOnTheGap(): void
    {
        // Un million de ressources detruites : 251 points, quel que soit le sens.
        $this->assertSame(251, $this->honneur->attackerOutcome(1_000_000, 1_000, 1_000), 'A balanced fight paid nothing.');
        $this->assertSame(-251, $this->honneur->attackerOutcome(1_000_000, 1_000, 100), 'Crushing a far weaker player cost nothing.');
    }

    /**
     * Contre une base pilotee par le serveur, on gagne mais on ne perd jamais.
     *
     * Decision de Keven : recompenser la chasse aux pirates sans faire des petites bases un piege.
     * L'asymetrie est volontaire, et c'est pourquoi les deux sens sont epingles ici.
     */
    public function testAgainstAServerDrivenBaseOneGainsButNeverLoses(): void
    {
        $this->assertSame(
            251,
            $this->honneur->attackerOutcome(1_000_000, 1_000, 1_000, defenderIsServerDriven: true),
            'A balanced fight against a pirate base paid nothing.'
        );

        $this->assertSame(
            0,
            $this->honneur->attackerOutcome(1_000_000, 1_000, 100, defenderIsServerDriven: true),
            'Crushing a small pirate base cost honour, which was explicitly ruled out.'
        );

        // **Le meme combat contre un joueur, lui, coute.** Sans cette moitie, l'essai passerait
        // meme si le drapeau ne servait a rien.
        $this->assertSame(-251, $this->honneur->attackerOutcome(1_000_000, 1_000, 100), 'The exemption leaked to human defenders.');
    }

    /**
     * Rien de detruit, rien de change — et l'interrupteur eteint neutralise tout.
     */
    public function testNothingDestroyedAndASleepingSystemChangeNothing(): void
    {
        $this->assertSame(0, $this->honneur->attackerOutcome(0, 1_000, 1_000));
        $this->assertSame(0, $this->honneur->attackerOutcome(500, 1_000, 1_000), 'A skirmish below one point still moved the total.');

        $this->reglages->set('honor_system_enabled', '0');
        $this->assertSame(0, $this->honneur->attackerOutcome(1_000_000, 1_000, 1_000), 'A disabled system still handed out points.');
    }

    /**
     * Les civils et les defenses reconstruites ne comptent pas.
     *
     * ## Pourquoi ces deux exclusions comptent
     *
     * Sans la premiere, la chasse aux transporteurs serait la meilleure source d'honneur du jeu.
     * Sans la seconde, un defenseur qui retrouve ses tourelles serait compte comme les ayant
     * perdues. Les deux se verifient ensemble, sur une meme perte melangee.
     */
    public function testCivilShipsAndRebuiltDefencesDoNotCount(): void
    {
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $cargo = ObjectService::getUnitObjectByMachineName('small_cargo');
        $lanceur = ObjectService::getUnitObjectByMachineName('rocket_launcher');

        $perdues = new UnitCollection();
        $perdues->addUnit($chasseur, 10);
        $perdues->addUnit($cargo, 10);
        $perdues->addUnit($lanceur, 10);

        $reconstruites = new UnitCollection();
        $reconstruites->addUnit($lanceur, 7);

        $attendu = (int)floor(ObjectService::getObjectRawPrice('light_fighter')->sum()) * 10
            + (int)floor(ObjectService::getObjectRawPrice('rocket_launcher')->sum()) * 3;

        $this->assertSame(
            $attendu,
            $this->honneur->destroyedValueOf($perdues, $reconstruites),
            'The valuation counted civil ships, or defences the defender got back.'
        );
    }

    /**
     * Le credit s'ajoute en base, il ne remplace pas ce qu'il a lu.
     *
     * Deux batailles reglees dans la meme seconde ne doivent pas s'effacer l'une l'autre : c'est la
     * meme lecon que le credit de ressources et celui des missiles.
     */
    public function testTheCreditAddsInTheDatabaseInsteadOfOverwriting(): void
    {
        $this->honneur->credit($this->currentUserId, 100);
        $this->honneur->credit($this->currentUserId, -30);

        $this->assertSame(
            70,
            (int)DB::table('users')->where('id', $this->currentUserId)->value('honor_points'),
            'The two credits did not add up: one overwrote the other.'
        );

        // Un changement nul n'ecrit rien du tout.
        $ecritures = 0;
        DB::listen(function ($requete) use (&$ecritures): void {
            if (str_contains(strtolower($requete->sql), 'update') && str_contains($requete->sql, 'honor_points')) {
                $ecritures++;
            }
        });

        $this->honneur->credit($this->currentUserId, 0);
        $this->assertSame(0, $ecritures, 'A zero change still wrote to the row.');
    }

    private function aUserWithHonor(int $points): User
    {
        $utilisateur = new User();
        $utilisateur->honor_points = $points;

        return $utilisateur;
    }
}
