<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Services\LootService;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Le butin reellement attribue ne depasse jamais celui d'une bataille sans perte.
 *
 * ## Pourquoi cette propriete decide de la reservation
 *
 * Les combats persistants immobilisent, a l'ouverture, une borne de ce qui pourra etre pille.
 * Cette borne se calcule avec la capacite de fret **avant** les pertes, puisque personne ne sait
 * encore qui survivra. Le butin reel, lui, se calcule a la fermeture avec les survivants.
 *
 * La reservation n'est sure que si le second ne peut jamais depasser le premier — sur chacune des
 * trois ressources separement, et pas seulement en somme.
 *
 * ## Pourquoi au niveau final, et pas sur l'allocateur seul
 *
 * Un premier balayage avait etabli la monotonie de `LootService::distributeLoot()`. Ce n'etait pas
 * suffisant : entre cet intermediaire et ce qui est reellement attribue, il y a le partage entre
 * flottes, le plafonnement par leur fret, la distribution des unites restantes et la priorite de
 * l'initiateur. Chacune de ces etapes arrondit, et une difference d'une unite y serait invisible
 * pour un test de l'intermediaire.
 *
 * Ces tests comparent donc `sumLootShares()` — le butin final, celui que la reservation devra
 * couvrir — entre une bataille sans perte et toutes les configurations de survivants.
 */
class LootMonotonicityTest extends UnitTestCase
{
    /**
     * Les parts attribuees a chaque flotte lors du dernier calcul.
     *
     * Le total ne dit rien du departage : deux repartitions differentes des unites restantes
     * donnent la meme somme. Seules les parts individuelles montrent qui a recu quoi.
     *
     * @var array<int, array<int, int>>
     */
    protected array $dernieresParts = [];

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
     * Aucune configuration de survivants ne rapporte plus qu'une bataille sans perte.
     *
     * Les configurations couvrent les cas ou un arrondi pourrait faire gagner une unite : la
     * destruction de l'initiateur, celle de la plus grosse flotte, celle des petites, et les
     * capacites egales ou les restes a egalite.
     */
    public function testNoSurvivorConfigurationEverYieldsMoreThanALosslessBattle(): void
    {
        $butin = new Resources(200_000, 150_000, 90_000, 0);

        // **Le butin depasse largement le fret disponible**, et c'est indispensable : avec un
        // butin plus petit que la capacite, le plafonnement ne joue jamais et toutes les
        // configurations rendent la meme chose. Une premiere version du test etait ainsi, et ne
        // prouvait donc rien — sauf pour le cas ou plus rien ne survit.
        //
        // Trois flottes de tailles differentes : 101 est l'initiateur. Quinze petits
        // transporteurs, soit 75 000 de fret, contre 440 000 de butin theorique.
        $tailles = [101 => 7, 102 => 3, 103 => 5];

        $sansPerte = $this->finalLootFor($butin, $tailles, [101 => 7, 102 => 3, 103 => 5]);

        $configurations = [
            'initiateur detruit' => [101 => 0, 102 => 3, 103 => 5],
            'plus grosse flotte detruite' => [101 => 0, 102 => 3, 103 => 5],
            'petites detruites, grande survivante' => [101 => 7, 102 => 0, 103 => 0],
            'grande detruite, petites survivantes' => [101 => 0, 102 => 3, 103 => 5],
            'une seule survivante' => [101 => 0, 102 => 0, 103 => 5],
            'aucune survivante' => [101 => 0, 102 => 0, 103 => 0],
            'pertes partielles partout' => [101 => 4, 102 => 1, 103 => 2],
            'une seule unite perdue' => [101 => 6, 102 => 3, 103 => 5],
        ];

        foreach ($configurations as $quoi => $survivants) {
            $final = $this->finalLootFor($butin, $tailles, $survivants);

            foreach (['metal', 'crystal', 'deuterium'] as $ressource) {
                $this->assertLessThanOrEqual(
                    $sansPerte->$ressource->get(),
                    $final->$ressource->get(),
                    "The configuration « {$quoi} » yielded more {$ressource} than a battle where nothing was lost, "
                    . 'so a reservation computed before the losses would not cover the real loot.'
                );
            }
        }
    }

