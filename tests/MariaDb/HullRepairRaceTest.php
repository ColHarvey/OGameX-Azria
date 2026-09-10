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
 * Cinq choses qu une seule connexion ne peut pas dire.
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
 *
 * **Un reglement contre une cloture par combat.** Les deux se produisent dans le jeu a la meme
 * seconde, et ils ne veulent pas la meme chose : l un rend des unites intactes sans rien rembourser,
 * l autre fige la coque a mi-parcours et rend la part non faite. Un etat mixte — regle **et**
 * rembourse — est exactement ce qu une absence de serialisation produirait.
 *
 * **Une cloture par combat contre une annulation du joueur.** Elle a trouve un defaut reel : la fin
 * anticipee prenait l ordre puis le corps, quand le reglement d une bataille tient deja le corps.
 * Deux ordres de verrous opposes, un interblocage ABBA, et le perdant aurait ete le reglement de la
 * bataille.
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

        // La connexion nommee que la course du reglement emploie : la purger relache tout ce
        // qu elle tiendrait encore si l essai s est arrete avant son commit.
        DB::purge('mysql_temoin');

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
     * Un reglement et une cloture par combat se disputent le meme ordre : **un seul effet**.
     *
     * ## Le chevauchement est impose, pas espere
     *
     * Le parent tient la ligne du **corps** sur une connexion a part, que la bifurcation ne ferme
     * pas. Les deux chemins partent, butent tous les deux dessus — c est le premier des deux verrous
     * du dock, et depuis le bac ils le prennent tous les deux —, et le parent **attend de les voir
     * attendre** : deux processus arretes sur `planets ... for update`, lus dans
     * `information_schema.PROCESSLIST`. Aucune duree ne decide de rien ; si les deux ne viennent pas
     * attendre, l essai echoue en disant qu il n a rien prouve, au lieu de verdir sur un croisement
     * qui n a pas eu lieu.
     *
     * **Ils attendent la meme ligne, et c est voulu.** Un rendez-vous pris sur deux lignes
     * differentes imposerait le vainqueur par construction, et la moitie du verdict ne serait
     * jamais exercee. Ici le moteur tranche, et l essai exige la coherence de l issue quelle qu elle
     * soit.
     *
     * Une premiere version tenait la ligne de l ordre : elle encodait l ancien ordre des verrous, ou
     * le reglement demandait l ordre sans passer par le corps. Le bac l a dit en refusant de
     * conclure.
     *
     * ## Pourquoi deux instants differents
     *
     * Les faire tomber sur le **meme** instant ne prouverait rien : passe l echeance, la fin
     * anticipee devient elle-meme un reglement (`$part >= 1.0`), et les deux issues coincident — le
     * juste et le faux seraient indiscernables. Le travailleur regle donc a l echeance — unites
     * intactes, rien de rembourse — pendant que la bataille clot a mi-parcours — coque figee a
     * mi-chemin, moitie du prix rendue.
     *
     * ## Ce qui est verifie, et ou
     *
     * **En base, pas dans la reponse.** Le statut final, la raison de fin, les degats poses sur le
     * corps et le solde de metal doivent decrire **le meme vainqueur**. Un etat mixte — regle et
     * rembourse, ou annule et rendu intact — est exactement ce qu une absence de serialisation
     * produirait, et rien d autre ne le montre.
     */
    public function testASettlementAndACombatClosureLeaveOneCoherentState(): void
    {
        [$corps] = $this->aBodyWithDamagedCruisers(20, 8, 5000);

        $planete = resolve(PlanetServiceFactory::class)->make($corps, true);
        $this->assertNotNull($planete, 'Le corps monte doit se charger.');

        $ordre = resolve(HullRepairService::class)->confirm(
            $planete,
            DamagedHulls::of(['cruiser' => [5000 => 8]]),
            '',
            (int)now()->timestamp
        );

        $debut = (int)$ordre->started_at;
        $echeance = (int)$ordre->completed_at;
        $duree = $echeance - $debut;

        $this->assertGreaterThan(1, $duree, 'Une reparation instantanee ne laisse aucun instant intermediaire a departager.');

        $miParcours = $debut + intdiv($duree, 2);
        $part = ($miParcours - $debut) / $duree;

        // Le solde d apres la confirmation : c est de lui que se mesure un remboursement.
        $metalApres = (int)DB::table('planets')->where('id', $corps)->value('metal');
        $rembourseSiCloture = (int)floor((int)$ordre->cost_metal * (1.0 - $part));
        $degatsSiCloture = (int)round(5000 * (1.0 - $part));

        $this->assertGreaterThan(0, $rembourseSiCloture, 'Sans remboursement attendu, les deux issues auraient le meme solde.');

        $identifiant = (int)$ordre->id;

        // Le parent tient le corps sur une connexion nommee : elle survit a la bifurcation, et les
        // enfants, qui ne s en servent jamais, ne la ferment pas en mourant.
        config(['database.connections.mysql_temoin' => config('database.connections.mysql')]);
        $temoin = DB::connection('mysql_temoin');
        $temoin->beginTransaction();
        $this->assertNotNull(
            $temoin->table('planets')->where('id', $corps)->lockForUpdate()->first(),
            'Le parent doit tenir la ligne du corps avant de lancer les deux chemins.'
        );

        $issues = $this->inParallel(
            2,
            static function (int $rang) use ($corps, $identifiant, $echeance, $miParcours): string {
                $reparations = resolve(HullRepairService::class);

                if ($rang === 0) {
                    /** @var HullRepairOrder|null $relu */
                    $relu = HullRepairOrder::where('id', $identifiant)->first();

                    if ($relu === null) {
                        return 'reglement:ordre disparu';
                    }

                    try {
                        // `settle()` et non `settleDue()` : la base du bac est partagee, et un ordre
                        // echu laisse par une classe voisine ferait mentir un comptage. Le
                        // travailleur appelle exactement cette methode-la.
                        return 'reglement:' . ($reparations->settle($relu, $echeance) ? 'oui' : 'non');
                    } catch (Throwable $echec) {
                        // **Rapporte, jamais leve** : une exception qui remonte au harnais fait
                        // echouer l essai avant que le parent ait pu demander a la base quel cycle
                        // elle a rompu.
                        return 'reglement:erreur:' . $echec::class . ' : ' . $echec->getMessage();
                    }
                }

                try {
                    return 'combat:' . ($reparations->endAnyRunningOn(
                        $corps,
                        HullRepairOrder::BECAUSE_COMBAT,
                        $miParcours
                    ) ? 'oui' : 'non');
                } catch (Throwable $echec) {
                    return 'combat:erreur:' . $echec::class . ' : ' . $echec->getMessage();
                }
            },
            function () use ($temoin): void {
                // **Les deux sont venus attendre le corps, et la base le dit.** La duree separe
                // l attente du simple passage : une lecture verrouillante libre se termine en
                // millisecondes.
                $this->waitUntil(
                    static function (): bool {
                        $compte = DB::selectOne(
                            'SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST'
                            . ' WHERE ID <> CONNECTION_ID() AND INFO LIKE ? AND INFO LIKE ? AND TIME >= 1',
                            ['%planets%', '%for update%']
                        );

                        return $compte !== null && (int)$compte->n >= 2;
                    },
                    'Les deux chemins ne sont pas venus attendre le corps : rien ne s est chevauche, et cette course ne prouverait rien.'
                );

                $temoin->commit();
            }
        );

        $trace = implode(' | ', $issues);

        $this->assertNoDeadlock($trace);
        $this->assertStringNotContainsString('erreur:', $trace, 'Un des deux chemins a echoue ; les issues : ' . $trace);

        $aRegle = str_contains($trace, 'reglement:oui');
        $aCloture = str_contains($trace, 'combat:oui');

        $this->assertTrue($aRegle || $aCloture, 'Aucun des deux chemins n a agi ; les issues : ' . $trace);
        $this->assertFalse($aRegle && $aCloture, 'Les deux chemins ont agi sur le meme ordre ; les issues : ' . $trace);

        $ligne = DB::table('hull_repair_orders')->where('id', $identifiant)->first();
        $this->assertNotNull($ligne, 'L ordre reste en base.');
        $this->assertNull($ligne->active_on_planet_id, 'Le verrou du dock doit etre relache dans les deux issues.');

        $metalFinal = (int)DB::table('planets')->where('id', $corps)->value('metal');
        $degatsFinaux = DamagedHulls::fromStorage(
            DB::table('planets')->where('id', $corps)->value('damaged_hulls')
        );

        // L effectif ne bouge dans aucune des deux issues : les unites ne quittent jamais le corps.
        $this->assertSame(
            20,
            (int)DB::table('planets')->where('id', $corps)->value('cruiser'),
            'Cette course ne cree ni ne detruit d unite ; les issues : ' . $trace
        );

        if ($aRegle) {
            $this->assertSame(HullRepairOrder::STATUS_SETTLED, $ligne->status, 'Le reglement a gagne, le statut doit le dire.');
            $this->assertNull($ligne->ended_because, 'Un reglement n a pas de raison de fin anticipee.');
            $this->assertSame($metalApres, $metalFinal, 'Un ordre regle ne rembourse rien ; les issues : ' . $trace);
            $this->assertTrue($degatsFinaux->isEmpty(), 'Un ordre regle rend des unites intactes ; les issues : ' . $trace);

            return;
        }

        $this->assertSame(HullRepairOrder::STATUS_CANCELLED, $ligne->status, 'La cloture a gagne, le statut doit le dire.');
        $this->assertSame(HullRepairOrder::BECAUSE_COMBAT, $ligne->ended_because, 'La raison de fin doit nommer le combat.');
        $this->assertSame(
            $metalApres + $rembourseSiCloture,
            $metalFinal,
            'La part non faite doit etre rendue une fois, exactement ; les issues : ' . $trace
        );
        $this->assertSame(
            [$degatsSiCloture => 8],
            $degatsFinaux->levelsOf('cruiser'),
            'Les huit croiseurs reviennent avec la coque figee a mi-parcours ; les issues : ' . $trace
        );
    }

    /**
     * Une cloture par combat et une annulation du joueur : **elles ne s interbloquent pas**.
     *
     * ## Le defaut que cette course a trouve
     *
     * `endEarly()` prenait l ordre **puis** le corps. Le reglement d une bataille, lui, tient deja la
     * ligne du corps quand il vient clore le chantier — `CombatSettlementService` verrouille les
     * corps avant `resolve()`, et `resolve()` appelle `endAnyRunningOn()`. Deux chemins, deux ordres
     * opposes : chacun tient ce que l autre attend, MariaDB en tue un (erreur 1213). En production,
     * le tue aurait ete le reglement d une bataille, mis en echec par une annulation de reparation
     * arrivee au mauvais instant.
     *
     * `lockForUpdate()` ne compile a rien sous SQLite : la suite ordinaire restait verte.
     *
     * ## L orchestration : deux rendez-vous, aucune duree
     *
     * Une course qui se contenterait de lancer les deux chemins et d esperer qu ils se croisent ne
     * prouverait rien — et un `usleep()` bien choisi n est qu une esperance ecrite en chiffres.
     * Cette course impose donc l ordre par deux faits observables :
     *
     * 1. **Le jalon.** La bataille verrouille le corps, puis pose un fichier. L annulation ne
     *    commence qu apres l avoir vu : elle ne peut pas prendre le corps avant la bataille, et les
     *    roles ne peuvent pas s inverser.
     * 2. **L attente lue en base.** La bataille ne demande l ordre qu apres avoir vu, dans
     *    `information_schema.PROCESSLIST`, un processus arrete sur `planets ... for update` — donc
     *    apres que l annulation a pris tout ce qu elle prend avant le corps. Sous l ancien code,
     *    c est precisement l ordre de reparation ; le cycle est alors complet et l interblocage
     *    certain, jamais probable.
     *
     * Si l un des deux rendez-vous ne se produit pas, l essai echoue en le disant : il refuse de
     * conclure d un croisement qui n a pas eu lieu.
     */
    public function testACombatClosureAndAPlayerCancellationNeverDeadlock(): void
    {
        [$corps] = $this->aBodyWithDamagedCruisers(20, 8, 5000);

        $planete = resolve(PlanetServiceFactory::class)->make($corps, true);
        $this->assertNotNull($planete, 'Le corps monte doit se charger.');

        $ordre = resolve(HullRepairService::class)->confirm(
            $planete,
            DamagedHulls::of(['cruiser' => [5000 => 8]]),
            '',
            (int)now()->timestamp
        );

        $debut = (int)$ordre->started_at;
        $duree = (int)$ordre->completed_at - $debut;
        $identifiant = (int)$ordre->id;

        // Deux instants distincts : le solde final dit alors **qui** a clos, sans avoir a le croire.
        $instantCombat = $debut + intdiv($duree, 4);
        $instantJoueur = $debut + intdiv(3 * $duree, 4);

        $metalApres = (int)DB::table('planets')->where('id', $corps)->value('metal');
        $rendu = (int)floor((int)$ordre->cost_metal * (1.0 - ($instantCombat - $debut) / $duree));

        $jalon = sys_get_temp_dir() . '/ogamex-dock-jalon-' . bin2hex(random_bytes(6));

        $issues = $this->inParallel(2, function (int $rang) use ($corps, $identifiant, $instantCombat, $instantJoueur, $jalon): string {
            $reparations = resolve(HullRepairService::class);

            if ($rang === 0) {
                // La bataille : le corps d abord, comme `CombatSettlementService` le fait, puis la
                // cloture du dock dans la meme transaction.
                DB::beginTransaction();

                try {
                    Planet::where('id', $corps)->lockForUpdate()->first();

                    // Le corps est tenu : l annulation peut partir.
                    touch($jalon);

                    // **Et elle est venue buter dessus.** Lu dans la base, jamais suppose : sous
                    // l ancien code, elle tient alors l ordre que cette transaction va demander.
                    $this->waitUntilAProcessWaitsOnALockOn('planets');

                    $ferme = $reparations->endAnyRunningOn($corps, HullRepairOrder::BECAUSE_COMBAT, $instantCombat);

                    DB::commit();

                    return 'combat:' . ($ferme ? 'oui' : 'non');
                } catch (Throwable $echec) {
                    DB::rollBack();

                    return 'combat:erreur:' . $echec::class . ' : ' . $echec->getMessage();
                }
            }

            // L annulation du joueur ne commence qu une fois le corps tenu par la bataille.
            $limite = microtime(true) + 15.0;

            while (!file_exists($jalon)) {
                if (microtime(true) >= $limite) {
                    return 'joueur:jalon jamais pose';
                }

                usleep(5_000);
            }

            /** @var HullRepairOrder|null $relu */
            $relu = HullRepairOrder::where('id', $identifiant)->first();

            if ($relu === null) {
                return 'joueur:ordre disparu';
            }

            $avant = microtime(true);

            try {
                $ferme = $reparations->endEarly($relu, HullRepairOrder::BECAUSE_PLAYER, $instantJoueur);

                return 'joueur:' . ($ferme ? 'oui' : 'non') . ':' . (int)round((microtime(true) - $avant) * 1000);
            } catch (Throwable $echec) {
                return 'joueur:erreur:' . $echec::class . ' : ' . $echec->getMessage();
            }
        });

        @unlink($jalon);

        $trace = implode(' | ', $issues);

        // **Aucun interblocage.** C est la raison d etre de cette course.
        $this->assertNoDeadlock($trace);
        $this->assertStringNotContainsString('erreur:', $trace, 'Un des deux chemins a echoue ; les issues : ' . $trace);
        $this->assertStringNotContainsString('jalon jamais pose', $trace, 'La bataille n a jamais tenu le corps : rien ne s est chevauche.');

        // L attente de l annulation n est pas la preuve — les deux rendez-vous le sont — mais elle
        // corrobore : bloquee sur le corps que la bataille tient, elle ne peut pas repondre avant
        // que celle-ci ait relache.
        if (preg_match('/joueur:(?:oui|non):(\d+)/', $trace, $mesure) !== 1) {
            $this->fail('L annulation n a pas rapporte son attente ; les issues : ' . $trace);
        }

        $this->assertGreaterThan(
            0,
            (int)$mesure[1],
            'L annulation a repondu sans attendre ; les issues : ' . $trace
        );

        // Un seul des deux met fin a l ordre, et la base decrit ce vainqueur-la.
        $this->assertSame(1, substr_count($trace, ':oui'), 'Un seul chemin doit mettre fin a l ordre ; les issues : ' . $trace);
        $this->assertStringContainsString('combat:oui', $trace, 'La bataille tenait le corps : c est elle qui devait clore ; les issues : ' . $trace);

        $ligne = DB::table('hull_repair_orders')->where('id', $identifiant)->first();
        $this->assertNotNull($ligne, 'L ordre reste en base.');
        $this->assertSame(HullRepairOrder::STATUS_CANCELLED, $ligne->status);
        $this->assertSame(HullRepairOrder::BECAUSE_COMBAT, $ligne->ended_because, 'La raison de fin doit nommer le combat ; les issues : ' . $trace);
        $this->assertNull($ligne->active_on_planet_id, 'Le verrou du dock doit etre relache.');

        $this->assertSame(
            $metalApres + $rendu,
            (int)DB::table('planets')->where('id', $corps)->value('metal'),
            'Le remboursement doit etre celui du vainqueur, verse une seule fois ; les issues : ' . $trace
        );
    }

    /**
     * Refuse un interblocage, **en le decrivant**.
     *
     * Le message de MariaDB est explicite — « Deadlock found when trying to get lock », SQLSTATE
     * 40001, erreur 1213 — mais il ne dit ni quelles transactions, ni ce que chacune tenait, ni ce
     * qu elle attendait. Le moteur, lui, le garde : `SHOW ENGINE INNODB STATUS` en donne la section
     * `LATEST DETECTED DEADLOCK`. Sans elle, le premier rouge de cette classe a coute une demi-heure
     * de raisonnement a vide sur un cycle qu il suffisait de lire.
     */
    private function assertNoDeadlock(string $trace): void
    {
        if (!str_contains(strtolower($trace), 'deadlock') && !str_contains($trace, '1213')) {
            return;
        }

        $this->fail(
            'Un interblocage a eu lieu ; les issues : ' . $trace . "\n\n"
            . "Ce que le moteur en dit :\n" . $this->latestDeadlockReport()
        );
    }

    /**
     * La section `LATEST DETECTED DEADLOCK` du moteur, ou ce qui explique qu elle manque.
     */
    private function latestDeadlockReport(): string
    {
        try {
            $etat = DB::selectOne('SHOW ENGINE INNODB STATUS');
        } catch (Throwable $refus) {
            return 'illisible (' . $refus->getMessage() . ') — le droit PROCESS est necessaire.';
        }

        $texte = is_object($etat) && property_exists($etat, 'Status') ? (string)$etat->Status : '';
        $debut = strpos($texte, 'LATEST DETECTED DEADLOCK');

        if ($debut === false) {
            return 'le moteur ne garde aucun interblocage recent.';
        }

        $fin = strpos($texte, '------------\nTRANSACTIONS', $debut);

        return trim(substr($texte, $debut, $fin === false ? 4000 : $fin - $debut));
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
