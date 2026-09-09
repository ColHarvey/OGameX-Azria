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
use Tests\Support\DetachesFromAnyAlliance;
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
 * **La coordination existe desormais** : `PlayerCoordinationBarrier`, une table que rien d autre ne
 * verrouille, prise en tete par la porte des mouvements, le chemin administratif et l adhesion. Ce
 * temoin est ce qui dira si elle tient. Une premiere tentative verrouillait `users` a la place et a
 * ete retiree — elle inversait un ordre avec le traitement des pages.
 *
 * Sous SQLite `lockForUpdate()` ne compile a rien, et deux appels sequentiels prouveraient
 * l idempotence, jamais la course : cette preuve n a de sens que sur MariaDB, avec deux processus
 * reels qui se disputent les memes lignes. **Elle n a pas encore tourne** : ce poste n a pas
 * MariaDB, et la poussee qui declencherait le job `courses` est refusee.
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
    use DetachesFromAnyAlliance;
    use RunsInParallelProcesses;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private int|null $alliance = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();

        /*
         * **Aucune bataille heritee, et ce n est pas une precaution : c est la lecon d un run.**
         *
         * L invariant se lit par `CombatsInvolvingPlayer::stillRunning()`, qui retient tout combat
         * **visant une planete du defenseur**, quel que soit l attaquant. Le bac MariaDB tourne sur
         * une base unique, et l essai frere de cette classe ouvre deliberement une bataille sur la
         * meme planete propre, partagee par le processus. Le temoin lisait donc la bataille de son
         * voisin et rougissait sans qu aucune course n ait rien contredit — run `34342533005`, puis
         * `34346418720`.
         */
        DB::table('fleet_missions')->whereNotNull('combat_instance_id')->update(['combat_instance_id' => null]);

        foreach ([
            'patrol_combat_barriers',
            'combat_snapshot_inclusions',
            'combat_outbox',
            'combat_participants',
            'combat_effect_receipts',
            'combat_loot_reservations',
            'celestial_body_combat_barriers',
            'combat_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
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
     * **Deux adversaires postulent a une TROISIEME alliance, et l acceptent en meme temps.**
     *
     * Cas signale par Codex. Verrouiller le seul candidat ne suffit pas : les deux n ont aucune
     * ligne commune. C est la ligne de l **alliance** qui les met en file — et encore faut-il que la
     * seconde lise la liste **mise a jour**, sans photographie transactionnelle ni relation en
     * cache. Sous `REPEATABLE READ`, une relecture ordinaire rendrait le monde tel qu il etait au
     * debut de la transaction, et le verrou ne servirait a rien : la lecture des membres est donc
     * verrouillante, et c est cet essai qui le prouve.
     */
    public function testTwoAdversariesCannotBothBeAcceptedIntoAThirdAllianceAtOnce(): void
    {
        $this->basicSetup();

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);

        $cible = $this->sendMissionToOtherPlayerCleanPlanet($flotte, new Resources(0, 0, 0, 0));
        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->orderByDesc('id')->firstOrFail();

        $defenseur = $cible->getPlayer();
        $this->assertNotNull($defenseur);
        $adverse = $defenseur->getId();
        $attaquant = $this->currentUserId;

        // La bataille est ouverte avant les candidatures : les deux sont adversaires.
        Date::setTestNow(Date::createFromTimestamp((int)$mission->time_arrival + 1));
        resolve(PlayerServiceFactory::class)->make($attaquant, true)->updateFleetMissions();

        $corps = Planet::query()->where('user_id', $adverse)->pluck('id')->map(static fn (mixed $id): int => (int)$id)->all();

        $this->assertTrue(
            CombatsInvolvingPlayer::stillRunning($attaquant, $corps)->isNotEmpty(),
            'No battle opened: the two players are not adversaries, and the race would prove nothing.'
        );

        // Un tiers fonde l alliance ; les deux adversaires y postulent.
        $tiers = (int)(DB::table('users')
            ->whereNotIn('id', [$attaquant, $adverse])
            ->whereNull('alliance_id')
            ->orderBy('id')
            ->value('id') ?? 0);

        if ($tiers === 0) {
            $this->markTestSkipped('Aucun troisieme joueur libre : le scenario ne peut pas etre monte.');
        }

        $this->detachFromAnyAlliance($tiers, $attaquant, $adverse);

        $service = resolve(AllianceService::class);
        $alliance = $service->createAlliance($tiers, 'T' . substr((string)$tiers, -3) . substr((string)$attaquant, -3), 'Tierce ' . $tiers);
        $this->alliance = (int)$alliance->id;

        $premiere = (int)$service->applyToAlliance($attaquant, $this->alliance)->id;
        $seconde = (int)$service->applyToAlliance($adverse, $this->alliance)->id;

        // Les deux acceptations, en meme temps.
        $issues = $this->inParallel(2, function (int $rang) use ($premiere, $seconde, $tiers): string {
            try {
                resolve(AllianceService::class)->acceptApplication($rang === 0 ? $premiere : $seconde, $tiers);

                return 'acceptee';
            } catch (Throwable $refus) {
                return 'refusee';
            }
        });

        // **L invariant** : jamais les deux dans la meme alliance.
        $this->assertFalse(
            resolve(AllianceService::class)->arePlayersInSameAlliance($attaquant, $adverse),
            'Two adversaries of a running battle were both accepted into the same third alliance. '
            . 'Issues: ' . implode(' | ', $issues)
        );

        // **Et un denouement normal** : exactement une acceptee, pas zero.
        $this->assertSame(
            1,
            count(array_filter($issues, static fn (string $issue): bool => $issue === 'acceptee')),
            'The two acceptances did not settle into exactly one: ' . implode(' | ', $issues)
        );
    }

    /**
     * De qui est cette bataille ? **Un rouge doit le dire de lui-meme.**
     *
     * Le message precedent affirmait « un combat court entre allies » sans nommer le combat : il a
     * fallu lire le code pour decouvrir qu il pouvait s agir de celui d un essai voisin.
     *
     * @param \Illuminate\Support\Collection<int, \OGame\Models\CombatInstance> $combats
     */
    private function describe($combats, int $missionDeCetEssai): string
    {
        if ($combats->isEmpty()) {
            return 'none';
        }

        return $combats->map(function ($combat) use ($missionDeCetEssai): string {
            $missions = FleetMission::query()->where('combat_instance_id', $combat->id)->pluck('id')->implode(',');

            return '#' . $combat->id . ' (statut ' . $combat->status->value . ', corps ' . $combat->target_planet_id
                . ', missions ' . ($missions === '' ? 'aucune' : $missions) . ', celle de cet essai ' . $missionDeCetEssai . ')';
        })->implode(' ; ');
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

        // Le defenseur fonde une alliance, l attaquant y postule — mais n y est pas encore. Les deux
        // sont d abord detaches de ce qu une classe voisine aurait laisse sur eux.
        $this->detachFromAnyAlliance($this->currentUserId, $adverse);

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

        /*
         * **La premisse, sans laquelle l invariant ne dit rien.** Un combat deja ouvert sur un corps
         * du defenseur satisferait « un combat court entre allies » sans qu aucun des deux chemins
         * n ait rien decide. Le monde de depart doit etre libre, et l essai l exige au lieu de
         * l esperer.
         */
        $corpsAvant = Planet::query()->where('user_id', $adverse)->pluck('id')->map(static fn (mixed $id): int => (int)$id)->all();

        $this->assertSame(
            0,
            CombatsInvolvingPlayer::stillRunning($attaquant, $corpsAvant)->count(),
            'A battle is already running against the defender before the race: the invariant would be satisfied by a neighbour.'
        );

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
            . 'Issues: ' . implode(' | ', $issues) . '. Battles: ' . $this->describe($partages, $mission->id)
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
