<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Une fermeture qui trouve une arrivee tenue par un autre travailleur se retire, sans rien laisser.
 *
 * ## Le defaut que ce temoin ferme
 *
 * `FleetMissionService::updateMission()` refuse de traiter une mission dont un autre travailleur
 * tient le jeton. Elle ne rendait rien, et la fermeture du ralliement, ne trouvant pas de delta au
 * registre, en concluait que **la barriere n avait pas ete vue par la porte** — et levait. Le
 * message accusait le mauvais coupable, et la fermeture mourait au lieu de se retirer.
 *
 * Un rouge du bac MariaDB l a montre le 9 septembre 2026 : `MissileVersusClosureRaceTest`, une fois,
 * puis vert a la relance sur le meme commit. La cause a ete etablie par lecture, et ce temoin la
 * reproduit **de facon deterministe** : tenir le jeton, c est ecrire sa colonne.
 *
 * ## Ce que ce temoin ne reproduit pas, et il faut le dire
 *
 * **L etat bloquant, oui ; la concurrence, non.** Ecrire la colonne place le monde dans l etat ou
 * un autre travailleur tient l arrivee, mais aucun second processus ne tourne ici. Ce qui est etabli
 * est donc le **retrait** et la **reprise**, pas leur comportement quand deux travailleurs se
 * disputent vraiment la mission — cela appartient a `MissileVersusClosureRaceTest`, au bac MariaDB.
 *
 * Precision de Codex, retenue : un temoin qui melangerait les deux ferait croire qu il prouve la
 * course alors qu il ne prouve que ses consequences.
 *
 * ## Les quatre exigences, telles que Codex les pose
 *
 * 1. la fermeture interrompue ne laisse **aucun effet partiel** et reste en `Rallying` ;
 * 2. la reprise conserve **l echeance logique initiale** : le retard technique ne prolonge pas la
 *    fenetre des renforts ;
 * 3. un prochain traitement est **reellement prevu**, sans dependre d une visite de joueur ;
 * 4. « tenue ailleurs » est une issue **explicite**, distincte d appliquee, annulee, differee.
 *
 * La quatrieme a ses temoins dans `MissionProcessingClaimTest`. Les trois autres sont ici.
 *
 * **Ce qui n est pas une garantie eprouvee** : la branche qui refuse un differe dans une fermeture.
 * Elle est inatteignable par construction — l instance est tenue en `Rallying` sous verrou — et
 * aucune mutation ne la tue. Elle est gardee comme **filet defensif non couvert**, et ne doit pas
 * etre comptee parmi les garanties de cette classe.
 */
