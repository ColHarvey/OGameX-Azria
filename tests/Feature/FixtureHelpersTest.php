<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlanetService;
use stdClass;
use Tests\AccountTestCase;

/**
 * Les trois helpers qui cherchent « quelqu'un d'autre », et ce qu'ils ne doivent jamais faire.
 *
 * ## Le defaut qu'ils partageaient
 *
 * `getNearbyForeignPlanet()`, `getNearbyForeignMoon()` et `getSecondPlayerId()` combinaient deux
 * fautes :
 *
 * - **`inRandomOrder()`** : le choix changeait a chaque execution ;
 * - **un repli appelant `createAndLoginUser()`**, qui remplace l'utilisateur authentifie,
 *   `currentUserId` et la planete courante. La seconde requete excluait alors le **nouveau** joueur,
 *   et pouvait donc rendre un corps du joueur d'origine.
 *
 * C'est ainsi que `JumpGateTest::testDetectsUnprocessedArrivedFleet` echouait par intermittence : la
 * flotte « etrangere » appartenait au proprietaire de la lune, et `hasUnprocessedArrivedFleet()`
 * filtre precisement sur ce proprietaire.
 *
 * ## Pourquoi forcer le repli est la seule preuve
 *
 * Un passage vert ne prouve rien : le defaut etait intermittent, et les suites passaient souvent.
 * Chaque essai de repli **verifie d'abord qu'aucun candidat n'existe**, sans quoi il resterait vert
 * sans jamais exercer la creation qu'il pretend eprouver.
 */
class FixtureHelpersTest extends AccountTestCase
{
    /**
     * L'etat des corps celestes avant l'essai, pour le rendre tel quel.
     *
     * @var array<int, \stdClass>
     */
    private array $corpsAvant = [];

    /**
     * Les comptes presents avant l'essai.
     *
     * @var array<int, int>
     */
    private array $comptesAvant = [];

    /**
     * ## Pourquoi cet essai rend l'univers exactement comme il l'a trouve
     *
     * Il le remodele profondement : il ecarte des corps, en cree, et deplace celui du joueur. Ces
     * effets survivaient au fichier et frappaient les suivants — `NpcFactionTest` s'est mis a placer
     * une base trop pres d'une planete humaine, parce que mes creations avaient densifie les
     * systemes voisins. La fixture cassait le monde pour se prouver elle-meme.
     *
     * `RefreshDatabase` reglait cela et en creait un autre : il deplace le `migrate:fresh` au milieu
     * du passage, et `PlanetSpacingTest` — qui l'utilise aussi — tombait a son tour. Melanger deux
     * regimes de base dans une meme suite se paie ailleurs.
     *
     * La restauration explicite ne touche donc rien d'autre : on supprime ce qu'on a cree, puis on
     * rend aux lignes survivantes leurs coordonnees et leur etat. Dans cet ordre — restaurer d'abord
     * heurterait la contrainte d'unicite avec une planete creee entre-temps.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->corpsAvant = DB::table('planets')
            ->get(['id', 'galaxy', 'system', 'planet', 'destroyed'])
            ->keyBy('id')
            ->all();

        $this->comptesAvant = DB::table('users')->pluck('id')->map(static fn ($id): int => (int)$id)->all();
    }

    protected function tearDown(): void
    {
        $corpsConnus = array_keys($this->corpsAvant);

        Schema::withoutForeignKeyConstraints(function () use ($corpsConnus): void {
            DB::table('planets')->whereNotIn('id', $corpsConnus)->delete();
            DB::table('users')->whereNotIn('id', $this->comptesAvant)->delete();
        });

        foreach ($this->corpsAvant as $id => $avant) {
            DB::table('planets')->where('id', $id)->update([
                'galaxy' => $avant->galaxy,
                'system' => $avant->system,
                'planet' => $avant->planet,
                'destroyed' => $avant->destroyed,
            ]);
        }

        parent::tearDown();
    }

    /**
     * Une planete etrangere deja presente est trouvee, sans rien creer.
     */
    public function testAnExistingForeignPlanetIsFound(): void
    {
        $attendue = $this->aKnownForeignPlanet();

        $joueursAvant = $this->countUsers();

        $trouvee = $this->getNearbyForeignPlanet();

        // **Son identifiant exact**, pas « une planete plausible ». C'est ce qui distingue une
        // recherche reussie d'une creation qui aurait produit un resultat tout aussi acceptable.
        $this->assertSame($attendue, $trouvee->getPlanetId(), 'The helper did not return the known candidate.');
        $this->assertSame($joueursAvant, $this->countUsers(), 'The lookup path created a player.');

        $this->assertSame($attendue, $this->getNearbyForeignPlanet()->getPlanetId(), 'The choice is not deterministic.');
    }

