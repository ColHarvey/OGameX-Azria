<?php

namespace Tests\Feature\Combat;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\RallyClosureService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Ce que la fermeture verrouille depuis qu'elle applique des effets, et dans quel ordre.
 *
 * ## Pourquoi cet essai existe
 *
 * La fermeture appelle desormais des gestionnaires de mission sous sa barriere et son instance. Un
 * gestionnaire qui prendrait un verrou **avant** la barriere — un autre corps, une union — ouvrirait
 * un second sens de rotation : une fermeture tenant la barriere et attendant une union pendant qu'un
 * autre travailleur tient l'union et attend la barriere. L'interblocage ne se verrait qu'en
 * production, sous charge.
 *
 * Cet essai n'est pas une preuve de concurrence — celle-la vit dans le bac MariaDB, ou `for update`
 * signifie quelque chose. Il **caracterise** ce que la fermeture demande : l'ordre reel des tables
 * verrouillees, dans une fermeture qui applique un transport admissible. Un gestionnaire qui se
 * mettrait a acquerir autre chose le ferait tomber, et c'est ce qu'on veut savoir tout de suite.
 *
 * ## L'ordre que la migration fixe
 *
 *     barriere → instance → union → missions → corps → champs
 *
 * Les corps viennent apres les missions, et l'application d'un transport ne sort pas de cet ordre :
 * elle relit sa mission et credite le corps vise, tous deux deja atteints.
 */
final class ClosureLockOrderTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

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

    public function testTheClosureTakesItsLocksInTheOrderTheBarrierMigrationFixes(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 10, 5_000);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $suivies = ['celestial_body_combat_barriers', 'combat_instances', 'fleet_unions', 'fleet_missions', 'planets'];
        $ordre = [];
        DB::listen(function (QueryExecuted $requete) use (&$ordre, $suivies): void {
            $sql = str_replace('`', '"', $requete->sql);
            foreach ($suivies as $table) {
                if (str_contains($sql, '"' . $table . '"') && !in_array($table, $ordre, true)) {
                    $ordre[] = $table;
                }
            }
        });

        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        // **La barriere en premier, l'instance ensuite** : c'est l'ordre global, et tout le reste en
        // depend. Les unions et les missions suivent ; les corps viennent apres les missions.
        $this->assertSame('celestial_body_combat_barriers', $ordre[0] ?? null, 'The closure did not start with the barrier.');
        $this->assertSame('combat_instances', $ordre[1] ?? null, 'The closure did not take the instance right after the barrier.');
        $this->assertLessThan(
            array_search('planets', $ordre, true),
            array_search('fleet_missions', $ordre, true),
            'The closure reached a body before the missions: the application of an effect broke the global order.'
        );
    }

    /**
     * Ce que la fermeture touche, et rien d'autre.
     *
     * L'application d'un effet passe par des gestionnaires que la fermeture ne controle pas. Si l'un
     * d'eux se mettait a ecrire dans une table qui n'appartient pas a ce combat, cet essai le dirait
     * — et l'ordre des verrous devrait etre reexamine avant que le defaut n'atteigne la production.
     */
    public function testTheClosureWritesOnlyToTheTablesThisCombatOwns(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 10, 5_000);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $ecrites = [];
        DB::listen(function (QueryExecuted $requete) use (&$ecrites): void {
            $sql = strtolower(str_replace('`', '"', $requete->sql));
            if (!str_starts_with($sql, 'insert') && !str_starts_with($sql, 'update') && !str_starts_with($sql, 'delete')) {
                return;
            }
            if (preg_match('/(?:into|update|from)\s+"([a-z_]+)"/', $sql, $m) === 1) {
                $ecrites[$m[1]] = true;
            }
        });

        (new RallyClosureService())->close($combat->id, $fermeture);

        $attendues = [
            // Le combat lui-meme et ses registres.
            'combat_instances', 'combat_participants', 'combat_snapshot_inclusions', 'combat_presentation_events',
            'combat_fleet_dispositions', 'combat_outbox', 'celestial_body_combat_barriers',
            // Ce que l'application d'un effet et la bataille touchent.
            'fleet_missions', 'planets', 'messages', 'battle_reports', 'debris_fields', 'wreck_fields', 'users',
            // **La file de travaux, et pourquoi elle est legitime ici.** La cloture met en file le
            // reglement a l echeance. L insertion se fait dans **sa** transaction — le pilote de
            // production est `database`, et `after_commit` vaut faux : le travail n existe donc pour
            // personne tant que la fermeture n a pas valide, et un retour en arriere l emporte avec le
            // reste. C est l inverse d une notification partie avant le commit.
            'jobs',
            // **Le registre des effets.** La fermeture applique un effet admissible par la porte unique,
            // `updateMission()`, qui mesure le corps avant et apres et inscrit le delta sous la barriere
            // que cette fermeture tient : la ligne est ecrite ici, et lue juste apres par la meme
            // fermeture. Une seule source pour l effet applique ici et pour celui que le monde a livre.
            'combat_effect_ledger',
        ];
        $inattendues = array_values(array_diff(array_keys($ecrites), $attendues));

        $this->assertSame([], $inattendues, 'The closure wrote to tables this combat does not own: ' . implode(', ', $inattendues));
    }
}
