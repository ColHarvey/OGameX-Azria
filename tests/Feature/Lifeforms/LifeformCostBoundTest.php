<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Score\LifeformScoreCalculator;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\HighscoreService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **Un cout qui ne se represente plus est refuse, jamais converti** (defaut de securite numerique repere par
 * Keven, 20 septembre 2026).
 *
 * `LifeformFormulas::cost()` calcule `base × facteur^(n−1) × n` en flottant puis convertit en entier. A tres haut
 * niveau le produit depasse la capacite d un entier signe : PHP emettait « not representable as an int » et rendait
 * une valeur **fausse** — `(int)9.3e18` vaut −9 146 744 073 709 551 616. Un palier devenait gratuit, ou payant a
 * l envers, et la file pouvait s ecrire sur ce chiffre-la.
 *
 * Ce n est pas un changement de regle de jeu : la formule est inchangee, c est sa **representation** qui est bornee.
 * Le refus emprunte le mecanisme deja en place (`LifeformRefused`), que les appelants savent presenter au joueur.
 *
 * ## Ou tombe la borne, et pourquoi on ne dit pas « inatteignable »
 *
 * Elle depend de l objet. La plus basse du catalogue est `mineral_research_centre` (Rocktal, facteur 1,8, base
 * 250 000 / 150 000 / 100 000) : le **niveau 48** n est plus representable. Le niveau 47 coute deja 6,49 × 10^18
 * en metal seul, ce qu aucune partie ordinaire n accumule — mais « ordinaire » n est pas « impossible » : une
 * ecriture d administration, un reglage d univers, une commande de banc ou un futur equilibrage peuvent y mener.
 * La garde existe pour que ce jour-la rien ne casse, pas parce qu on aurait prouve qu il n arrivera jamais.
 */
final class LifeformCostBoundTest extends AccountTestCase
{
    use PinsSettings;

    /** L objet dont la borne est la plus basse du catalogue, et le niveau ou elle tombe. */
    private const int OBJET = 12111;       // mineral_research_centre
    private const int DERNIER_REPRESENTABLE = 47;

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * Le compte devient Rocktal, et la planete porte tout ce que le centre de recherche mineral exige, a son
     * dernier niveau representable.
     */
    private function unCompteAuDernierNiveauRepresentable(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);

        $corps = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $objet = LifeformCatalogue::byId(self::OBJET);