    /**
     * Sans aucune planete etrangere, le repli en cree une — et ne touche a rien d'autre.
     */
    public function testTheFallbackCreatesAForeignPlanetAndChangesNothingElse(): void
    {
        $this->isolateTheCurrentPlayer();
        $this->assertNoAdmissibleCandidate(PlanetType::Planet);

        $avant = $this->context();
        $joueursAvant = $this->countUsers();

        $planete = $this->getNearbyForeignPlanet();

        $this->assertSame($joueursAvant + 1, $this->countUsers(), 'The fallback did not create exactly one player.');
        $this->assertOwnerIsNot($planete, $this->currentUserId);
        $this->assertIsInTheNearZone($planete);
        $this->assertContextUnchanged($avant);
    }

    /**
     * Une lune etrangere deja presente est trouvee, sans rien creer.
     */
    public function testAnExistingForeignMoonIsFound(): void
    {
        $attendue = $this->aKnownForeignMoon();

        $joueursAvant = $this->countUsers();

        $trouvee = $this->getNearbyForeignMoon();

        $this->assertSame($attendue, $trouvee->getPlanetId(), 'The helper did not return the known moon.');
        $this->assertTrue($trouvee->isMoon());
        $this->assertSame($joueursAvant, $this->countUsers(), 'The lookup path created a player.');

        $this->assertSame($attendue, $this->getNearbyForeignMoon()->getPlanetId(), 'The choice is not deterministic.');
    }

    /**
     * Sans aucune lune etrangere, le repli en cree une — et ne touche a rien d'autre.
     */
    public function testTheFallbackCreatesAForeignMoonAndChangesNothingElse(): void
    {
        $this->isolateTheCurrentPlayer();
        $this->assertNoAdmissibleCandidate(PlanetType::Moon);

        $avant = $this->context();
        $joueursAvant = $this->countUsers();

        $lune = $this->getNearbyForeignMoon();

        $this->assertTrue($lune->isMoon(), 'The fallback returned something that is not a moon.');
        $this->assertSame($joueursAvant + 1, $this->countUsers(), 'The fallback did not create exactly one player.');
        $this->assertOwnerIsNot($lune, $this->currentUserId);
        $this->assertIsInTheNearZone($lune);
        $this->assertContextUnchanged($avant);
    }

    /**
     * Un second joueur deja present est trouve, sans changer le contexte.
     */
    public function testAnExistingSecondPlayerIsFound(): void
    {
        $avant = $this->context();

        $premier = $this->getSecondPlayerId();
        $second = $this->getSecondPlayerId();

        $this->assertSame($premier, $second, 'The choice is not deterministic.');
        $this->assertNotSame($this->currentUserId, $premier);
        $this->assertContextUnchanged($avant);
    }

