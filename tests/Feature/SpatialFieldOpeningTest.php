<?php

namespace Tests\Feature;

use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\AttackerFleetSnapshot;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\LootContext;
use OGame\Combat\Support\LootPolicy;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\DebrisField;
use OGame\Models\Resources;
use OGame\Patrol\Combat\FrozenCombatant;
use OGame\Patrol\Combat\SpatialCombatSite;
use OGame\Patrol\Combat\SpatialFieldOpening;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le champ d un combat en espace libre, tel qu il est **avant le premier tir**.
 *
 * ## Les deux affirmations de cette tranche
 *
 * **Rien n est joue.** L etat rendu porte zero round, deux accumulateurs de pertes vides, et les
 * effectifs des deux camps intacts. Aucune ligne de rapport, de champ de debris ou de message n est
 * ecrite — la fermeture compose, elle ne recompense pas.
 *
 * **Rien n est relu.** Les deux camps sont montes sur des combattants geles ; changer le monde
 * ensuite ne change aucun nombre du champ.
 *
 * ## Le generateur n est pas un choix de cette tranche
 *
 * Il n est pas arrete pour la production. Ces essais passent donc une suite **reproductible de
 * banc** par `withDraws()`, et l ouverture n en fabrique aucune : c est ce qui empeche un choix
 * d essai de devenir un choix de production par inadvertance.
 */
class SpatialFieldOpeningTest extends AccountTestCase
{
    private function site(): SpatialCombatSite
    {
        $proprietaire = new FrozenCombatant($this->currentUserId, 4, 3, 2, 0);

        return new SpatialCombatSite(
            resolve(PlayerServiceFactory::class),
            resolve(SettingsService::class),
            $proprietaire,
            new SpatialPoint(500, -500),
            2,
            60,
        );
    }

    /**
     * Une flotte, montee sur un combattant gele : c est la seule forme admise ici.
     *
     * @param array<string, int> $composition
     */
    private function flotteAttaquante(int $missionId, array $composition, FrozenCombatant $combattant): AttackerFleet
    {
        $flotte = new AttackerFleet();
        $flotte->units = self::effectif($composition);
        $flotte->player = $combattant;
        $flotte->fleetMissionId = $missionId;
        $flotte->ownerId = $combattant->getId();
        $flotte->cargoResources = new Resources(0, 0, 0, 0);
        $flotte->isInitiator = true;
        $flotte->fleetMission = null;

        return $flotte;
    }

    /**
     * Un contexte de butin **lie a ces flottes exactement** : le moteur le verifie a sa construction.
     *
     * Le butin d un combat en espace libre — reserve de retour protegee, excedent pillable — est une
     * tranche a part entiere. Ici il n est qu une piece de montage : ce qui compte, c est qu il
     * decrive les memes flottes que le champ, sans quoi le moteur refuserait de se construire.
     *
     * @param array<int, AttackerFleet> $attaquants
     */
    private function contexteDeButin(array $attaquants): LootContext
    {
        $photographies = [];

        foreach ($attaquants as $flotte) {
            $photographies[] = AttackerFleetSnapshot::of($flotte, ActorKind::Player, false, 0);
        }

        return LootContext::fromObservedFacts(
            new LootPolicy(false, new AttackerCargoShare(0, 0)),
            $photographies,
            // La garnison d un point libre porte le nom reserve : aucun corps ne le porte.
            ['body_key' => CombatParticipantKey::UNIDENTIFIED_BODY, 'owner_id' => $this->currentUserId],
            1_700_000_000,
            LootAllocatorRegistry::default()->currentVersion(),
        );
    }

    /**
     * @param array<string, int> $composition
     */
    private function flotteDefenseuse(int $missionId, array $composition, FrozenCombatant $combattant): DefenderFleet
    {
        $flotte = new DefenderFleet();
        $flotte->units = self::effectif($composition);
        $flotte->player = $combattant;
        $flotte->fleetMissionId = $missionId;
        $flotte->ownerId = $combattant->getId();
        $flotte->fleetMission = null;

        return $flotte;
    }

