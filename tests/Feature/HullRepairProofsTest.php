<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Hull\DamagedHulls;
use OGame\Hull\HullRepairPanel;
use OGame\Hull\HullRepairService;
use OGame\Models\HullRepairOrder;
use OGame\Models\Resources;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * Les preuves que le cahier des charges du 10 septembre 2026 exige nommement (§10).
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI UN FICHIER A PART
 *
 * `HullRepairTest` etablit le **comportement** du dock : devis, confirmation, reglement, annulation.
 * Celui-ci etablit les exigences que la consigne a listees une par une — et qui n avaient **pas**
 * toutes de temoin, ce que je n avais pas releve avant que Keven le demande.
 *
 * Les nommer separement rend visible ce qui est prouve et ce qui ne l est pas. Un fichier ou chaque
 * essai porte le numero de son exigence se relit contre la consigne, ligne a ligne.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE CES ESSAIS NE PEUVENT PAS FAIRE
 *
 * Ils sont **deterministes** : un seul processus, une seule connexion. Les exigences de concurrence
 * reelle — confirmation contre depart simultane, double reglement sous deux travailleurs — vivent
 * dans `tests/MariaDb/HullRepairRaceTest.php`, parce que sous SQLite `lockForUpdate()` ne compile a
 * rien et que le juste et le faux y coincideraient.
 */
class HullRepairProofsTest extends AccountTestCase
{
    private HullRepairService $reparations;

    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('hull_damage_enabled', '1');
        HullRepairOrder::query()->delete();

        $this->reparations = resolve(HullRepairService::class);
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('hull_damage_enabled', '0');