    /**
     * Le repli du second joueur, couvert sans detruire l'univers.
     *
     * ## Pourquoi cet essai ne force pas son chemin
     *
     * Forcer ce repli exige qu'il ne reste **aucun** autre compte : il faut donc les supprimer, y
     * compris l'administrateur. Une premiere version le faisait, contraintes desactivees — et
     * `MissileProtectionTest` tombait plus loin dans la suite avec « The administrator account is
     * missing from this universe ». La fixture cassait le monde pour se prouver elle-meme.
     *
     * La propriete reste couverte, et de deux facons :
     *
     * - **le chemin de creation** est celui de la planete et de la lune, deja force et verifie ;
     * - **la garde architecturale** constate que `getSecondPlayerIdFor()` y passe et n'appelle
     *   jamais `createAndLoginUser()`.
     *
     * Ce qui reste ici est ce qui s'observe sans rien detruire : le resultat exclut le joueur donne,
     * il est deterministe, et le contexte ne bouge pas.
     */
    public function testTheSecondPlayerIsExcludedDeterministicallyAndWithoutTouchingTheContext(): void
    {
        $avant = $this->context();

        $premier = $this->getSecondPlayerIdFor($this->currentUserId);
        $second = $this->getSecondPlayerIdFor($this->currentUserId);

        $this->assertSame($premier, $second, 'The choice is not deterministic.');
        $this->assertNotSame($this->currentUserId, $premier);
        $this->assertContextUnchanged($avant);

        // Et l'exclusion suit bien le parametre : en excluant le joueur trouve, on ne le retrouve pas.
        $this->assertNotSame($premier, $this->getSecondPlayerIdFor($premier));
    }

    /**
     * Le joueur exclu est bien le parametre, pas le contexte courant.
     *
     * ## L'essai qui prouve que le nouveau parametre est la source de verite
     *
     * A reste authentifie, mais on demande explicitement d'exclure B. Le resultat doit exclure **B**,
     * et il peut parfaitement appartenir a A. Un helper qui relirait silencieusement `currentUserId`
     * exclurait A et pourrait rendre B — exactement l'inverse.
     */
    public function testTheExcludedPlayerIsTheParameterAndNotTheCurrentContext(): void
    {
        // **B doit etre le seul candidat**, sinon l'essai ne mord pas : une premiere version se
        // contentait de demander d'exclure B au milieu de plusieurs etrangers. Un helper fautif, qui
        // aurait exclu A par habitude, rendait alors la planete d'un troisieme joueur — et
        // l'assertion passait. La mutation a survecu, et c'est ainsi qu'on l'a su.
        //
        // En ne laissant que B, les deux comportements divergent forcement : exclure B correctement
        // ne laisse aucun candidat et oblige a creer quelqu'un d'autre ; exclure A par erreur rend la
        // planete de B.
        $planeteDeB = $this->aKnownForeignPlanet();
        $b = (int)DB::table('planets')->where('id', $planeteDeB)->value('user_id');

        $avant = $this->context();

        $planete = $this->getNearbyForeignPlanetFor($b);

        // **Le resultat doit appartenir a A**, et c'est le point de l'essai : exclure B ne rend pas
        // A intouchable. Un helper qui relirait `currentUserId` exclurait A et rendrait la planete de
        // B — exactement l'inverse de ce qu'on lui demande.
        $this->assertNotSame($planeteDeB, $planete->getPlanetId(), 'The helper returned the very body it was asked to exclude.');
        $this->assertOwnerIsNot($planete, $b);

        // On n affirme pas que le resultat appartient a A : sur une base fraiche, le crochet du
        // modele promeut le premier compte en administrateur, et le helper ecarte les
        // administrateurs. A peut donc etre inadmissible pour une raison qui n a rien a voir avec
        // l exclusion demandee.
        //
        // La dent de cet essai est ailleurs, et elle suffit : un helper qui excluerait `currentUserId`
        // au lieu du parametre rendrait la planete de B, seule candidate restante.

        $this->assertContextUnchanged($avant);
    }

