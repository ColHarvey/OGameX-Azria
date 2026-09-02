<?php

namespace Tests\Unit\Combat;

use Illuminate\Support\Facades\Log;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Enums\UnsupportedSideReason;
use OGame\Combat\Exceptions\UnsupportedActorSide;
use OGame\Combat\Policies\NoLootV1;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\Combat\Support\LootContext;
use OGame\Combat\Support\LootContextForMission;
use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use RuntimeException;
use Tests\UnitTestCase;

/**
 * Le repli controle a la frontiere de la mission.
 *
 * ## Ce que ces essais fixent
 *
 * Un camp qu'aucune regle ne couvre doit produire **un combat sans butin, journalise**, et non une
 * exception qui remonte a l'ordonnanceur. Une exception y laisserait la mission non traitee, et
 * elle reviendrait a chaque passage : une incoherence de donnees se transformerait en boucle
 * d'echec silencieuse.
 *
 * Et ce repli doit rester **etroit** : une panne technique doit continuer de remonter, sans quoi
 * elle se deguiserait en regle de jeu.
 */
class LootContextForMissionTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetPlanetModel([]);
        $this->createAndSetUserTechModel([]);
    }

    /**
     * Un camp homogene passe sans repli.
     */
    public function testAnHomogeneousSideNeedsNoFallback(): void
    {
        $contexte = LootContextForMission::lootingOrDegraded(
            [$this->fleet(101, false)],
            $this->planetService,
            'attack',
            7
        );

        $this->assertFalse($contexte->grantsNoLoot(), 'An ordinary attack must keep its right to loot.');
    }

    /**
     * Un camp mixte donne un combat sans butin, avec sa raison nommee.
     */
    public function testAMixedSideDegradesToANamedNoLoot(): void
    {
        $journal = Log::spy();

        $contexte = LootContextForMission::lootingOrDegraded(
            [$this->fleet(101, false), $this->fleet(102, true)],
            $this->planetService,
            'attack',
            7
        );

        $this->assertTrue($contexte->grantsNoLoot());
        $this->assertSame(NoLootReason::MixedPlayerNpcSide, $contexte->noLootBecause);
        $this->assertSame(NoLootV1::VERSION, $contexte->policyVersion);
        $this->assertSame(0, $contexte->rateInBasisPoints);

        // La degradation doit se voir : elle signale une incoherence, pas une issue normale.
        $journal->shouldHaveReceived('critical')->once();
    }

    /**
     * Sans le repli, le camp mixte est bel et bien refuse.
     *
     * **La degradation ne remplace pas le refus de domaine, elle l'encadre.** Le selecteur continue
     * de dire non ; c'est la mission qui choisit de continuer sans butin.
     */
    public function testTheDomainStillRefusesTheMixedSide(): void
    {
        $this->expectException(UnsupportedActorSide::class);

        LiveLootContextFactory::forBattle(
            [$this->fleet(101, false), $this->fleet(102, true)],
            $this->planetService
        );
    }

    /**
     * Chaque raison de refus donne le repli qui lui correspond, sans jamais remonter.
     *
     * ## Pourquoi une couture plutot qu une union PNJ fabriquee
     *
     * Le camp invalide n existe pas dans le jeu : aucune flotte pilotee par le serveur ne rejoint
     * une union. Le fabriquer en base donnerait un montage spectaculaire qui pretendrait qu un tel
     * etat est atteignable, et ne prouverait rien de plus.
     *
     * La fabrique injectee leve le refus ; **tout le reste est le code de production**. C est
     * exactement la propriete voulue : le refus n atteint pas l ordonnanceur.
     */
    public function testEveryRefusalReasonDegradesInsteadOfEscaping(): void
    {
        $attendus = [
            UnsupportedSideReason::EmptySide->value => [UnsupportedSideReason::EmptySide, NoLootReason::EmptyAttackingSide],
            UnsupportedSideReason::MixedPlayerNpc->value => [UnsupportedSideReason::MixedPlayerNpc, NoLootReason::MixedPlayerNpcSide],
            UnsupportedSideReason::SystemActorPresent->value => [UnsupportedSideReason::SystemActorPresent, NoLootReason::SystemActorPresent],
        ];

        // **Un seul espion pour toute la boucle.** Une facade deja substituee rend `null` au
        // deuxieme appel de `spy()`, et l assertion porterait alors sur rien.
        $journal = Log::spy();

        foreach ($attendus as $quoi => [$raison, $persistee]) {
            $contexte = LootContextForMission::lootingOrDegraded(
                [$this->fleet(101, false)],
                $this->planetService,
                'attack',
                7,
                static function () use ($raison): LootContext {
                    throw UnsupportedActorSide::because($raison, [101 => $raison->value]);
                }
            );

            $this->assertTrue($contexte->grantsNoLoot(), "The refusal « {$quoi} » escaped the boundary.");
            $this->assertSame($persistee, $contexte->noLootBecause, "The refusal « {$quoi} » persisted the wrong reason.");
            $this->assertSame(NoLootV1::VERSION, $contexte->policyVersion);
            $this->assertSame(0, $contexte->rateInBasisPoints);
        }

        // Une trace par degradation, et pas une de moins.
        $journal->shouldHaveReceived('critical')->times(3);
    }

    /**
     * Le journal critique porte la raison et les identifiants concernes.
     *
     * Sans eux, la trace dirait qu il s est passe quelque chose sans dire ou : l administrateur
     * n aurait aucun moyen de retrouver le combat en cause.
     */
    public function testTheCriticalLogCarriesTheReasonAndTheIdentifiers(): void
    {
        $capture = [];

        Log::listen(function ($message) use (&$capture): void {
            $capture[] = $message;
        });

        LootContextForMission::lootingOrDegraded(
            [$this->fleet(101, false), $this->fleet(102, true)],
            $this->planetService,
            'attack',
            77
        );

        $this->assertCount(1, $capture, 'The degradation must leave exactly one trace.');
        $this->assertSame('critical', $capture[0]->level);
        $this->assertSame(UnsupportedSideReason::MixedPlayerNpc->value, $capture[0]->context['side_reason']);
        $this->assertSame(77, $capture[0]->context['mission_id']);
        $this->assertSame(['101/101', '102/102'], $capture[0]->context['attacking_fleets']);
    }

    /**
     * Une autre exception traverse la frontiere sans etre deguisee en absence de butin.
     *
     * **C est la contrepartie du repli.** Une panne technique convertie en regle de jeu ferait
     * tourner le combat sur des donnees dont personne n a verifie la validite, et la panne resterait
     * invisible.
     */
    public function testAnyOtherFailureCrossesTheBoundary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('base injoignable');

        LootContextForMission::lootingOrDegraded(
            [$this->fleet(101, false)],
            $this->planetService,
            'attack',
            7,
            static function (): LootContext {
                throw new RuntimeException('base injoignable');
            }
        );
    }

    /**
     * Une flotte attaquante, tenue par un joueur ou par le serveur.
     *
     * @param int $missionId
     * @param bool $piloteeParLeServeur
     * @return AttackerFleet
     */
    private function fleet(int $missionId, bool $piloteeParLeServeur): AttackerFleet
    {
        $joueur = resolve(PlayerService::class, ['player_id' => 0]);
        $joueur->setUserTech(UserTech::factory()->make([]));
        $joueur->getUser()->username = $piloteeParLeServeur ? 'Base pirate 3' : 'ColHarvey';
        $joueur->getUser()->is_npc = $piloteeParLeServeur;
        $joueur->getUser()->character_class = CharacterClass::GENERAL->value;

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 5);

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