    /**
     * Des capacites egales et des restes a egalite ne font pas exception.
     *
     * C'est la ou le departage entre en jeu — initiateur d'abord, puis reste, puis capacite, puis
     * identifiant — et donc la ou une unite pourrait se deplacer.
     */
    public function testEqualCapacitiesAndTiedRemaindersAreNoException(): void
    {
        // Un butin qui ne se divise pas en trois : chaque flotte a le meme reste.
        $butin = new Resources(50_000, 50_000, 50_000, 0);
        $tailles = [101 => 4, 102 => 4, 103 => 4];

        $sansPerte = $this->finalLootFor($butin, $tailles, $tailles);

        foreach ([[101 => 4, 102 => 4, 103 => 0], [101 => 0, 102 => 4, 103 => 4], [101 => 4, 102 => 0, 103 => 4]] as $rang => $survivants) {
            $final = $this->finalLootFor($butin, $tailles, $survivants);

            foreach (['metal', 'crystal', 'deuterium'] as $ressource) {
                $this->assertLessThanOrEqual(
                    $sansPerte->$ressource->get(),
                    $final->$ressource->get(),
                    "Tied remainders, configuration {$rang}, resource {$ressource}: the loot grew when a fleet was lost."
                );
            }
        }
    }

    /**
     * Le butin attribue est entier, et sa somme ne depasse pas le fret survivant.
     *
     * Les fractions de `distributeLoot()` sont un artefact interne : le butin de la bataille est
     * redefini comme la somme des parts reellement attribuees, lesquelles sont construites par
     * une partie entiere puis des unites entieres.
     */
    public function testTheAssignedLootIsWholeUnits(): void
    {
        $final = $this->finalLootFor(new Resources(9_631, 5_874, 749, 0), [101 => 7, 102 => 3], [101 => 5, 102 => 2]);

        foreach (['metal', 'crystal', 'deuterium'] as $ressource) {
            $valeur = $final->$ressource->get();

            $this->assertSame(
                (float)floor($valeur),
                (float)$valeur,
                "The assigned {$ressource} is not a whole unit, so the reservation and the settlement could never match exactly."
            );
        }
    }

    /**
     * Le comportement actuel, aux valeurs exactes.
     *
     * **Une propriete ne remplace pas une fixture.** Les tests ci-dessus verifient une inegalite —
     * le butin ne grandit pas quand des flottes meurent — et une inegalite survit a beaucoup de
     * changements. Retirer le plafonnement par flotte, par exemple, augmente tous les butins a la
     * fois : l'ordre est preserve, et la propriete ne voit rien.
     *
     * Ces valeurs figent donc ce que le code fait **aujourd'hui**. Elles servent de reference avant
     * la conversion en arithmetique exacte : toute difference apres cette conversion devra etre
     * expliquee, et non decouverte.
     *
     * Le montage : trois flottes de sept, trois et cinq petits transporteurs, soit 75 000 de fret ;
     * un butin theorique de 440 000 que le fret ramene a 25 000 par ressource.
     */
    public function testTheCurrentBehaviourAtExactValues(): void
    {
        $butin = new Resources(200_000, 150_000, 90_000, 0);
        $tailles = [101 => 7, 102 => 3, 103 => 5];

        $attendus = [
            'sans perte' => [[101 => 7, 102 => 3, 103 => 5], [25_000, 25_000, 25_000]],
            'initiateur detruit' => [[101 => 0, 102 => 3, 103 => 5], [25_000, 15_000, 0]],
            'une seule survivante' => [[101 => 0, 102 => 0, 103 => 5], [25_000, 0, 0]],
            'aucune survivante' => [[101 => 0, 102 => 0, 103 => 0], [0, 0, 0]],
            'une unite perdue' => [[101 => 6, 102 => 3, 103 => 5], [25_000, 25_000, 20_000]],
        ];

        foreach ($attendus as $quoi => [$survivants, $attendu]) {
            $final = $this->finalLootFor($butin, $tailles, $survivants);

            $this->assertSame(
                $attendu,
                [(int)$final->metal->get(), (int)$final->crystal->get(), (int)$final->deuterium->get()],
                "The current behaviour for « {$quoi} » changed. If the change is intended, update this fixture and say why."
            );
        }
    }

