<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Admission\FrozenAllianceMembership;
use OGame\Combat\Exceptions\CorruptedFrozenMembership;
use PHPUnit\Framework\TestCase;

/**
 * La photographie d'appartenance refuse ce qu'elle ne peut pas signifier.
 *
 * ## Le defaut que ces essais ferment
 *
 * Cette classe *corrigeait* ses entrees : des membres passes sans alliance etaient effacees, un
 * identifiant mal type etait filtre, une structure inconnue devenait « aucune alliance ». Chacune de
 * ces indulgences transformait une corruption de persistance en **decision de jeu silencieuse** —
 * tous les allies d'un combat en cours cessaient d'etre admissibles, et rien ne le disait.
 *
 * Ne pas savoir qui etait membre n'est pas savoir que personne ne l'etait. Le seul comportement qui
 * ne ment pas est de s'arreter.
 *
 * ## Une seule representation par etat
 *
 * Colonne nulle veut dire « aucune alliance ne gouverne », et c'est la seule facon de le dire. Un
 * JSON portant `alliance_id: null` serait une seconde forme du meme fait ; deux formes finissent par
 * diverger, et une comparaison d'empreintes les declarerait differentes alors qu'elles disent la
 * meme chose.
 */
class FrozenAllianceMembershipTest extends TestCase
{
    /**
     * Une photographie ecrite puis relue rend exactement les memes faits.
     */
    public function testAPhotographSurvivesTheRoundTrip(): void
    {
        $prise = FrozenAllianceMembership::of(12, [41, 3, 7]);

        $relue = FrozenAllianceMembership::fromStorage($prise->toStorage());

        $this->assertSame(12, $relue->allianceId);
        $this->assertSame([3, 7, 41], $relue->memberUserIds());
        $this->assertSame($prise->toStorage(), $relue->toStorage());
    }

    /**
     * L'ecriture est triee : la meme photographie donne toujours le meme JSON.
     *
     * Sans cela, l'empreinte des faits geles dependrait de l'ordre dans lequel la base a rendu les
     * lignes — deux ouvertures identiques auraient deux empreintes.
     */
    public function testTheWrittenFormIsSortedAndThereforeStable(): void
    {
        $premiere = FrozenAllianceMembership::of(12, [41, 3, 7]);
        $seconde = FrozenAllianceMembership::of(12, [7, 41, 3]);

        $this->assertSame($premiere->toStorage(), $seconde->toStorage());
        $this->assertSame(['alliance_id' => 12, 'members' => [3, 7, 41]], $premiere->toStorage());
    }

    /**
     * Aucune alliance s'ecrit `null`, et rien d'autre.
     */
    public function testNoGoverningAllianceIsWrittenAsANullColumn(): void
    {
        $this->assertNull(FrozenAllianceMembership::none()->toStorage());
        $this->assertNull(FrozenAllianceMembership::of(null, [])->toStorage());

        $relue = FrozenAllianceMembership::fromStorage(null);

        $this->assertNull($relue->allianceId);
        $this->assertSame([], $relue->memberUserIds());
    }

    /**
     * Des membres sans alliance qui gouverne : les deux faits se contredisent.
     */
    public function testMembersWithoutAGoverningAllianceAreRefused(): void
    {
        $this->expectException(CorruptedFrozenMembership::class);

        FrozenAllianceMembership::of(null, [3, 7]);
    }

    /**
     * Un identifiant d'alliance non positif n'a pas de sens.
     */
    public function testANonPositiveAllianceIdentifierIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMembership::class);

        FrozenAllianceMembership::of(0, [3]);
    }

    /**
     * Un membre non positif non plus.
     */
    public function testANonPositiveMemberIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMembership::class);

        FrozenAllianceMembership::of(12, [3, -1]);
    }

    /**
     * Un doublon change l'empreinte sans changer qui est admissible.
     */
    public function testADuplicatedMemberIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMembership::class);

        FrozenAllianceMembership::of(12, [3, 7, 3]);
    }

    /**
     * Une structure persistee incomplete s'arrete, elle ne se devine pas.
     */
    public function testAnIncompleteStoredStructureIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMembership::class);

        FrozenAllianceMembership::fromStorage(['alliance_id' => 12]);
    }

    /**
     * Une cle inconnue signale que ce n'est pas cette photographie qu'on relit.
     */
    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMembership::class);

        FrozenAllianceMembership::fromStorage([
            'alliance_id' => 12,
            'members' => [3],
            'inconnue' => 1,
        ]);
    }

    /**
     * Un identifiant persiste sous forme de chaine n'est pas converti en silence.
     *
     * `"12"` et `12` viendraient de deux ecritures differentes ; en accepter une seule evite qu'un
     * changement de pilote de base passe inapercu.
     */
    public function testAStoredIdentifierOfTheWrongTypeIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMembership::class);

        FrozenAllianceMembership::fromStorage(['alliance_id' => '12', 'members' => [3]]);
    }

    /**
     * Des membres qui ne forment pas une liste ne sont pas ceux qui ont ete ecrits.
     */
    public function testStoredMembersThatAreNotAListAreRefused(): void
    {
        $this->expectException(CorruptedFrozenMembership::class);

        FrozenAllianceMembership::fromStorage([
            'alliance_id' => 12,
            'members' => ['a' => 3],
        ]);
    }

    /**
     * L'alliance rendue pour un proprietaire ne depend que de la photographie.
     */
    public function testTheAllianceReturnedComesOnlyFromThePhotograph(): void
    {
        $photographie = FrozenAllianceMembership::of(12, [3, 7]);

        $this->assertSame(12, $photographie->allianceFor(3));
        $this->assertNull($photographie->allianceFor(41));
    }
}
