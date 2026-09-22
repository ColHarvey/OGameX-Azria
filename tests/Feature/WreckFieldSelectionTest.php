<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Models\WreckField;
use OGame\Services\InitialUserDataService;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use Tests\AccountTestCase;

/**
 * Sur quelle epave une commande agit : l'identite exacte du champ autorise, jamais « la premiere a ces coordonnees ».
 *
 * ## Le defaut reproduit (revue de Codex, 21 septembre 2026)
 *
 * `FacilitiesController::startRepairs()` et `burnWreckField()` verifiaient d'abord un champ **filtre par
 * proprietaire** (`getWreckFieldForCurrentPlanet()` : proprietaire, etats admissibles, non expire, ordre par etat
 * puis par anciennete), puis rechargeaient par `loadForCoordinates()` — coordonnees seules, sans proprietaire, sans
 * etat, sans ordre : `first()` rend la ligne la plus ancienne. Depuis que l'unicite par coordonnees a ete retiree
 * (migration du 24 decembre 2025), plusieurs champs vivent aux memes coordonnees : une vieille epave brulee, celle
 * d'un ancien proprietaire de la position. La commande agissait alors sur la mauvaise, et le disait faite.
 *
 * ## Deux histoires distinctes, et le temoin qui les separe
 *
 * - **La suppression d'un compte emporte ses champs** : `owner_player_id -> users.id ON DELETE CASCADE`, applique au
 *   banc (`foreign_key_constraints`) comme en production. Aucun champ etranger ne vient de la.
 * - **L'abandon d'une planete par un proprietaire qui existe encore laisse son champ** aux coordonnees liberees :
 *   `permanentlyDeletePlanet()` efface la ligne du corps, ses files et les liens de missions — jamais `wreck_fields`.
 *   Le colon suivant de la position heritait donc d'une epave etrangere sous ses commandes.
 *
 * Les deux temoins de la fin etablissent ces faits ; les quatre du debut jouent les commandes par leurs vraies routes.
 */
