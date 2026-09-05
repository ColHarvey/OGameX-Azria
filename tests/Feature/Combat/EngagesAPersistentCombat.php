<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;

/**
 * Un combat durable engage — cloture faite, bataille figee, fil ecrit — pret a etre observe.
 *
 * ## Le montage, et pourquoi il est partage
 *
 * Une flotte ecrasante contre une garnison qui perd quelque chose, sur une planete **propre** : la
 * planete etrangere partagee du banc porte vingt mille tourelles laissees par un autre essai, et sa
 * garnison fuirait a cinq contre un. Le proprietaire ne bat pas en retraite (`tactical_retreat_ratio`
 * a zero), la cible est riche, l'interrupteur des combats durables est leve.
 *
 * Le fil de presentation et le panneau de la vue generale observent le meme combat ; un montage par
 * classe divergerait un jour sans que personne ne le voie.
 */
trait EngagesAPersistentCombat
{
    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 60);
        $this->planetAddUnit('light_fighter', 400);
        $this->playerSetResearchLevel('computer_technology', object_level: 2);

        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 8);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('attack_block_until', 0);

        // Le carburant du trajet : sans lui, l'envoi est refuse avant tout combat.
        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * Un combat clos, bataille figee, dont le fil est ecrit. Le joueur courant est l'attaquant.
     */
    protected function anEngagedCombat(): CombatInstance
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));

        // Le proprietaire ne fuit pas, et sa garnison a de quoi perdre.
        // **La planete propre est partagee entre les essais d un processus.** Poser deux types de
        // defenses ne suffit pas : ce qu un voisin y a laisse — vaisseaux, autres defenses — decide
        // alors de la bataille, et un essai qui exige des pertes des deux camps echoue au hasard. On
        // vide avant de poser.
        $cible->removeUnits($cible->getShipUnits(), false);
        $cible->removeUnits($cible->getDefenseUnits(), false);
        $cible->save();
        $cible->reloadPlanet();

        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
        DB::table('users')->where('id', $proprietaire)->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 200_000,
            'crystal' => 100_000,
            'deuterium' => 20_000,
            'rocket_launcher' => 60,
            'light_laser' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);

        $mission = DB::table('fleet_missions')->where('user_id', $this->currentUserId)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($mission, 'No fleet was dispatched.');

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival));
        $this->get('/overview')->assertStatus(200);

        $combat = CombatInstance::query()->where('mission_id', $mission->id)->first();
        $this->assertNotNull($combat, 'The arrival did not open a combat.');
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not close on arrival: a single fleet closes its window at once.');
        $this->assertNotNull($combat->battle_result);

        return $combat;
    }

    /**
     * Les secondes de chaque round, telles que le calendrier persiste les donne.
     *
     * @return array<int, int>
     */
    protected function secondsPerRoundOf(CombatInstance $combat): array
    {
        $calendrier = $combat->round_schedule;
        $this->assertIsArray($calendrier);

        return array_map(static fn (array $round): int => (int)$round['seconds'], $calendrier);
    }
}
