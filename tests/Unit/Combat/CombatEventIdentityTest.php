<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\CombatParticipantKey;
use PHPUnit\Framework\TestCase;

/**
 * Une identite d'evenement ne ressemble jamais a une cle de participant.
 *
 * ## L'incident que ces essais ferment
 *
 * Des identites d'evenement avaient ete ecrites a la main sous la forme `fleet:4242:arrival`,
 * c'est-a-dire avec le prefixe d'une **cle de participant**. Les deux vivent dans des tables
 * differentes, mais elles se ressemblaient assez pour qu'une requete de diagnostic les melange, et
 * assez peu pour que personne ne le remarque — seule la suite complete l'a vu.
 *
 * Une mutation qui retirait le prefixe `event:` a ensuite **survecu** a tous les essais de la
 * fermeture : les inclusions etaient ecrites et relues avec la meme fonction, donc elles
 * concordaient. Rien ne disait qu'elles etaient distinguables d'autre chose. C'est ce que ces
 * essais disent.
 */
class CombatEventIdentityTest extends TestCase
{
    /**
     * La forme est fixe, et elle porte son espace de noms.
     */
    public function testAnEventIdentityCarriesItsNamespace(): void
    {
        $this->assertSame('event:arrival:123', CombatEventIdentity::forFleetArrival(123));
    }

    /**
     * Les deux espaces de noms ne se croisent jamais.
     *
     * Pour un meme identifiant de mission, la cle de participant et l'identite d'evenement doivent
     * rester reconnaissables l'une de l'autre : elles repondent a deux questions differentes — qui
     * se bat, et quel evenement figure dans la photographie.
     */
    public function testAnEventIdentityIsNeverMistakenForAParticipantKey(): void
    {
        $evenement = CombatEventIdentity::forFleetArrival(4242);
        $participant = CombatParticipantKey::forFleet(4242);

        $this->assertNotSame($participant, $evenement);

        $this->assertStringStartsWith(
            CombatEventIdentity::PREFIX . ':',
            $evenement,
            'An event identity without its namespace can be confused with a participant key.'
        );

        $this->assertStringStartsNotWith(
            CombatParticipantKey::FLEET_PREFIX . ':',
            $evenement,
            'An event identity is using the participant key namespace: this is the incident that already happened.'
        );

        $this->assertStringStartsNotWith(
            CombatEventIdentity::PREFIX . ':',
            $participant,
            'A participant key is using the event namespace.'
        );
    }

    /**
     * La meme mission rend toujours la meme identite.
     *
     * Les deux idempotences en dependent : celle du monde, qui refuse d'appliquer deux fois le meme
     * effet, et celle de la photographie, qui refuse d'inclure deux fois le meme evenement.
     */
    public function testTheSameMissionAlwaysYieldsTheSameIdentity(): void
    {
        $this->assertSame(
            CombatEventIdentity::forFleetArrival(77),
            CombatEventIdentity::forFleetArrival(77)
        );

        $this->assertNotSame(
            CombatEventIdentity::forFleetArrival(77),
            CombatEventIdentity::forFleetArrival(78)
        );
    }

    /**
     * Un identifiant non persiste ne construit rien.
     *
     * Il serait different au rejeu, et les deux idempotences cesseraient de reconnaitre ce
     * qu'elles ont deja vu.
     */
    public function testAnIdentifierThatIsNotPersistedIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CombatEventIdentity::forFleetArrival(0);
    }
}
