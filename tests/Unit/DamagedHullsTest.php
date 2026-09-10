<?php

namespace Tests\Unit;

use InvalidArgumentException;
use OGame\Hull\DamagedHulls;
use PHPUnit\Framework\TestCase;

/**
 * L histogramme des degats, et les deux regles de jeu qu il porte.
 *
 * Ces essais valent pour la **forme** et pour l **effet** : une regle de depart qui rendrait le bon
 * nombre d unites mais les mauvais paliers passerait un `assertCount` et echouerait ici.
 */
class DamagedHullsTest extends TestCase
{
    public function testUneUniteIntacteNEstJamaisStockee(): void
    {
        // Zero degat n a rien a faire dans l histogramme : l unite est deja decrite par la colonne
        // entiere de son type, et l inscrire la compterait deux fois.
        $this->assertNull(DamagedHulls::none()->toStorage());
        $this->assertNull(DamagedHulls::of(['cruiser' => [5000 => 0]])->toStorage());
        $this->assertTrue(DamagedHulls::none()->withUnit('cruiser', 0)->isEmpty());
    }

    public function testUnNiveauHorsBornesEstRefuse(): void
    {
        // Une unite entierement detruite n existe pas : elle serait morte.
        $this->expectException(InvalidArgumentException::class);
        DamagedHulls::of(['cruiser' => [DamagedHulls::FULL_DAMAGE => 3]]);
    }

    public function testUnNiveauNegatifEstRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DamagedHulls::of(['cruiser' => [-1 => 3]]);
    }

    public function testUnNombreQuiNEstPasUnEntierEstRefuse(): void
    {
        // **Aucun fichier du depot ne declare `strict_types`** : une signature `int` accepterait
        // `1.5` et en ferait `1`. Le refus se tient a la porte de confiance, par `is_int()`.
        //
        // L essai passe par `fromStorage()` et non par `of()`, et c est le bon chemin : `fromStorage`
        // prend `mixed` parce que c est **elle** qui garde la frontiere avec la base, la ou `of()`
        // est l API interne, deja typee. Eprouver le refus sur `of()` demanderait de lui mentir sur
        // son type pour verifier qu elle s en apercoit.
        $this->expectException(InvalidArgumentException::class);
        DamagedHulls::fromStorage(['cruiser' => [5000 => 1.5]]);
    }

    public function testUneRelectureDepuisUneChaineJsonDonneLeMemeEnsemble(): void
    {
        $depart = DamagedHulls::of(['cruiser' => [5000 => 8, 2500 => 4]]);
        $relu = DamagedHulls::fromStorage((string)json_encode($depart->toStorage()));

        $this->assertSame($depart->toStorage(), $relu->toStorage());
        $this->assertSame(12, $relu->damagedCountOf('cruiser'));
    }

    public function testUneColonneVideVeutDireToutIntact(): void
    {
        // C est ce qui rend la migration des donnees existantes triviale.
        $this->assertTrue(DamagedHulls::fromStorage(null)->isEmpty());
        $this->assertTrue(DamagedHulls::fromStorage('')->isEmpty());
    }

    /**
     * **La regle de depart : les plus intactes d abord.**
     */
    public function testLesPlusIntactesPartentDAbord(): void
    {
        // Vingt croiseurs : huit intacts, quatre a 25 %, huit a 50 %.
        $corps = DamagedHulls::of(['cruiser' => [5000 => 8, 2500 => 4]]);

        // Huit partent : les huit intacts suffisent, rien d abime ne bouge.
        [$partent, $restent] = $corps->takeMostIntact('cruiser', 8, 20);
        $this->assertTrue($partent->isEmpty(), 'Les intactes suffisent : aucune abimee ne part.');
        $this->assertSame($corps->toStorage(), $restent->toStorage());

        // Dix partent : les huit intacts, puis les deux **moins** abimees (2500, pas 5000).
        [$partent, $restent] = $corps->takeMostIntact('cruiser', 10, 20);
        $this->assertSame([2500 => 2], $partent->levelsOf('cruiser'), 'Ce sont les moins abimees qui suivent.');
        $this->assertSame([2500 => 2, 5000 => 8], $restent->levelsOf('cruiser'));

        // Quinze partent : les huit intacts, les quatre a 25 %, puis trois a 50 %.
        [$partent, $restent] = $corps->takeMostIntact('cruiser', 15, 20);
        $this->assertSame([2500 => 4, 5000 => 3], $partent->levelsOf('cruiser'));
        $this->assertSame([5000 => 5], $restent->levelsOf('cruiser'));

        // Tout part : le corps ne garde rien.
        [$partent, $restent] = $corps->takeMostIntact('cruiser', 20, 20);
        $this->assertSame([2500 => 4, 5000 => 8], $partent->levelsOf('cruiser'));
        $this->assertTrue($restent->isEmpty());
    }

    public function testLaConservationTientSurChaqueDepart(): void
    {
        $corps = DamagedHulls::of(['cruiser' => [5000 => 8, 2500 => 4, 1000 => 3]]);

        for ($combien = 0; $combien <= 20; $combien++) {
            [$partent, $restent] = $corps->takeMostIntact('cruiser', $combien, 20);

            // **Aucune unite creee, aucune perdue** : c est l invariant qui compte, et il se verifie
            // a chaque taille de depart, pas seulement sur un cas choisi.
            $this->assertSame(
                15,
                $partent->damagedCountOf('cruiser') + $restent->damagedCountOf('cruiser'),
                'Un depart de ' . $combien . ' unites ne doit ni creer ni perdre d abimee.'
            );
        }
    }

    public function testDemanderPlusQueCeQuiExisteEstRefuse(): void
    {
        $corps = DamagedHulls::of(['cruiser' => [5000 => 8]]);

        $this->expectException(InvalidArgumentException::class);
        $corps->takeMostIntact('cruiser', 21, 20);
    }

    public function testUnInvariantBriseEstRefuse(): void
    {
        // Plus d abimees que d unites presentes : la donnee est fausse, et le dire vaut mieux que de
        // continuer sur un etat impossible.
        $corps = DamagedHulls::of(['cruiser' => [5000 => 30]]);

        $this->expectException(InvalidArgumentException::class);
        $corps->takeMostIntact('cruiser', 5, 20);
    }

    /**
     * **L ordre d entree au combat : les plus intactes en tete.**
     */
    public function testLaSuiteDEntreeAuCombatEstOrdonneeEtComplete(): void
    {
        $corps = DamagedHulls::of(['cruiser' => [5000 => 3, 2500 => 2]]);
        $suite = $corps->damageSequenceFor('cruiser', 10);

        // Une entree par unite, exactement.
        $this->assertCount(10, $suite);

        // Cinq intactes, puis les moins abimees, puis les plus abimees.
        $this->assertSame([0, 0, 0, 0, 0, 2500, 2500, 5000, 5000, 5000], $suite);
    }

    public function testUneFlotteIntacteEntreAvecUneSuiteDeZeros(): void
    {
        // Le comportement du jeu d avant : rien de pose, rien qui change.
        $this->assertSame([0, 0, 0], DamagedHulls::none()->damageSequenceFor('cruiser', 3));
    }

    public function testLaFusionNeSoigneNiNePerdRien(): void
    {
        $corps = DamagedHulls::of(['cruiser' => [5000 => 8]]);
        $arrivants = DamagedHulls::of(['cruiser' => [5000 => 2, 2500 => 3], 'battle_ship' => [1000 => 1]]);

        $fusion = $corps->merge($arrivants);

        $this->assertSame([2500 => 3, 5000 => 10], $fusion->levelsOf('cruiser'), 'Les memes paliers se cumulent.');
        $this->assertSame([1000 => 1], $fusion->levelsOf('battle_ship'));
        $this->assertSame(13, $fusion->damagedCountOf('cruiser'));
    }

    public function testLaConversionEntreCoqueEtDegatsEstExacteSaufAuxExtremes(): void
    {
        $coquePleine = 4860;

        // Au coeur de l echelle, l aller-retour est exact.
        foreach ([2500, 5000, 7500] as $degats) {
            $coque = DamagedHulls::hullFromDamage($coquePleine, $degats);
            $this->assertSame(
                $degats,
                DamagedHulls::damageFromHull($coque, $coquePleine),
                'L aller-retour doit etre exact a ' . $degats . ' points de base.'
            );
        }

        // Une unite intacte le reste.
        $this->assertSame($coquePleine, DamagedHulls::hullFromDamage($coquePleine, 0));
        $this->assertSame(0, DamagedHulls::damageFromHull($coquePleine, $coquePleine));

        // **Et le biais est du bon cote** : `floor` fait qu une unite a peine abimee reste abimee,
        // au lieu de redevenir neuve gratuitement.
        $presqueIntacte = DamagedHulls::hullFromDamage($coquePleine, 1);
        $this->assertLessThan($coquePleine, $presqueIntacte, 'Un degat minime entame quand meme la coque.');
        $this->assertGreaterThan(0, DamagedHulls::damageFromHull($presqueIntacte, $coquePleine));
    }

    public function testUneCoqueNeTombeJamaisAZeroALEntree(): void
    {
        // Une unite qui entre au combat est vivante : une coque nulle la ferait mourir avant le
        // premier tir, et la bataille ne serait pas celle que le joueur a engagee.
        $this->assertSame(1, DamagedHulls::hullFromDamage(4860, DamagedHulls::FULL_DAMAGE - 1));
        $this->assertGreaterThanOrEqual(1, DamagedHulls::hullFromDamage(10, 9999));
    }

    public function testLaPartPerdueEquivalenteSertAuDevis(): void
    {
        // Huit unites a moitie detruites et quatre au quart : cinq unites entieres a reconstruire.
        $corps = DamagedHulls::of(['cruiser' => [5000 => 8, 2500 => 4]]);

        $this->assertSame(5.0, $corps->damageShareOf('cruiser'));
    }

    /**
     * **Une flotte qui maigrit en vol garde des degats qui tiennent dedans.**
     *
     * Une expedition qui perd des vaisseaux contre des pirates rentre avec moins d unites que
     * l aller n en portait, sans que ses coques soient reecrites. Sans bornage, le retour
     * decrirait plus d unites abimees qu il n en ramene, et l atterrissage **leverait** — une
     * expedition malheureuse aurait casse le jeu.
     *
     * Ce sont les plus abimees qui sautent : les survivantes sont les plus saines.
     */
    public function testDesDegatsQuiNeTiennentPlusDansLaFlotteSeBornentParLeHaut(): void
    {
        $depart = DamagedHulls::of(['cruiser' => [5000 => 8, 2500 => 4]]);

        // Douze abimees pour cinq unites qui rentrent : sept doivent sauter.
        [$borne, $retires] = $depart->withoutMostDamaged('cruiser', 12 - 5);

        $this->assertSame(7, $retires);
        $this->assertSame(5, $borne->damagedCountOf('cruiser'), 'Le compte doit tenir dans la flotte.');

        // Et ce sont les moins abimees qui restent : quatre a 25 %, une a 50 %.
        $this->assertSame([2500 => 4, 5000 => 1], $borne->levelsOf('cruiser'));
    }

    public function testLeRetraitDesPlusAbimeesEstLeComplementDuDepart(): void
    {
        $corps = DamagedHulls::of(['cruiser' => [5000 => 8, 2500 => 4]]);

        // Ce qui disparait — une perte au combat — se retire par l autre bout que ce qui part.
        [$restant, $retires] = $corps->withoutMostDamaged('cruiser', 3);

        $this->assertSame(3, $retires);
        $this->assertSame([2500 => 4, 5000 => 5], $restant->levelsOf('cruiser'));

        // Retirer plus qu il n y en a retire tout, et le dit.
        [$restant, $retires] = $corps->withoutMostDamaged('cruiser', 99);
        $this->assertSame(12, $retires);
        $this->assertTrue($restant->isEmpty());
    }
}
