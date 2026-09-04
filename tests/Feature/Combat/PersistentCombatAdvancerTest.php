<?php

namespace Tests\Feature\Combat;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Le battement du combat durable : ce qui doit avancer avance, et rien d'autre.
 *
 * ## Le cycle complet, sans personne
 *
 * Une attaque ouvre un combat. Personne ne regarde. A l'echeance du ralliement, la bataille se
 * calcule ; a la fin du combat, elle s'applique. Ces deux instants sont l'affaire de ce service, et
 * ces essais les traversent de bout en bout : de la flotte envoyee par la route au retour cree et
 * au rapport ecrit, sans qu'aucun appel n'ait ete fait a la fermeture ni au reglement.
 */
class PersistentCombatAdvancerTest extends FleetDispatchTestCase
{
    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /**
     * Chaque essai part d'un monde sans combat.
     *
     * La base n'est pas remise a zero entre deux essais de la meme classe, et ce service **compte
     * des combats** : un combat laisse par l'essai precedent, encore actif et deja echu, serait
     * regle par le passage suivant et fausserait le compte. Un essai qui compte doit etablir ce
     * qu'il compte.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('fleet_missions')->whereNotNull('combat_instance_id')->update(['combat_instance_id' => null]);

        // Dans l'ordre des dependances : ce qui pointe vers un combat s'efface avant lui.
        foreach ([
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
        $this->planetAddUnit('small_cargo', 200);
        $this->planetAddUnit('light_fighter', 900);
        $this->playerSetResearchLevel('computer_technology', object_level: 2);

        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('fleet_speed_holding', 1);
        $settingsService->set('fleet_speed_peaceful', 1);
        $settingsService->set('attack_block_until', 0);

        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * Un combat traverse ses deux echeances sans qu'on l'appelle : la bataille, puis son issue.
     */
    public function testACombatCrossesItsTwoDeadlinesOnItsOwn(): void
    {
        [$combat, $mission, $cible, $barriere] = $this->anOpenedCombat();

        $avanceur = new PersistentCombatAdvancer();

        // Avant l'echeance du ralliement, rien ne bouge.
        $rien = $avanceur->advance((int)$barriere->owned_through_effect_at - 1);
        $this->assertFalse($rien->didSomething(), 'Something advanced before the rally deadline.');
        $combat->refresh();
        $this->assertSame(CombatState::Rallying, $combat->status);

        // A l'echeance, le ralliement ferme et la bataille se calcule.
        $fermeture = $avanceur->advance((int)$barriere->owned_through_effect_at);
        $this->assertSame(1, $fermeture->closed, 'The rally did not close at its deadline.');
        $this->assertSame(0, $fermeture->settled, 'A combat was settled the moment it began.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
        $this->assertNotNull($combat->battle_result);
        $this->assertNotNull($combat->ends_at);

        // Pendant le combat, toujours rien.
        $pendant = $avanceur->advance((int)$combat->ends_at - 1);
        $this->assertFalse($pendant->didSomething(), 'A combat still under way was settled.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());

        $avant = (int)Planet::query()->whereKey($cible->getPlanetId())->value('metal');

        // A la fin, le resultat s'applique.
        $reglement = $avanceur->advance((int)$combat->ends_at);
        $this->assertSame(1, $reglement->settled, 'The combat did not settle at its end.');
        $this->assertSame([], $reglement->failures);

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status);
        $this->assertNotNull($combat->battle_report_id);
        $this->assertNotNull(BattleReport::query()->find($combat->battle_report_id));
        $this->assertSame(1, FleetMission::query()->where('parent_id', $mission->id)->count(), 'No return was created for the attacking fleet.');
        $this->assertLessThan($avant, (int)Planet::query()->whereKey($cible->getPlanetId())->value('metal'), 'The target was never looted.');

        // Un passage de plus ne refait rien.
        $encore = $avanceur->advance((int)$combat->ends_at + 60);
        $this->assertFalse($encore->didSomething());
        $this->assertSame(1, FleetMission::query()->where('parent_id', $mission->id)->count(), 'A second return was created.');
    }

    /**
     * Un combat qui echoue n'empeche pas les autres d'avancer.
     */
    public function testAFailingCombatDoesNotStopTheOthers(): void
    {
        [$premier, , , $barrierePremier] = $this->anOpenedCombat();
        [$second, $missionSecond] = $this->anOpenedCombat();

        $avanceur = new PersistentCombatAdvancer();

        // **Les ralliements ferment du plus tot au plus tard, et chaque combat commence est aussitot
        // repousse.** Deux attaques envoyees a la suite arrivent a des minutes d'ecart : sans ce
        // report, le passage qui ferme le second reglerait le premier au passage, et les deux ne
        // seraient jamais dus ensemble — or c'est exactement ce que l'essai veut voir.
        $echeances = [
            (int)$barrierePremier->owned_through_effect_at,
            (int)$this->barrierOf($second)->owned_through_effect_at,
        ];
        sort($echeances);
        $horizon = end($echeances) + 86_400;

        foreach ($echeances as $echeanceDeRalliement) {
            $avanceur->advance($echeanceDeRalliement);
            $this->pushDeadlinesTo($horizon);
        }

        $premier->refresh();
        $second->refresh();
        $this->assertSame(CombatState::Active, $premier->status, 'The first rally did not close at its deadline.');
        $this->assertSame(CombatState::Active, $second->status, 'The second rally did not close at its deadline.');

        $echeance = $horizon;

        // Le premier devient inreglable : son resultat fige ne se relit plus.
        DB::table('combat_instances')->where('id', $premier->id)->update(['battle_result' => '{"schema":99}']);

        $avance = $avanceur->advance($echeance);

        $this->assertArrayHasKey($premier->id, $avance->failures, 'The corrupted combat did not report a failure.');
        $this->assertSame(1, $avance->settled, 'The healthy combat did not settle while its neighbour failed.');

        $second->refresh();
        $this->assertSame(CombatState::Resolved, $second->status);
        $this->assertSame(1, FleetMission::query()->where('parent_id', $missionSecond->id)->count());

        $premier->refresh();
        $this->assertSame(CombatState::Active, $premier->status, 'The failing combat was left half settled.');
        $this->assertSame(1, $premier->advance_attempts, 'The failure was not counted on the combat.');
        $this->assertNotNull($premier->advance_last_error);
    }

    /**
     * Repousse l'echeance des combats actifs — **et l'instant que leur photographie porte**.
     *
     * Le reglement refuse une photographie dont l'instant d'application n'est pas l'echeance de son
     * combat : sans cela, un champ d'epaves serait date d'un autre moment que celui ou la bataille
     * s'acheve. Une fixture qui repousse l'un sans l'autre fabrique un etat que la production ne
     * produit jamais — et l'essai tombait sur ce refus, pas sur ce qu'il voulait prouver.
     */
    private function pushDeadlinesTo(int $horizon): void
    {
        foreach (CombatInstance::query()->where('status', CombatState::Active->value)->get() as $combat) {
            $photographie = $combat->frozen_settings;

            if (is_array($photographie)) {
                $photographie['applied_at'] = $horizon;
                $combat->frozen_settings = $photographie;
            }

            $combat->ends_at = $horizon;
            $combat->save();
        }
    }

    /**
     * Passe le seuil, un combat qui echoue toujours est laisse a l'exploitation.
     *
     * Sans cela, le passage le reprendrait chaque minute pour toujours : des journaux qui grossissent
     * et un incident que personne ne voit parce qu'il se repete.
     */
    public function testACombatThatKeepsFailingIsSetAside(): void
    {
        [$combat, , , $barriere] = $this->anOpenedCombat();

        $avanceur = new PersistentCombatAdvancer();
        $avanceur->advance((int)$barriere->owned_through_effect_at);

        $combat->refresh();
        DB::table('combat_instances')->where('id', $combat->id)->update(['battle_result' => '{"schema":99}']);
        $echeance = (int)$combat->ends_at;

        for ($essai = 1; $essai <= PersistentCombatAdvancer::MAX_ATTEMPTS; $essai++) {
            $avance = $avanceur->advance($echeance);
            $this->assertArrayHasKey($combat->id, $avance->failures, "The combat was not attempted on pass {$essai}.");
        }

        $combat->refresh();
        $this->assertSame(PersistentCombatAdvancer::MAX_ATTEMPTS, $combat->advance_attempts);

        // Le passage suivant ne le reprend plus, et le compte parmi ceux qui attendent.
        $apres = $avanceur->advance($echeance);
        $this->assertSame([], $apres->failures, 'A quarantined combat was attempted again.');
        $this->assertSame(1, $apres->quarantined, 'The quarantined combat is not reported to the operator.');

        $combat->refresh();
        $this->assertSame(PersistentCombatAdvancer::MAX_ATTEMPTS, $combat->advance_attempts, 'A quarantined combat kept counting failures.');

        // Remis a zero par un humain, il repart — et le resultat repare se regle.
        DB::table('combat_instances')->where('id', $combat->id)->update(['advance_attempts' => 0]);
        $this->assertArrayHasKey($combat->id, $avanceur->advance($echeance)->failures, 'A combat cleared by hand was not picked up again.');
    }

    /**
     * Un echec de fermeture compte comme un echec de reglement : le compteur sert aux deux.
     *
     * La panne est reelle, pas simulee : la photographie d'alliance du combat est corrompue, et la
     * fermeture refuse de la relire plutot que de deviner qui appartenait a quelle alliance.
     */
    public function testAClosureFailureIsCountedToo(): void
    {
        [$combat, , , $barriere] = $this->anOpenedCombat();

        DB::table('combat_instances')->where('id', $combat->id)->update([
            'frozen_alliance_membership' => '{"alliance_id":"douze"}',
        ]);

        $avance = (new PersistentCombatAdvancer())->advance((int)$barriere->owned_through_effect_at);

        $this->assertSame(0, $avance->closed, 'A rally closed on a corrupted membership snapshot.');
        $this->assertArrayHasKey($combat->id, $avance->failures, 'The closure failure was not reported.');

        $combat->refresh();
        $this->assertSame(1, $combat->advance_attempts, 'A closure failure was not counted on the combat.');
        $this->assertNotNull($combat->advance_last_error);
        $this->assertSame(CombatState::Rallying, $combat->status, 'The rally closed half way on a corrupted snapshot.');
    }

    /**
     * Une phase reussie remet le compteur a zero : le reglement ne paie pas les echecs de la fermeture.
     *
     * Le compteur est partage entre les deux phases. Sans remise a zero, quatre fermetures ratees
     * puis une reussie ne laisseraient qu'un essai au reglement — qui n'a encore rien rate — et la
     * derniere raison d'un incident gueri resterait affichee a l'exploitation.
     */
    public function testASuccessfulPhaseResetsTheCounterAndClearsTheReason(): void
    {
        [$combat, , , $barriere] = $this->anOpenedCombat();
        $avanceur = new PersistentCombatAdvancer();
        $echeanceDuRalliement = (int)$barriere->owned_through_effect_at;

        // Quatre fermetures ratees : la photographie d'alliance est corrompue.
        $saine = $combat->frozen_alliance_membership;
        DB::table('combat_instances')->where('id', $combat->id)->update(['frozen_alliance_membership' => '{"alliance_id":"douze"}']);

        for ($essai = 1; $essai <= PersistentCombatAdvancer::MAX_ATTEMPTS - 1; $essai++) {
            $this->assertArrayHasKey($combat->id, $avanceur->advance($echeanceDuRalliement)->failures, "The closure did not fail on pass {$essai}.");
        }

        $combat->refresh();
        $this->assertSame(PersistentCombatAdvancer::MAX_ATTEMPTS - 1, $combat->advance_attempts);
        $this->assertNotNull($combat->advance_last_error);

        // Gueri, la fermeture reussit — et efface ce qui l'a precedee.
        DB::table('combat_instances')->where('id', $combat->id)->update(['frozen_alliance_membership' => json_encode($saine)]);
        $this->assertSame(1, $avanceur->advance($echeanceDuRalliement)->closed, 'The healed rally did not close.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);
        $this->assertSame(0, $combat->advance_attempts, 'A successful closure left the failures of its predecessors on the counter.');
        $this->assertNull($combat->advance_last_error, 'A healed incident is still reported as the last error.');

        // Le reglement part de zero : quatre echecs ne le mettent pas de cote.
        DB::table('combat_instances')->where('id', $combat->id)->update(['battle_result' => '{"schema":99}']);
        $echeance = (int)$combat->ends_at;

        for ($essai = 1; $essai <= PersistentCombatAdvancer::MAX_ATTEMPTS - 1; $essai++) {
            $avance = $avanceur->advance($echeance);
            $this->assertArrayHasKey($combat->id, $avance->failures, "The settlement was not attempted on pass {$essai}: it inherited the closure's failures.");
            $this->assertSame(0, $avance->quarantined, "The combat was set aside on settlement pass {$essai}.");
        }

        $combat->refresh();
        $this->assertSame(PersistentCombatAdvancer::MAX_ATTEMPTS - 1, $combat->advance_attempts, 'The settlement counter did not start from zero.');
    }

    /**
     * La commande passe l'instant qu'on lui donne, et rend toujours un succes.
     *
     * Un echec de combat n'est pas un echec du passage : les autres ont ete traites, et le compteur
     * ramenera celui-la a l'attention d'un humain. Un code non nul ferait sonner l'ordonnanceur
     * chaque minute pour un incident deja enregistre.
     */
    public function testTheCommandCarriesTheGivenInstantAndAlwaysSucceeds(): void
    {
        [$combat, $mission, , $barriere] = $this->anOpenedCombat();

        // **L'heure vient de l'horloge.** La commande n'accepte aucun instant en argument : le
        // donner permettrait de regler un combat avant son echeance. On avance donc l'horloge.
        $this->travelTo(Date::createFromTimestamp((int)$barriere->owned_through_effect_at));

        $this->assertSame(
            Command::SUCCESS,
            Artisan::call('ogamex:combat:avancer'),
            'The command failed on a due rally.'
        );

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The command did not close the due rally.');

        DB::table('combat_instances')->where('id', $combat->id)->update(['battle_result' => '{"schema":99}']);

        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));

        $this->assertSame(
            Command::SUCCESS,
            Artisan::call('ogamex:combat:avancer'),
            'A combat failure was reported as a failure of the whole pass.'
        );

        $combat->refresh();
        $this->assertSame(1, $combat->advance_attempts, 'The command did not record the failure.');
        $this->assertSame(0, FleetMission::query()->where('parent_id', $mission->id)->count());
    }

