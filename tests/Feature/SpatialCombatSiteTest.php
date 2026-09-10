<?php

namespace Tests\Feature;

use OGame\Combat\Support\CombatParticipantKey;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Resources;
use OGame\Patrol\Combat\SpatialCombatSite;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\SettingsService;
use ReflectionClass;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * Le lieu d'un combat en espace libre repond « rien » a tout ce que le moteur lui demande.
 *
 * ## Ce que ce temoin ferme
 *
 * `BattleEngine` interroge son defenseur sur des choses qui n'existent pas dans le vide : une
 * garnison, une lune, un stock a piller, un chantier spatial. `SpatialCombatSite` repond a chacune,
 * et la seule facon de ne pas s'en apercevoir serait qu'une methode **non redefinie** soit appelee :
 * la propriete `$planet` du parent n'est alors pas initialisee, et PHP leve une `Error`.
 *
 * Le dernier essai de cette classe est donc le plus important : il **inventorie les appels du
 * moteur** et exige que chacun soit couvert ici. Un appel ajoute demain a `BattleEngine` fait
 * rougir cet essai avant d'atteindre un joueur.
 */
class SpatialCombatSiteTest extends AccountTestCase
{
    private function aSite(int $galaxie = 3, int $systeme = 128, int $x = 420, int $y = -260): SpatialCombatSite
    {
        $proprietaire = $this->planetService->getPlayer();
        $this->assertNotNull($proprietaire, 'The test planet has no owner.');

        return new SpatialCombatSite(
            resolve(PlayerServiceFactory::class),
            resolve(SettingsService::class),
            $proprietaire,
            new SpatialPoint($x, $y),
            $galaxie,
            $systeme,
        );
    }

    /**
     * Le proprietaire est celui qu'on donne — jamais celui d'un corps.
     *
     * Une patrouille garde son proprietaire meme si sa base d'attache change de mains, et peut
     * n'avoir aucune base du tout (`patrols.home_planet_id` est nullable). Deduire le joueur d'une
     * planete aurait donc echoue deux fois, et silencieusement la premiere.
     */
    public function testTheOwnerIsTheOneGivenAndComesFromNoBody(): void
    {
        $site = $this->aSite();

        // Pas de `?->` : le site resserre le type de retour, et un point spatial a toujours un
        // proprietaire. Le lire prudemment ici suggererait l'inverse.
        $this->assertSame(
            $this->currentUserId,
            $site->getPlayer()->getId(),
            'The spatial site does not defend for the player it was built for.'
        );
    }

    /**
     * Rien au sol : ni garnison, ni defense, ni batiment, ni stock.
     */
    public function testNothingStandsAtASpatialPoint(): void
    {
        $site = $this->aSite();

        $this->assertSame(0, $site->getShipUnits()->getAmount(), 'A spatial point holds a garrison.');
        $this->assertSame(0, $site->getDefenseUnits()->getAmount(), 'A spatial point holds defences.');
        $this->assertSame(0, $site->getObjectLevel('space_dock'), 'A spatial point has a space dock.');
        $this->assertSame(0, $site->getObjectAmount('light_fighter'), 'A spatial point stores ships.');
        // `sum()` rend un flottant : comparer a l'entier zero echouerait sur le type, pas sur la
        // valeur, et le message parlerait de ressources la ou le defaut serait ailleurs.
        $this->assertSame(0.0, $site->getResources()->sum(), 'A spatial point stores resources to loot.');
        $this->assertFalse($site->isMoon(), 'A spatial point is a moon.');
        $this->assertFalse($site->hasMoon(), 'A spatial point carries a moon.');
        $this->assertFalse($site->isDestroyed(), 'A spatial point can be destroyed.');
    }

    /**
     * La garnison d'un combat spatial porte un nom reserve, jamais celui d'un corps reel.
     *
     * C'est ce qui rend inoffensif le fait que le moteur nomme une garnison meme ici : la clef
     * `body:unidentified` n'appartient a aucune ligne, et cette garnison ne perd rien puisqu'elle
     * n'a aucune unite.
     */
    public function testTheGarrisonKeyOfASpatialCombatBelongsToNoBody(): void
    {
        $site = $this->aSite();

        $this->assertSame(0, $site->getPlanetId(), 'A spatial point claims a body identifier.');
        $this->assertSame(
            CombatParticipantKey::UNIDENTIFIED_BODY,
            CombatParticipantKey::forBody($site),
            'The garrison of a free-space combat took the key of a real body.'
        );
    }

