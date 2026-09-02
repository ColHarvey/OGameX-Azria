<?php

namespace Tests\Unit\Combat;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\Combat\Support\LootContext;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\UnitTestCase;

/**
 * La photographie des faits de pillage, et la frontiere de l'inactivite.
 *
 * ## La regle arretee
 *
 * Une cible est inactive des que sa derniere connexion remonte a sept jours **ou plus** :
 *
 *     derniereConnexion <= instant - 7 jours
 *
 * Six jours vingt-trois heures cinquante-neuf minutes cinquante-neuf secondes : active.
 * Exactement sept jours : inactive. Au-dela : inactive.
 *
 * Le cas ne se presente presque jamais a la seconde pres. Mais un comportement non choisi finit par
 * etre observe en jeu, et il est alors pris pour un defaut : autant le decider et l'ecrire.
 *
 * ## Pourquoi l'horloge est controlee
 *
 * Sans horloge fixe, ces essais dependraient de l'instant ou ils tournent, et la frontiere ne serait
 * jamais atteinte exactement. `tearDown()` la restaure, y compris quand une assertion echoue :
 * une horloge laissee figee contaminerait tous les tests suivants du meme processus.
 */
class LiveLootContextFactoryTest extends UnitTestCase
{
    /**
     * L'instant de reference de ces essais.
     */
    private const int MAINTENANT = 1_800_000_000;

