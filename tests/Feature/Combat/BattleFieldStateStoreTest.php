<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\StepAlreadyPlayed;
use OGame\Combat\Services\BattleFieldStateStore;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\BattleUnit;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use ReflectionClass;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * Une etape de bataille s ecrit une fois, avec son historique, ou pas du tout.
 *
 * ## Ce que ces temoins etablissent
 *
 * L etat du champ et la chronologie du round sont **deux ecritures**. Separees, une panne entre les
 * deux ferait perdre un round au joueur — l etat avance, l historique non — ou le lui ferait rejouer
 * — l historique avance, l etat non. Elles vivent donc dans une transaction, et c est la porte qui
 * la tient, non la memoire de l appelant.
 *
 * ## Ce qu ils ne prouvent pas, et qu il ne faut pas leur faire dire
 *
 * Une exception levee entre les deux ecritures etablit le **retour arriere de la transaction** :
 * c est le code qui echoue, proprement. Elle n etablit **pas** qu un processus tue brutalement
 * laisse la base intacte — ce sont deux choses, la seconde appartient au banc MariaDB, ou un enfant
 * se fait tuer par signal. Et ni l une ni l autre ne dit rien d une panne de la machine : la
 * durabilite d un commit depend alors des reglages de vidage du serveur, qui sont de la
 * configuration, pas de l assertion.
 *
 * Sous SQLite, `lockForUpdate()` ne compile a rien : la relecture verrouillee se lit ici dans sa
 * **forme**, jamais dans son effet.
 */
class BattleFieldStateStoreTest extends AccountTestCase
{
    /**
     * @var array<int, int>
     */
    private array $combats = [];

    /**
     * @var array<int, int>
     */
    private array $missions = [];

    protected function tearDown(): void
    {
        if ($this->combats !== []) {
            DB::table('combat_field_states')->whereIn('combat_instance_id', $this->combats)->delete();
            DB::table('combat_entry_characteristics')->whereIn('combat_instance_id', $this->combats)->delete();
            CombatInstance::query()->whereIn('id', $this->combats)->delete();
            $this->combats = [];
        }

        if ($this->missions !== []) {
            FleetMission::query()->whereIn('id', $this->missions)->delete();
            $this->missions = [];
        }

        parent::tearDown();
    }

    public function testAWrittenStepComesBackWithItsIndexAndItsUnits(): void
    {
        $combat = $this->aCombat();
        $magasin = resolve(BattleFieldStateStore::class);

        $magasin->writeStep($combat, 1, $this->aState(), static function (): void {
        });

        $tenue = $magasin->heldLatestStep($combat);

        $this->assertNotNull($tenue, 'The written step did not come back.');
        $this->assertSame(1, $tenue['index']);
        $this->assertSame(2, count($tenue['state']->attackerUnits), 'The units did not come back.');
        $this->assertSame(2, $tenue['state']->roundsPlayed);
    }

    /**
     * **Un round ne se joue jamais deux fois.** La cle unique tranche dans la base, pas une lecture.
     */
    public function testTheSameStepCannotBeWrittenTwice(): void
    {
        $combat = $this->aCombat();
        $magasin = resolve(BattleFieldStateStore::class);
        $rien = static function (): void {
        };

        $magasin->writeStep($combat, 1, $this->aState(), $rien);

        try {
            $magasin->writeStep($combat, 1, $this->aState(), $rien);
            $this->fail('A step was written twice: a round would have been counted twice.');
        } catch (StepAlreadyPlayed $deja) {
            $this->assertSame(1, $deja->stepIndex);
            $this->assertSame($combat, $deja->combatInstanceId);
        }

        $this->assertSame(1, $magasin->stepsWritten($combat), 'The refused write left something behind.');
    }

    /**
     * **L etat et l historique tombent ensemble.** Ce temoin joue une exception, pas une panne.
     */
    public function testAFailingHistoryRollsTheStateBackWithIt(): void
    {
        $combat = $this->aCombat();
        $magasin = resolve(BattleFieldStateStore::class);

        try {
            $magasin->writeStep($combat, 1, $this->aState(), static function (): void {
                throw new RuntimeException('L historique du round a echoue.');
            });
            $this->fail('The failing history did not stop the step.');
        } catch (RuntimeException $echec) {
            $this->assertSame('L historique du round a echoue.', $echec->getMessage());
        }

        $this->assertSame(0, $magasin->stepsWritten($combat), 'The state was written although its history was not.');
        $this->assertNull($magasin->heldLatestStep($combat));
    }

