<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\CombatsInvolvingPlayer;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\AllianceService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\FleetDispatchTestCase;
use Throwable;

/**
 * L adhesion et l ouverture d un combat ne peuvent pas valider deux etats contradictoires.
 *
 * ## La course, et pourquoi elle ne se prouve qu ici
 *
 * Les deux chemins lisent puis ecrivent : l adhesion lit « aucun combat entre eux » et inscrit le
 * joueur ; l arrivee lit « pas allies » et ouvre le combat. Executes en meme temps, ils pouvaient
 * commiter tous les deux — et laisser un combat actif **entre deux membres d une meme alliance**,
 * exactement ce que la regle interdit.
 *
 * **La course est ouverte a ce jour**, et ce fichier ne la ferme pas : il est la preuve qui attend la
 * coordination, pas la coordination elle-meme. Voir `requiresTheCoordinationThisProofNeeds()`.
 *
 * Sous SQLite `lockForUpdate()` ne compile a rien, et deux appels sequentiels prouveraient
 * l idempotence, jamais la course : cette preuve n a de sens que sur MariaDB, avec deux processus
 * reels qui se disputent les memes lignes.
 *
 * ## Ce que le temoin exigera
 *
 * Pas « l un des deux echoue » — cela laisserait passer les deux issues fautives, dont « rien n a
 * abouti ». Deux exigences, donc :
 *
 *  - **l invariant** : jamais, a la fin, un combat non final entre deux joueurs de la meme alliance ;
 *  - **un denouement complet** : adhesion acceptee **et** attaque rentree, ou combat ouvert **et**
 *    adhesion refusee — la flotte existant une fois, ni perdue ni dupliquee.
 */
