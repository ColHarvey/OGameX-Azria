<?php

namespace Tests\Feature;

use OGame\Combat\Enums\CombatState;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Patrol\Combat\CorruptedSpatialDefence;
use OGame\Patrol\Combat\FrozenCombatant;
use OGame\Patrol\Combat\FrozenSpatialDefence;
use OGame\Patrol\Combat\SpatialOpeningState;
use OGame\Patrol\Enums\PatrolState;
use OGame\Services\CharacterClassService;
use Tests\AccountTestCase;

/**
 * La photographie d une patrouille qui defend : ce qui est vrai a l ouverture, et qui ne bouge plus.
 *
 * ## Le temoin essentiel, et pourquoi il a deux moities
 *
 * Geler ne sert a rien si le monde peut encore parler. L essai central change donc **le monde vivant
 * apres le gel** — la flotte grossit, les technologies montent, la reserve change — et exige que
 * l etat gele reste identique.
 *
 * Sa seconde moitie est l aller-retour : l etat est relu depuis une **instance rechargee de la base**,
 * pas depuis l objet garde en memoire. Sans cela, l essai pourrait passer en lisant un tableau que
 * personne n a jamais serialise, et une colonne JSON qui perdrait les types passerait inapercue.
 */
class SpatialOpeningStateTest extends AccountTestCase
{
    private function patrouille(float $reserve = 4200.75): Patrol
    {
        $patrouille = new Patrol();

        $patrouille->forceFill([
            'user_id' => $this->currentUserId,
            'home_planet_id' => null,
            'state' => PatrolState::Stationed,
            'galaxy' => 2,
            'system' => 55,
            'x' => 400,
            'y' => -900,
            'fuel_reserve' => $reserve,
            'order_version' => 0,
        ]);

        $patrouille->save();

        return $patrouille;
    }

    /**
     * La flotte posee de la patrouille : elle porte les unites et la cargaison.
     */
    private function flotte(int $chasseurs = 40, int $croiseurs = 7): FleetMission
    {
        $depart = $this->planetService->getPlanetCoordinates();

        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $mission->planet_id_from = $this->planetService->getPlanetId();
        $mission->galaxy_from = $depart->galaxy;
        $mission->system_from = $depart->system;
        $mission->position_from = $depart->position;
        $mission->type_from = PlanetType::Planet->value;
        $mission->planet_id_to = null;
        $mission->galaxy_to = 2;
        $mission->system_to = 55;
        $mission->position_to = 0;
        $mission->type_to = PlanetType::SpatialPoint->value;
        $mission->mission_type = 11;
        $mission->time_departure = time();
        $mission->time_arrival = time() + 300;
        $mission->light_fighter = $chasseurs;
        $mission->cruiser = $croiseurs;
        $mission->metal = 1500;
        $mission->crystal = 900;
        $mission->deuterium = 300;
        $mission->save();

        return $mission;
    }

    private function combat(): CombatInstance
    {
        $combat = new CombatInstance();

        $combat->forceFill([
            'status' => CombatState::Rallying,
            'mission_id' => 1,
            'target_planet_id' => null,
            'target_type' => PlanetType::SpatialPoint->value,
            'galaxy' => 2,
            'system' => 55,
            'position' => 0,
        ]);

        $combat->save();

        return $combat;
    }

    /**
     * Ce qui est photographie est ce qui etait la : effectif, cargaison, reserve, niveaux.
     */
    public function testThePhotographSaysWhatWasThere(): void
    {
        $patrouille = $this->patrouille(reserve: 4200.75);
        $flotte = $this->flotte(chasseurs: 40, croiseurs: 7);
        $combat = $this->combat();

        $this->playerSetResearchLevel('weapon_technology', 6);
        $this->playerSetResearchLevel('shielding_technology', 4);
        $this->playerSetResearchLevel('armor_technology', 3);

        new SpatialOpeningState()->capture($combat, $patrouille, $flotte, 1_700_000_000);

        $gele = new SpatialOpeningState()->protectedDefenceOf($combat);

        $this->assertSame((int)$patrouille->id, $gele->patrolId);
        $this->assertSame($this->currentUserId, $gele->ownerId);
        $this->assertSame((int)$flotte->id, $gele->missionId);
        $this->assertSame(40, $gele->units->getAmountByMachineName('light_fighter'));
        $this->assertSame(7, $gele->units->getAmountByMachineName('cruiser'));
        $this->assertSame(['metal' => 1500, 'crystal' => 900, 'deuterium' => 300], $gele->cargo);

        // **La reserve descend a l unite entiere**, et la cargaison ne la contient pas : une reserve
        // melangee au fret ferait piller le carburant du retour.
        $this->assertSame(4200, $gele->fuelReserve, 'The fuel reserve was not photographed as whole units.');

        $this->assertSame(6, $gele->defender->weaponLevel);
        $this->assertSame(4, $gele->defender->shieldLevel);
        $this->assertSame(3, $gele->defender->armorLevel);
        $this->assertSame(0, $gele->defender->spaceDockLevel, 'A spatial point claims a space dock.');
    }