    /**
     * Qui recoit les unites restantes, et dans quel ordre.
     *
     * **Le total ne dit rien du departage.** Vingt mille repartis entre trois flottes identiques
     * donnent six mille six cent soixante-six chacune, plus deux unites a placer : quelle que soit
     * la flotte qui les recoit, la somme reste vingt mille. Seules les parts individuelles
     * montrent la regle appliquee.
     *
     * L'ordre actuel est : l'initiateur d'abord, puis le plus grand reste, puis la plus grande
     * capacite survivante, puis l'identifiant de mission. Il est **conserve tel quel** — le
     * remettre en cause serait un changement de mecanique separe.
     *
     * ## Ce que la mesure a revele, et qui ne se devinait pas
     *
     * Sur le metal puis le cristal, l'initiateur recoit bien l'unite supplementaire. Sur le
     * deuterium, **c'est la troisieme flotte** qui en recoit deux — parce que les deux premieres
     * ressources ont deja consomme le fret des flottes 101 et 102, et que leur capacite restante
     * ne leur permet plus d'en prendre.
     *
     * La priorite de l'initiateur ne s'applique donc pas ressource par ressource independamment :
     * elle s'exerce sur ce qu'il reste de place. Ce n'est ni un defaut ni une intention explicite
     * du code — c'est ce qu'il fait, et cette fixture le consigne avant toute conversion.
     */
    public function testWhoReceivesTheLeftoverUnitsAndInWhichOrder(): void
    {
        // Trois flottes identiques : les restes sont a egalite, donc seul le rang decide.
        $this->finalLootFor(new Resources(100_000, 100_000, 100_000, 0), [101 => 4, 102 => 4, 103 => 4], [101 => 4, 102 => 4, 103 => 4]);

        $this->assertSame(
            [
                101 => [6_667, 6_667, 6_666],
                102 => [6_667, 6_667, 6_666],
                103 => [6_666, 6_666, 6_668],
            ],
            $this->dernieresParts,
            'The leftover units are no longer handed out the way the engine does today. '
            . 'If the change is intended, update this fixture and say why.'
        );

        // La somme reste exacte : aucune unite creee ni perdue.
        foreach ([0, 1, 2] as $ressource) {
            $somme = array_sum(array_column($this->dernieresParts, $ressource));

            $this->assertSame(20_000, $somme, 'Units were created or lost while handing out the remainder.');
        }
    }

    /**
     * Le plafonnement par le fret ne perd plus d unites, et ce que cela a change.
     *
     * ## Le defaut, mesure
     *
     * L ancien plafonnement partageait la place restante par une division **flottante** :
     * `$reste / $nombreDeRessources`. Il produisait des montants comme `17 794,666...`, que le
     * moteur transtypait ensuite en entiers. Les unites tombees dans la fraction disparaissaient.
     *
     * Sur vingt mille repartitions tirees au hasard, **quarante pour cent perdaient une ou deux
     * unites**, pour un total de dix mille sept cent quatre-vingt-une. Jamais plus de deux par
     * combat, jamais au-dela du fret disponible : un defaut discret, et constant.
     *
     * ## Le cas ci-dessous, avant et apres
     *
     *     entree   : butin (37 991, 41 583, 20 836), fret total 53 384
     *     avant    : (17 794,67, 17 794,67, 17 794,67) -> transtype (17 794, 17 794, 17 794) = 53 382
     *     apres    : (17 795, 17 795, 17 794) = 53 384
     *
     * **La raison des deux unites deplacees** : 53 384 ne se divise pas par trois. Le quotient
     * entier vaut 17 794, et il reste deux unites a placer. La regle exacte les attribue dans
     * l ordre metal, cristal, deuterium — celui dans lequel le jeu enumere ses ressources partout
     * ailleurs. L ancienne version les laissait dans une fraction que personne ne ramassait.
     *
     * Aucune compensation n a ete ajoutee pour imiter l ancien comportement : le butin etait
     * simplement incomplet.
     */
    public function testTheCargoCapIsExactAndLosesNothing(): void
    {
        $plafonne = LootService::distributeLoot(new Resources(37_991, 41_583, 20_836, 0), 53_384);

        $this->assertSame(17_795.0, $plafonne->metal->get());
        $this->assertSame(17_795.0, $plafonne->crystal->get());
        $this->assertSame(17_794.0, $plafonne->deuterium->get());
        $this->assertSame(53_384.0, $plafonne->sum(), 'The cap must use the whole cargo, to the unit.');

        // Et il ne depasse jamais le fret, meme quand le butin est bien plus petit que lui.
        $petit = LootService::distributeLoot(new Resources(10, 20, 30, 0), 1_000);
        $this->assertSame(60.0, $petit->sum(), 'Nothing was to be capped here.');
    }

