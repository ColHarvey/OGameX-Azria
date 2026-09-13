<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\RallyClosureService;
use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;
use Tests\RecordsClassHistory;

/**
 * **Un combat durable joue la manoeuvre de Hamill sous la regle ecrite a son ouverture.**
 *
 * ## Pourquoi ce banc vit du cote de la bibliotheque
 *
 * Le moteur PHP retirait deja l Etoile de la mort de la bataille : il ne distingue pas les deux regles. Seul
 * le moteur Rust les separe — c est lui que la correction change, et c est lui qui tourne en production par
 * defaut. Ces essais sont donc ignores ici et executes en integration continue ; le nom de la classe les fait
 * entrer dans le groupe du moteur.
 *
 * ## Ce qu ils etablissent
 *
 * - un combat ouvert aujourd hui porte `v2` et **detruit** l Etoile : la manoeuvre fait ce que le jeu annonce ;
 * - le meme montage, ramene a `v1` comme un combat ouvert avant la correction, garde le comportement livre :
 *   l Etoile disparait du depart annonce et continue de tirer. **C est la protection des combats deja
 *   ouverts**, prouvee plutot qu affirmee.
 */
final class RustHamillRuleOfADurableCombatTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;
    use RecordsClassHistory;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private int $chance = 1000;

    protected function setUp(): void
    {
        // **La garde d ignorance vient apres le montage parent** : posee avant, elle interrompt `setUp()`
        // alors que le demontage s execute quand meme, et celui-ci resout un reglage sur un conteneur qui
        // n existe pas encore.
        parent::setUp();

        $this->skipWhenTheRustLibraryIsUnavailable();

        $reglages = resolve(SettingsService::class);
        $this->chance = $reglages->hamillManoeuvreChance();
        $reglages->set('hamill_manoeuvre_chance', 1);
    }

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        $reglages = resolve(SettingsService::class);
        $reglages->set('persistent_combat_enabled', '0');
        $reglages->set('hamill_manoeuvre_chance', $this->chance);

        parent::tearDown();
    }

    /**
     * **Un combat ouvert aujourd hui detruit l Etoile de la mort**, sous le moteur Rust comme sous le PHP.
     */
    public function testACombatOpenedTodayDestroysTheDeathstar(): void
    {
        $combat = $this->unRalliementDUnGeneralContreUneEtoile();

        $this->assertSame(HamillManoeuvreRule::Effective->value, $combat->hamill_rule_version, 'Un combat neuf ne porte pas la regle effective.');

        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertSame(1, $resultat->defenderUnitsStart->getAmountByMachineName('deathstar'), 'Le depart annonce ne porte plus l Etoile.');
        $this->assertSame(1, $resultat->defenderUnitsLost->getAmountByMachineName('deathstar'), 'La manoeuvre n a detruit aucune Etoile.');

        // **Le chiffre que le reglement applique au corps**, relu apres persistance. Le decompte global
        // suffisait a passer avec un moteur qui ne detruisait rien : la perte y est inscrite a part, hors de
        // la bataille. Celui-ci ne peut pas mentir — c est l effectif que la planete recevra.
        $garnison = $this->laGarnison($resultat);
        $this->assertSame(0, $garnison->unitsResult->getAmountByMachineName('deathstar'), 'L Etoile figure encore parmi les survivants du corps : la manoeuvre n a rien detruit.');
        $this->assertSame(1, $garnison->unitsLost->getAmountByMachineName('deathstar'), 'L Etoile n est pas comptee perdue dans la flotte qui la portait.');
    }

    /**
     * **Un combat ouvert avant la correction garde la regle qu il avait promise.**
     */
    public function testACombatOpenedBeforeTheCorrectionKeepsTheRuleItWasOpenedUnder(): void
    {
        $combat = $this->unRalliementDUnGeneralContreUneEtoile(HamillManoeuvreRule::AsDelivered);

        $this->assertSame(HamillManoeuvreRule::AsDelivered->value, $combat->hamill_rule_version);

        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertSame(0, $resultat->defenderUnitsStart->getAmountByMachineName('deathstar'), 'Sous la regle livree, l Etoile quitte le depart annonce.');
        $this->assertSame(0, $resultat->defenderUnitsLost->getAmountByMachineName('deathstar'), 'La correction a ete appliquee a un combat deja ouvert.');

        // Et le corps garde son Etoile : c est exactement ce que la regle livree faisait. Un combat ouvert
        // avant la correction ne doit pas se regler autrement que promis.
        $this->assertSame(1, $this->laGarnison($resultat)->unitsResult->getAmountByMachineName('deathstar'), 'La correction a atteint un combat ouvert sous la regle livree.');
    }

    /**
     * La flotte defensive du corps lui-meme : celle dont l identifiant de mission est zero.
     */
    private function laGarnison(BattleResult $resultat): DefenderFleetResult
    {
        foreach ($resultat->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === 0) {
                return $flotte;
            }
        }

        $this->fail('La garnison manque au resultat de la bataille.');
    }

    /**
     * Un ralliement ouvert par un General contre une garnison qui porte une Etoile de la mort, ferme apres la
     * seconde vague — et, si on le demande, ramene a la regle telle qu elle a ete livree avant la fermeture.
     */
    private function unRalliementDUnGeneralContreUneEtoile(HamillManoeuvreRule|null $regle = null): CombatInstance
    {
        [$ouvreuse, , $ouverture] = $this->aRallyAboutToOpen(20, ['deathstar' => 1]);

        // General avant l arrivee de l ouvreuse : sans classe, la manoeuvre ne se joue pas du tout.
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);

        if ($regle !== null) {
            DB::table('combat_instances')->where('id', $combat->id)->update(['hamill_rule_version' => $regle->value]);
        }

        $vague = FleetMission::query()
            ->where('user_id', $this->currentUserId)
            ->where('mission_type', 1)
            ->where('processed', 0)
            ->whereKeyNot($combat->mission_id)
            ->orderByDesc('id')
            ->first();

        $this->assertInstanceOf(FleetMission::class, $vague, 'La seconde vague manque.');

        $instant = (int)$vague->time_arrival + 30;
        $this->travelTo(Date::createFromTimestamp($instant));

        $this->assertTrue((new RallyClosureService())->close((int)$combat->id, $instant)->closed, 'Le ralliement ne s est pas ferme.');

        return CombatInstance::query()->findOrFail($combat->id);
    }
}