    /**
     * **Le temoin essentiel : le monde bouge, la photographie ne bouge pas.**
     *
     * Et la relecture se fait sur une instance **rechargee de la base**. Lire l objet garde en
     * memoire prouverait seulement qu une propriete PHP n a pas ete reaffectee ; le passage par la
     * colonne JSON est ce qui etablit que l etat survit a l aller-retour.
     */
    public function testTheFrozenStateIgnoresEverythingThatHappensAfterwards(): void
    {
        $patrouille = $this->patrouille(reserve: 900.0);
        $flotte = $this->flotte(chasseurs: 10, croiseurs: 0);
        $combat = $this->combat();

        $this->playerSetResearchLevel('weapon_technology', 2);

        new SpatialOpeningState()->capture($combat, $patrouille, $flotte, 1_700_000_000);

        $avant = new SpatialOpeningState()->rawStateOf($combat);
        $this->assertNotSame([], $avant, 'Nothing was written: the witness would compare two absences.');

        // --- Le monde bouge, et de toutes les manieres qui comptent ---
        $flotte->light_fighter = 999;
        $flotte->metal = 999999;
        $flotte->save();

        $patrouille->fuel_reserve = 1.0;
        $patrouille->save();

        $this->playerSetResearchLevel('weapon_technology', 12);
        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);

        // --- Et l etat gele est relu depuis la base, pas depuis la memoire ---
        $recharge = CombatInstance::query()->findOrFail($combat->id);
        $gele = new SpatialOpeningState()->protectedDefenceOf($recharge);

        $this->assertSame(10, $gele->units->getAmountByMachineName('light_fighter'), 'The frozen roster followed the living fleet.');
        $this->assertSame(1500, $gele->cargo['metal'], 'The frozen cargo followed the living fleet.');
        $this->assertSame(900, $gele->fuelReserve, 'The frozen reserve followed the living patrol.');
        $this->assertSame(2, $gele->defender->weaponLevel, 'The frozen weapon level followed the living research.');