class ClosureWithdrawsWhenAnArrivalIsHeldTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int GARRISON = 200;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('fleet_missions')->whereNotNull('combat_instance_id')->update(['combat_instance_id' => null]);

        foreach ([
            'patrol_combat_barriers',
            'combat_field_states',
            'combat_presentation_events',
            'combat_snapshot_inclusions',
            'combat_outbox',
            'combat_participants',
            'combat_entry_characteristics',
            'combat_effect_ledger',
            'combat_effect_receipts',
            'combat_loot_reservations',
            'celestial_body_combat_barriers',
            'combat_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        parent::tearDown();
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

    public function testTheClosureWithdrawsAndLeavesNothingBehindThenSucceedsOnTheNextPass(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);

        $missile = $this->aPendingMissileTowards($cible, $ouverture - 50, $ouverture + 8);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $echeance = (int)DB::table('celestial_body_combat_barriers')->where('combat_instance_id', $combat->id)->value('owned_through_effect_at');
        $garnisonAvant = (int)Planet::query()->whereKey($cible)->value('rocket_launcher');

        // **Un autre travailleur tient l arrivee.** C est exactement ce qu il ecrit en la reservant.
        FleetMission::query()->whereKey($missile->id)->update(['processing_claimed_at' => Date::now()]);

        $retrait = resolve(RallyClosureService::class)->close((int)$combat->id, $fermeture);

        // 4. L issue est explicite.
        $this->assertFalse($retrait->closed, 'The closure went through although an arrival was held elsewhere.');
        $this->assertSame('arrivee tenue ailleurs', $retrait->reason, 'The closure did not say why it withdrew.');

        // 1. Aucun effet partiel, et le ralliement reste ouvert.
        $combat->refresh();
        $this->assertSame(CombatState::Rallying, $combat->status, 'The withdrawn closure left the combat out of its rally.');
        $this->assertNull($combat->battle_result, 'The withdrawn closure froze a battle anyway.');
        $this->assertSame(0, DB::table('combat_presentation_events')->where('combat_instance_id', $combat->id)->count(), 'The withdrawn closure wrote a timeline.');
        $this->assertSame(0, DB::table('combat_effect_ledger')->where('combat_instance_id', $combat->id)->count(), 'The withdrawn closure left a delta behind.');
        $this->assertSame(0, (int)FleetMission::query()->whereKey($missile->id)->value('processed'), 'The held missile was applied by the closure that could not claim it.');
        $this->assertSame($garnisonAvant, (int)Planet::query()->whereKey($cible)->value('rocket_launcher'), 'The garrison changed although nothing was applied.');

        // 2. L echeance logique n a pas bouge : le retard technique ne prolonge pas la fenetre.
        $this->assertSame(
            $echeance,
            (int)DB::table('celestial_body_combat_barriers')->where('combat_instance_id', $combat->id)->value('owned_through_effect_at'),
            'The withdrawal moved the rally deadline: the technical delay would have extended the window for reinforcements.'
        );

        // **Le travailleur rend le jeton**, et un passage suivant reprend — plus tard qu il n aurait du.
        FleetMission::query()->whereKey($missile->id)->update(['processing_claimed_at' => null]);
        $this->travelTo(Date::createFromTimestamp($fermeture + 120));

        $reprise = resolve(RallyClosureService::class)->close((int)$combat->id, $fermeture + 120);

        $this->assertTrue($reprise->closed, 'The rally never closed on the next pass: ' . $reprise->reason);

        // Exactement un effet missile, et la fermeture est correcte.
        $this->assertSame(1, (int)FleetMission::query()->whereKey($missile->id)->value('processed'), 'The missile was never applied.');
        $this->assertLessThan($garnisonAvant, (int)Planet::query()->whereKey($cible)->value('rocket_launcher'), 'The garrison did not take the strike.');
        $this->assertSame(
            1,
            DB::table('combat_effect_ledger')
                ->where('combat_instance_id', $combat->id)
                ->where('event_identity', CombatEventIdentity::forFleetArrival((int)$missile->id))
                ->count(),
            'The missile effect was recorded more than once, or not at all.'
        );

        $this->assertSame(CombatState::Active, CombatInstance::query()->whereKey($combat->id)->firstOrFail()->status, 'The combat did not become active after its rally closed.');
    }

    /**
     * **Un prochain traitement est prevu**, et il ne depend pas de la visite d un joueur.
     *
     * La condition que Codex pose ne se lit pas dans la fermeture : elle se lit dans ce qui la
     * rappelle. `ogamex:combat:avancer` est planifiee a la minute, et elle ferme les ralliements
     * echus — donc un retrait est repris sans que personne ne se connecte.
     */
    public function testTheAdvancerIsTheOneThatComesBack(): void
    {
        $console = (string)file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString(
            'Schedule::command(AdvancePersistentCombats::class)->everyMinute()',
            $console,
            'The advancer is not scheduled every minute: a withdrawn closure would wait for a player to pass by.'
        );

        // **Et c est bien elle qui ferme les ralliements echus**, pas seulement qui passe.
        $avanceur = (string)file_get_contents(base_path('app/Combat/Services/PersistentCombatAdvancer.php'));

        $this->assertStringContainsString(
            'RallyClosureService',
            $avanceur,
            'The advancer does not close rallies: being scheduled would not bring a withdrawn closure back.'
        );
    }
}