    /**
     * La plus proche gagne, pas la plus petite en numero de systeme.
     *
     * ## La regression que cet essai verrouille
     *
     * Un premier correctif triait par `galaxy, system, position, id`. Deterministe, oui — mais il
     * choisissait systematiquement le **bord inferieur** de la fenetre de quinze systemes, donc la
     * planete la plus **eloignee**. Le helper s'appelle « nearby » ; il etait devenu « le plus petit
     * numero de systeme ».
     *
     * `FleetMissionChainTest` l'a revele : la cible retenue offrait moins de missions, et le lot de
     * 317 essais est tombe.
     */
    public function testTheNearestWinsRatherThanTheLowestSystemNumber(): void
    {
        $this->isolateTheCurrentPlayer();
        $this->assertNoAdmissibleCandidate(PlanetType::Planet);

        // **A doit sieger assez loin de la borne 1.** Le montage exige un systeme de numero plus
        // petit **et** plus eloigne ; sur une base fraiche A nait au systeme 1, ou un tel systeme
        // n'existe pas. On l'installe donc a un systeme confortable — son identifiant de planete ne
        // change pas, et aucun autre essai ne depend de ses coordonnees.
        //
        // Le systeme est **cherche libre**, pas suppose : une valeur fixe heurtait la contrainte
        // d'unicite des le moment ou la base contenait deja des planetes.
        $depart = $this->planetService->getPlanetCoordinates();
        $vise = $this->aFreeSystemFor($depart->galaxy, $depart->position, 30);

        DB::table('planets')
            ->where('id', $this->planetService->getPlanetId())
            ->update(['system' => $vise]);
        $this->planetService->reloadPlanet();

        $ici = $this->planetService->getPlanetCoordinates();
        $this->assertSame($vise, $ici->system);

        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire);

        // Celle-ci porte le plus petit numero de systeme, et elle est la plus loin.
        $loin = $this->placeAForeignPlanetAt($proprietaire->getId(), $ici->system - 12);

        // Celle-la porte un numero plus grand, et elle est la plus proche.
        $pres = $this->placeAForeignPlanetAt($proprietaire->getId(), $ici->system + 2);

        // **Le repli cree deux planetes, pas une** : la planete mere du nouveau joueur, puis celle
        // qu'on lui pose a portee. Les laisser fausserait la comparaison — la planete mere s'est
        // trouvee plus proche que mes deux candidates lors du premier passage, et l'essai accusait le
        // helper d'un choix qui etait le bon. On ne garde donc que les deux candidates voulues.
        DB::table('planets')
            ->where('user_id', '!=', $this->currentUserId)
            ->whereNotIn('id', [$loin, $pres])
            ->update(['destroyed' => 1]);

        $this->assertHasAnAdmissibleCandidate($loin);
        $this->assertHasAnAdmissibleCandidate($pres);

        $choisie = $this->getNearbyForeignPlanet();