    /**
     * La suite des etapes est un historique : la derniere fait foi, les precedentes restent.
     */
    public function testTheLatestStepIsTheOneThatIsHeld(): void
    {
        $combat = $this->aCombat();
        $magasin = resolve(BattleFieldStateStore::class);
        $rien = static function (): void {
        };

        foreach ([0, 1, 2] as $etape) {
            $magasin->writeStep($combat, $etape, $this->aState($etape), $rien);
        }

        $tenue = $magasin->heldLatestStep($combat);

        $this->assertNotNull($tenue);
        $this->assertSame(2, $tenue['index'], 'The held step is not the latest one.');
        $this->assertSame(2, $tenue['state']->roundsPlayed);
        $this->assertSame(3, $magasin->stepsWritten($combat), 'The earlier steps did not stay: there is no history left to read.');
    }

    /**
     * La relecture qui decide **demande un verrou**, et c est une garde de source qui le dit.
     *
     * ## Pourquoi pas une observation des requetes
     *
     * Je l avais d abord ecrite ainsi, et elle a rougi pour la bonne raison : **sous SQLite,
     * `lockForUpdate()` ne compile a rien**. La requete emise ne porte donc aucun « for update », et
     * un temoin qui le chercherait ne pourrait jamais passer ici — ni distinguer un verrou pris d un
     * verrou oublie.
     *
     * La garde regarde donc le source, comme le depot le fait deja pour l ordre des verrous. **Elle
     * prouve une forme, pas un effet** : l effet appartient au banc MariaDB, ou deux processus se
     * disputent vraiment la ligne.
     */
    public function testTheDecidingReadAsksForALock(): void
    {
        $fichier = (new ReflectionClass(BattleFieldStateStore::class))->getFileName();
        $this->assertNotFalse($fichier);

        $source = preg_replace('/\s+/', ' ', (string)file_get_contents($fichier));
        $this->assertNotNull($source);

        $decidante = strstr($source, 'function heldLatestStep');
        $this->assertIsString($decidante, 'The deciding read is gone: this guard watches nothing.');

        $this->assertStringContainsString(
            'lockForUpdate()',
            substr($decidante, 0, 600),
            'The deciding read takes no lock: nothing would stop a second worker from playing the same round.'
        );
    }

    private function aCombat(): int
    {
        $corps = (int)Planet::query()->where('user_id', $this->currentUserId)->value('id');

        $initiatrice = FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'mission_type' => 1,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'planet_id_to' => $corps,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 1,
            'processed' => 1,
        ]);

        $this->missions[] = (int)$initiatrice->id;

        $combat = CombatInstance::query()->create([
            'mission_id' => $initiatrice->id,
            'target_type' => 1,
            'galaxy' => 1,
            'system' => 1,
            'position' => 1,
            'target_planet_id' => $corps,
            'status' => CombatState::Active->value,
            'started_at' => 1_700_000_000,
            'ends_at' => 1_700_003_600,
        ]);

        $this->combats[] = (int)$combat->id;

        return (int)$combat->id;
    }

    /**
     * Un champ tenu a la main : deux chasseurs, dont un entame.
     */
    private function aState(int $roundsJoues = 2): BattleFieldState
    {
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');

        $premier = new BattleUnit($chasseur, 400, 10, 50, 1000, $this->currentUserId);
        $second = new BattleUnit($chasseur, 400, 10, 50, 1000, $this->currentUserId);
        $second->currentHullPlating = 12;

        $restantes = new UnitCollection();
        $restantes->addUnit($chasseur, 2);

        return new BattleFieldState(
            [$premier, $second],
            [],
            new SeededDraws(20260909),
            new SeededDraws(20260909),
            $roundsJoues,
            $restantes,
            new UnitCollection(),
            new UnitCollection(),
            new UnitCollection(),
            [],
            [],
        );
    }
}
