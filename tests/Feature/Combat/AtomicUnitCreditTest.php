<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Models\User;
use RuntimeException;
use Tests\TestCase;

/**
 * Un credit d'unites ecrit en base, et seulement si le corps appartient encore a son destinataire.
 *
 * ## Les deux courses que `addUnit()` laissait ouvertes
 *
 * `addUnit()` lit la valeur **telle qu'elle a ete chargee**, y ajoute, et sauve le modele. Son
 * symetrique `removeUnit()`, juste au-dessous dans la meme classe, fait deja son debit par une
 * operation de la base : l'addition etait la seule des deux a ne pas le faire. Deux crediteurs
 * concurrents lisaient donc la meme valeur, et le second effacait le premier.
 *
 * La seconde course est plus discrete. Le remboursement des missiles designe le corps qui les
 * reprend, puis verifie son proprietaire par une lecture separee. Sous `REPEATABLE READ` cette
 * lecture ne pose **aucun verrou** et repond depuis l'instantane de la transaction : le corps peut
 * changer de mains entre le controle et l'ecriture, et une planete abandonnee puis recolonisee
 * garde son identifiant. Les missiles allaient alors a un inconnu, sans que rien ne le dise.
 *
 * ## Ce que cette classe prouve, et ce qu'elle ne peut pas prouver
 *
 * Elle prouve **le contrat** : la condition sur le proprietaire vit dans l'ecriture, un montant nul
 * est refuse, et le modele en memoire suit la ligne. Tout cela se voit sur une seule connexion.
 *
 * Elle ne prouve **pas** l'atomicite face a un second processus : SQLite ne donne pas deux
 * connexions, et le lire-modifier-ecrire fautif rendrait ici exactement la meme valeur que le code
 * correct. C'est `tests/MariaDb/MissileRefundRaceTest` qui separe le juste du faux sur ce point.
 */
class AtomicUnitCreditTest extends TestCase
{
    /**
     * Chaque essai vit dans sa transaction : les corps qu'il cree occupent des coordonnees que la
     * table impose uniques, et la base est partagee entre les essais d'un meme processus.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Le credit a lieu quand le corps appartient bien a son destinataire.
     */
    public function testTheCreditHappensWhenTheBodyStillBelongsToTheOwner(): void
    {
        $corps = $this->aBodyWithMissiles(7);
        $service = resolve(PlanetServiceFactory::class)->make($corps->id, true);
        $this->assertNotNull($service);

        $this->assertTrue(
            $service->addUnitAtomicIfStillOwnedBy('interplanetary_missile', 5, (int)$corps->user_id),
            'The credit was refused on a body that had not changed hands.'
        );

        $this->assertSame(12, $this->missilesOn($corps->id), 'The row does not carry the credited missiles.');
    }

    /**
     * Un corps qui a change de mains n'est jamais credite — et la condition vit dans l'ecriture.
     *
     * ## Pourquoi cet essai ne passe pas par le remboursement
     *
     * Le remboursement filtre deja son candidat par une lecture du proprietaire. Un essai qui
     * passerait par lui verrait ce filtre refuser, et **la mutation qui retire la condition de
     * l'ecriture y survivrait** : le filtre suffirait a le faire passer. En s'adressant au credit
     * lui-meme, l'essai interroge la seule garantie qui tienne sous la concurrence.
     */
    public function testABodyThatChangedHandsIsNotCreditedAndNothingIsWritten(): void
    {
        $corps = $this->aBodyWithMissiles(7);
        $ancienProprietaire = (int)$corps->user_id;
        $service = resolve(PlanetServiceFactory::class)->make($corps->id, true);
        $this->assertNotNull($service);

        // Le corps change de mains apres que le service l'a charge : c'est exactement l'etat que la
        // course produit entre la designation du silo et son credit.
        $repreneur = User::factory()->create();
        DB::table('planets')->where('id', $corps->id)->update(['user_id' => $repreneur->id]);

        $this->assertFalse(
            $service->addUnitAtomicIfStillOwnedBy('interplanetary_missile', 5, $ancienProprietaire),
            'A body that had changed hands accepted a credit meant for its former owner.'
        );

        $this->assertSame(7, $this->missilesOn($corps->id), 'The refused credit still wrote to the row.');
    }