    private const int SEPT_JOURS = 7 * 24 * 60 * 60;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetPlanetModel([
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
        $this->createAndSetUserTechModel([]);

        Date::setTestNow(Date::createFromTimestamp(self::MAINTENANT, 'UTC'));
    }

    protected function tearDown(): void
    {
        // **Toujours, meme apres un echec.** PHPUnit appelle `tearDown()` quelle que soit l'issue ;
        // une horloge laissee figee ferait echouer des tests sans rapport, et le diagnostic
        // partirait alors dans la mauvaise direction.
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * Une seconde avant sept jours : la cible est encore active.
     */
    public function testJustUnderSevenDaysTheTargetIsStillActive(): void
    {
        $this->setTargetLastLogin(self::MAINTENANT - self::SEPT_JOURS + 1);

        $this->assertSame(5_000, $this->rateWithADiscovererFleet(), 'One second under seven days must still be active.');
    }

    /**
     * Exactement sept jours : la cible est inactive.
     *
     * La frontiere est fermee de ce cote-la. C'est un choix, pas un hasard d'implementation.
     */
    public function testExactlyAtSevenDaysTheTargetIsInactive(): void
    {
        $this->setTargetLastLogin(self::MAINTENANT - self::SEPT_JOURS);

        $this->assertSame(7_500, $this->rateWithADiscovererFleet(), 'Exactly seven days must count as inactive.');
    }

    /**
     * Une seconde apres sept jours : la cible est inactive.
     */
    public function testJustOverSevenDaysTheTargetIsInactive(): void
    {
        $this->setTargetLastLogin(self::MAINTENANT - self::SEPT_JOURS - 1);

        $this->assertSame(7_500, $this->rateWithADiscovererFleet());
    }

    /**
     * Une connexion posterieure a la photographie ne change rien au contexte deja pris.
     *
     * **C'est la raison d'etre de tout ce chantier.** Un combat persistant dure jusqu'a deux
     * heures ; si la cible se connecte pendant, le taux ne doit pas bouger sous les pieds des
     * attaquants qui se sont engages sur la foi de l'etat annonce.
     */
    public function testALoginAfterTheSnapshotChangesNothing(): void
    {
        $this->setTargetLastLogin(self::MAINTENANT - self::SEPT_JOURS - 1);

        $contexte = $this->contextWithADiscovererFleet();
        $faits = $contexte->toFrozenFacts();

        // La cible se connecte, et l'horloge avance d'une heure.
        $this->setTargetLastLogin(self::MAINTENANT + 3_600);
        Date::setTestNow(Date::createFromTimestamp(self::MAINTENANT + 3_600, 'UTC'));

        $relu = LootContext::fromFrozenFacts($faits);

        $this->assertSame(7_500, $relu->rateInBasisPoints, 'A login during the combat must not lower the frozen rate.');
        $this->assertTrue($relu->targetIsInactive);
        $this->assertSame(self::MAINTENANT, $relu->observedAt, 'The context must keep the instant it was taken at.');
    }

    /**
     * L'inactivite lue est celle du proprietaire du corps vise, et d'aucun autre.
     *
     * Une planete et sa lune sont deux cibles distinctes ; c'est le corps passe a la fabrique qui
     * decide, pas un joueur ambiant. Ici, deux corps aux proprietaires opposes recoivent les memes
     * flottes et donnent deux taux differents.
     */
    public function testTheInactivityReadterIsThatOfTheTargetedBodyOwner(): void
    {
        $this->setTargetLastLogin(self::MAINTENANT - self::SEPT_JOURS - 1);
        $autreCorps = $this->anotherBodyWhoseOwnerLoggedInAt(self::MAINTENANT);

        $flottes = [$this->fleet(10, CharacterClass::DISCOVERER)];

        $this->assertSame(7_500, LiveLootContextFactory::forBattle($flottes, $this->planetService)->rateInBasisPoints);
        $this->assertSame(5_000, LiveLootContextFactory::forBattle($flottes, $autreCorps)->rateInBasisPoints);
    }

    /**
     * Un refus nomme ne photographie aucune inactivite ni aucun fret.
     *
     * Il n'y a rien a observer : le combat ne pille pas. Retenir malgre tout l'etat de la cible
     * laisserait croire qu'il a compte.
     */
    public function testARefusedLootObservesNothing(): void
    {
        $this->setTargetLastLogin(self::MAINTENANT - self::SEPT_JOURS - 1);

        $contexte = LiveLootContextFactory::withoutLoot(
            NoLootReason::NpcEncounter,
            [$this->fleet(10, CharacterClass::DISCOVERER)],
            $this->planetService
        );

        $this->assertSame(0, $contexte->rateInBasisPoints);
        $this->assertFalse($contexte->targetIsInactive);
        $this->assertSame(0, $contexte->totalCargo);
        $this->assertSame(NoLootReason::NpcEncounter, $contexte->noLootBecause);
    }

    /**
     * Le contexte est lie aux flottes qui ont servi a le prendre.
     */
    public function testTheContextBindsToTheFleetsItWasTakenFor(): void
    {
        $this->setTargetLastLogin(self::MAINTENANT);

        $flottes = [$this->fleet(3, CharacterClass::GENERAL, 101), $this->fleet(4, CharacterClass::GENERAL, 102)];
        $contexte = LiveLootContextFactory::forBattle($flottes, $this->planetService);

        // L ordre de presentation ne compte pas : l appariement porte sur les faits, pas sur la
        // position dans le tableau.
        $contexte->ensureItBindsTo(array_reverse($flottes), $this->targetKey());
        $this->addToAssertionCount(1);
    }

    /**
     * Le taux obtenu avec une flotte de Decouvreurs seuls.
     *
     * @return int
     */
    private function rateWithADiscovererFleet(): int
    {
        return $this->contextWithADiscovererFleet()->rateInBasisPoints;
    }

    /**
     * @return LootContext
     */
    private function contextWithADiscovererFleet(): LootContext
    {
        return LiveLootContextFactory::forBattle(
            [$this->fleet(10, CharacterClass::DISCOVERER)],
            $this->planetService
        );
    }

    /**
     * L'identite du corps vise, telle que l'empreinte la nomme.
     *
     * @return string
     */
    private function targetKey(): string
    {
        return CombatParticipantKey::forBody($this->planetService);
    }

    /**
     * Fixe la derniere connexion du proprietaire de la cible.
     *
     * @param int $timestamp
     * @return void
     */
    private function setTargetLastLogin(int $timestamp): void
    {
        $proprietaire = $this->planetService->getPlayer();

        $this->assertNotNull($proprietaire, 'The target planet has no owner.');

        // La colonne est du texte : y ecrire un entier mentirait sur ce que la base contient.
        $proprietaire->getUser()->time = (string)$timestamp;
    }

    /**
     * Un second corps celeste, appartenant a un autre joueur.
     *
     * @param int $lastLogin
     * @return PlanetService
     */
    private function anotherBodyWhoseOwnerLoggedInAt(int $lastLogin): PlanetService
    {
        $joueur = resolve(PlayerService::class, ['player_id' => 0]);
        $joueur->setUserTech(UserTech::factory()->make([]));
        $joueur->getUser()->time = (string)$lastLogin;

        // **Sans le cache.** `makeForPlayer()` memorise ses instances par identifiant de planete, et
        // le montage de base en a deja cree une pour l identifiant zero : rappeler la fabrique
        // rendrait la cible elle-meme, dont on ecraserait alors le modele. La mesure l a montre —
        // les deux corps donnaient le meme taux parce qu ils etaient le meme objet.
        $corps = resolve(PlanetServiceFactory::class)->makeForPlayer($joueur, 0, false);
        $modele = Planet::factory()->make(['galaxy' => 1, 'system' => 1, 'planet' => 5, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
        $modele->id = 2;
        $corps->setPlanet($modele);

        return $corps;
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
