<?php

namespace Tests\Unit;

use ArrayObject;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\FleetUnionService;
use Tests\TestCase;

/**
 * Les ecritures d'une union sont atomiques, et une jointure valide ce que la creation validait.
 *
 * ## Les deux defauts que ce fichier fige
 *
 * **Aucune transaction.** `joinUnion()` enchainait trois ecritures : l'heure d'arrivee de l'union,
 * celle de tous ses membres, puis le rattachement de la nouvelle mission. Une panne entre les deux
 * premieres et la troisieme laissait l'union decalee **et le rejoignant dehors** — tous les membres
 * arrivaient plus tard pour rien, au profit d'une flotte qui n'etait jamais entree.
 *
 * **Presque aucune validation.** `createUnion()` en faisait trois que `joinUnion()` ne faisait pas :
 * le genre de mission, l'etat de la mission, et l'appartenance a une union. Leur absence laissait un
 * transport devenir une attaque groupee, une mission deja traitee rejoindre, et une mission deja
 * engagee ailleurs changer d'union en laissant un trou dans les creneaux de la premiere.
 *
 * ## Comment l'atomicite est mesuree, plutot que supposee
 *
 * Un ecouteur de requetes releve `DB::transactionLevel()` a chaque ecriture reelle. Si les ecritures
 * se produisent dans une transaction propre, le niveau observe est **strictement superieur** a celui
 * qui regnait avant l'appel. C'est une mesure directe, pas une lecture du code : elle tiendrait
 * encore si quelqu'un remplacait `DB::transaction()` par autre chose d'equivalent, et tomberait si
 * on la retirait.
 *
 * Le verrou de ligne, lui, n'est pas mesurable ici : `lockForUpdate()` ne compile rien sur SQLite,
 * ou tourne cette suite. Il est ecrit, documente, et n'a d'effet qu'en MariaDB.
 */
class FleetUnionAtomicityTest extends TestCase
{
    private FleetUnionService $service;

    /**
     * Les niveaux de transaction releves a chaque ecriture reelle.
     *
     * Un objet mutable plutot qu'un tableau : l'ecouteur y ajoute pendant l'appel mesure, et un
     * analyseur statique ne peut pas correler cette ecriture-la avec l'appel qui la declenche. Avec
     * un tableau, il concluait que la liste restait vide — juste dans son modele, faux dans les
     * faits.
     *
     * @var ArrayObject<int, int>
     */
    private ArrayObject $writeLevels;

    /**
     * Le niveau de transaction qui regnait avant l'appel mesure.
     */
    private int $outerLevel = 0;