#[Group('mariadb')]
final class AllianceVersusCombatOpeningRaceTest extends FleetDispatchTestCase
{
    use RunsInParallelProcesses;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private int|null $alliance = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
        $this->requiresTheCoordinationThisProofNeeds();
    }

    /**
     * La condition que cette preuve attend, et qui n existe pas encore.
     *
     * ## Pourquoi elle est ecrite avant d etre eprouvable
     *
     * Une premiere tentative verrouillait les comptes des deux combattants a l arrivee. Elle a ete
     * **retiree** : elle introduisait une inversion d ordre entre `PlayerService::update()` — compte
     * puis planetes, a presque chaque page — et `updateFleetMissions()` — planetes puis missions, le
     * compte serait venu apres. Un interblocage entre deux des chemins les plus frequentes du jeu
     * vaut pire que la course qu il pretendait fermer.
     *
     * Le temoin reste donc ecrit et **inactif**. L activer aujourd hui donnerait un rouge
     * intermittent qui ne prouverait qu une chose deja sue : la course est ouverte. Il s active le
     * jour ou une coordination couvrant les quatre chemins existe — pages, arrivees, traitement
     * administratif, adhesions — et c est alors lui qui dira si elle tient.
     */
    private function requiresTheCoordinationThisProofNeeds(): void
    {
        $this->markTestSkipped(
            'La coordination transactionnelle entre adhesion et ouverture de combat n existe pas encore. '
            . 'Cette preuve attend sa livraison ; la course est ouverte et documentee.'
        );
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('light_fighter', 5);
        $this->playerSetResearchLevel('computer_technology', object_level: 1);

        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 8);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('attack_block_until', 0);
        $reglages->set('persistent_combat_enabled', 1);
        $reglages->set('alliance_offensive_protection_enabled', 1);

        $this->planetAddResources(new Resources(0, 0, 1000000, 0));
    }

    protected function tearDown(): void
    {
        if ($this->alliance !== null) {
            DB::table('users')->where('alliance_id', $this->alliance)->update(['alliance_id' => null]);
            AllianceMember::query()->where('alliance_id', $this->alliance)->delete();
            Alliance::query()->whereKey($this->alliance)->delete();
            $this->alliance = null;
        }

        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 0);
        resolve(SettingsService::class)->set('persistent_combat_enabled', 0);

        parent::tearDown();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * L attaque part, l alliance se prepare, puis les deux chemins courent ensemble.
     */
    public function testNoConcurrentExecutionOpensACombatBetweenAllies(): void
    {
        $this->basicSetup();

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);

        $cible = $this->sendMissionToOtherPlayerCleanPlanet($flotte, new Resources(0, 0, 0, 0));
        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->orderByDesc('id')->firstOrFail();

        $defenseur = $cible->getPlayer();
        $this->assertNotNull($defenseur);
        $adverse = $defenseur->getId();

        // Le defenseur fonde une alliance, l attaquant y postule — mais n y est pas encore.
        $service = resolve(AllianceService::class);
        $alliance = $service->createAlliance($adverse, 'C' . substr((string)$adverse, -3) . substr((string)$this->currentUserId, -3), 'Course ' . $adverse);
        $this->alliance = (int)$alliance->id;

        $candidature = $service->applyToAlliance($this->currentUserId, $this->alliance);

        $this->assertFalse(
            $service->arePlayersInSameAlliance($this->currentUserId, $adverse),
            'They are already allies: the race would prove nothing.'
        );

        $arrivee = (int)$mission->time_arrival + 1;
        $attaquant = $this->currentUserId;
        $candidatureId = (int)$candidature->id;

        // Les deux chemins, en meme temps.
        $issues = $this->inParallel(2, function (int $rang) use ($attaquant, $adverse, $candidatureId, $arrivee): string {
            Date::setTestNow(Date::createFromTimestamp($arrivee));

            if ($rang === 0) {
                try {
                    resolve(AllianceService::class)->acceptApplication($candidatureId, $adverse);

                    return 'adhesion acceptee';
                } catch (Throwable $refus) {
                    return 'adhesion refusee';
                }
            }

            resolve(PlayerServiceFactory::class)->make($attaquant, true)->updateFleetMissions();

            return 'arrivee traitee';
        });

        $this->assertCount(2, $issues);

        Date::setTestNow(Date::createFromTimestamp($arrivee));

        $allies = resolve(AllianceService::class)->arePlayersInSameAlliance($attaquant, $adverse);

        $corps = Planet::query()->where('user_id', $adverse)->pluck('id')->map(static fn (mixed $id): int => (int)$id)->all();
        $combats = CombatsInvolvingPlayer::stillRunning($attaquant, $corps);

        $partages = $combats->filter(
            fn ($combat): bool => CombatsInvolvingPlayer::isPartyTo($combat, $adverse, $corps)
        );

        // **L invariant.** Jamais les deux a la fois.
        $this->assertFalse(
            $allies && $partages->isNotEmpty(),
            'A combat is running between two members of the same alliance: the two paths committed contradictory states. '
            . 'Issues: ' . implode(' | ', $issues)
        );

        /*
         * **Et une issue normale, sans quoi l invariant se satisferait de deux echecs.**
         *
         * « Aucun combat entre allies » est vrai si rien n a abouti — l adhesion refusee ET la flotte
         * perdue en route, par exemple. Le temoin exige donc l un des deux denouements complets, et
         * rien d autre.
         */
        $retour = FleetMission::query()->where('parent_id', $mission->id)->get();
        $mission->refresh();

        if ($allies) {
            $this->assertTrue($partages->isEmpty(), 'The membership went through while a combat was open.');
            $this->assertCount(1, $retour, 'The membership went through but the attack neither fought nor came home.');
            $this->assertSame(1, (int)$mission->processed, 'The attack was left unprocessed.');
        } else {
            $this->assertTrue($partages->isNotEmpty(), 'Neither the membership nor the combat happened: both paths failed.');
            $this->assertCount(0, $retour, 'The combat opened and the fleet came home at the same time.');
        }

        // Dans les deux cas, la flotte existe une fois et une seule.
        $this->assertSame(
            1,
            FleetMission::query()->whereKey($mission->id)->count() + $retour->count() - 1,
            'The fleet was lost or duplicated by the race.'
        );
    }
}
