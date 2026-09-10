<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Hull\DamagedHulls;
use OGame\Hull\HullRepairService;
use OGame\Models\HullRepairOrder;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * Le chantier spatial sous une vraie concurrence — ce que SQLite ne peut pas montrer.
 *
 * ## Ce que la suite ordinaire prouve deja, et qu on ne refait pas ici
 *
 * `Tests\Feature\HullRepairTest` etablit le **contrat**, de facon deterministe : une seconde
 * confirmation est refusee, un second reglement ne rend rien, une annulation rejouee ne rembourse
 * pas. Mais elle le fait en appelant deux fois de suite, dans un seul processus.
 *
 * **Un second appel sequentiel prouve l idempotence, jamais la course.** Sous SQLite,
 * `lockForUpdate()` ne compile a rien et la colonne unique n a aucun concurrent a departager : le
 * juste et le faux y coincident.
 *
 * ## Ce qui appartient au bac, et pourquoi
 *
 * Trois choses qu une seule connexion ne peut pas dire.
 *
 * **Deux confirmations reellement simultanees.** La regle « un ordre a la fois par planete » n est
 * pas une verification applicative — `if (exists())` serait passe par les deux — mais une colonne
 * **unique**, `active_on_planet_id`. C est la base qui refuse le second, et il faut deux processus
 * pour le voir.
 *
 * **Deux reglements reellement simultanes.** La garde est une mise a jour conditionnelle sur le
 * statut, et c est le nombre de lignes prises qui decide. **MariaDB compte les lignes changees,
 * SQLite les lignes trouvees** : ce depot a deja paye huit jours de production pour cette
 * difference (le bail du diffuseur, §CLAUDE.md).
 *
 * **Une confirmation contre un depart.** Les unites confiees au dock ne doivent pas pouvoir partir.
 * Les deux chemins verrouillent la meme ligne de `planets` ; l ordre dans lequel ils l obtiennent
 * decide, et aucun des deux ne doit produire un etat ou l unite existe deux fois.
 */
#[Group('mariadb')]
final class HullRepairRaceTest extends TestCase
{
    use RunsInParallelProcesses;

    /**
     * @var array<int, int>
     */
    private array $corpsCrees = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresMariaDb();
        $this->requiresProcesses();

        // **L interrupteur est pose par l epreuve, jamais suppose** : la base du bac est unique et
        // partagee entre classes, et une voisine peut l avoir laisse dans n importe quel etat.
        resolve(SettingsService::class)->set('hull_damage_enabled', '1');
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        resolve(SettingsService::class)->set('hull_damage_enabled', '0');

        if ($this->corpsCrees !== []) {
            DB::table('hull_repair_orders')->whereIn('planet_id', $this->corpsCrees)->delete();
            // Les corps partent, les comptes restent : supprimer un compte se heurte aux clefs
            // etrangeres qui le nomment, et le refus de MariaDB ferait echouer le job entier.
            DB::table('planets')->whereIn('id', $this->corpsCrees)->delete();
            $this->corpsCrees = [];
        }

