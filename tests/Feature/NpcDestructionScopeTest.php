<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\Npc\NpcDestructionService;
use OGame\Services\PlanetService;
use Tests\AccountTestCase;
use Tests\SpawnsNpcBases;

/**
 * **La destruction d une base ne solde que SES flottes** (defaut repere par Keven, 20 septembre 2026).
 *
 * `groundOutboundFleets()` annonce dans son commentaire « settle every mission **this base** still has in
 * flight », mais sa requete portait sur tout le compte :
 *
 * ```php
 * FleetMission::where('user_id', $owner->getId())->where('processed', 0)->update(['processed' => 1]);
 * ```
 *
 * Tant qu un compte pirate n a qu une base, les deux formulations coincident. **L essaimage les separe** : un
 * compte peut alors posseder plusieurs colonies, et la chute de l une soldait en silence les flottes des autres,
 * encore vivantes. Le reglage `npc_swarm_enabled` est desarme par defaut, donc le defaut etait latent — mais il
 * attendait le jour ou Keven l armerait.
 *
 * Ce banc monte exactement cette situation : deux corps sur un meme compte, deux flottes en vol, une seule base
 * detruite.
 */
class NpcDestructionScopeTest extends AccountTestCase
{
    use SpawnsNpcBases;

    /**
     * Une flotte en vol entre deux corps, qui n est pas encore traitee.
     *
     * **Depart et arrivee sont distincts**, et c est le point : un montage ou les deux valent la base
     * detruite confond « partie d ici » et « rentre ici ». Le filtre porte sur les deux colonnes, chacune
     * doit donc etre eprouvee seule.
     */
    private function uneFlotteEnVol(int $depart, int $arrivee, int $userId): FleetMission
    {
        // `FleetMission` n est pas remplissable en masse : on pose chaque colonne, comme les autres bancs.
        $mission = new FleetMission();
        $mission->user_id = $userId;
        $mission->planet_id_from = $depart;
        $mission->planet_id_to = $arrivee;
        $mission->galaxy_from = 1;
        $mission->system_from = 1;
        $mission->position_from = 1;
        $mission->type_from = PlanetType::Planet->value;
        $mission->galaxy_to = 1;
        $mission->system_to = 1;
        $mission->position_to = 2;
        $mission->type_to = PlanetType::Planet->value;
        $mission->mission_type = 1;
        $mission->time_departure = (int)Date::now()->subMinutes(10)->timestamp;
        $mission->time_arrival = (int)Date::now()->addMinutes(10)->timestamp;
        $mission->processed = 0;
        $mission->small_cargo = 5;
        $mission->save();

        return $mission;
    }

    /**
     * Une seconde colonie pour le meme compte pirate — ce que l essaimage produit.
     */
    private function uneSecondeBasePourLeMemeCompte(PlanetService $premiere, int $decalage = 1): PlanetService
    {
        $proprietaire = $premiere->getPlayer();
        $this->assertNotNull($proprietaire);

        $modele = Planet::query()->findOrFail($premiere->getPlanetId());
        $copie = $modele->replicate();
        $copie->name = 'Base du banc no ' . $decalage;
        // Une coordonnee libre, cherchee au-dela de la plage du banc pour ne bousculer personne.
        $copie->galaxy = (int)$modele->galaxy;
        $copie->system = (int)$modele->system;
        $copie->planet = (int)$modele->planet + $decalage;
        $copie->destroyed = 0;
        $copie->save();

        $seconde = resolve(PlanetServiceFactory::class)->make((int)$copie->id, true);
        $this->assertNotNull($seconde, 'La seconde base doit exister.');

        return $seconde;
    }

    /**
     * Le montage commun : deux bases pirates de plus sur le compte, et la premiere prete a tomber.
     *
     * @return array{premiere: PlanetService, deuxieme: PlanetService, troisieme: PlanetService, proprietaire: int}
     */
    private function troisBasesDuMemeCompte(): array
    {
        $premiere = $this->aSpawnedBase();
        $premiere = resolve(PlanetServiceFactory::class)->make($premiere->getPlanetId(), true);
        $this->assertNotNull($premiere);

        $proprietaire = $premiere->getPlayer();
        $this->assertNotNull($proprietaire);

        return [
            'premiere' => $premiere,
            'deuxieme' => $this->uneSecondeBasePourLeMemeCompte($premiere, 1),
            'troisieme' => $this->uneSecondeBasePourLeMemeCompte($premiere, 2),
            'proprietaire' => $proprietaire->getId(),
        ];
    }