    /**
     * @param array<string, int> $composition
     */
    private static function effectif(array $composition): UnitCollection
    {
        $unites = new UnitCollection();

        foreach ($composition as $nom => $nombre) {
            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $unites;
    }

    /**
     * @param array<int, AttackerFleet> $attaquants
     * @param array<int, DefenderFleet> $defenseurs
     */
    private function champ(array $attaquants, array $defenseurs, int $graine = 90210): BattleFieldState
    {
        // **La garnison du point, vide mais presente.** Le moteur exige une flotte defensive
        // d identifiant zero — celle du corps —, et un point libre en a une : elle ne porte rien.
        // La composer depuis le site plutot que de l ecrire a la main garde une seule source.
        $garnison = DefenderFleet::fromPlanet($this->site());

        $ouverture = new SpatialFieldOpening(
            $attaquants,
            $this->site(),
            [$garnison, ...$defenseurs],
            resolve(SettingsService::class),
            $this->contexteDeButin($attaquants),
        );

        // **Les tirages sont donnes, jamais choisis par l ouverture** : le generateur des combats
        // progressifs n est pas arrete, et une valeur par defaut ici en ferait un choix tacite.
        return $ouverture->withDraws(new SeededDraws($graine))->initialField();
    }

    /**
     * **Aucun round n est joue, et rien n est recompense.**
     */
    public function testTheOpeningPlaysNothingAndRewardsNothing(): void
    {
        $attaquant = new FrozenCombatant($this->currentUserId, 5, 3, 2, 0);
        $defenseur = new FrozenCombatant($this->currentUserId, 4, 3, 2, 0);

        $rapportsAvant = BattleReport::query()->count();
        $debrisAvant = DebrisField::query()->count();

        $champ = $this->champ(
            [$this->flotteAttaquante(101, ['light_fighter' => 12], $attaquant)],
            [$this->flotteDefenseuse(202, ['light_fighter' => 7], $defenseur)],
        );

        $this->assertSame(0, $champ->roundsPlayed, 'The opening played a round.');
        $this->assertSame(0, $champ->attackerLosses->getAmount(), 'The opening already recorded attacker losses.');
        $this->assertSame(0, $champ->defenderLosses->getAmount(), 'The opening already recorded defender losses.');

        // Les effectifs sont intacts : douze et sept unites, exactement.
        $this->assertCount(12, $champ->attackerUnits, 'The attacker roster is not the one that entered.');
        $this->assertCount(7, $champ->defenderUnits, 'The defender roster is not the one that entered.');
        $this->assertSame(12, $champ->attackerRemainingShips->getAmount());
        $this->assertSame(7, $champ->defenderRemainingShips->getAmount());

        $this->assertTrue($champ->bothSidesStillStand(), 'A side is already gone before the first shot.');

        // **Rien n a ete ecrit.** Une fermeture qui recompenserait laisserait une trace ici.
        $this->assertSame($rapportsAvant, BattleReport::query()->count(), 'The opening wrote a battle report.');
        $this->assertSame($debrisAvant, DebrisField::query()->count(), 'The opening created a debris field.');
    }

    /**
     * **L ordre canonique** : flottes par identifiant de mission croissant, unites par identifiant
     * d objet croissant.
     *
     * Il est porteur de sens : une cible se choisit **par position** parmi les unites restantes.
     * Deux champs ranges differemment, nourris des memes tirages, ne visent pas la meme unite.
     */
    public function testTheFieldIsBuiltInCanonicalOrder(): void
    {
        $combattant = new FrozenCombatant($this->currentUserId, 3, 2, 1, 0);

        // Les flottes sont donnees dans le desordre, et les types aussi.
        $champ = $this->champ(
            [
                $this->flotteAttaquante(900, ['cruiser' => 2], $combattant),
                $this->flotteAttaquante(100, ['cruiser' => 1, 'light_fighter' => 1], $combattant),
            ],
            [$this->flotteDefenseuse(500, ['light_fighter' => 1], $combattant)],
        );

        $flottes = array_map(static fn ($u): int => $u->fleetMissionId, $champ->attackerUnits);
        $rangees = $flottes;
        sort($rangees);

        $this->assertSame($rangees, $flottes, 'The attacker fleets are not laid out by ascending mission identifier.');
        $this->assertSame(100, $flottes[0], 'The lowest mission identifier does not come first.');

        // Dans la premiere flotte, le chasseur (id le plus bas) precede le croiseur.
        $premiers = array_values(array_filter(
            $champ->attackerUnits,
            static fn ($u): bool => $u->fleetMissionId === 100,
        ));

        $this->assertCount(2, $premiers);
        $this->assertLessThan(
            $premiers[1]->unitObject->id,
            $premiers[0]->unitObject->id,
            'Within a fleet, units are not laid out by ascending object identifier.'
        );
    }

    /**
     * **Les nombres viennent des niveaux geles, et le monde ne les change plus.**
     */
    public function testTheFieldCarriesFrozenNumbersOnly(): void
    {
        $this->playerSetResearchLevel('weapon_technology', 5);

        $combattant = new FrozenCombatant($this->currentUserId, 5, 0, 0, 0);
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $attendu = $chasseur->properties->attack->calculate($combattant)->totalValue;

        $champ = $this->champ(
            [$this->flotteAttaquante(10, ['light_fighter' => 3], $combattant)],
            [$this->flotteDefenseuse(20, ['light_fighter' => 3], $combattant)],
        );

        $this->assertSame($attendu, $champ->attackerUnits[0]->attackPower);

        // --- Le monde bouge, et le champ deja compose n en sait rien ---
        $this->playerSetResearchLevel('weapon_technology', 25);
        $vivant = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);

        $this->assertNotSame(
            $attendu,
            $chasseur->properties->attack->calculate($vivant)->totalValue,
            'The living player gives the same attack power: this witness could not see a drift.'
        );

        $recompose = $this->champ(
            [$this->flotteAttaquante(10, ['light_fighter' => 3], $combattant)],
            [$this->flotteDefenseuse(20, ['light_fighter' => 3], $combattant)],
        );

        $this->assertSame(
            $attendu,
            $recompose->attackerUnits[0]->attackPower,
            'A field composed after the research carries the new power: the freeze does not hold at composition.'
        );
    }

    /**
     * Les deux bandes de tirages voyagent avec le champ, et elles sont **celles qu on a donnees**.
     *
     * Sans elles, l avanceur repartirait d une suite neuve a chaque pas et le combat cesserait
     * d etre rejouable.
     */
    public function testBothDrawBandsTravelWithTheField(): void
    {
        $combattant = new FrozenCombatant($this->currentUserId, 1, 1, 1, 0);

        $champ = $this->champ(
            [$this->flotteAttaquante(1, ['light_fighter' => 1], $combattant)],
            [$this->flotteDefenseuse(2, ['light_fighter' => 1], $combattant)],
            graine: 4242,
        );

        $this->assertInstanceOf(SeededDraws::class, $champ->battleDraws);
        $this->assertInstanceOf(SeededDraws::class, $champ->roundDraws);
        $this->assertSame(4242, $champ->battleDraws->seed(), 'The battle band is not the one that was handed in.');
        $this->assertSame(4242, $champ->roundDraws->seed(), 'The round band did not start from the battle seed.');
    }
}