        parent::tearDown();
    }

    /**
     * Deux processus confirment la meme reparation : **un seul ordre nait, et un seul paiement**.
     */
    public function testTwoConcurrentConfirmationsCreateOneOrderAndPayOnce(): void
    {
        [$corps, $proprietaire] = $this->aBodyWithDamagedCruisers(20, 8, 5000);

        $metalAvant = (int)DB::table('planets')->where('id', $corps)->value('metal');

        $issues = $this->inParallel(2, static function (int $rang) use ($corps): string {
            $planete = resolve(PlanetServiceFactory::class)->make($corps, true);

            if ($planete === null) {
                return 'refuse:corps introuvable';
            }

            $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
            $reparations = resolve(HullRepairService::class);

            try {
                // **L empreinte vide** : elle est eprouvee ailleurs, et la mettre ici ferait echouer
                // les deux processus pour une autre raison que la course.
                $reparations->confirm($planete, $selection, '', (int)now()->timestamp);

                return 'confirme';
            } catch (Throwable $refus) {
                return 'refuse:' . $refus->getMessage();
            }
        });

        $confirmes = count(array_filter($issues, static fn (string $issue): bool => $issue === 'confirme'));

        $this->assertSame(
            1,
            $confirmes,
            'Exactement une confirmation doit aboutir ; les issues : ' . implode(' | ', $issues)
        );

        $this->assertSame(
            1,
            (int)DB::table('hull_repair_orders')->where('planet_id', $corps)->count(),
            'Un seul ordre doit exister en base.'
        );

        // **Et un seul paiement** : c est le fait que la course pourrait casser sans qu aucun compte
        // d ordres ne le montre — deux debits et un seul ordre.
        $ordre = DB::table('hull_repair_orders')->where('planet_id', $corps)->first();
        $this->assertNotNull($ordre, 'L ordre confirme doit exister.');

        $metalApres = (int)DB::table('planets')->where('id', $corps)->value('metal');

        $this->assertSame(
            (int)$ordre->cost_metal,
            $metalAvant - $metalApres,
            'Le metal debite doit valoir exactement le devis d un seul ordre.'
        );

        unset($proprietaire);
    }

    /**
     * Deux processus reglent le meme ordre echu : **les unites ne sont rendues qu une fois**.
     */
    public function testTwoConcurrentSettlementsReturnTheUnitsOnce(): void
    {
        [$corps] = $this->aBodyWithDamagedCruisers(20, 8, 5000);

        $planete = resolve(PlanetServiceFactory::class)->make($corps, true);
        $this->assertNotNull($planete, 'Le corps monte doit se charger.');

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $ordre = resolve(HullRepairService::class)->confirm($planete, $selection, '', (int)now()->timestamp);

        $echeance = (int)$ordre->completed_at;

        $issues = $this->inParallel(2, static function (int $rang) use ($echeance): string {
            return (string)resolve(HullRepairService::class)->settleDue($echeance);
        });

        $regles = array_sum(array_map('intval', $issues));

        $this->assertSame(
            1,
            $regles,
            'Un seul processus doit regler ; les issues : ' . implode(' | ', $issues)
        );

        $ligne = DB::table('hull_repair_orders')->where('id', $ordre->id)->first();
        $this->assertNotNull($ligne, 'L ordre reste en base, marque comme regle.');

        $this->assertSame(HullRepairOrder::STATUS_SETTLED, $ligne->status);
        $this->assertNull($ligne->active_on_planet_id, 'Le verrou du dock doit etre relache.');

        // L effectif n a jamais bouge : les unites ne quittent pas le corps pendant la reparation.
        $this->assertSame(
            20,
            (int)DB::table('planets')->where('id', $corps)->value('cruiser'),
            'Un reglement joue deux fois ne cree ni ne detruit d unite.'
        );

        $this->assertNull(
            DB::table('planets')->where('id', $corps)->value('damaged_hulls'),
            'Une reparation menee a terme rend des unites intactes.'
        );
    }

    /**
     * Une confirmation et un depart se disputent les memes unites : **aucune ne se dedouble**.
     *
     * L invariant qui doit tenir quel que soit le vainqueur : ce qui reste sur le corps, plus ce qui
     * est parti, plus ce qui est tenu au dock, vaut exactement l effectif de depart.
     */
    public function testAConfirmationAndADepartureNeverDuplicateAUnit(): void
    {
        [$corps] = $this->aBodyWithDamagedCruisers(20, 8, 5000);

        $issues = $this->inParallel(2, static function (int $rang) use ($corps): string {
            $planete = resolve(PlanetServiceFactory::class)->make($corps, true);

            if ($planete === null) {
                return 'corps introuvable';
            }

            if ($rang === 0) {
                try {
                    resolve(HullRepairService::class)->confirm(
                        $planete,
                        DamagedHulls::of(['cruiser' => [5000 => 8]]),
                        '',
                        (int)now()->timestamp
                    );

                    return 'reparation:oui';
                } catch (Throwable $refus) {
                    return 'reparation:non';
                }
            }

            $unites = new UnitCollection();
            $unites->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 15);

            try {
                $partis = $planete->detachUnitsForDeparture(new Resources(0, 0, 0, 0), $unites);

                return $partis === null ? 'depart:non' : 'depart:oui';
            } catch (Throwable $refus) {
                return 'depart:non';
            }
        });

        $surLeCorps = (int)DB::table('planets')->where('id', $corps)->value('cruiser');
        $partis = str_contains(implode('|', $issues), 'depart:oui') ? 15 : 0;

        $this->assertSame(
            20,
            $surLeCorps + $partis,
            'Une unite ne peut etre ni creee ni perdue par cette course ; les issues : '
            . implode(' | ', $issues)
        );

        // Et si le dock a gagne, ce qu il tient est bien present sur le corps.
        $ordre = DB::table('hull_repair_orders')->where('planet_id', $corps)->first();

        if ($ordre !== null) {
            $tenues = array_sum(DamagedHulls::fromStorage($ordre->units)->levelsOf('cruiser'));

            $this->assertLessThanOrEqual(
                $surLeCorps,
                $tenues,
                'Le dock ne peut pas tenir plus d unites que le corps n en porte.'
            );
        }
    }

    /**
     * @return array{0: int, 1: int} l identifiant du corps, puis celui de son proprietaire
     */
    private function aBodyWithDamagedCruisers(int $total, int $abimes, int $degats): array
    {
        $proprietaire = (int)User::factory()->create()->id;

        // Une position libre, cherchee et non supposee : la base du bac est unique et partagee.
        $systeme = (int)DB::table('planets')->where('galaxy', 9)->max('system');
        $systeme = max($systeme, 0) + 1;

        $planete = Planet::factory()->create([
            'user_id' => $proprietaire,
            'galaxy' => 9,
            'system' => $systeme,
            'planet' => 1,
            'planet_type' => 1,
            'cruiser' => $total,
            'space_dock' => 5,
            'metal' => 5_000_000,
            'crystal' => 5_000_000,
            'deuterium' => 5_000_000,
            'damaged_hulls' => DamagedHulls::of(['cruiser' => [$degats => $abimes]])->toStorage(),
            // L horloge de production est mise au futur : sinon la relecture ajouterait la
            // production ecoulee et les nombres attendus ne tiendraient plus.
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);

        $this->corpsCrees[] = (int)$planete->id;

        return [(int)$planete->id, $proprietaire];
    }
}