    /**
     * Un combat ouvert par une vraie attaque, pret a traverser ses echeances.
     *
     * @return array{0: CombatInstance, 1: FleetMission, 2: PlanetService, 3: CelestialBodyCombatBarrier}
     */
    private function anOpenedCombat(): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

        // **Une seconde vague, attendue plus tard.** Sans personne a attendre, l'ouverture
        // fermerait le ralliement elle-meme — et le sujet de ces essais est justement le passage
        // planifie qui le ferme a l'echeance. Deux flottes parties ensemble arrivent ensemble :
        // l'arrivee de la seconde est repoussee a la main, c'est le seul moyen d'ouvrir une
        // fenetre par cette route.
        $renfort = new UnitCollection();
        $renfort->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 20);
        $this->dispatchFleet($cible->getPlanetCoordinates(), $renfort, new Resources(0, 0, 0, 0), PlanetType::Planet);

        // La plus ancienne est l'ouvreuse ; la plus recente est le renfort, repousse.
        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderBy('id')
            ->first();

        $dernier = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();

        if ($mission !== null && $dernier !== null && $dernier->id !== $mission->id) {
            DB::table('fleet_missions')->where('id', $dernier->id)->update(['time_arrival' => (int)$mission->time_arrival + 30]);
        }

        if ($mission === null) {
            $this->fail('No fleet mission was dispatched.');
        }

        // Un stock et une garnison connus : la bataille doit durer et rapporter quelque chose.
        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 500_000,
            'crystal' => 300_000,
            'deuterium' => 100_000,
            'rocket_launcher' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $cible->reloadPlanet();

        $combat = (new CombatOpeningService())->openOrJoin($mission, $cible->getPlanetId(), (int)$mission->time_arrival);

        return [$combat, $mission, $cible, $this->barrierOf($combat)];
    }

    private function barrierOf(CombatInstance $combat): CelestialBodyCombatBarrier
    {
        $barriere = CelestialBodyCombatBarrier::query()->where('combat_instance_id', $combat->id)->first();

        if ($barriere === null) {
            $this->fail('The opening left no barrier for combat ' . $combat->id . '.');
        }

        return $barriere;
    }
}