class WreckFieldSelectionTest extends AccountTestCase
{
    /**
     * @var array<int, Coordinate>
     */
    private array $coordonneesANettoyer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->establishNoWreckFieldAt($this->planetService->getPlanetCoordinates());
        $this->giveASpaceDockTo($this->planetService->getPlanetId());
    }

    protected function tearDown(): void
    {
        foreach ($this->coordonneesANettoyer as $coordonnees) {
            $this->wreckFieldsAt($coordonnees)->delete();
        }

        parent::tearDown();
    }

    public function testTheStartCommandRepairsTheActiveWreckAndNotAnOlderBurnedOne(): void
    {
        $ici = $this->planetService->getPlanetCoordinates();
        $brulee = $this->aWreck($ici, $this->currentUserId, 'burned', 48);
        $active = $this->aWreck($ici, $this->currentUserId, 'active', 1);
        $this->assertLessThan($active->id, $brulee->id, 'The bench needs the burned wreck to be the older row: it is the one a coordinate-only first() returns.');

        $reponse = $this->postJson(route('facilities.startrepairs'));

        $reponse->assertStatus(200);
        $reponse->assertJson(['success' => true, 'error' => false]);
        $this->assertSame('repairing', $this->statusOf($active), 'The start command did not reach the active wreck: it acted on the older, burned one.');
        $this->assertSame('burned', $this->statusOf($brulee), 'The burned wreck changed state under a start command.');
        $this->assertNull($this->repairStartOf($brulee), 'A burned wreck received a repair timer.');
    }

    public function testTheBurnCommandBurnsTheActiveWreckInsteadOfBurningAnOldOneAgain(): void
    {
        $ici = $this->planetService->getPlanetCoordinates();
        $brulee = $this->aWreck($ici, $this->currentUserId, 'burned', 48);
        $active = $this->aWreck($ici, $this->currentUserId, 'active', 1);
        $this->assertLessThan($active->id, $brulee->id, 'The bench needs the burned wreck to be the older row.');

        $reponse = $this->postJson(route('facilities.burnwreckfield'));

        $reponse->assertStatus(200);
        $reponse->assertJson(['success' => true, 'error' => false]);
        $this->assertSame('burned', $this->statusOf($active), 'The burn command reported success and left the active wreck intact: it burned the old wreck a second time.');
    }

    public function testTheStartCommandNeverTouchesTheWreckLeftByThePreviousOwnerOfThePosition(): void
    {
        $position = $this->aPositionInheritedFromAnotherPlayer();
        $heritee = $position['heritee'];
        $mienne = $this->aWreck($position['ou'], $this->currentUserId, 'active', 0);
        $this->assertLessThan($mienne->id, $heritee->id, 'The bench needs the inherited wreck to be the older row.');

        $reponse = $this->postJson(route('facilities.startrepairs'));

        $reponse->assertStatus(200);
        $reponse->assertJson(['success' => true, 'error' => false]);
        $this->assertSame('active', $this->statusOf($heritee), 'The previous owner s wreck was put into repair by a player who does not own it.');
        $this->assertNull($this->repairStartOf($heritee), 'The previous owner s wreck received a repair timer from another player s space dock.');
        $this->assertSame('repairing', $this->statusOf($mienne), 'The start command did not reach the newcomer s own wreck.');
    }

    public function testTheBurnCommandNeverBurnsTheWreckLeftByThePreviousOwnerOfThePosition(): void
    {
        $position = $this->aPositionInheritedFromAnotherPlayer();
        $heritee = $position['heritee'];
        $mienne = $this->aWreck($position['ou'], $this->currentUserId, 'active', 0);
        $this->assertLessThan($mienne->id, $heritee->id, 'The bench needs the inherited wreck to be the older row.');

        $reponse = $this->postJson(route('facilities.burnwreckfield'));

        $reponse->assertStatus(200);
        $reponse->assertJson(['success' => true, 'error' => false]);
        $this->assertSame('active', $this->statusOf($heritee), 'The previous owner s wreck was burned by a player who does not own it.');
        $this->assertSame('burned', $this->statusOf($mienne), 'The burn command did not reach the newcomer s own wreck.');
    }

    /**
     * Une bataille etend l'epave **active**, meme quand une bloquee est plus ancienne qu'elle.
     *
     * Une active et une bloquee coexistent des qu'une bataille survient pendant une reparation, puis que celle-ci se
     * termine. `createWreckField()` cherchait l'existante sans `orderBy` : c'est le plan d'execution qui choisissait
     * laquelle repond, et une bloquee rendue la premiere faisait creer une **troisieme** ligne au lieu d'etendre
     * l'active. Le banc pose la bloquee en premier — identifiant plus petit — pour que le faux soit observable.
     */
    public function testABattleExtendsTheActiveWreckEvenWhenABlockedOneIsOlder(): void
    {
        $ici = $this->planetService->getPlanetCoordinates();
        $bloquee = $this->aWreck($ici, $this->currentUserId, 'blocked', 48);
        $active = $this->aWreck($ici, $this->currentUserId, 'active', 1);
        $this->assertLessThan($active->id, $bloquee->id, 'The bench needs the blocked wreck to be the older row: it is the one an unordered first() returns.');

        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $champ = (new WreckFieldService($joueur, resolve(SettingsService::class)))->createWreckField($ici, [
            ['machine_name' => 'light_fighter', 'quantity' => 7, 'repair_progress' => 0],
        ], $this->currentUserId);

        $this->assertSame($active->id, $champ->id, 'The battle did not extend the active wreck: it answered with the blocked one.');
        $this->assertSame(2, $this->wreckFieldsAt($ici)->count(), 'A third wreck was created instead of extending the active one.');
        $this->assertSame(17, $this->shipsIn($active), 'The seven new ships did not join the active wreck.');
        $this->assertSame(10, $this->shipsIn($bloquee), 'The blocked wreck was extended.');
        $this->assertSame('blocked', $this->statusOf($bloquee));
    }

    /**
     * `reload()` garde l'epave chargee par son identifiant : il suivait les coordonnees, et pouvait en changer.
     */
    public function testReloadKeepsTheWreckThatWasLoadedByItsIdentifier(): void
    {
        $ici = $this->planetService->getPlanetCoordinates();
        $brulee = $this->aWreck($ici, $this->currentUserId, 'burned', 48);
        $active = $this->aWreck($ici, $this->currentUserId, 'active', 1);
        $this->assertLessThan($active->id, $brulee->id, 'The bench needs the burned wreck to be the older row.');

        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $commande = new WreckFieldService($joueur, resolve(SettingsService::class));
        $this->assertTrue($commande->loadById($active->id));

        $commande->reload();

        $recharge = $commande->getWreckField();
        $this->assertNotNull($recharge);
        $this->assertSame($active->id, $recharge->id, 'reload() followed the coordinates and answered with another wreck than the one that was loaded.');
    }

    /**
     * Charger l'epave d'un autre joueur par son identifiant ne suffit pas a agir dessus : la transition reverifie le
     * proprietaire sous verrou, et c'est elle, pas le lecteur du controleur, qui porte cette regle.
     */
    public function testATransitionRefusesTheWreckOfAnotherPlayerEvenWhenLoadedByItsIdentifier(): void
    {
        $ou = $this->getNearbyEmptyCoordinate();
        $this->coordonneesANettoyer[] = $ou;
        $etrangere = $this->aWreck($ou, (int)$this->anotherAccount()->id, 'active', 1);
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $commande = new WreckFieldService($joueur, resolve(SettingsService::class));
        $this->assertTrue($commande->loadById($etrangere->id));

        foreach (['startRepairs' => static fn (WreckFieldService $c) => $c->startRepairs(1), 'burnWreckField' => static fn (WreckFieldService $c) => $c->burnWreckField()] as $nom => $transition) {
            // **Le refus est rendu, pas attendu par un `fail()` dans un `try`** : `AssertionFailedError` etend
            // `Exception`, donc un `fail()` place la serait avale par le `catch` qui suit, et l'essai rougirait sur
            // « refus pour une autre raison » alors que la transition a bel et bien ecrit sur l'epave d'un tiers.
            $refus = $this->refusalOf(static fn () => $transition($commande));
            $this->assertNotNull($refus, $nom . ' acted on another player s wreck.');
            $this->assertSame('Wreck field not found', $refus->getMessage(), $nom . ' refused for another reason than ownership.');
        }

        $this->assertSame('active', $this->statusOf($etrangere), 'The other player s wreck changed state.');
        $this->assertNull($this->repairStartOf($etrangere));
    }

    /**
     * La suppression d'un compte emporte ses champs : la cle etrangere le fait, au banc comme en production.
     *
     * C'est le temoin qui interdit d'accuser la suppression de compte d'un champ etranger : un champ qui survit a son
     * proprietaire n'existe pas.
     */
    public function testDeletingAnAccountTakesItsWreckFieldsWithIt(): void
    {
        $compte = User::factory()->create(['username' => 'efface_' . Str::random(12)]);
        $ou = $this->getNearbyEmptyCoordinate();
        $this->coordonneesANettoyer[] = $ou;
        $champ = $this->aWreck($ou, (int)$compte->id, 'active', 1);

        DB::table('users')->where('id', $compte->id)->delete();

        $this->assertSame(0, WreckField::query()->whereKey($champ->id)->count(), 'The wreck field survived its owner: the cascade on owner_player_id does not apply here, and no analysis may rely on it.');
    }

    /**
     * L'abandon d'une planete par un proprietaire qui existe encore laisse son champ aux coordonnees liberees.
     *
     * C'est le cas de production : le champ est a lui, il existe, et la position est libre pour le colon suivant.
     */
    public function testAbandoningAPlanetLeavesItsWreckFieldAtTheFreedCoordinates(): void
    {
        $position = $this->aPositionInheritedFromAnotherPlayer();

        $this->assertSame('active', $this->statusOf($position['heritee']));
        $this->assertSame($position['ancien'], (int)DB::table('wreck_fields')->where('id', $position['heritee']->id)->value('owner_player_id'), 'The inherited wreck changed owner.');
        $this->assertSame(1, DB::table('users')->where('id', $position['ancien'])->count(), 'The previous owner no longer exists: this is the account-deletion story, not the abandonment one.');
        $this->assertSame(
            $this->currentUserId,
            (int)DB::table('planets')->where('galaxy', $position['ou']->galaxy)->where('system', $position['ou']->system)->where('planet', $position['ou']->position)->value('user_id'),
            'The freed position is not held by the newcomer.'
        );
    }

    /**
     * Une position liberee par un autre joueur, qui y laisse son epave, et que le joueur du banc colonise ensuite.
     *
     * L'abandon est joue tel que le jeu le fait : la marque (`markAsDestroyed()`), puis la purge planifiee
     * (`permanentlyDeletePlanet()`). Le colon en fait sa planete courante, avec un chantier spatial.
     *
     * @return array{ou: Coordinate, ancien: int, heritee: WreckField}
     */
    private function aPositionInheritedFromAnotherPlayer(): array
    {
        $ancien = $this->anotherAccount();
        $joueur = resolve(PlayerServiceFactory::class)->make((int)$ancien->id, true);
        $fabrique = resolve(PlanetServiceFactory::class);

        // Deux planetes : la derniere ne s'abandonne pas.
        $fabrique->createAdditionalPlanetForPlayer($joueur, $this->getNearbyEmptyCoordinate());
        $ou = $this->getNearbyEmptyCoordinate();
        $this->coordonneesANettoyer[] = $ou;
        $abandonnee = $fabrique->createAdditionalPlanetForPlayer($joueur, $ou);
        $heritee = $this->aWreck($ou, (int)$ancien->id, 'active', 24);

        $joueur->load((int)$ancien->id);
        $abandonnee = $joueur->planets->getById($abandonnee->getPlanetId());
        $abandonnee->markAsDestroyed();
        $abandonnee->permanentlyDeletePlanet();

        $this->assertFalse($fabrique->planetExistsAtCoordinate($ou), 'The abandoned planet still occupies its position.');
        $this->assertSame('active', $this->statusOf($heritee), 'The previous owner s wreck did not survive the abandonment: the story this bench tells no longer exists.');

        $moi = $this->planetService->getPlayer();
        $this->assertNotNull($moi);
        $colonie = $fabrique->createAdditionalPlanetForPlayer($moi, $ou);
        $moi->load($this->currentUserId);
        $moi->setCurrentPlanetId($colonie->getPlanetId());
        $this->giveASpaceDockTo($colonie->getPlanetId());
        $this->assertSame($colonie->getPlanetId(), (int)DB::table('users')->where('id', $this->currentUserId)->value('planet_current'), 'The colony is not the current planet: the commands would act elsewhere.');

        return ['ou' => $ou, 'ancien' => (int)$ancien->id, 'heritee' => $heritee];
    }

    /**
     * Un compte de plus, joueur ordinaire, avec ses donnees initiales — jamais le compte du banc.
     */
    private function anotherAccount(): User
    {
        $compte = User::factory()->create(['username' => 'ancien_' . Str::random(12)]);

        // Le crochet `created` du modele promeut le premier utilisateur d'une transaction en admin.
        if ($compte->hasRole('admin')) {
            $compte->removeRole('admin');
            $compte->username = 'ancien_' . Str::random(12);
            $compte->save();
        }

        resolve(InitialUserDataService::class)->createFor($compte);

        return $compte;
    }

    /**
     * L'exception que la commande leve, ou `null` si elle a abouti.
     *
     * @param callable(): mixed $commande
     */
    private function refusalOf(callable $commande): Exception|null
    {
        try {
            $commande();
        } catch (Exception $refus) {
            return $refus;
        }

        return null;
    }

    private function aWreck(Coordinate $ou, int $proprietaire, string $etat, int $ageEnHeures): WreckField
    {
        /** @var WreckField $champ */
        $champ = WreckField::factory()->create([
            'galaxy' => $ou->galaxy,
            'system' => $ou->system,
            'planet' => $ou->position,
            'owner_player_id' => $proprietaire,
            'status' => $etat,
            'created_at' => now()->subHours($ageEnHeures),
            'expires_at' => now()->addHours(72),
            'ship_data' => [
                ['machine_name' => 'light_fighter', 'quantity' => 10, 'repair_progress' => 0],
            ],
        ]);

        return $champ;
    }

    private function statusOf(WreckField $champ): string
    {
        return (string)DB::table('wreck_fields')->where('id', $champ->id)->value('status');
    }

    private function shipsIn(WreckField $champ): int
    {
        $frais = WreckField::query()->find($champ->id);

        return $frais === null ? 0 : $frais->getTotalShips();
    }

    private function repairStartOf(WreckField $champ): string|null
    {
        $valeur = DB::table('wreck_fields')->where('id', $champ->id)->value('repair_started_at');

        return $valeur === null ? null : (string)$valeur;
    }

    private function giveASpaceDockTo(int $planetId): void
    {
        DB::table('planets')->where('id', $planetId)->update(['space_dock' => 1]);
    }

    /**
     * Un essai etablit ce qu'il exige : aucune epave a cette coordonnee avant lui, quoi que les voisins y aient laisse.
     */
    private function establishNoWreckFieldAt(Coordinate $coordonnees): void
    {
        $this->coordonneesANettoyer[] = $coordonnees;
        $this->wreckFieldsAt($coordonnees)->delete();
    }

    /**
     * @return Builder<WreckField>
     */
    private function wreckFieldsAt(Coordinate $coordonnees)
    {
        return WreckField::query()
            ->where('galaxy', $coordonnees->galaxy)
            ->where('system', $coordonnees->system)
            ->where('planet', $coordonnees->position);
    }
}