    /**
     * Le corps a disparu : le credit est refuse, il ne leve pas et n'invente rien.
     */
    public function testAVanishedBodyRefusesTheCreditInsteadOfSucceeding(): void
    {
        $corps = $this->aBodyWithMissiles(7);
        $service = resolve(PlanetServiceFactory::class)->make($corps->id, true);
        $this->assertNotNull($service);

        DB::table('planets')->where('id', $corps->id)->delete();

        $this->assertFalse(
            $service->addUnitAtomicIfStillOwnedBy('interplanetary_missile', 5, (int)$corps->user_id),
            'A credit onto a vanished body reported success.'
        );
    }

    /**
     * Un montant nul est refuse, et le refus est explicite.
     *
     * ## Ce que le silence aurait coute
     *
     * **MariaDB compte les lignes changees, pas les lignes trouvees.** Crediter zero ne changerait
     * rien, l'ecriture rendrait zero, et la methode conclurait « le corps a change de mains » alors
     * que rien n'a bouge : le remboursement laisserait la creance due pour toujours. C'est le meme
     * piege qui faisait sortir le diffuseur de son bail a chaque minute en production. Ici SQLite
     * repondrait « une ligne trouvee » et le faux serait invisible — d'ou le refus explicite, qui
     * rend le contrat identique sur les deux moteurs.
     */
    public function testANonPositiveCreditIsRefusedOutright(): void
    {
        $corps = $this->aBodyWithMissiles(7);
        $service = resolve(PlanetServiceFactory::class)->make($corps->id, true);
        $this->assertNotNull($service);

        $this->expectException(RuntimeException::class);

        try {
            $service->addUnitAtomicIfStillOwnedBy('interplanetary_missile', 0, (int)$corps->user_id);
        } finally {
            $this->assertSame(7, $this->missilesOn($corps->id), 'A refused credit still touched the row.');
        }
    }

    /**
     * Le modele en memoire suit **la ligne**, pas la valeur qu'il avait chargee.
     *
     * ## La perte que cet essai empeche de revenir
     *
     * Si la synchronisation faisait « valeur chargee + credit », la base porterait « valeur courante
     * + credit » et l'objet autre chose. Une sauvegarde ulterieure de cet objet — et le service est
     * garde en cache par sa fabrique — reecrirait exactement la perte que l'ecriture atomique vient
     * d'empecher.
     */
    public function testTheModelFollowsTheRowAndNotTheValueItHadLoaded(): void
    {
        $corps = $this->aBodyWithMissiles(7);
        $service = resolve(PlanetServiceFactory::class)->make($corps->id, true);
        $this->assertNotNull($service);

        // Une autre ecriture passe apres le chargement : un chantier termine, un retour de flotte.
        DB::table('planets')->where('id', $corps->id)->update(['interplanetary_missile' => 100]);

        $this->assertTrue($service->addUnitAtomicIfStillOwnedBy('interplanetary_missile', 5, (int)$corps->user_id));

        // 105 des deux cotes : ni 12 en base, ni 12 en memoire.
        $this->assertSame(105, $this->missilesOn($corps->id), 'The credit overwrote a change made after the load.');
        $this->assertSame(
            105,
            (int)$service->getObjectAmount('interplanetary_missile'),
            'The in-memory model carries the value it had loaded plus the credit, not the row.'
        );
    }

    private function missilesOn(int $bodyId): int
    {
        return (int)DB::table('planets')->where('id', $bodyId)->value('interplanetary_missile');
    }

    private function aBodyWithMissiles(int $missiles): Planet
    {
        // **Une position libre, cherchee et non supposee.** La table impose l'unicite des
        // coordonnees, et la base est partagee entre les essais d'un meme processus.
        $systeme = (int)DB::table('planets')->where('galaxy', 9)->max('system');
        $systeme = max($systeme, 0) + 1;

        return Planet::factory()->create([
            'user_id' => User::factory()->create()->id,
            'galaxy' => 9,
            'system' => $systeme,
            'planet' => 1,
            'planet_type' => 1,
            'interplanetary_missile' => $missiles,
            // L'horloge de production est mise au futur : sans cela, la relecture du service
            // ajouterait la production ecoulee et les nombres attendus ne tiendraient plus.
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
    }
}