        // Les prerequis, lus sur le catalogue : un prerequis oublie donnerait un refus, mais pas celui qu on eprouve.
        foreach ($objet->requirements as $exige => $niveauExige) {
            $niveaux->setLevel($corps, LifeformKind::Building, (int)$exige, (int)$niveauExige);
        }
        $niveaux->setLevel($corps, LifeformKind::Building, $objet->id, self::DERNIER_REPRESENTABLE);
    }

    public function testTheLastRepresentableLevelStillHasACostAndTheNextOneIsRefused(): void
    {
        $objet = LifeformCatalogue::byId(self::OBJET);

        // Le niveau d avant garde un cout entier, positif, et egal au calcul.
        $cout = LifeformFormulas::cost($objet, self::DERNIER_REPRESENTABLE, 0.0);
        $this->assertGreaterThan(0, $cout->metal->get(), 'Le dernier niveau representable doit encore couter quelque chose.');
        $this->assertLessThan(
            (float)PHP_INT_MAX,
            $cout->metal->get(),
            'Premisse : ce niveau-la doit tenir dans un entier, sinon la borne n est pas celle qu on croit.'
        );

        // Le suivant est refuse, avec sa raison typee et l objet nomme.
        try {
            LifeformFormulas::cost($objet, self::DERNIER_REPRESENTABLE + 1, 0.0);
            $this->fail('Le niveau au-dela de la borne a rendu un cout au lieu d etre refuse.');
        } catch (LifeformRefused $refus) {
            $this->assertSame(LifeformRefused::COST_NOT_REPRESENTABLE, $refus->reason);
            $this->assertStringContainsString($objet->machineName, $refus->getMessage(), 'Le refus ne dit pas de quel objet il parle.');
        }
    }

    /**
     * **Aucun cout negatif, aucun cout gratuit.** C est la faute que la garde empeche : avant elle, la conversion
     * rendait une valeur fausse, parfois negative, sans lever quoi que ce soit.
     */
    public function testNoLevelEverProducesANegativeOrFreeCost(): void
    {
        $objet = LifeformCatalogue::byId(self::OBJET);

        for ($niveau = 1; $niveau <= 80; $niveau++) {
            try {
                $cout = LifeformFormulas::cost($objet, $niveau, 0.0);
            } catch (LifeformRefused) {
                continue;
            }
            foreach (['metal', 'crystal', 'deuterium'] as $ressource) {
                $valeur = $cout->{$ressource}->get();
                $this->assertGreaterThanOrEqual(0, $valeur, "Le niveau $niveau rend un cout negatif en $ressource.");
                $this->assertLessThanOrEqual((float)PHP_INT_MAX, $valeur, "Le niveau $niveau rend un cout hors de l entier en $ressource.");
            }
            $this->assertGreaterThan(0, $cout->sum(), "Le niveau $niveau est gratuit.");
        }
    }

    /**
     * Une fiche de catalogue fabriquee, pour eprouver la garde la ou aucune fiche reelle ne le permet.
     *
     * Le constructeur de `LifeformObject` verifie l identifiant contre l espece, le genre et l index : la fiche
     * est donc aussi valide que celles du catalogue. Seules ses bases changent.
     */
    private function uneFicheDeBases(int $metal, int $cristal, int $deuterium): LifeformObject
    {
        return new LifeformObject(
            id: 11101,
            species: Species::Humans,
            kind: LifeformKind::Building,
            index: 1,
            machineName: 'fiche_de_banc',
            metal: $metal,
            crystal: $cristal,
            deuterium: $deuterium,
            energy: 0,
            costFactor: 1.0,
            energyFactor: 1.0,
            durationBase: 1,
            durationFactor: 1.0,
            requirements: [],
            populationBase: null,
            populationFactor: null,
            bonuses: [],
        );
    }

    /**
     * **La frontiere se prend au sens large.** `PHP_INT_MAX` vaut 2^63 − 1, mais le flottant le plus proche vaut
     * 2^63 : une valeur exactement egale a `(float)PHP_INT_MAX` ne rentre donc PAS dans un entier, et sa
     * conversion rend `PHP_INT_MIN` — un cout negatif. Une garde en `>` laisserait passer exactement ce cas.
     */
    public function testACostExactlyOnTheBoundaryIsRefusedBecauseItWouldTurnNegative(): void
    {
        $fiche = $this->uneFicheDeBases(PHP_INT_MAX, 0, 0);

        // Premisse : le calcul tombe exactement sur la frontiere, ni au-dessus ni au-dessous.
        $valeur = (float)PHP_INT_MAX * (1.0 ** 0) * 1;
        $this->assertSame((float)PHP_INT_MAX, $valeur, 'Premisse : cette fiche ne tombe pas sur la frontiere.');

        // Et aucun entier ne peut la porter : sa valeur decimale exacte depasse PHP_INT_MAX. On le montre en
        // arithmetique decimale, **sans convertir** — la conversion elle-meme emet l avertissement qu on decrit.
        $this->assertSame(
            1,
            bccomp(sprintf('%.0F', $valeur), (string)PHP_INT_MAX, 0),
            'Premisse : cette valeur tiendrait dans un entier, la garde n aurait rien a refuser.'
        );

        $this->expectException(LifeformRefused::class);
        LifeformFormulas::cost($fiche, 1, 0.0);
    }

    /**
     * **La garde regarde les trois ressources.** Dans le catalogue reel, le metal est toujours la plus grosse
     * base : une garde qui ne regarderait que lui passerait partout. Une fiche ou le deuterium est le seul a
     * deborder separe le juste du faux.
     */
    public function testTheBoundIsCheckedOnEveryResourceAndNotOnlyOnMetal(): void
    {
        foreach (['cristal' => [1, PHP_INT_MAX, 1], 'deuterium' => [1, 1, PHP_INT_MAX]] as $nom => $bases) {
            $fiche = $this->uneFicheDeBases(...$bases);
            try {
                $cout = LifeformFormulas::cost($fiche, 1, 0.0);
                $this->fail("Un cout non representable en $nom a ete rendu : " . $cout->sum());
            } catch (LifeformRefused $refus) {
                $this->assertSame(LifeformRefused::COST_NOT_REPRESENTABLE, $refus->reason);
            }
        }

        // Premisse : la meme fiche avec des bases ordinaires passe sans encombre — sinon ce temoin ne
        // distinguerait rien.
        $this->assertGreaterThan(0, LifeformFormulas::cost($this->uneFicheDeBases(1, 1, 1), 1, 0.0)->sum());
    }

    /**
     * **Le temoin que Keven a demande : par la VRAIE demande de construction.** Au-dela de la borne, refus
     * explicite, ressources et file inchangees.
     */
    public function testTheRealBuildRequestRefusesBeyondTheBoundAndChangesNothing(): void
    {
        $this->unCompteAuDernierNiveauRepresentable();

        $corps = $this->planetService->getPlanetId();
        $avantRessources = $this->planetService->getResources();
        $avantFile = LifeformQueue::query()->where('planet_id', $corps)->count();

        $reponse = $this->post(route('lifeforms.buildings.addbuildrequest.post'), [
            'technologyId' => self::OBJET,
            '_token' => csrf_token(),
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('success', false);

        // **Le joueur lit une phrase, pas une clef** : une clef non traduite traverserait cette comparaison.
        $phrase = __('t_lifeforms_ui.refused.' . LifeformRefused::COST_NOT_REPRESENTABLE);
        $this->assertStringNotContainsString('t_lifeforms_ui.', $phrase, 'La clef du refus n est pas traduite.');
        $reponse->assertJsonPath('errors.0.message', $phrase);

        // Rien n a bouge : ni le stock, ni la file.
        $apres = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->current();
        $this->assertSame(
            [$avantRessources->metal->get(), $avantRessources->crystal->get(), $avantRessources->deuterium->get()],
            [$apres->getResources()->metal->get(), $apres->getResources()->crystal->get(), $apres->getResources()->deuterium->get()],
            'Le refus a tout de meme debite la planete.'
        );
        $this->assertSame($avantFile, LifeformQueue::query()->where('planet_id', $corps)->count(), 'Le refus a laisse une ligne dans la file.');
    }

    /**
     * Premisse du temoin precedent : au dernier niveau representable, la meme demande **aboutit**. Sans elle, un
     * refus pour une toute autre raison — prerequis, espece, file — passerait pour la preuve de la garde.
     */
    public function testTheSameRequestOneLevelLowerIsAccepted(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);

        $corps = $this->planetService->getPlanetId();
        $objet = LifeformCatalogue::byId(self::OBJET);
        $niveaux = resolve(LifeformLevels::class);
        foreach ($objet->requirements as $exige => $niveauExige) {
            $niveaux->setLevel($corps, LifeformKind::Building, (int)$exige, (int)$niveauExige);
        }
        // Un cran plus bas : la demande vise le niveau 47, le dernier representable.
        $niveaux->setLevel($corps, LifeformKind::Building, $objet->id, self::DERNIER_REPRESENTABLE - 1);

        $reponse = $this->post(route('lifeforms.buildings.addbuildrequest.post'), [
            'technologyId' => self::OBJET,
            '_token' => csrf_token(),
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('status', 'success');
        $this->assertSame(
            self::DERNIER_REPRESENTABLE,
            (int)LifeformQueue::query()->where('planet_id', $corps)->where('object_id', $objet->id)->value('target_level'),
            'La demande acceptee ne vise pas le niveau attendu.'
        );
    }

    /**
     * **Le classement ne ferme jamais une page.** Un niveau au-dela de la borne ne peut plus etre acquis par le
     * jeu — la garde le refuse avant toute ecriture —, mais il peut exister par une ecriture directe en base.
     * Le calcul s arrete alors sur cet objet **et le dit au journal** : ce n est pas un plafonnement silencieux.
     */
    public function testAnUnreachableLevelWrittenStraightIntoTheDatabaseNeverBreaksTheRanking(): void
    {
        $corps = $this->planetService->getPlanetId();
        $objet = LifeformCatalogue::byId(self::OBJET);
        resolve(LifeformLevels::class)->setLevel($corps, LifeformKind::Building, $objet->id, self::DERNIER_REPRESENTABLE + 5);

        $dits = [];
        Log::listen(static function ($message) use (&$dits): void {
            $dits[] = $message;
        });

        $ressources = resolve(LifeformScoreCalculator::class)->buildingResourcesOf($corps);

        $this->assertInstanceOf(Resources::class, $ressources);
        $this->assertGreaterThan(0.0, $ressources->sum(), 'Les niveaux representables comptent quand meme.');
        $this->assertTrue(is_finite($ressources->sum()), 'Le total n est plus un nombre.');

        $this->assertNotEmpty($dits, 'Le calcul s est arrete sans rien dire : un plafonnement silencieux.');
        $this->assertStringContainsString(
            LifeformRefused::COST_NOT_REPRESENTABLE,
            implode(' ', array_map(static fn ($m) => $m->message . ' ' . json_encode($m->context), $dits)),
            'Le journal ne dit pas pourquoi l objet a ete ecarte.'
        );

        // Et la page du classement repond, au lieu de tomber.
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertGreaterThan(0, resolve(HighscoreService::class)->getPlayerScoreLifeformEconomy($joueur));
        $this->get(route('highscore.index'))->assertStatus(200);
    }
}
