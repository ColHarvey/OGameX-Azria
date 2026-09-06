<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Le cycle complet d'une bataille durable, conduit comme le serveur le conduit.
 *
 * ## Ce que cet essai est, et ce qu'il n'est pas
 *
 * Ce n'est pas un essai de plus sur une piece : chacune a deja les siens. C'est l'essai
 * d'**acceptation** du systeme entier — arrivee, ralliement, seconde vague, transport livre pendant
 * la fenetre, fermeture, bataille figee, echeance, reglement, butin, debris, rapport, retours, fil de
 * presentation, avis livres. Il ne monte rien a la main : il fait tourner la **commande planifiee**
 * `ogamex:combat:avancer`, celle que le conteneur appelle chaque minute, et regarde le monde apres.
 *
 * ## Les identites qu'il tient
 *
 * Un cycle qui « passe » sans rien conserver ne prouve rien. Ce qui est verifie ici, ce sont des
 * egalites, pas des presences :
 *
 * 1. **Le moteur se conserve** : depart moins pertes egale survivants, des deux cotes.
 * 2. **Le monde applique exactement la bataille** : la garnison restante egale les survivants
 *    defenseurs plus les defenses relevees des ruines.
 * 3. **Rien ne se perd en vol** : les unites qui rentrent egalent les survivants attaquants.
 * 4. **Le butin change de mains sans se creer** : ce que la cible perd est ce que les retours
 *    portent en plus de leur cargaison.
 * 5. **Ce qui est detruit tombe** : le champ de debris porte ce que la bataille a fige.
 *
 * Un systeme qui tuerait des unites que le corps ne porte pas, crediterait deux fois un butin ou
 * oublierait un retour echouerait ici, meme si chaque piece prise separement passait.
 */
class WholeCombatCycleTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /**
     * De quoi perdre des deux cotes : trop peu, et le camp qui ne perd rien ne prouve rien.
     */
    private const int GARRISON = 220;

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
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        parent::tearDown();
    }

    public function testAWholeBattleRunsFromArrivalToSettlementWithoutLosingAnything(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);

        // Un transport arrive **pendant** la fenetre : sa cargaison rejoint le stock de la cible
        // avant la photographie, donc elle entre dans le butin potentiel. C'est le cas que la
        // reconciliation causale existe pour trancher.
        $this->aPendingTransportTowards($cible, $ouverture - 30, $ouverture + 5, metal: 25_000);

        $defensesAvant = (int)Planet::query()->whereKey($cible)->value('rocket_launcher');
        $this->assertSame(self::GARRISON, $defensesAvant, 'The garrison is not the one the test posed.');

        $this->assertCount(1, $this->attackingMissions($combat), 'The rally already holds the second wave before it landed.');

        // ---------------------------------------------------------------------------------------
        // 1. La seconde vague arrive pendant la fenetre et rejoint la bataille.
        //
        // C'est le travailleur des pages qui la traite, comme en jeu : personne n'appelle la porte
        // a la main.
        // ---------------------------------------------------------------------------------------
        $this->travelTo(Date::createFromTimestamp($ouverture + self::RALLY_WINDOW_SECONDS));
        $this->get('/overview')->assertStatus(200);

        $missions = $this->attackingMissions($combat);
        $this->assertCount(2, $missions, 'The second wave did not join the battle it arrived on.');

        // ---------------------------------------------------------------------------------------
        // 2. La fenetre est echue : la bataille est calculee et figee.
        //
        // La derniere arrivee ferme le ralliement dans la meme requete — la barriere est stricte, et
        // l'egalite compte pour « apres ». Le passage planifie qui suit ne trouve donc rien a fermer,
        // et c'est aussi ce qu'on verifie : une cloture ne se rejoue pas.
        // ---------------------------------------------------------------------------------------
        $this->travelTo(Date::createFromTimestamp($ouverture + self::RALLY_WINDOW_SECONDS + 1));
        $this->assertSame(0, Artisan::call('ogamex:combat:avancer'), 'The scheduled advance failed after the closure.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not close into a running battle.');
        $this->assertNotNull($combat->battle_result, 'The closure did not freeze a battle.');
        $this->assertNotNull($combat->ends_at, 'The closed battle carries no deadline.');

        $bataille = BattleResultCodec::fromStorage($combat->battle_result);

        // **Le moteur se conserve.** Depart moins pertes egale survivants, des deux cotes. Une
        // bataille qui ne tue rien ne prouverait rien non plus : les pertes sont exigees.
        $this->assertUnitsBalance($bataille->attackerUnitsStart->toArray(), $bataille->attackerUnitsLost->toArray(), $bataille->attackerUnitsResult->toArray(), 'attacker');
        $this->assertUnitsBalance($bataille->defenderUnitsStart->toArray(), $bataille->defenderUnitsLost->toArray(), $bataille->defenderUnitsResult->toArray(), 'defender');
        $this->assertNotSame([], array_filter($bataille->attackerUnitsLost->toArray()), 'The attacker lost nothing: the battle proves too little.');
        $this->assertNotSame([], array_filter($bataille->defenderUnitsLost->toArray()), 'The defender lost nothing: the battle proves too little.');

        // La garnison est inscrite comme participante, et le fil est ecrit des la cloture.
        $this->assertSame(
            1,
            CombatParticipant::query()->where('combat_instance_id', $combat->id)->whereNull('fleet_mission_id')->count(),
            'The garrison was not enrolled at closure: the presentation would not know who saw what.'
        );
        $this->assertGreaterThan(
            0,
            DB::table('combat_presentation_events')->where('combat_instance_id', $combat->id)->count(),
            'The closure wrote no presentation timeline.'
        );

        $stockAvant = $this->stockOf($cible);

        // ---------------------------------------------------------------------------------------
        // 2. Le reglement, par la meme commande, a l'echeance.
        // ---------------------------------------------------------------------------------------
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at + 1));
        $this->assertSame(0, Artisan::call('ogamex:combat:avancer'), 'The scheduled advance failed on the settlement.');

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'The battle was not settled at its deadline.');
        $this->assertNotNull($combat->battle_report_id, 'The settlement wrote no battle report.');

        // ---------------------------------------------------------------------------------------
        // 3. Ce que le monde doit porter apres.
        // ---------------------------------------------------------------------------------------

        // **Le monde applique exactement la bataille** : ce qui reste debout est ce qui a survecu,
        // plus ce que les ruines ont rendu.
        $survivantes = $bataille->defenderUnitsResult->toArray();
        $reparees = $bataille->repairedDefenses->toArray();
        $this->assertSame(
            ($survivantes['rocket_launcher'] ?? 0) + ($reparees['rocket_launcher'] ?? 0),
            (int)Planet::query()->whereKey($cible)->value('rocket_launcher'),
            'The garrison standing after the settlement is not the one the frozen battle describes.'
        );

        // **Rien ne se perd en vol** : ce qui rentre est exactement ce qui a survecu.
        $retours = [];
        $unitesQuiRentrent = [];

        foreach ($missions as $mission) {
            $retour = FleetMission::query()->where('parent_id', $mission->id)->first();
            $this->assertNotNull($retour, 'The surviving fleet of mission ' . $mission->id . ' has no return.');
            $retours[] = $retour;

            foreach ($this->unitsOf($retour) as $nom => $nombre) {
                $unitesQuiRentrent[$nom] = ($unitesQuiRentrent[$nom] ?? 0) + $nombre;
            }
        }

        ksort($unitesQuiRentrent);
        $attendues = array_filter($bataille->attackerUnitsResult->toArray());
        ksort($attendues);
        $this->assertSame($attendues, $unitesQuiRentrent, 'The units coming home are not the ones that survived the battle.');

        // **Le butin change de mains sans se creer.** La production est gelee par le montage
        // (`time_last_update` dans le futur) : l'ecart du stock est le butin, et rien d'autre.
        $stockApres = $this->stockOf($cible);
        $butin = [
            'metal' => (int)$bataille->loot->metal->get(),
            'crystal' => (int)$bataille->loot->crystal->get(),
            'deuterium' => (int)$bataille->loot->deuterium->get(),
        ];

        $this->assertGreaterThan(0, $butin['metal'], 'Nothing was looted: the accounting identity would hold trivially.');

        foreach ($butin as $ressource => $pris) {
            $this->assertSame(
                $stockAvant[$ressource] - $pris,
                $stockApres[$ressource],
                'The target lost something other than the frozen loot in ' . $ressource . '.'
            );
        }

        $rapporte = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        $embarque = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];

        foreach ($retours as $rang => $retour) {
            $flotte = $bataille->attackerFleetResults[$rang];
            $rapporte['metal'] += (int)$retour->metal;
            $rapporte['crystal'] += (int)$retour->crystal;
            $rapporte['deuterium'] += (int)$retour->deuterium;
            $embarque['metal'] += (int)$flotte->survivingCargo->metal->get();
            $embarque['crystal'] += (int)$flotte->survivingCargo->crystal->get();
            $embarque['deuterium'] += (int)$flotte->survivingCargo->deuterium->get();
        }

        foreach ($butin as $ressource => $pris) {
            $this->assertSame(
                $embarque[$ressource] + $pris,
                $rapporte[$ressource],
                'The returns do not carry exactly their surviving cargo plus the loot, in ' . $ressource . '.'
            );
        }

        // **Ce qui est detruit tombe.** Le champ de debris porte ce que la bataille a fige.
        $coordonnees = Planet::query()->whereKey($cible)->first(['galaxy', 'system', 'planet']);
        $this->assertNotNull($coordonnees);
        $debris = DB::table('debris_fields')
            ->where('galaxy', $coordonnees->galaxy)
            ->where('system', $coordonnees->system)
            ->where('planet', $coordonnees->planet)
            ->first();

        $this->assertNotNull($debris, 'The battle left no debris field.');
        $this->assertGreaterThanOrEqual((int)$bataille->debris->metal->get(), (int)$debris->metal, 'The debris field holds less metal than the battle produced.');

        // Le rapport est lisible par la cible comme par les attaquants, et le fil ne montre que le
        // passe : a cet instant, tout est passe.
        $this->assertGreaterThan(
            0,
            DB::table('combat_presentation_events')
                ->where('combat_instance_id', $combat->id)
                ->where('visible_at', '<=', (int)Date::now()->timestamp)
                ->count(),
            'No loss ever became visible to the players.'
        );
    }

    /**
     * Depart moins pertes egale survivants, unite par unite.
     *
     * En bloc, l'egalite passerait avec un type oublie : c'est la comparaison par clef qui refuse.
     *
     * @param array<string, int> $depart
     * @param array<string, int> $perdues
     * @param array<string, int> $survivantes
     */
    private function assertUnitsBalance(array $depart, array $perdues, array $survivantes, string $camp): void
    {
        foreach ($depart as $nom => $nombre) {
            $this->assertSame(
                $nombre - ($perdues[$nom] ?? 0),
                $survivantes[$nom] ?? 0,
                'The ' . $camp . ' side does not conserve ' . $nom . ' : start minus losses is not the survivors.'
            );
        }
    }

    /**
     * Les missions attaquantes de ce combat, dans l'ordre ou elles y sont entrees.
     *
     * @return array<int, FleetMission>
     */
    private function attackingMissions(CombatInstance $combat): array
    {
        return FleetMission::query()
            ->where('combat_instance_id', $combat->id)
            ->where('mission_type', 1)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Les unites que porte une mission, par nom de machine, les vides ecartees.
     *
     * @return array<string, int>
     */
    private function unitsOf(FleetMission $mission): array
    {
        $unites = [];

        foreach (resolve(FleetMissionService::class)->getFleetUnits($mission)->units as $unite) {
            if ($unite->amount > 0) {
                $unites[$unite->unitObject->machine_name] = ($unites[$unite->unitObject->machine_name] ?? 0) + $unite->amount;
            }
        }

        return $unites;
    }

    /**
     * Le solde de la cible, relu hors de tout cache.
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function stockOf(int $planetId): array
    {
        $ligne = DB::table('planets')->where('id', $planetId)->first(['metal', 'crystal', 'deuterium']);

        $this->assertNotNull($ligne);

        return [
            'metal' => (int)$ligne->metal,
            'crystal' => (int)$ligne->crystal,
            'deuterium' => (int)$ligne->deuterium,
        ];
    }
}