        $this->assertSame(
            $pres,
            $choisie->getPlanetId(),
            'The helper chose the lowest system number instead of the nearest body, which is what its name promises.'
        );
        $this->assertNotSame($loin, $choisie->getPlanetId());
    }

    /**
     * Le scenario JumpGate, avec le repli force.
     *
     * ## Ce que cet essai prouve, et que les suites vertes ne prouvaient pas
     *
     * Il **oblige** le repli a s'exercer, puis verifie que le proprietaire rendu est bien etranger au
     * joueur exclu. Si le repli redevenait `createAndLoginUser()`, la planete rendue pourrait
     * appartenir au proprietaire de la lune, et `hasUnprocessedArrivedFleet()` — qui filtre sur ce
     * proprietaire — ne verrait plus la flotte. C'est exactement l'echec intermittent d'origine.
     */
    public function testTheJumpGateScenarioWithAForcedFallback(): void
    {
        $this->isolateTheCurrentPlayer();
        $this->assertNoAdmissibleCandidate(PlanetType::Planet);

        $avant = $this->context();
        $joueursAvant = $this->countUsers();

        $etrangere = $this->getNearbyForeignPlanetFor($this->currentUserId);
        $proprietaireEtranger = $etrangere->getPlayer();

        $this->assertNotNull($proprietaireEtranger);
        $this->assertSame($joueursAvant + 1, $this->countUsers());
        $this->assertNotSame(
            $this->currentUserId,
            $proprietaireEtranger->getId(),
            'The « foreign » planet belongs to the very player the jump gate test needs to exclude.'
        );
        $this->assertIsInTheNearZone($etrangere);
        $this->assertContextUnchanged($avant);
    }

    /**
     * Garde **architecturale** sur les helpers de fixture.
     *
     * ## Ce qu'elle est, et ce qu'elle n'est pas
     *
     * Ce n'est **pas une preuve de determinisme**. La preuve du comportement, ce sont les essais
     * ci-dessus : identifiant exact rendu, contexte inchange, plus proche gagnant. Cette garde-ci
     * empeche seulement le retour d'une construction dont la mutation ne se tue pas en un passage —
     * remettre `inRandomOrder()` pourrait, par chance, designer le bon candidat et laisser la suite
     * verte.
     *
     * Elle porte sur un seul fichier. Rien n'interdit a un futur essai probabiliste d'exister
     * ailleurs, pourvu qu'il soit reproductible et justifie.
     *
     * Les commentaires sont retires avant l'examen : ce fichier et `AccountTestCase` expliquent tous
     * deux le defaut, et le mot y figure a dessein.
     */
    public function testTheArchitecturalGuardOnTheFixtureHelpers(): void
    {
        $source = file_get_contents(base_path('tests/AccountTestCase.php'));

        $this->assertIsString($source);

        $code = '';

        foreach (token_get_all($source) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        $this->assertStringNotContainsString(
            'inRandomOrder',
            $code,
            'A fixture helper draws lots again: its choice would stop being reproducible.'
        );

        // **Les trois chemins de repli**, directement ou par leur fabrique commune. La lune et le
        // second joueur passent par la fabrique de planete ; le controle le constate au lieu de le
        // supposer, sans quoi deplacer la creation ailleurs rendrait la garde muette.
        $this->assertStringNotContainsString(
            'createAndLoginUser',
            $this->methodBodyOf($code, 'createNearbyPlanetForANewPlayer'),
            'The shared fallback factory logs a user in again, which changes who is playing.'
        );

        foreach (['createNearbyMoonForANewPlayer', 'getSecondPlayerIdFor'] as $chemin) {
            $corps = $this->methodBodyOf($code, $chemin);

            $this->assertStringNotContainsString(
                'createAndLoginUser',
                $corps,
                'The fallback of ' . $chemin . ' logs a user in again.'
            );

            $this->assertStringContainsString(
                'createNearbyPlanetForANewPlayer',
                $corps,
                'The fallback of ' . $chemin . ' no longer goes through the shared factory, so the guard '
                . 'above no longer covers it.'
            );
        }
    }

    /**
     * Le corps d'une methode, dans une source deja depouillee de ses commentaires.
     */
    private function methodBodyOf(string $code, string $method): string
    {
        $debut = strpos($code, 'function ' . $method);

        $this->assertNotFalse($debut, 'The method ' . $method . ' has disappeared from the fixture.');

        $suivante = strpos($code, 'function ', $debut + 1);

        return $suivante === false
            ? substr($code, $debut)
            : substr($code, $debut, $suivante - $debut);
    }

    /**
     * L'etat de contexte qui ne doit jamais bouger.
     *
     * `auth()->id()` rend `int|string|null` : la cle d'un authentifiable n'est pas forcement un
     * entier. Le type le dit plutot qu'une conversion ne l'efface — comparer les deux photos
     * telles quelles est exactement ce qu'on veut.
     *
     * @return array<string, int|string|null>
     */
    private function context(): array
    {
        return [
            'currentUserId' => $this->currentUserId,
            'authenticated' => auth()->id(),
            'currentPlanet' => $this->planetService->getPlanetId(),
        ];
    }

    /**
     * @param array<string, int|string|null> $avant
     */
    private function assertContextUnchanged(array $avant): void
    {
        $this->assertSame($avant, $this->context(), 'The fixture changed who is playing, which is never its job.');
    }

    private function assertOwnerIsNot(PlanetService $body, int $excludedPlayerId): void
    {
        $proprietaire = $body->getPlayer();

        $this->assertNotNull($proprietaire);
        $this->assertNotSame($excludedPlayerId, $proprietaire->getId());
    }

    /**
     * Le corps est bien dans la fenetre que le helper promet.
     */
    private function assertIsInTheNearZone(PlanetService $body): void
    {
        $ici = $this->planetService->getPlanetCoordinates();
        $la = $body->getPlanetCoordinates();

        $this->assertSame($ici->galaxy, $la->galaxy, 'The « nearby » body is in another galaxy.');
        $this->assertLessThanOrEqual(15, abs($ici->system - $la->system), 'The « nearby » body is outside the window.');
    }

    /**
     * Chaque corps etranger est ecarte, et pour une raison **nommee**.
     *
     * ## Pourquoi cette precondition ne rejoue pas la requete du helper
     *
     * Une premiere version recopiait le filtre clause par clause — meme galaxie, meme fenetre, memes
     * exclusions d'administrateurs — et comptait le resultat. Deux implementations identiques
     * derivent ensemble : le jour ou le helper se tromperait de clause, la precondition se
     * tromperait pareil et confirmerait l'erreur. Ce n'est pas une preuve independante, c'est un
     * echo.
     *
     * Elle enumere donc les corps et constate, pour chacun, **le fait** qui l'ecarte : detruit,
     * dans une autre galaxie, ou hors de la fenetre. Trois faits que la fixture a elle-meme
     * construits.
     */
    private function assertNoAdmissibleCandidate(PlanetType $bodyType): void
    {
        $ici = $this->planetService->getPlanetCoordinates();
        $administrateurs = $this->getAdminUserIds();
        $restants = [];

        foreach ($this->foreignBodies($bodyType) as $corps) {
            // Quatre faits ecartent un corps, et le quatrieme compte autant que les trois autres :
            // le helper exclut les comptes administrateurs et systeme. Ne pas le constater ici
            // laisserait croire a un repli force alors qu'un corps d'administrateur reste, sans
            // qu'aucun essai ne le voie.
            $ecarte = (int)$corps->destroyed === 1
                || (int)$corps->galaxy !== $ici->galaxy
                || abs((int)$corps->system - $ici->system) > 15
                || in_array((int)$corps->user_id, $administrateurs, true);

            if (!$ecarte) {
                $restants[] = $corps->id . ' at ' . $corps->galaxy . ':' . $corps->system;
            }
        }

        $this->assertSame(
            [],
            $restants,
            'A reachable foreign body remains, so this test would stay green without ever exercising the fallback.'
        );
    }

    /**
     * Ce corps satisfait, fait par fait, ce que la fixture promet.
     */
    private function assertHasAnAdmissibleCandidate(int $bodyId): void
    {
        $ici = $this->planetService->getPlanetCoordinates();
        $corps = DB::table('planets')->where('id', $bodyId)->first();

        $this->assertNotNull($corps);
        $this->assertNotSame($this->currentUserId, (int)$corps->user_id, 'The known candidate belongs to the current player.');
        $this->assertNotContains(
            (int)$corps->user_id,
            $this->getAdminUserIds(),
            'The known candidate belongs to an administrator, whom the helper excludes.'
        );
        $this->assertSame(0, (int)$corps->destroyed, 'The known candidate is destroyed.');
        $this->assertSame($ici->galaxy, (int)$corps->galaxy, 'The known candidate is in another galaxy.');
        $this->assertLessThanOrEqual(15, abs((int)$corps->system - $ici->system), 'The known candidate is outside the window.');
    }

    /**
     * Les corps etrangers d'un type donne, tels quels.
     *
     * @param PlanetType $bodyType
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function foreignBodies(PlanetType $bodyType): \Illuminate\Support\Collection
    {
        return DB::table('planets')
            ->where('user_id', '!=', $this->currentUserId)
            ->where('planet_type', $bodyType)
            ->get(['id', 'user_id', 'galaxy', 'system', 'destroyed']);
    }

    /**
     * Une planete etrangere unique, connue par son identifiant et ses coordonnees.
     */
    private function aKnownForeignPlanet(): int
    {
        $this->isolateTheCurrentPlayer();

        $creee = $this->getNearbyForeignPlanet();
        $identifiant = $creee->getPlanetId();

        // Le repli cree une planete mere **et** celle qu'on pose a portee : on ne garde que celle-ci,
        // pour que le helper n'ait qu'un seul choix possible.
        DB::table('planets')
            ->where('user_id', '!=', $this->currentUserId)
            ->whereNot('id', $identifiant)
            ->update(['destroyed' => 1]);

        $this->assertHasAnAdmissibleCandidate($identifiant);

        return $identifiant;
    }

    /**
     * Une lune etrangere unique, connue par son identifiant et ses coordonnees.
     */
    private function aKnownForeignMoon(): int
    {
        $this->isolateTheCurrentPlayer();

        $creee = $this->getNearbyForeignMoon();
        $identifiant = $creee->getPlanetId();

        DB::table('planets')
            ->where('user_id', '!=', $this->currentUserId)
            ->where('planet_type', PlanetType::Moon)
            ->whereNot('id', $identifiant)
            ->update(['destroyed' => 1]);

        $this->assertHasAnAdmissibleCandidate($identifiant);

        return $identifiant;
    }

    /**
     * Ecarte tous les corps etrangers de la fenetre de recherche, sans rien detruire.
     *
     * ## Pourquoi une autre galaxie plutot qu'une suppression
     *
     * Le deplacement preserve chaque ligne, son proprietaire et son contenu. Il preserve aussi la
     * **relation planete/lune** : les deux lignes partagent `system` et `position`, et les deplacer
     * ensemble d'une galaxie garde cette egalite. Une suppression casserait des references.
     *
     * Le corps courant de A n'est jamais deplace, et l'absence de collision est verifiee : deux corps
     * du meme type ne peuvent pas se retrouver sur la meme coordonnee.
     */
    private function isolateTheCurrentPlayer(): void
    {
        // **On ecarte, on ne deplace pas.** Un premier montage rassemblait tous les corps etrangers
        // dans une autre galaxie : deux d'entre eux se retrouvaient alors sur la meme coordonnee,
        // et le controle de collision que j'avais ajoute l'a montre tout de suite. Marquer les corps
        // comme detruits les sort du filtre du helper — qui exige `destroyed = 0` — sans toucher a
        // une seule coordonnee, donc sans collision possible et sans casser la relation
        // planete/lune.
        //
        // Le corps courant de A n'est jamais concerne.
        DB::table('planets')
            ->where('user_id', '!=', $this->currentUserId)
            ->update(['destroyed' => 1]);
    }

    /**
     * Pose une planete du joueur donne dans un systeme choisi, et rend son identifiant.
     */
    private function placeAForeignPlanetAt(int $ownerId, int $system): int
    {
        $ici = $this->planetService->getPlanetCoordinates();
        $joueur = resolve(PlayerServiceFactory::class)->make($ownerId);

        $position = 4;

        while ($this->coordinateIsTaken($ici->galaxy, $system, $position)) {
            $position++;

            if ($position > 15) {
                $this->fail('No free position left in system ' . $system . ' for the proximity fixture.');
            }
        }

        return resolve(PlanetServiceFactory::class)
            ->createAdditionalPlanetForPlayer($joueur, new Coordinate($ici->galaxy, $system, $position))
            ->getPlanetId();
    }

    /**
     * La position d'un corps s'appelle `planet` en base, pas `position`.
     *
     * Une premiere version interrogeait `position` : la clause ne correspondait a aucune colonne,
     * le controle ne trouvait **jamais** de collision, et la creation heurtait ensuite la contrainte
     * d'unicite. Le nom de la colonne se lit dans la migration, pas dans l'intuition.
     */
    private function coordinateIsTaken(int $galaxy, int $system, int $position): bool
    {
        return DB::table('planets')
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('planet', $position)
            ->where('planet_type', PlanetType::Planet)
            ->exists();
    }

    /**
     * Un systeme ou la position donnee est libre, a partir d'un point de depart.
     */
    private function aFreeSystemFor(int $galaxy, int $position, int $from): int
    {
        for ($systeme = $from; $systeme < $from + 100; $systeme++) {
            if (!$this->coordinateIsTaken($galaxy, $systeme, $position)) {
                return $systeme;
            }
        }

        $this->fail('No free system found to place the current player for the proximity fixture.');
    }

    private function countUsers(): int
    {
        return DB::table('users')->count();
    }
}