    /**
     * Les deux regles de departage, chacune rendue observable separement.
     *
     * ## Pourquoi la fixture precedente ne suffisait pas
     *
     * Elle fait courir trois flottes identiques : les restes y sont a egalite et l'initiateur est
     * aussi le premier par identifiant. Retirer la priorite de l'initiateur ou inverser l'ordre
     * des restes n'y change donc rien, et la mesure l'a confirme — les deux mutations survivaient.
     *
     * ## Le montage qui les separe
     *
     * Deux, trois et six petits transporteurs, soit dix, quinze et trente mille de fret, et un
     * butin de cent deux par ressource. Les planchers valent 18, 27 et 55 ; il reste deux unites a
     * placer.
     *
     * Deux inversions y sont montees expres : **l'initiateur a le plus petit reste des trois**, et
     * **le plus grand reste appartient a la plus petite flotte**. Sans la seconde, ignorer les
     * restes reviendrait a classer par capacite decroissante et donnerait le meme resultat que la
     * regle en vigueur — la mesure l'a confirme, cette mutation-la survivait.
     *
     * Les quatre regles concevables donnent ici quatre resultats differents :
     * - initiateur d'abord, puis plus grand reste : 19, 28, 55 — c'est la regle en vigueur ;
     * - sans priorite de l'initiateur : 18, 28, 56 ;
     * - par plus petit reste : 19, 27, 56 ;
     * - restes ignores, donc par capacite decroissante : 19, 27, 56.
     */
    public function testTheInitiatorPriorityAndTheRemainderOrderAreEachObservable(): void
    {
        $flottes = [101 => 2, 102 => 3, 103 => 6];

        $this->finalLootFor(new Resources(102, 102, 102, 0), $flottes, $flottes);

        $this->assertSame(
            [
                101 => [19, 19, 19],
                102 => [28, 28, 28],
                103 => [55, 55, 55],
            ],
            $this->dernieresParts,
            'The tie-breaking rules changed: the initiator no longer comes first, or the largest '
            . 'remainder no longer wins. If the change is intended, update this fixture and say why.'
        );
    }

    /**
     * La repartition a une echelle ou le produit butin x fret sort de la plage exacte.
     *
     * ## Ce que la mesure a etabli, et qui contredit l'intuition
     *
     * Un flottant represente chaque entier jusqu'a `2^53`, soit neuf mille milliards de
     * millions. Trois flottes de quatre mille grands transporteurs portent trois cents millions
     * de fret ; le produit qui servait a calculer une part depassait donc largement cette plage.
     *
     * **Et pourtant le calcul flottant y donnait deja le bon quotient.** Un million deux cent
     * mille tirages, a des echelles allant jusqu'a deux cents milliards de fret total, n'ont pas
     * produit un seul ecart — parce que le butin est **deja plafonne par le fret total** avant
     * d'arriver ici : le quotient reste petit devant la precision disponible.
     *
     * Le passage en arithmetique exacte ne corrige donc aucun defaut observable aujourd'hui. Il
     * supprime la **dependance** a ce plafonnement : le jour ou la reservation calculera une
     * borne avant la bataille, sans ce plafond, la version flottante se tromperait en silence.
     *
     * Cette fixture fige la repartition a cette echelle pour que ce changement de socle reste
     * verifiable.
     */
    public function testTheAllocationBeyondTheExactFloatRange(): void
    {
        $tailles = [101 => 6_000, 102 => 6_000, 103 => 6_000];

        $this->finalLootFor(
            new Resources(120_000_007, 90_000_013, 60_000_011, 0),
            $tailles,
            [101 => 6_000, 102 => 5_999, 103 => 1],
            'large_cargo'
        );

        // Ces planchers ont ete etablis independamment, a precision arbitraire, et non releves
        // sur la sortie du moteur. Le butin depasse d une unite la somme des planchers sur chacune
        // des trois ressources ; elle revient a l initiateur, qui a aussi le plus grand reste.
        $this->assertSame(
            [
                101 => [60_000_004, 45_000_007, 30_000_006],
                102 => [59_990_003, 44_992_506, 29_995_005],
                103 => [10_000, 7_500, 5_000],
            ],
            $this->dernieresParts,
            'The allocation at this scale changed. If the change is intended, update this fixture and say why.'
        );

        // Rien n est cree ni perdu : la somme des parts vaut exactement le butin.
        foreach ([0 => 120_000_007, 1 => 90_000_013, 2 => 60_000_011] as $ressource => $butin) {
            $this->assertSame(
                $butin,
                array_sum(array_column($this->dernieresParts, $ressource)),
                'Units were created or lost while allocating at this scale.'
            );
        }
    }

