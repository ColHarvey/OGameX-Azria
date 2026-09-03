<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\Combat\Support\LootContext;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use Tests\UnitTestCase;

/**
 * Le taux de pillage d'un combat : d'ou il vient, et de quoi il ne depend plus.
 *
 * ## Les deux defauts que ces essais verrouillent
 *
 * Le moteur lisait `attackers[0]` et appliquait le bonus de **ce joueur-la** a toute l'attaque
 * groupee : une sonde appartenant a un Decouvreur, arrivee en tete de la collection, faisait passer
 * tout le butin de 50 % a 75 %. Et la methode consultee, `getInactiveLootPercentage()`, ne
 * regardait jamais si la cible etait reellement inactive — son nom le laissait pourtant croire.
 *
 * Les deux se corrigeaient separement, et se verifient donc separement.
 */
class BattleEngineLootRateTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetPlanetModel([
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
        $this->createAndSetUserTechModel([]);
    }

    /**
     * Une cible active ne donne aucun bonus, meme attaquee par un Decouvreur seul.
     *
     * **C'est le defaut d'inactivite.** L'ancien calcul rendait 75 % ici, parce qu'il ne consultait
     * que la classe de l'attaquant.
     */
    public function testAnActiveTargetGivesNoBonusEvenToADiscoverer(): void
    {
        $this->setTargetInactive(false);

        $taux = $this->rateFor([$this->fleet(10, CharacterClass::DISCOVERER)]);

        $this->assertSame(5_000, $taux, 'An active target must not grant the Discoverer bonus.');
    }

    /**
     * Une cible inactive attaquee par des Decouvreurs seuls : trois quarts.
     */
    public function testAnInactiveTargetAttackedOnlyByDiscoverersGivesThreeQuarters(): void
    {
        $this->setTargetInactive(true);

        $taux = $this->rateFor([$this->fleet(10, CharacterClass::DISCOVERER)]);

        $this->assertSame(7_500, $taux);
    }

    /**
     * Le taux suit la part de fret des Decouvreurs, et non le premier de la liste.
     *
     * **C'est le defaut `attackers[0]`.** Un seul petit transporteur de Decouvreur devant neuf
     * transporteurs d'un autre joueur ne vaut plus 75 %, mais un dixieme du bonus.
     */
    public function testTheRateFollowsTheShareOfDiscovererCargo(): void
    {
        $this->setTargetInactive(true);

        // Un General ne recoit aucun bonus de soute : les dix flottes portent donc la meme chose,
        // et la part se lit directement.
        $taux = $this->rateFor([
            $this->fleet(1, CharacterClass::DISCOVERER),
            $this->fleet(9, CharacterClass::GENERAL, 102),
        ]);

        // 5 000 + 2 500 x 1/10 = 5 250 points de base.
        $this->assertSame(5_250, $taux, 'One tenth of the cargo must give one tenth of the bonus.');
    }

    /**
     * Le poids est celui du fret, pas celui du nombre de vaisseaux.
     *
     * ## Ce que la mesure a revele, et qui merite d'etre consigne
     *
     * Un Collecteur porte **vingt-cinq pour cent de plus** dans les memes transporteurs. A nombre
     * de vaisseaux egal, sa flotte pese donc davantage dans la ponderation, et la part du
     * Decouvreur tombe sous le dixieme qu'on attendrait en comptant les coques.
     *
     * Ce n'est pas un accident du calcul : la regle recompense la classe **a proportion de ce
     * qu'elle peut emporter**, et un Collecteur peut emporter plus.
     */
    public function testTheWeightIsCargoAndNotHulls(): void
    {
        $this->setTargetInactive(true);

        $taux = $this->rateFor([
            $this->fleet(1, CharacterClass::DISCOVERER),
            $this->fleet(9, CharacterClass::COLLECTOR, 102),
        ]);

        // Un transporteur de Decouvreur sur 5 000 de fret contre neuf de Collecteur a 6 250 :
        // 5 000 / 61 250, soit 204 points de base de bonus.
        $this->assertSame(5_204, $taux, 'The Collector hold bonus must lower the Discoverer share.');
    }

    /**
     * Permuter les participants ne change rien.
     *
     * La propriete qui rendait l'ancien calcul indefendable : le meme groupe, dans un autre ordre,
     * pillait un montant different.
     */
    public function testPermutingTheAttackersChangesNothing(): void
    {
        $this->setTargetInactive(true);

        $composition = [
            [3, CharacterClass::DISCOVERER],
            [11, CharacterClass::GENERAL],
            [2, CharacterClass::DISCOVERER],
            [7, CharacterClass::COLLECTOR],
        ];

        $flottes = [];
        foreach ($composition as $rang => [$nombre, $classe]) {
            $flottes[] = $this->fleet($nombre, $classe, 101 + $rang);
        }

        $inverses = [];
        foreach (array_reverse($composition) as $rang => [$nombre, $classe]) {
            $inverses[] = $this->fleet($nombre, $classe, 201 + $rang);
        }

        $this->assertSame(
            $this->rateFor($flottes),
            $this->rateFor($inverses),
            'Permuting the attacking fleets changed the loot rate.'
        );
    }

    /**
     * Une flotte deja pleine ne pese rien dans le taux.
     *
     * Le fret qui compte est celui qui reste **libre** : une soute pleine ne peut rien emporter de
     * plus, et ne doit donc pas donner de poids au bonus de son proprietaire.
     */
    public function testAFullHoldWeighsNothing(): void
    {
        $this->setTargetInactive(true);

        $decouvreurPlein = $this->fleet(10, CharacterClass::DISCOVERER);
        $decouvreurPlein->cargoResources = new Resources(50_000, 0, 0, 0);

        $taux = $this->rateFor([$decouvreurPlein, $this->fleet(10, CharacterClass::COLLECTOR, 102)]);

        $this->assertSame(5_000, $taux, 'A fully loaded Discoverer fleet must not weigh in the rate.');
    }

    /**
     * Un attaquant pilote par le serveur n'herite d'aucun bonus.
     *
     * Le compte systeme porte une valeur dans sa colonne de classe comme n'importe quel compte ;
     * sans ce controle, un pirate deviendrait Decouvreur par accident de donnee.
     */
    public function testAServerDrivenAttackerGetsNoBonus(): void
    {
        $this->setTargetInactive(true);

        $pirate = $this->fleet(10, CharacterClass::DISCOVERER);
        $pirate->player->getUser()->is_npc = true;

        $this->assertSame(5_000, $this->rateFor([$pirate]), 'A pirate must not inherit the Discoverer bonus.');
    }

    /**
     * Le taux en pour-cent du rapport suit celui du calcul, arrondi vers le bas.
     */
    public function testTheReportedPercentageNeverAnnouncesMoreThanWasTaken(): void
    {
        $this->setTargetInactive(true);

        $moteur = $this->engineFor([
            $this->fleet(1, CharacterClass::DISCOVERER),
            $this->fleet(9, CharacterClass::GENERAL, 102),
        ]);

        $this->assertSame(5_250, $moteur->rateInBasisPoints());
        $this->assertSame(52, $moteur->reportedPercentage(), 'The report must round the rate down, never up.');
    }

    /**
     * Le stock de la cible est arrondi vers le bas avant l application du taux.
     *
     * **On ne pille pas une fraction d unite qui n existe pas encore.** Les ressources du jeu sont
     * des flottants, que la production fait avancer par fractions ; arrondir vers le haut ferait
     * prendre une unite de plus que ce que la cible possede.
     *
     * La borne reservee, elle, arrondit dans l autre sens — c est ce qui garantit qu elle couvre
     * toujours ce calcul-ci.
     */
    public function testTheTargetStockIsRoundedDownBeforeTheRateIsApplied(): void
    {
        $this->setTargetInactive(false);
        // **La fraction doit venir du modele lui-meme.** `getResources()` reconstruit son objet a
        // chaque appel : ecrire la fraction sur le resultat d un appel precedent la perdrait, et
        // l essai passerait sans rien verifier. La mesure l a confirme — la mutation survivait.
        $this->createAndSetPlanetModelWithFractionalMetal(1_001.9);

        $butin = $this->engineFor([$this->fleet(10, CharacterClass::GENERAL)])->lootBeforeSharing();

        // 1 001 de metal a 50 %, et non 1 002 : la fraction ne compte pas.
        $this->assertSame(500.0, $butin->metal->get());
        $this->assertSame(1_001.0, $butin->crystal->get());
        $this->assertSame(3.0, $butin->deuterium->get());
    }

    /**
     * Une planete cible dont le metal porte une fraction.
     *
     * @param float $metal
     * @return void
     */
    private function createAndSetPlanetModelWithFractionalMetal(float $metal): void
    {
        $this->createAndSetPlanetModel([
            'metal' => $metal,
            'crystal' => 2_003,
            'deuterium' => 7,
        ]);
    }

    /**
     * Les diagnostics de la conversion du stock remontent jusqu au resultat.
     *
     * ## Le defaut que cet essai protege
     *
     * `lootableAmount()` jetait ce que la frontiere lui rendait. C est pourtant la **premiere**
     * conversion du chemin, et la seule qui voie le solde brut de la planete : un stock au-dela de
     * la plage exacte d un flottant y etait converti, signale, et le signalement disparaissait.
     *
     * Le defaut a ete trouve par accident, en montant un autre essai. Celui-ci le fige.
     */
    public function testTheStockConversionDiagnosticsReachTheResult(): void
    {
        $this->setTargetInactive(false);

        // Deux puissance cinquante-trois plus deux : reel, positif, convertible, mais au-dela de la
        // plage ou un flottant distingue chaque entier.
        $this->createAndSetPlanetModel(['metal' => 9007199254740994.0, 'crystal' => 0, 'deuterium' => 0]);

        $moteur = $this->engineFor([$this->fleet(10, CharacterClass::GENERAL)]);
        $moteur->lootBeforeSharing();

        $diagnostics = $moteur->diagnostics();

        $this->assertTrue(
            $diagnostics->any(),
            'The stock conversion diagnostics were dropped: the only reading of the raw planet balance is lost.'
        );
        $this->assertArrayHasKey(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            $diagnostics->groupedByCode()
        );
    }

    /**
     * La version d allocateur vient du contexte, et le registre la reconnait.
     *
     * ## Le defaut que cet essai protege
     *
     * Le resultat portait encore `largest_remainder_v1`, une constante morte que le registre ne
     * connait pas : un combat ainsi enregistre aurait ete **refuse au rechargement**. La version
     * doit venir du contexte du combat, pas d une constante figee dans le moteur.
     */
    public function testTheAllocatorVersionComesFromTheContextAndIsKnown(): void
    {
        $this->setTargetInactive(false);

        $flottes = [$this->fleet(10, CharacterClass::GENERAL)];
        $contexte = LiveLootContextFactory::forBattle($flottes, $this->planetService, FrozenLootAllocation::atOperationStart());

        $this->assertSame(ExactLootAllocationV1::VERSION, $contexte->allocatorVersion);
        $this->assertContains(
            $contexte->allocatorVersion,
            LootAllocatorRegistry::default()->knownVersions(),
            'The version written on new combats is not one the registry can apply.'
        );

        // Et le contexte gele se recharge : c est ce que le defaut aurait empeche.
        $relu = LootContext::fromFrozenFacts($contexte->toFrozenFacts());

        $this->assertSame($contexte->allocatorVersion, $relu->allocatorVersion);

        // **Et surtout : la valeur inscrite sur le resultat.** C est elle qui sera persistee, et
        // c est elle qui portait la constante morte. Sans cette assertion, le defaut d origine
        // passerait de nouveau.
        $resultat = $this->engineFor($flottes)->simulateBattle();

        $this->assertSame(
            $contexte->allocatorVersion,
            $resultat->lootAllocatorVersion,
            'The result records an allocator version that does not come from the combat context.'
        );
        $this->assertContains(
            $resultat->lootAllocatorVersion,
            LootAllocatorRegistry::default()->knownVersions(),
            'The recorded allocator version is unknown to the registry: this combat could not be reloaded.'
        );
    }

    /**
     * Le taux applique par un montage de flottes.
     *
     * @param array<AttackerFleet> $flottes
     * @return int
     */
    private function rateFor(array $flottes): int
    {
        return $this->engineFor($flottes)->rateInBasisPoints();
    }

    /**
     * Le moteur monte sur ces flottes.
     *
     * @param array<AttackerFleet> $flottes
     * @return LootRateHarness
     */
    private function engineFor(array $flottes): LootRateHarness
    {
        return new LootRateHarness(
            $flottes,
            $this->planetService,
            [DefenderFleet::fromPlanet($this->planetService)],
            $this->settingsService,
            LiveLootContextFactory::forBattle($flottes, $this->planetService, FrozenLootAllocation::atOperationStart())
        );
    }

    /**
     * Rend la cible inactive, ou active.
     *
     * L'inactivite se lit sur la date de derniere connexion du proprietaire de la planete : sept
     * jours suffisent, un mois ne laisse aucun doute.
     *
     * @param bool $inactive
     * @return void
     */
    private function setTargetInactive(bool $inactive): void
    {
        $proprietaire = $this->planetService->getPlayer();

        $this->assertNotNull($proprietaire, 'The target planet has no owner, so its inactivity cannot be set.');

        // La colonne est du texte, et `PlayerService::isInactive()` la relit avec un transtypage
        // explicite. Y ecrire un entier passerait ici et mentirait sur ce que la base contient.
        $proprietaire->getUser()->time = (string)($inactive
            ? now()->subDays(30)->timestamp
            : now()->timestamp);
    }

    /**
     * Une flotte attaquante de petits transporteurs.
     *
     * @param int $transporteurs
     * @param CharacterClass $classe
     * @param int $missionId
     * @return AttackerFleet
     */
    private function fleet(int $transporteurs, CharacterClass $classe, int $missionId = 101): AttackerFleet
    {
        $joueur = resolve(PlayerService::class, ['player_id' => 0]);
        $joueur->setUserTech(UserTech::factory()->make([]));
        $joueur->getUser()->character_class = $classe->value;
        $joueur->getUser()->is_npc = false;

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), $transporteurs);

        $flotte = new AttackerFleet();
        $flotte->units = $unites;
        $flotte->player = $joueur;
        $flotte->fleetMissionId = $missionId;
        $flotte->ownerId = $missionId;
        $flotte->cargoResources = new Resources(0, 0, 0, 0);
        $flotte->isInitiator = $missionId === 101;
        $flotte->fleetMission = null;

        return $flotte;
    }
}

/**
 * Expose le taux retenu, sans faire tourner de bataille.
 */
class LootRateHarness extends BattleEngine
{
    public function rateInBasisPoints(): int
    {
        return $this->lootRateInBasisPoints;
    }

    public function reportedPercentage(): int
    {
        return $this->lootPercentage;
    }

    public function diagnostics(): ResourceNormalizationDiagnostics
    {
        return $this->resourceDiagnostics;
    }

    public function lootBeforeSharing(): Resources
    {
        return $this->calculateLootCapacityConstrained();
    }

    protected function fightBattleRounds(BattleResult $result): array
    {
        return [];
    }
}