    /**
     * Le nombre de corps deja crees, pour donner a chacun des coordonnees a lui.
     */
    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FleetUnionService::class);
        $this->writeLevels = new ArrayObject();

        // **Un seul ecouteur, pose une fois.** En enregistrer un par mesure les accumulerait dans le
        // processus, chacun gardant une reference sur un tableau que plus personne ne lit.
        DB::listen(function (QueryExecuted $requete): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $requete->sql) === 1) {
                $this->writeLevels[] = DB::transactionLevel();
            }
        });

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Un transport ne devient pas une attaque groupee.
     */
    public function testOnlyAnAttackCanJoinAUnion(): void
    {
        [$union, $second] = $this->aUnionAndASecondAttack();

        $second->mission_type = 3; // Transport
        $second->save();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(__('t_acs.error_invalid_mission_type'));

        $this->service->joinUnion($union, $second);
    }

    /**
     * Une mission arrivee ou annulee n'a plus rien a rejoindre.
     */
    public function testAMissionThatIsOverCannotJoin(): void
    {
        foreach (['processed', 'canceled'] as $colonne) {
            [$union, $second] = $this->aUnionAndASecondAttack();

            $second->{$colonne} = 1;
            $second->save();

            try {
                $this->service->joinUnion($union, $second);

                $this->fail("A mission marked {$colonne} joined a union.");
            } catch (Exception $refus) {
                $this->assertSame(__('t_acs.error_mission_not_active'), $refus->getMessage());
            }
        }
    }

    /**
     * Une flotte qui rentre chez elle ne rejoint pas une union.
     *
     * ## Le defaut que cet essai ferme
     *
     * `startReturn()` recopie le `mission_type` du parent : un retour d'attaque porte donc
     * `mission_type = 1` et se presente exactement comme une attaque. Il n'est ni traite, ni annule,
     * ni deja dans une union — la mission de retour est une ligne neuve.
     *
     * Il franchissait donc **tous** les autres controles. Un joueur pouvait faire rejoindre une union
     * a une flotte qui rentre : elle consommait un creneau sur seize, et surtout — puisque la
     * jointure aligne l'union sur l'arrivee la plus tardive — elle retardait toute l'attaque groupee.
     *
     * Le lien vers la mission prolongee est le seul fait qui distingue les deux.
     */
    public function testAFleetOnItsWayHomeCannotJoinAUnion(): void
    {
        [$union, $second] = $this->aUnionAndASecondAttack();

        // Le retour d'une attaque : meme genre, meme proprietaire, mais il prolonge une mission.
        $fondatrice = FleetMission::where('union_id', $union->id)->orderBy('union_slot')->first();

        $second->parent_id = $fondatrice?->id;
        $second->save();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(__('t_acs.error_returning_fleet'));

        $this->service->joinUnion($union, $second);
    }

    /**
     * Une mission deja dans une union n'en change pas.
     *
     * La deplacer laisserait un creneau vide dans la premiere : les numeros ne seraient plus
     * consecutifs, et le compactage du rappel ne rattraperait pas le trou.
     */
    public function testAMissionAlreadyInAUnionCannotJoinAnother(): void
    {
        [$union, $second] = $this->aUnionAndASecondAttack();

        $this->service->joinUnion($union, $second);
        $second->refresh();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(__('t_acs.error_already_in_union'));

        $this->service->joinUnion($union, $second);
    }

    /**
     * Les ecritures d'une jointure se produisent dans une transaction a elles.
     */
    public function testTheWritesOfAJoinHappenInsideATransaction(): void
    {
        [$union, $second] = $this->aUnionAndASecondAttack();

        $plusBas = $this->lowestWriteLevelDuring(function () use ($union, $second): void {
            $this->service->joinUnion($union, $second);
        });

        $this->assertGreaterThan(
            $this->outerLevel,
            $plusBas,
            'A join wrote outside any transaction of its own: a failure halfway would move the union without letting anybody in.'
        );
    }

    /**
     * Une union et sa mission fondatrice ne font qu'une ecriture.
     *
     * Une union sans sa fondatrice apparaitrait dans la liste des unions a rejoindre, vide, et sans
     * moyen d'y entrer.
     */
    public function testANewUnionAndItsFoundingMissionAreOneWrite(): void
    {
        $premiere = $this->anAttackMission($this->aPlayerWithAPlanet());

        $plusBas = $this->lowestWriteLevelDuring(function () use ($premiere): void {
            $this->service->createUnion($premiere);
        });

        $this->assertGreaterThan(
            $this->outerLevel,
            $plusBas,
            'A union was created outside any transaction: a failure would leave an empty union nobody can join.'
        );
    }

    /**
     * Une jointure prend la barriere du corps vise avant l'union.
     *
     * ## L'ordre global, et ce que l'inverse ouvrait
     *
     * La jointure verrouillait l'union en premier. La porte des mouvements prend barriere,
     * instances, unions puis mission : une jointure qui commence par l'union peut attendre une porte
     * qui tient la barriere et attend cette union. Deux transactions, chacune tenant ce que l'autre
     * demande. La jointure entre donc par la porte, et l'union visee y prend son rang.
     */
    public function testAJoinTakesTheBarrierBeforeTheUnion(): void
    {
        [$union, $second] = $this->aUnionAndASecondAttack();

        $tables = [];
        DB::listen(function (QueryExecuted $requete) use (&$tables): void {
            foreach (['celestial_body_combat_barriers', 'fleet_unions'] as $table) {
                if (str_contains($requete->sql, '"' . $table . '"')) {
                    $tables[] = $table;

                    return;
                }
            }
        });

        $this->service->joinUnion($union, $second);

        $this->assertNotSame([], $tables, 'The join touched neither the barrier nor the union.');
        $this->assertSame('celestial_body_combat_barriers', $tables[0], 'A join takes the union before the barrier: the global order is inverted.');
        $this->assertSame($union->id, (int)$second->union_id, 'The caller model was not aligned on the row written under the lock.');
    }

    /**
     * Le retrait d'une flotte, le compactage des creneaux et le transfert de propriete ne font
     * qu'une ecriture.
     */
    public function testARecallLeavesTheUnionInOneWrite(): void
    {
        [$union, $second] = $this->aUnionAndASecondAttack();

        $this->service->joinUnion($union, $second);
        $second->refresh();

        $plusBas = $this->lowestWriteLevelDuring(function () use ($second): void {
            $this->service->handleFleetRecall($second);
        });

        $this->assertGreaterThan(
            $this->outerLevel,
            $plusBas,
            'A recall wrote outside any transaction: slots could be left half-renumbered.'
        );
    }

    /**
     * Aucune erreur d'attaque groupee n'atteint le joueur sous la forme de sa propre cle.
     *
     * Les neuf cles etaient appelees par le service et le controleur bien avant qu'un fichier de
     * langue n'existe : chacune s'affichait telle quelle dans une fenetre du jeu.
     */
    public function testEveryAcsErrorIsTranslated(): void
    {
        $cles = [
            'error_already_in_union',
            'error_exceeds_delay_limit',
            'error_invalid_mission_type',
            'error_max_fleets_reached',
            'error_max_players_reached',
            'error_mission_not_active',
            'error_mission_not_found',
            'error_not_buddy_or_ally',
            'error_returning_fleet',
            'error_technical',
            'error_not_found',
        ];

        foreach (['en', 'fr'] as $locale) {
            foreach ($cles as $cle) {
                // **Sans repli.** `__()` retombe sur `en` quand le fichier francais manque, et rend
                // alors une phrase anglaise : l'assertion « ce n'est pas la cle » serait satisfaite
                // pendant qu'un joueur francais lit de l'anglais. La mutation qui supprimait le
                // fichier francais avait effectivement survecu a la version precedente de cet essai.
                $this->assertTrue(
                    Lang::has('t_acs.' . $cle, $locale, false),
                    "The ACS error {$cle} has no translation of its own in {$locale}."
                );

                $traduite = __('t_acs.' . $cle, [], $locale);

                $this->assertNotSame(
                    't_acs.' . $cle,
                    $traduite,
                    "The ACS error {$cle} reaches the player as its own key in {$locale}."
                );

                $this->assertIsString($traduite);
                $this->assertNotSame('', trim($traduite));
            }
        }
    }

    /**
     * Le plus bas niveau de transaction observe pendant l'appel, sur une ecriture reelle.
     *
     * Une operation qui n'ecrit rien n'a aucune atomicite a montrer : l'echec est porte ici plutot
     * que par une assertion en amont, pour que le type rendu dise la verite.
     *
     * @param callable(): void $action
     * @return int
     */
    private function lowestWriteLevelDuring(callable $action): int
    {
        $this->outerLevel = DB::transactionLevel();
        $this->writeLevels->exchangeArray([]);

        $action();

        $niveaux = $this->writeLevels->getArrayCopy();

        if ($niveaux === []) {
            $this->fail('The operation wrote nothing at all: there is no atomicity to observe.');
        }

        return min($niveaux);
    }

    /**
     * Une union creee par un joueur, et une seconde attaque du meme joueur, prete a rejoindre.
     *
     * Le meme joueur des deux cotes : la regle « ami ou allie » est eprouvee ailleurs, et elle
     * n'est pas le sujet ici.
     *
     * @return array{0: FleetUnion, 1: FleetMission}
     */
    private function aUnionAndASecondAttack(): array
    {
        $joueur = $this->aPlayerWithAPlanet();

        $union = $this->service->createUnion($this->anAttackMission($joueur));

        return [$union, $this->anAttackMission($joueur)];
    }

    /**
     * Un joueur avec une planete, a des coordonnees libres.
     *
     * @return array{0: User, 1: Planet}
     */
    private function aPlayerWithAPlanet(): array
    {
        $utilisateur = User::factory()->create();

        // Des coordonnees deterministes, une par corps : un tirage dans une fixture finit toujours
        // par produire un echec une fois sur quatre, sans que personne ne sache pourquoi.
        $this->bodies++;

        $planete = Planet::factory()->create([
            'user_id' => $utilisateur->id,
            'galaxy' => 3,
            'system' => 200 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);

        return [$utilisateur, $planete];
    }

    /**
     * Une attaque simple en vol, visant toujours la meme cible.
     *
     * @param array{0: User, 1: Planet} $player
     */
    private function anAttackMission(array $player): FleetMission
    {
        [$utilisateur, $planete] = $player;

        return FleetMission::forceCreate([
            'user_id' => $utilisateur->id,
            'planet_id_from' => $planete->id,
            'mission_type' => 1,
            'time_departure' => time(),
            'time_arrival' => time() + 1_000,
            // **Un corps vise, pas seulement des coordonnees.** La porte des mouvements prend la
            // barriere de ce corps en premier ; une mission sans corps n'aurait rien a tenir, et
            // l'ordre des verrous ne se verrait pas.
            'planet_id_to' => $this->theTargetBodyId(),
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);
    }

    /**
     * Le corps que toutes les attaques de ces essais visent, en 1:1:1.
     */
    private function theTargetBodyId(): int
    {
        $existant = Planet::query()
            ->where('galaxy', 1)
            ->where('system', 1)
            ->where('planet', 1)
            ->where('planet_type', 1)
            ->value('id');

        if ($existant !== null) {
            return (int)$existant;
        }

        return (int)Planet::factory()->create([
            'user_id' => User::factory()->create()->id,
            'galaxy' => 1,
            'system' => 1,
            'planet' => 1,
        ])->id;
    }
}
