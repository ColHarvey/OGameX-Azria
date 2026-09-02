<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Exceptions\NotTheCombatOpener;
use OGame\Combat\Support\CombatOpenerClaim;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\EffectOrderKey;
use OGame\Combat\Support\PersistedCombatOpener;
use OGame\Combat\Support\VerifiedCombatOpener;
use ReflectionClass;
use ReflectionMethod;
use Tests\UnitTestCase;

/**
 * Nul ne se declare initiateur d'un combat : la base le dit, ou personne.
 *
 * La qualite d'initiateur ouvre des privileges considerables — franchir le verrou causal,
 * echapper aux limites du camp, entrer dans la photographie sans condition. Un booleen fourni par
 * l'appelant, meme obligatoire, laissait n'importe quel site d'appel se les attribuer.
 */
class VerifiedCombatOpenerTest extends UnitTestCase
{
    private const int INSTANCE = 77;
    private const int CIBLE_ID = 1_234;
    private const int ARRIVEE = 1_000;

    /**
     * Le veritable initiateur est certifie, avec ses identites.
     */
    public function testTheGenuineOpenerIsCertified(): void
    {
        $verifie = VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim());

        $this->assertSame(self::INSTANCE, $verifie->combatInstanceId);
        $this->assertSame(self::cible(), $verifie->targetBodyKey);
        $this->assertSame(self::ARRIVEE, $verifie->plannedArrival);
        $this->assertSame(CombatMissionKind::Attack, $verifie->missionKind);
        $this->assertSame(ActorKind::Player, $verifie->actorKind);
    }

    /**
     * Le meme evenement rejoue rend la meme certification.
     *
     * Une file de messages peut livrer deux fois la meme arrivee. La seconde livraison ne doit ni
     * creer un second initiateur, ni echouer.
     */
    public function testReplayingTheSameEventCertifiesTheSameOpener(): void
    {
        $premier = VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim());
        $second = VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim());

        $this->assertEquals($premier, $second, 'Replaying the opening event produced a different certification.');
    }

    /**
     * Une autre mission presentee comme initiatrice est refusee.
     *
     * **Le cas qui a motive tout cet objet.** Une seconde flotte qui se declarerait initiatrice
     * franchirait toutes les barrieres.
     */
    public function testAnotherMissionPresentedAsTheOpenerIsRefused(): void
    {
        $this->expectException(NotTheCombatOpener::class);
        $this->expectExceptionMessageMatches('/initiateur enregistre/');

        VerifiedCombatOpener::verify(
            $this->persistedOpener(),
            $this->claim(eventKey: EffectOrderKey::forEvent(self::ARRIVEE, CombatEventType::FleetArrival, 999))
        );
    }

    /**
     * Une mission visant un autre corps celeste est refusee.
     *
     * Une planete et sa lune partagent leurs coordonnees : la confusion serait facile et le
     * resultat, un combat applique au mauvais corps.
     */
    public function testAMissionAimingAtAnotherBodyIsRefused(): void
    {
        $this->expectException(NotTheCombatOpener::class);
        $this->expectExceptionMessageMatches('/deux cibles differentes/');

        VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim(targetBodyKey: 'moon:1234'));
    }

    /**
     * Une flotte sur sa branche retour est refusee.
     */
    public function testAReturningFleetIsRefused(): void
    {
        $this->expectException(NotTheCombatOpener::class);
        $this->expectExceptionMessageMatches('/branche retour/');

        VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim(leg: FlightLeg::Return));
    }

    /**
     * Aucune mission qui n'ouvre pas de combat ne peut produire cet objet.
     *
     * Transport, deploiement, Defense ACS, espionnage, colonisation, recyclage, missile,
     * expedition : aucune ne dispute la possession du corps celeste.
     */
    public function testNoMissionKindThatCannotOpenACombatEverProducesTheObject(): void
    {
        $refusees = 0;

        foreach (CombatMissionKind::cases() as $genre) {
            if ($genre->opensCombat()) {
                continue;
            }

            $refusees++;

            try {
                VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim(missionKind: $genre));
                $this->fail("A « {$genre->value} » mission was certified as a combat opener.");
            } catch (NotTheCombatOpener) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertGreaterThan(0, $refusees, 'No non-opening mission kind was exercised, so this test proves nothing.');
    }

    /**
     * Les genres qui ouvrent un combat, eux, sont acceptes.
     */
    public function testTheKindsThatDoOpenACombatAreAccepted(): void
    {
        foreach (CombatMissionKind::cases() as $genre) {
            if (!$genre->opensCombat()) {
                continue;
            }

            $verifie = VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim(missionKind: $genre));

            $this->assertSame($genre, $verifie->missionKind);
        }
    }

    /**
     * Une heure d'arrivee qui ne correspond pas est refusee.
     *
     * Signe d'un evenement perime ou d'une mission modifiee depuis l'ouverture. La comparer est
     * ce qui empeche un payload ancien de faire autorite.
     */
    public function testAnArrivalTimeThatDoesNotMatchIsRefused(): void
    {
        $this->expectException(NotTheCombatOpener::class);
        $this->expectExceptionMessageMatches('/perime/');

        VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim(plannedArrival: self::ARRIVEE + 5));
    }

    /**
     * Un acteur pilote par le serveur peut etre initiateur.
     *
     * Un raid pirate ouvre un combat comme un joueur. Il n'a pas toujours de destination de
     * retour, mais cela ne concerne pas la qualite d'initiateur.
     */
    public function testAServerDrivenActorCanBeTheOpener(): void
    {
        $verifie = VerifiedCombatOpener::verify($this->persistedOpener(), $this->claim(actorKind: ActorKind::Npc));

        $this->assertSame(ActorKind::Npc, $verifie->actorKind);
    }

    /**
     * Il n'existe aucun chemin de construction en dehors de la verification.
     *
     * Pas de constructeur public, pas de fabrique de confiance. C'est ce qui distingue cet objet
     * d'un booleen emballe : on ne peut pas le fabriquer en affirmant simplement qu'on y a droit.
     */
    public function testThereIsNoWayToBuildItOutsideVerification(): void
    {
        $classe = new ReflectionClass(VerifiedCombatOpener::class);

        $this->assertTrue($classe->getConstructor()?->isPrivate(), 'The constructor is not private.');

        $publiques = array_map(
            static fn ($methode): string => $methode->getName(),
            $classe->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC)
        );

        $this->assertSame(
            ['verify'],
            $publiques,
            'A construction path other than verify() exists, so the certification can be bypassed.'
        );
    }

    /**
     * La cible de ces essais.
     *
     * Une methode et non une constante : la forme de la cle appartient a sa fabrique, et une
     * constante ne peut pas l appeler. L ecrire en clair ici en ferait une seconde orthographe
     * de la meme identite — exactement ce que la contrainte d unicite ne saurait pas rattraper.
     *
     * @return string
     */
    private static function cible(): string
    {
        return CombatParticipantKey::forPlanet(self::CIBLE_ID);
    }

    /**
     * Ce que l instance de combat enregistre sur son initiateur.
     */
    private function persistedOpener(): PersistedCombatOpener
    {
        return new PersistedCombatOpener(
            self::INSTANCE,
            EffectOrderKey::forEvent(self::ARRIVEE, CombatEventType::FleetArrival, 42),
            self::cible(),
            self::ARRIVEE,
            500,
        );
    }

    /**
     * Ce que la mission relue affirme, avec les ecarts qu'on veut lui donner.
     */
    private function claim(
        EffectOrderKey|null $eventKey = null,
        string|null $targetBodyKey = null,
        FlightLeg $leg = FlightLeg::Outbound,
        CombatMissionKind $missionKind = CombatMissionKind::Attack,
        ActorKind $actorKind = ActorKind::Player,
        int $plannedArrival = self::ARRIVEE,
    ): CombatOpenerClaim {
        return new CombatOpenerClaim(
            $eventKey ?? EffectOrderKey::forEvent(self::ARRIVEE, CombatEventType::FleetArrival, 42),
            $targetBodyKey ?? self::cible(),
            $leg,
            $missionKind,
            $actorKind,
            $plannedArrival,
        );
    }
}