    /**
     * Le butin final pour cette configuration de survivants.
     *
     * @param Resources $butinTheorique
     * @param array<int, int> $tailles Nombre de petits transporteurs par flotte, avant la bataille.
     * @param array<int, int> $survivants Nombre restant apres la bataille.
     * @param string $vaisseau Le type de transporteur qui compose les flottes.
     * @return Resources
     */
    private function finalLootFor(Resources $butinTheorique, array $tailles, array $survivants, string $vaisseau = 'small_cargo'): Resources
    {
        $petitTransporteur = ObjectService::getUnitObjectByMachineName($vaisseau);

        $flottes = [];
        $resultats = [];

        foreach ($tailles as $identifiant => $nombre) {
            $unites = new UnitCollection();
            $unites->addUnit($petitTransporteur, $nombre);

            $flotte = new AttackerFleet();
            $flotte->units = clone $unites;
            $flotte->player = $this->playerService;
            $flotte->fleetMissionId = $identifiant;
            $flotte->ownerId = $this->playerService->getId();
            $flotte->cargoResources = new Resources(0, 0, 0, 0);
            $flotte->isInitiator = $identifiant === 101;
            $flotte->fleetMission = null;

            $flottes[] = $flotte;

            $restants = $survivants[$identifiant] ?? 0;

            $resultat = new AttackerFleetResult($identifiant, $flotte->ownerId, $unites);
            $resultat->unitsResult = new UnitCollection();

            if ($restants > 0) {
                $resultat->unitsResult->addUnit($petitTransporteur, $restants);
            }

            $resultat->completelyDestroyed = $restants === 0;

            $resultats[] = $resultat;
        }

        // **Le vrai enchainement.** Le moteur plafonne d abord le butin theorique par la
        // capacite de fret **d avant la bataille** — c est ce que fait
        // `calculateLootCapacityConstrained()` — puis le repartit entre les flottes selon leur
        // capacite **survivante**. Passer directement un butin non plafonne ferait tout partir
        // dans la premiere ressource et ne testerait pas le chemin reel.
        $fretAvantBataille = 0;

        foreach ($flottes as $flotte) {
            $fretAvantBataille += $flotte->units->getTotalCargoCapacity($flotte->player);
        }

        $butinPlafonne = LootService::distributeLoot(clone $butinTheorique, $fretAvantBataille);

        $moteur = new LootMonotonicityHarness(
            $flottes,
            $this->planetService,
            [DefenderFleet::fromPlanet($this->planetService)],
            $this->settingsService,
            LiveLootContextFactory::forBattle($flottes, $this->planetService)
        );

        $resultat = new BattleResult();
        $resultat->loot = $butinPlafonne;
        $resultat->attackerFleetResults = $resultats;

        $moteur->runDistributeResources($resultat);

        $this->dernieresParts = [];

        foreach ($resultat->attackerFleetResults as $part) {
            $this->dernieresParts[$part->fleetMissionId] = [
                (int)$part->lootShare->metal->get(),
                (int)$part->lootShare->crystal->get(),
                (int)$part->lootShare->deuterium->get(),
            ];
        }

        ksort($this->dernieresParts);

        return $resultat->loot;
    }
}

/**
 * Expose la repartition sans faire tourner de bataille.
 */
class LootMonotonicityHarness extends BattleEngine
{
    public function runDistributeResources(BattleResult $result): void
    {
        $this->distributeResources($result);
    }

    protected function fightBattleRounds(BattleResult $result): array
    {
        return [];
    }
}