        $this->assertSame(
            $avant,
            new SpatialOpeningState()->rawStateOf($recharge),
            'The opening state document itself changed between the capture and the reload.'
        );
    }

    /**
     * **La classe d entree tient, meme si le joueur en change.**
     *
     * ## Le defaut que ce temoin ferme, decouvert le 10 septembre 2026
     *
     * La premiere version ne gelait que les trois niveaux et le bonus **derive** de la classe. Or
     * le moteur ne demande pas seulement « combien » : il demande « ce joueur est-il General » pour
     * la manoeuvre de Hamill, et « quel fret » pour un transporteur — deux questions posees a la
     * classe, pas a un nombre.
     *
     * Un defenseur recharge perdait donc ses capacites **en silence** : aucune erreur, seulement
     * une manoeuvre qui ne se declenchait plus.
     *
     * ## Ce que l essai mesure, et pourquoi il mesure aussi le vivant
     *
     * La classe change apres le gel, l instance est **rechargee depuis la base**, et la
     * photographie rend toujours la classe d entree. Le controle du joueur vivant est la pour que
     * « rien n a bouge » ne puisse pas vouloir dire « rien ne pouvait bouger ».
     */
    public function testTheEntryClassSurvivesAChangeOfClass(): void
    {
        $patrouille = $this->patrouille();
        $flotte = $this->flotte();
        $combat = $this->combat();

        $utilisateur = $this->planetService->getPlayer()?->getUser();
        $this->assertNotNull($utilisateur);
        $utilisateur->character_class = CharacterClass::GENERAL->value;
        $utilisateur->save();

        new SpatialOpeningState()->capture($combat, $patrouille, $flotte, 1_700_000_000);

        // --- Le joueur change de classe apres le gel ---
        $utilisateur->character_class = CharacterClass::COLLECTOR->value;
        $utilisateur->save();

        $vivant = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $classes = resolve(CharacterClassService::class);

        $this->assertFalse(
            $classes->isGeneral($vivant->getUser()),
            'The living player is still a General: this witness could not see a change of class.'
        );

        // --- Et la photographie, relue depuis la base, garde la classe d entree ---
        $recharge = CombatInstance::query()->findOrFail($combat->id);
        $gele = new SpatialOpeningState()->protectedDefenceOf($recharge);

        $this->assertSame(
            CharacterClass::GENERAL->value,
            $gele->characterClass,
            'The frozen defence followed the living class: a battle already engaged would change its capabilities.'
        );

        // Et les capacites suivent la photographie, pas le monde : c est le fait qui compte.
        $combattant = new FrozenCombatant(
            $gele->ownerId,
            $gele->defender->weaponLevel,
            $gele->defender->shieldLevel,
            $gele->defender->armorLevel,
            $gele->defender->classCombatBonus,
            $gele->characterClass,
        );

        $this->assertTrue(
            $classes->isGeneral($combattant->getUser()),
            'A combatant rebuilt from the photograph lost the class it entered with: the Hamill manoeuvre would vanish.'
        );
    }

    /**
     * Un combat sans photographie ne s en invente pas une.
     */
    public function testACombatWithoutAnOpeningStateIsRefused(): void
    {
        $this->expectException(CorruptedSpatialDefence::class);
        $this->expectExceptionMessageMatches('/cannot be rebuilt/');

        new SpatialOpeningState()->protectedDefenceOf($this->combat());
    }

    /**
     * **Une photographie de corps n est pas une photographie d espace libre**, et la confondre
     * jouerait une bataille sur un effectif qui n est pas le sien.
     */
    public function testABodyPhotographIsRefusedHere(): void
    {
        // **La version est celle que ce serveur lit**, et c'est ce qui isole le controle du genre :
        // avec une version etrangere, le refus viendrait d'elle et cet essai passerait sans que le
        // genre soit verifie nulle part. Une mutation l'a montre.
        $combat = $this->combat();
        $combat->opening_state = [
            'version' => SpatialOpeningState::VERSION,
            'captured_at' => 1,
            'target_body_id' => 42,
            'kind' => 'body',
        ];
        $combat->save();

        $this->expectException(CorruptedSpatialDefence::class);
        $this->expectExceptionMessageMatches('/do not describe the same world/');

        new SpatialOpeningState()->protectedDefenceOf($combat);
    }

    /**
     * Une version que ce serveur ne lit pas est refusee, jamais interpretee au mieux.
     */
    public function testAnUnknownVersionIsRefused(): void
    {
        $combat = $this->combat();
        $combat->opening_state = ['version' => 99, 'kind' => 'spatial', 'captured_at' => 1, 'defence' => []];
        $combat->save();

        $this->expectException(CorruptedSpatialDefence::class);
        $this->expectExceptionMessageMatches('/version 99/');

        new SpatialOpeningState()->protectedDefenceOf($combat);
    }

    /**
     * **Une porte de confiance ne transtype pas.** Un effectif ecrit en chaine passerait pour un
     * effectif valide, et la bataille se jouerait sur des nombres que personne n a ecrits.
     */
    public function testANumericStringInTheRosterIsRefused(): void
    {
        $faits = [
            'kind' => 'spatial_defence',
            'patrol_id' => 1,
            'owner_id' => 2,
            'mission_id' => 3,
            'units' => ['light_fighter' => '40'],
            'cargo' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'fuel_reserve' => 0,
            'defender' => [
                'weapon_level' => 0,
                'shield_level' => 0,
                'armor_level' => 0,
                'class_combat_bonus' => 0,
                'space_dock_level' => 0,
            ],
        ];

        $this->expectException(CorruptedSpatialDefence::class);
        $this->expectExceptionMessageMatches('/whole amount/');

        FrozenSpatialDefence::fromFrozenFacts($faits);
    }

    /**
     * Un effectif ne porte pas de ligne a zero : une photographie dit ce qui est debout.
     */
    public function testAZeroLineInTheRosterIsRefused(): void
    {
        $faits = [
            'kind' => 'spatial_defence',
            'patrol_id' => 1,
            'owner_id' => 2,
            'mission_id' => 3,
            'units' => ['light_fighter' => 0],
            'cargo' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'fuel_reserve' => 0,
            'defender' => [
                'weapon_level' => 0,
                'shield_level' => 0,
                'armor_level' => 0,
                'class_combat_bonus' => 0,
                'space_dock_level' => 0,
            ],
        ];

        $this->expectException(CorruptedSpatialDefence::class);
        $this->expectExceptionMessageMatches('/holds what stands/');

        FrozenSpatialDefence::fromFrozenFacts($faits);
    }
}