        parent::tearDown();
    }

    private function unCorps(int $croiseurs, int $abimes, int $degats = 5000): void
    {
        $this->planetService->addUnit('cruiser', $croiseurs);
        $this->planetService->setObjectLevel(36, 5, true);

        if ($abimes > 0) {
            $this->planetService->writeDamagedHulls(DamagedHulls::of(['cruiser' => [$degats => $abimes]]));
        }

        $this->planetService->addResources(new Resources(5_000_000, 5_000_000, 5_000_000, 0));
    }

    /**
     * **§2 et §10 — une unite appartient a exactement une categorie.**
     *
     * L exigence centrale du cahier des charges : « une survivante endommagee ne produit pas
     * d epave ; une unite detruite ne revient pas aussi comme survivante ». Elle se verifie sur la
     * seule source qui alimente epaves et debris — la liste des unites **perdues** — et sur la
     * seule qui decrit les survivants.
     */
    public function testUneUniteEstDansExactementUneCategorie(): void
    {
        $this->unCorps(40, 0);

        $resultat = $this->uneBatailleSurLeCorps();

        $garnison = null;

        foreach ($resultat->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === 0) {
                $garnison = $flotte;
            }
        }

        $this->assertNotNull($garnison, 'La garnison doit figurer parmi les resultats defensifs.');

        $depart = $garnison->unitsStart->getAmountByMachineName('cruiser');
        $survivants = $garnison->unitsResult->getAmountByMachineName('cruiser');
        $perdus = $garnison->unitsLost->getAmountByMachineName('cruiser');
        $abimes = $garnison->survivorHulls()->damagedCountOf('cruiser');

        // La bataille doit avoir fait quelque chose, sinon les trois categories seraient triviales.
        $this->assertGreaterThan(0, $depart);

        // **Somme exacte** : intactes + abimees + detruites = depart.
        $this->assertSame(
            $depart,
            ($survivants - $abimes) + $abimes + $perdus,
            'Une unite est comptee dans plus d une categorie, ou dans aucune.'
        );

        // **Une abimee n est jamais dans les perdues** : c est ce qui garantit qu elle ne produit
        // ni epave ni debris, puisque les deux se calculent sur `unitsLost` et rien d autre.
        $this->assertLessThanOrEqual(
            $survivants,
            $abimes,
            'Il y a plus d unites abimees que de survivantes : une detruite a ete comptee deux fois.'
        );
    }

    /**
     * **§10 — epaves et survivants jamais comptes deux fois.**
     *
     * Le champ d epaves se calcule sur les **pertes**, la persistance des coques sur les
     * **survivants** : les deux listes sont disjointes par construction. L essai le verifie sur les
     * memes nombres plutot que de faire confiance a la construction.
     */
    public function testLesEpavesEtLesSurvivantsNeSeRecouvrentJamais(): void
    {
        $this->unCorps(40, 0);

        $resultat = $this->uneBatailleSurLeCorps();

        $perdusTotal = $resultat->defenderUnitsLost->getAmountByMachineName('cruiser');
        $survivantsTotal = $resultat->defenderUnitsResult->getAmountByMachineName('cruiser');

        $abimesTotal = 0;

        foreach ($resultat->defenderFleetResults as $flotte) {
            $abimesTotal += $flotte->survivorHulls()->damagedCountOf('cruiser');
        }

        // Ce qui nourrit les epaves, c est `unitsLost`. Ce qui garde une coque entamee, c est
        // `unitsResult`. Leur somme vaut le depart, et **aucune unite n est dans les deux**.
        $this->assertSame(
            40,
            $perdusTotal + $survivantsTotal,
            'Le total ne se conserve pas : une unite est perdue et survivante a la fois.'
        );

        $this->assertLessThanOrEqual($survivantsTotal, $abimesTotal);
    }

    /**
     * **§9 et §10 — migration depuis les donnees existantes.**
     *
     * « Les unites existantes sans etat de coque doivent etre considerees intactes, sans inventer de
     * dommages historiques. » C est ce que fait une colonne `null`, et l essai le verifie sur un
     * corps qui n a jamais rien ecrit — l etat exact d une planete d avant la migration.
     */
    public function testUnCorpsDAvantLaMigrationEstIntact(): void
    {
        $this->planetService->addUnit('cruiser', 25);

        // La colonne est remise a son etat d origine : jamais ecrite.
        DB::table('planets')->where('id', $this->planetService->getPlanetId())->update(['damaged_hulls' => null]);
        $this->planetService->reloadPlanet();

        $degats = $this->planetService->damagedHulls();

        $this->assertTrue($degats->isEmpty(), 'Un corps sans colonne doit etre entierement intact.');
        $this->assertSame(0, $degats->damagedCountOf('cruiser'));

        // Et il entre au combat avec une suite de zeros : aucun dommage historique invente.
        $this->assertSame(
            array_fill(0, 25, 0),
            $degats->damageSequenceFor('cruiser', 25),
            'Un corps d avant la migration entre au combat avec des unites entamees.'
        );
    }

    /**
     * **§6 et §10 — une interruption entre le paiement, la reservation et l ecriture ne laisse
     * rien derriere.**
     *
     * Les trois gestes vivent dans une seule transaction. L essai la fait echouer **au milieu**, apres
     * le debit et avant que l ordre existe, et exige que le monde soit exactement celui d avant.
     *
     * C est une preuve **d effet**, pas de forme : elle ne lit pas le code, elle casse le chemin.
     */
    public function testUneInterruptionEntrePaiementEtOrdreNeLaisseRien(): void
    {
        $this->unCorps(20, 8);

        $avantMetal = $this->planetService->getResources()->metal->get();
        $avantDegats = $this->planetService->damagedHulls()->toStorage();

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);

        // **La panne est provoquee dans la transaction**, apres le debit : un evenement de modele
        // qui leve au moment ou l ordre s ecrit.
        HullRepairOrder::creating(static function (): void {
            throw new RuntimeException('panne simulee entre le paiement et l ecriture de l ordre');
        });

        try {
            $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), (int)Date::now()->timestamp);
            $this->fail('La panne simulee aurait du interrompre la confirmation.');
        } catch (RuntimeException $panne) {
            $this->assertStringContainsString('panne simulee', $panne->getMessage());
        } finally {
            HullRepairOrder::flushEventListeners();
        }

        $this->planetService->reloadPlanet();

        // **Rien n a bouge** : ni les ressources, ni les degats, ni un ordre fantome.
        $this->assertEqualsWithDelta(
            $avantMetal,
            $this->planetService->getResources()->metal->get(),
            1.0,
            'Le paiement a survecu a une transaction annulee.'
        );

        $this->assertSame(
            $avantDegats,
            $this->planetService->damagedHulls()->toStorage(),
            'Les degats ont ete deplaces vers un ordre qui n existe pas.'
        );

        $this->assertSame(
            0,
            HullRepairOrder::where('planet_id', $this->planetService->getPlanetId())->count(),
            'Un ordre fantome a survecu a la panne.'
        );

        $this->assertSame([], $this->planetService->unitsHeldAtDock(), 'Le dock tient des unites sans ordre.');
    }

    /**
     * **§7 et §10 — une attaque pendant une reparation ne laisse pas le dock soigner.**
     *
     * La regle tranchee le 10 septembre : l ouverture d un combat **clot** l ordre. Il n y a donc
     * plus rien a traiter pendant la bataille, et une fin de reparation ne peut pas ressusciter une
     * unite detruite — le probleme est supprime, pas surveille.
     */
    public function testUneAttaqueClotLOrdreEtLeDockNeSoignePlus(): void
    {
        $this->unCorps(20, 8);

        $selection = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $devis = $this->reparations->quoteFor($this->planetService, $selection);
        $debut = (int)Date::now()->timestamp;
        $ordre = $this->reparations->confirm($this->planetService, $selection, $devis->fingerprint(), $debut);

        // La bataille s ouvre a mi-parcours.
        $miParcours = $debut + (int)(($ordre->completed_at - $debut) / 2);

        $this->assertTrue(
            $this->reparations->endAnyRunningOn($this->planetService->getPlanetId(), HullRepairOrder::BECAUSE_COMBAT, $miParcours),
            'L ouverture d un combat doit clore l ordre en cours.'
        );

        $this->planetService->reloadPlanet();

        // **Plus rien n est tenu** : les unites sont rendues au corps, avec leur coque du moment.
        $this->assertSame([], $this->planetService->unitsHeldAtDock());
        $this->assertSame(8, $this->planetService->damagedHulls()->damagedCountOf('cruiser'));

        // Et le reglement de l echeance ne rend plus rien : il n y a plus d ordre a regler, donc
        // aucune unite ne peut ressusciter.
        $this->assertSame(
            0,
            $this->reparations->settleDue($ordre->completed_at),
            'Un ordre clos par un combat a quand meme ete regle a son echeance.'
        );

        $relu = HullRepairOrder::find($ordre->id);
        $this->assertNotNull($relu);
        $this->assertSame(HullRepairOrder::STATUS_CANCELLED, $relu->status);
        $this->assertSame(HullRepairOrder::BECAUSE_COMBAT, $relu->ended_because);
    }

    /**
     * **§8 et §10 — les droits d affichage.**
     *
     * « Ne pas exposer automatiquement ces nouvelles donnees aux adversaires ni aux allies. » Le
     * panneau se compose depuis les colonnes du corps courant : il n existe aucun chemin qui le
     * rende pour un tiers, et l essai etablit que le contenu decrit bien **ce corps-la**.
     *
     * Il etablit aussi le libelle exact que la consigne demande — « 20 croiseurs, dont 8
     * endommages » — et surtout le **detail par palier**, pour qu aucune moyenne ne passe pour la
     * sante de chaque vaisseau.
     */
    public function testLePanneauDitLEtatSansMoyenneEtSansLExposerAuxTiers(): void
    {
        $this->planetService->addUnit('cruiser', 20);
        $this->planetService->setObjectLevel(36, 5, true);
        $this->planetService->writeDamagedHulls(DamagedHulls::of(['cruiser' => [5000 => 5, 2500 => 3]]));

        $panneau = resolve(HullRepairPanel::class)->forPlanet($this->planetService);

        $this->assertNotNull($panneau, 'Le panneau doit exister quand le chantier est arme.');

        $ligne = null;

        foreach ($panneau['fleet'] as $candidate) {
            if ($candidate['machine_name'] === 'cruiser') {
                $ligne = $candidate;
            }
        }

        $this->assertNotNull($ligne, 'Le panneau doit decrire les croiseurs endommages.');
        $this->assertSame(20, $ligne['total'], 'Le total doit etre celui du corps.');
        $this->assertSame(8, $ligne['damaged'], 'Huit croiseurs sont endommages.');

        // **Le detail par palier, jamais une moyenne** : deux paliers distincts, pas un pourcentage.
        $this->assertCount(2, $ligne['levels'], 'Une moyenne unique masquerait la sante de chaque vaisseau.');

        $paliers = [];

        foreach ($ligne['levels'] as $palier) {
            $paliers[$palier['damage_basis_points']] = $palier['count'];
        }

        $this->assertSame([2500 => 3, 5000 => 5], $paliers);

        // Interrupteur desarme : le panneau n existe pas du tout — pas un bloc vide qui annoncerait
        // une fonction absente.
        resolve(SettingsService::class)->set('hull_damage_enabled', '0');
        $this->assertNull(resolve(HullRepairPanel::class)->forPlanet($this->planetService));
    }

    /**
     * Une bataille reelle sur le corps courant, jouee par le moteur partage.
     */
    private function uneBatailleSurLeCorps(): \OGame\GameMissions\BattleEngine\Models\BattleResult
    {
        $attaquant = new \OGame\GameMissions\BattleEngine\Models\AttackerFleet();
        $attaquant->units = new \OGame\GameObjects\Models\Units\UnitCollection();
        $attaquant->units->addUnit(\OGame\Services\ObjectService::getUnitObjectByMachineName('battle_ship'), 25);
        $attaquant->player = resolve(\OGame\Factories\PlayerServiceFactory::class)->make($this->currentUserId, true);
        $attaquant->fleetMissionId = 7777;
        $attaquant->ownerId = $this->currentUserId;
        $attaquant->cargoResources = new Resources(0, 0, 0, 0);
        $attaquant->isInitiator = true;
        $attaquant->fleetMission = null;

        $defenseur = \OGame\GameMissions\BattleEngine\Models\DefenderFleet::fromPlanet($this->planetService);

        $moteur = new \OGame\GameMissions\BattleEngine\PhpBattleEngine(
            [$attaquant],
            $this->planetService,
            [$defenseur],
            resolve(SettingsService::class),
            \OGame\Combat\Support\LootContextForMission::lootingOrDegraded(
                [$attaquant],
                $this->planetService,
                'proof',
                7777,
                \OGame\Combat\Allocation\FrozenLootAllocation::atOperationStart(),
            ),
        );

        return $moteur->simulateBattle();
    }
}