    /**
     * **La position vaut zero, et ce n'est pas un defaut.**
     *
     * Un point libre n'occupe aucune des quinze positions du systeme. Lui en attribuer une ferait
     * tomber les debris de son combat dans le champ de la planete qui l'occupe — exactement ce que
     * la revue 120 interdit : les debris spatiaux s'adressent explicitement, et ne se fondent pas
     * dans le champ d'une position.
     */
    public function testAPointOccupiesNoPositionOfTheSystem(): void
    {
        $site = $this->aSite(galaxie: 3, systeme: 128, x: 420, y: -260);
        $coordonnees = $site->getPlanetCoordinates();

        $this->assertSame(3, $coordonnees->galaxy);
        $this->assertSame(128, $coordonnees->system);
        $this->assertSame(
            0,
            $coordonnees->position,
            'A spatial point claims a planetary position, so its debris would land in that planet field.'
        );

        $this->assertSame('3:128:420/-260', $site->getPlanetName(), 'The site does not name itself by its point.');
        $this->assertSame(420, $site->point()->x);
        $this->assertSame(-260, $site->point()->y);
    }

    /**
     * Un debit non nul sur le vide est une contradiction, et elle se dit.
     *
     * Le moteur debite le defenseur du deuterium d'une retraite tactique. En espace libre il n'y a
     * pas de sol vers lequel fuir : atteindre ce chemin avec un montant signifierait qu'une regle
     * de fuite s'applique la ou elle n'a pas de sens, et le silence la rendrait invisible.
     */
    public function testPayingFromNowhereIsRefusedButAskingForNothingIsFine(): void
    {
        $site = $this->aSite();

        // Zero passe : le moteur appelle ce chemin meme quand la retraite ne coute rien.
        $site->deductResources(new Resources(0, 0, 0, 0));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nothing is stored in free space/');

        $site->deductResources(new Resources(0, 0, 900, 0));
    }

    /**
     * **La garde : tout ce que le combat demande a son defenseur est redefini ici.**
     *
     * Sans elle, un appel ajoute demain tomberait sur une methode heritee qui lit une planete
     * inexistante. La panne serait bruyante — donc jamais un resultat faux — mais elle arriverait
     * en jeu plutot qu'ici.
     *
     * ## Pourquoi trois fichiers, et pas seulement le moteur
     *
     * La premiere version de cette garde ne lisait que `BattleEngine`, et elle etait trop etroite :
     * le moteur ne se contente pas d'interroger son defenseur, il le **transmet**. Il le passe au
     * service de retraite tactique, et a la fabrique de clef de participant. Une mutation l'a
     * montre — retirer une redefinition laissait la garde verte.
     *
     * L'inventaire porte donc sur les trois endroits ou une planete defenseuse est lue, chacun avec
     * le nom sous lequel elle y vit. Il se fait sur la **forme du code** — la variable suivie de sa
     * fleche et d'une parenthese —, jamais sur un mot : un nom de methode apparait aussi dans les
     * commentaires.
     *
     * La garde reste bornee, et le dit : elle couvre les chemins qu'un combat en espace libre
     * emprunte reellement. Un service nouveau qui recevrait le defenseur devrait etre ajoute ici,
     * et rien ne le rappellera automatiquement.
     */
    public function testEveryCallMadeOnADefenderPlanetIsAnsweredHere(): void
    {
        $racine = dirname(__DIR__, 2);

        // **Une planete defenseuse vit sous plusieurs noms de variable dans un meme fichier**, et
        // c'est une mutation qui l'a appris : retirer la redefinition des coordonnees laissait la
        // garde verte parce que le service de retraite les demande a `$planet` dans une methode
        // interne, alors que la garde ne lisait que `$defenderPlanet`. Chaque nom est donc declare.
        $chemins = [
            'app/GameMissions/BattleEngine/BattleEngine.php' => ['this->defenderPlanet', 'spaceDockPlanet'],
            'app/GameMissions/BattleEngine/Services/TacticalRetreatService.php' => ['defenderPlanet', 'planet'],
            'app/Combat/Support/CombatParticipantKey.php' => ['body'],
        ];

        $demandees = [];

        foreach ($chemins as $relatif => $variables) {
            $source = file_get_contents($racine . '/' . $relatif);

            $this->assertIsString($source, $relatif . ' could not be read: the guard would measure nothing.');

            foreach ($variables as $variable) {
                $motif = '/\$' . preg_quote($variable, '/') . '->([A-Za-z_][A-Za-z0-9_]*)\(/';
                $trouves = preg_match_all($motif, $source, $m);

                $this->assertGreaterThan(
                    0,
                    $trouves,
                    'No call on « $' . $variable . ' » was found in ' . $relatif . ': either it was renamed, '
                    . 'or this file no longer reads a defender — and the guard is measuring nothing there.'
                );

                $demandees = array_merge($demandees, $m[1]);
            }
        }

        $demandees = array_values(array_unique($demandees));
        sort($demandees);

        $reflexion = new ReflectionClass(SpatialCombatSite::class);
        $couvertes = [];

        foreach ($reflexion->getMethods() as $methode) {
            if ($methode->getDeclaringClass()->getName() === SpatialCombatSite::class) {
                $couvertes[] = $methode->getName();
            }
        }

        $manquantes = array_values(array_diff($demandees, $couvertes));

        $this->assertSame(
            [],
            $manquantes,
            'A defender planet is asked these, and a spatial point does not answer them: '
            . implode(', ', $manquantes) . '. An inherited method would read a planet that does not exist.'
        );
    }
}