    private function faireTomber(PlanetService $base): void
    {
        $tombee = resolve(NpcDestructionService::class)->destroy($base);
        $this->assertTrue($tombee, 'Premisse : la base doit bien tomber, sinon rien n est solde.');
    }

    /**
     * **Cas 1 : partie de la base detruite.** L equipage n a plus de port d attache.
     */
    public function testAFleetThatLeftTheDestroyedBaseIsSettled(): void
    {
        ['premiere' => $premiere, 'deuxieme' => $deuxieme, 'proprietaire' => $proprietaire] = $this->troisBasesDuMemeCompte();

        $partie = $this->uneFlotteEnVol($premiere->getPlanetId(), $deuxieme->getPlanetId(), $proprietaire);
        $this->assertSame(0, (int)$partie->refresh()->processed, 'Premisse : la flotte est en vol.');

        $this->faireTomber($premiere);

        $this->assertSame(1, (int)$partie->refresh()->processed, 'Une flotte partie de la base detruite doit etre soldee.');
    }

    /**
     * **Cas 2 : elle rentre vers la base detruite.** Sa destination vient d etre purgee.
     */
    public function testAFleetHeadingBackToTheDestroyedBaseIsSettled(): void
    {
        ['premiere' => $premiere, 'deuxieme' => $deuxieme, 'proprietaire' => $proprietaire] = $this->troisBasesDuMemeCompte();

        $rentre = $this->uneFlotteEnVol($deuxieme->getPlanetId(), $premiere->getPlanetId(), $proprietaire);
        $this->assertSame(0, (int)$rentre->refresh()->processed, 'Premisse : la flotte est en vol.');

        $this->faireTomber($premiere);

        $this->assertSame(1, (int)$rentre->refresh()->processed, 'Une flotte qui rentre vers la base detruite doit etre soldee.');
    }

    /**
     * **Cas 3 : entre deux AUTRES bases du meme compte.** C est le defaut que Keven a repere le 20 septembre 2026 :
     * la requete portait sur tout le compte, et l essaimage en donne plusieurs.
     */
    public function testAFleetBetweenTwoOtherBasesOfTheSameAccountIsUntouched(): void
    {
        ['premiere' => $premiere, 'deuxieme' => $deuxieme, 'troisieme' => $troisieme, 'proprietaire' => $proprietaire] = $this->troisBasesDuMemeCompte();

        $ailleurs = $this->uneFlotteEnVol($deuxieme->getPlanetId(), $troisieme->getPlanetId(), $proprietaire);
        $this->assertSame(0, (int)$ailleurs->refresh()->processed, 'Premisse : la flotte est en vol.');

        // Premisse du cas : cette flotte ne touche ni au depart ni a l arrivee la base qui tombe.
        $this->assertNotSame($premiere->getPlanetId(), (int)$ailleurs->planet_id_from);
        $this->assertNotSame($premiere->getPlanetId(), (int)$ailleurs->planet_id_to);

        $this->faireTomber($premiere);

        $this->assertSame(
            0,
            (int)$ailleurs->refresh()->processed,
            'Une flotte entre deux autres bases du meme compte, encore vivantes, ne doit pas etre soldee.'
        );
    }

    /**
     * **Cas 4 : l attaque d un joueur contre la base.** Elle n appartient pas au compte pirate : la chute de la
     * base ne la solde pas, et le joueur garde sa flotte.
     */
    public function testAPlayerFleetAimedAtTheBaseIsUntouched(): void
    {
        ['premiere' => $premiere, 'proprietaire' => $proprietaire] = $this->troisBasesDuMemeCompte();

        $joueur = $this->currentUserId;
        $this->assertNotSame($proprietaire, $joueur, 'Premisse : le joueur n est pas le pirate.');

        $attaque = $this->uneFlotteEnVol($this->planetService->getPlanetId(), $premiere->getPlanetId(), $joueur);
        $this->assertSame(0, (int)$attaque->refresh()->processed, 'Premisse : la flotte est en vol.');

        $this->faireTomber($premiere);

        $this->assertSame(
            0,
            (int)$attaque->refresh()->processed,
            'La flotte d un joueur visant la base ne lui appartient pas : la chute de la base ne la solde pas.'
        );
    }
}
