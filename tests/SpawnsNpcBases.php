<?php

namespace Tests;

use OGame\Factories\PlanetServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\Models\Planet\Coordinate;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\PlanetService;
use RuntimeException;

/**
 * Fait naitre une base pirate a une position **etablie**, jamais tiree au sort.
 *
 * ## Le defaut que ce trait ferme
 *
 * `NpcBaseService::createBase()` sans position tire deux cents coordonnees au hasard dans tout
 * l'univers, et n'en retient une que si un joueur humain habite la meme galaxie a bonne distance.
 * Dans une base d'essai clairsemee, dont les quelques planetes humaines sont posees par d'autres
 * essais a des endroits qui changent avec la repartition des processus, les deux cents tirages
 * echouent parfois tous — et `createBase()` rend `null`.
 *
 * Seize appels, dans cinq fichiers, affirmaient alors « une base existe » sans l'avoir etabli. Le
 * premier a tomber a ete `NpcIntegrationTest`, quand l'ajout d'autres fichiers d'essais a change ses
 * voisins de processus. Il passait par accident depuis le debut.
 *
 * ## Ce que ce trait etablit
 *
 * Une position libre, trouvee par **balayage deterministe** de systemes eloignes de ceux que les
 * fabriques et les essais de combat emploient, puis imposee a `createBase()` — qui ne verifie alors
 * que l'occupation de la case, pas la distance aux humains. Deux appels dans un meme processus
 * rendent deux cases differentes, parce que la premiere est occupee quand la seconde cherche.
 */
trait SpawnsNpcBases
{
    /**
     * La galaxie balayee.
     *
     * **La premiere, et non une lointaine** : c'est la seule dont l'existence ne depend pas du
     * nombre de galaxies regle pour l'univers d'essai. Les essais de combat posent leurs planetes en
     * galaxies 6 a 9 et les fabriques dans les systemes bas ; ici, les systemes hauts de la galaxie 1
     * ne croisent ni les uns ni les autres.
     */
    private const int SPAWN_GALAXY = UniverseConstants::MIN_GALAXY;

    /**
     * Le premier systeme balaye, loin des positions basses que les fabriques distribuent.
     */
    private const int SPAWN_FIRST_SYSTEM = 470;

    /**
     * Une base pirate, nee a une case libre trouvee par balayage.
     *
     * @throws RuntimeException Si aucune case n'est libre dans la plage balayee — ce qui dirait que
     *                          la plage est trop etroite, pas que l'univers est plein.
     */
    protected function aSpawnedBase(string $type = NpcBaseService::TYPE_PIRATE): PlanetService
    {
        $base = resolve(NpcBaseService::class)->createBase($type, $this->aFreeSpawnCoordinate());

        if ($base === null) {
            throw new RuntimeException(
                'A base could not be spawned at a coordinate that was just verified free: '
                . 'something took it between the check and the creation.'
            );
        }

        return $base;
    }

    /**
     * La premiere case libre de la plage balayee.
     */
    protected function aFreeSpawnCoordinate(): Coordinate
    {
        $fabrique = resolve(PlanetServiceFactory::class);

        for ($systeme = self::SPAWN_FIRST_SYSTEM; $systeme <= UniverseConstants::MAX_SYSTEM_COUNT; $systeme++) {
            for ($position = UniverseConstants::MIN_PLANET_POSITION; $position <= UniverseConstants::MAX_PLANET_POSITION; $position++) {
                $candidate = new Coordinate(self::SPAWN_GALAXY, $systeme, $position);

                if (!$fabrique->planetExistsAtCoordinate($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException(
            'No free coordinate in galaxy ' . self::SPAWN_GALAXY . ' from system ' . self::SPAWN_FIRST_SYSTEM
            . ' onwards: the scanned range is too narrow for this process, widen it.'
        );
    }
}
