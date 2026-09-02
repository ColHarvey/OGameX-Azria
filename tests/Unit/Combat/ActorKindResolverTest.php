<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Support\ActorKindResolver;
use OGame\Models\User;
use Tests\UnitTestCase;

/**
 * Qui tient une flotte, et pourquoi l'ordre des controles compte.
 *
 * Le compte systeme est reconnu **avant** le drapeau PNJ. Il porte aujourd'hui `is_npc = 0`, mais
 * rien ne garantit qu'il en sera toujours ainsi : le jour ou quelqu'un lui mettra ce drapeau — par
 * commodite, pour l'exclure d'un classement — l'ordre inverse le ferait passer pour une faction
 * pirate ordinaire, avec les regles de pillage qui vont avec.
 */
class ActorKindResolverTest extends UnitTestCase
{
    /**
     * Le compte systeme, tel qu'il est aujourd'hui.
     */
    public function testTheSystemAccountIsRecognisedAsSuch(): void
    {
        $this->assertSame(ActorKind::System, ActorKindResolver::of($this->user(User::SYSTEM_ACCOUNT_USERNAME, false)));
    }

    /**
     * Le compte systeme, meme s'il recevait un jour le drapeau PNJ.
     *
     * **C'est ce cas qui impose l'ordre des controles.** Avec le drapeau teste en premier, ce compte
     * deviendrait une faction pirate, et un camp le contenant cesserait d'etre refuse.
     */
    public function testTheSystemAccountStaysSystemEvenFlaggedAsNpc(): void
    {
        $this->assertSame(ActorKind::System, ActorKindResolver::of($this->user(User::SYSTEM_ACCOUNT_USERNAME, true)));
    }

    /**
     * Un compte pilote par le serveur.
     */
    public function testAServerDrivenAccountIsAnNpc(): void
    {
        $this->assertSame(ActorKind::Npc, ActorKindResolver::of($this->user('Base pirate 7', true)));
    }

    /**
     * Un joueur ordinaire.
     */
    public function testAnOrdinaryAccountIsAPlayer(): void
    {
        $this->assertSame(ActorKind::Player, ActorKindResolver::of($this->user('ColHarvey', false)));
    }

    /**
     * Un compte, sans passer par la base.
     *
     * @param string $username
     * @param bool $isNpc
     * @return User
     */
    private function user(string $username, bool $isNpc): User
    {
        $utilisateur = new User();
        $utilisateur->username = $username;
        $utilisateur->is_npc = $isNpc;

        return $utilisateur;
    }
}
