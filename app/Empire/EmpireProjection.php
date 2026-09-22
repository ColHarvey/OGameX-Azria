<?php

namespace OGame\Empire;

use Illuminate\Support\Facades\Date;
use OGame\Facades\AppUtil;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Presentation\LifeformBanner;
use OGame\Lifeforms\Presentation\LifeformBonusPage;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Services\LifeformResearchService;
use OGame\Lifeforms\Species;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;
use OGame\Services\UnitQueueService;

/**
 * **La vue Empire : une colonne par corps, une ligne par objet, une seule photographie.**
 *
 * Cette classe ne rend que des donnees — exactement la charge utile que le code client de la vue Empire attend
 * (`createImperiumHtml`) — et **n ecrit rien**. Elle est le seul endroit ou la page se decide ; la vue n est qu une
 * enveloppe, et le navigateur ne recalcule rien.
 *
 * ## Un seul instant
 *
 * Les projections du jeu lisent l horloge elles-memes : `updateResources()` et `LifeformBanner::planetFigures()`
 * appellent `Date::now()` a chaque appel. En boucle sur quatorze corps, cela ferait quatorze instants, et le total
 * additionnerait des valeurs qui n ont jamais coexiste. L instant est donc **pris une fois** par l appelant et passe
 * a chaque colonne : `updateResourcesUntil($at, false)` pour les ressources, `planetFigures(..., $at)` pour les formes
 * de vie. C est cet instant que la page affiche.
 *
 * ## Deux portees, jamais melangees
 *
 * Les niveaux de batiments appartiennent a la planete ; **la recherche classique et les effets des technologies de
 * forme de vie appartiennent au compte**. Ils sont donc lus **une fois** — pas une fois par colonne — et le total ne
 * les additionne pas : la recherche s affiche telle quelle (le code client lit deja `planets[0]` pour ce groupe), et
 * les effets de forme de vie figurent une seule fois, en bas du groupe.
 *
 * ## Ce que le client injecte sans l echapper
 *
 * `createPlanetsHtml` concatene `planet.name` dans du HTML **et** dans un attribut `title`, sans echappement, et
 * `setPlanetName()` n impose aucune validation : un nom de planete peut contenir n importe quoi. Tout ce qui vient du
 * joueur est donc echappe **ici**, a la source.
 *
 * @phpstan-type Cellule array{html: string, title: string}
 */
class EmpireProjection
{
    /**
     * Les groupes de lignes, dans l ordre ou la page les montre.
     */
    private const GROUPS = ['resources', 'supply', 'station', 'research', 'shipyard', 'defense', 'lifeforms'];

    /**
     * Les quatre lignes de ressources. Elles portent un prefixe : la clef `energy` est lue par le code client pour
     * l en-tete de colonne, et une ligne du meme nom l ecraserait.
     */
    private const RESOURCE_KEYS = ['res_metal', 'res_crystal', 'res_deuterium', 'res_energy'];

    /**
     * Les prix deja calcules pendant cette photographie. Voir `priceOf()` pour ce que la clef garantit.
     *
     * @var array<string, Resources>
     */
    private array $prices = [];

    public function __construct(
        private readonly BuildingQueueService $buildingQueue,
        private readonly ResearchQueueService $researchQueue,
        private readonly UnitQueueService $unitQueue,
        private readonly LifeformBanner $lifeformBanner,
        private readonly LifeformInstallationService $lifeformInstallation,
        private readonly LifeformLevels $lifeformLevels,
        private readonly LifeformResearchService $lifeformResearch,
        private readonly LifeformQueueService $lifeformQueue,
        private readonly LifeformBonusResolver $lifeformBonuses,
        private readonly LifeformBonusPage $lifeformEffects,
    ) {
    }

    /**
     * La photographie complete d un onglet.
     *
     * @param PlayerService $player le joueur connecte — **ses corps, et rien d autre**
     * @param bool $moons l onglet des lunes plutot que celui des planetes
     * @param int $at l instant de la photographie, pris une fois par l appelant
     * @return array<string, mixed>
     */
    public function of(PlayerService $player, bool $moons, int $at): array
    {
        $bodies = $moons ? $player->planets->allMoons() : $player->planets->allPlanets();
        $bodies = $this->inPlayerOrder($player, $bodies, $moons);

        $species = $this->lifeformInstallation->speciesOf($player->getId());
        $objects = $this->objectsPerGroup($moons);
        $lifeformObjects = $species === null
            ? ['buildings' => [], 'technologies' => []]
            : ['buildings' => LifeformCatalogue::buildingsOf($species), 'technologies' => LifeformCatalogue::technologiesOf($species)];

        // Le compte, lu une fois : la recherche classique et les technologies qui contribuent reellement.
        $research = $this->accountResearch($player);
        $contributing = $this->contributingTechnologies($player);

        $columns = [];
        $buildingLevels = [];
        $activeCount = [];

        foreach ($bodies as $body) {
            $column = $this->column($body, $moons, $at, $objects, $research, $species, $lifeformObjects, $contributing);

            foreach ($lifeformObjects['buildings'] as $object) {
                $buildingLevels[$object->id][] = $column['levels'][$object->id] ?? 0;
            }
            foreach ($lifeformObjects['technologies'] as $object) {
                if (($column['active'][$object->id] ?? false) === true) {
                    $activeCount[$object->id] = ($activeCount[$object->id] ?? 0) + 1;
                }
            }

            unset($column['levels'], $column['active']);
            $columns[] = $column;
        }

        return [
            'taken_at' => $at,
            'taken_at_formatted' => Date::createFromTimestamp($at)->format('H:i:s'),
            'moons' => $moons,
            'moon_count' => count($player->planets->allMoons()),
            'planet_count' => count($player->planets->allPlanets()),
            'order' => array_map(static fn (PlanetService $body): int => $body->getPlanetId(), $bodies),
            'translations' => $this->translations($objects, $lifeformObjects),
            'groups' => $this->groupKeys($objects, $lifeformObjects, $species),
            'planets' => $columns,
            'summary' => $this->summary($player, $bodies, $lifeformObjects, $buildingLevels, $activeCount, $species),
        ];
    }

    /**
     * Les corps du joueur, dans l ordre qu il a choisi. Un identifiant inconnu de l ordre enregistre est ignore, et
     * un corps absent de cet ordre vient a la fin : l ordre est une preference, il ne peut ni cacher un corps ni en
     * inventer un.
     *
     * @param array<int, PlanetService> $bodies
     * @return array<int, PlanetService>
     */
    private function inPlayerOrder(PlayerService $player, array $bodies, bool $moons): array
    {
        $order = EmpireOrder::of($player->getUser(), $moons);
        if ($order === []) {
            return array_values($bodies);
        }

        $rank = array_flip($order);
        $sorted = array_values($bodies);
        usort($sorted, static function (PlanetService $a, PlanetService $b) use ($rank): int {
            $ra = $rank[$a->getPlanetId()] ?? PHP_INT_MAX;
            $rb = $rank[$b->getPlanetId()] ?? PHP_INT_MAX;

            return $ra === $rb ? $a->getPlanetId() <=> $b->getPlanetId() : $ra <=> $rb;
        });

        return $sorted;
    }

    /**
     * Les objets de chaque groupe, filtres par le genre de corps : une lune ne porte ni mine ni chantier.
     *
     * @return array<string, array<int, GameObject>>
     */
    private function objectsPerGroup(bool $moons): array
    {
        $type = $moons ? PlanetType::Moon : PlanetType::Planet;
        $keep = static function (array $objects) use ($type): array {
            return array_values(array_filter($objects, static function (GameObject $object) use ($type): bool {
                return $object->valid_planet_types === [] || in_array($type, $object->valid_planet_types, true);
            }));
        };

        return [
            'supply' => $keep(ObjectService::getBuildingObjects()),
            'station' => $keep(ObjectService::getStationObjects()),
            'research' => $keep(ObjectService::getResearchObjects()),
            'shipyard' => $keep(ObjectService::getShipObjects()),
            'defense' => $keep(ObjectService::getDefenseObjects()),
        ];
    }

    /**
     * La recherche du compte : niveaux, element en cours et elements en file. **Une seule lecture**, quel que soit le
     * nombre de colonnes — la requete du service joint les comptes et rendrait N fois la meme chose.
     *
     * @return array{levels: array<string, int>, building: string|null, target: int, queued: array<string, bool>}
     */
    private function accountResearch(PlayerService $player): array
    {
        $levels = [];
        foreach (ObjectService::getResearchObjects() as $object) {
            $levels[$object->machine_name] = $player->getResearchLevel($object->machine_name);
        }

        $first = $player->planets->first();
        if ($first === null) {
            return ['levels' => $levels, 'building' => null, 'target' => 0, 'queued' => []];
        }

        $queue = $this->researchQueue->retrieveQueue($first);
        $current = $queue->getCurrentlyBuildingFromQueue();
        $queued = [];
        foreach ($queue->getQueuedFromQueue() as $item) {
            $queued[$item->object->machine_name] = true;
        }

        return [
            'levels' => $levels,
            'building' => $current?->object->machine_name,
            'target' => $current === null ? 0 : $current->level_target,
            'queued' => $queued,
        ];
    }

    /**
     * Les technologies de forme de vie qui **contribuent reellement**, par planete. Lues dans le detail du resolveur :
     * une technologie posee sur un emplacement ferme n y figure pas. On ne le deduit pas d un niveau.
     *
     * @return array<int, array<int, true>> planete => identifiant de technologie => true
     */
    private function contributingTechnologies(PlayerService $player): array
    {
        $contributing = [];
        foreach ($this->lifeformBonuses->contributionsOf($player->getId()) as $contribution) {
            $contributing[$contribution->planetId][$contribution->objectId] = true;
        }

        return $contributing;
    }

    /**
     * Une colonne : l en-tete du corps, puis une valeur par ligne.
     *
     * Les deux clefs `levels` et `active` servent au total et sont retirees avant l envoi.
     *
     * @param array<string, array<int, GameObject>> $objects
     * @param array{levels: array<string, int>, building: string|null, target: int, queued: array<string, bool>} $research
     * @param array{buildings: array<int, LifeformObject>, technologies: array<int, LifeformObject>} $lifeformObjects
     * @param array<int, array<int, true>> $contributing
     * @return array<int|string, mixed>
     */
    private function column(
        PlanetService $body,
        bool $moons,
        int $at,
        array $objects,
        array $research,
        Species|null $species,
        array $lifeformObjects,
        array $contributing,
    ): array {
        // La projection des stocks, au meme instant que toutes les autres colonnes, et sans sauvegarde.
        $body->updateResourcesUntil($at, false);

        $biome = $body->getPlanetBiomeType();
        $variant = $body->getPlanetImageType();
        $energy = $body->energy()->get();
        $stock = [
            'res_metal' => $body->metal()->get(),
            'res_crystal' => $body->crystal()->get(),
            'res_deuterium' => $body->deuterium()->get(),
        ];
        $storage = [
            'res_metal' => $body->metalStorage()->get(),
            'res_crystal' => $body->crystalStorage()->get(),
            'res_deuterium' => $body->deuteriumStorage()->get(),
        ];

        $column = [
            'id' => $body->getPlanetId(),
            // `createPlanetsHtml` injecte ce nom dans du HTML et dans un `title`, sans l echapper.
            'name' => e($body->getPlanetName()),
            'image' => $moons
                ? asset('img/moons/big/' . $variant . '.gif')
                : asset('img/planets/empire/' . $biome . '_' . $variant . '.jpg'),
            'border' => '',
            'coordinates' => '[' . $body->getPlanetCoordinates()->asString() . ']',
            'coordinatesLink' => route('overview.index', ['cp' => $body->getPlanetId()]),
            'fieldUsed' => $body->getBuildingCount(),
            'fieldMax' => $body->getPlanetFieldMax(),
            'temperature' => __('t_ingame.empire.temperature', ['min' => $body->getPlanetTempMin(), 'max' => $body->getPlanetTempMax()]),
            'energy' => $this->span(AppUtil::formatNumber((int)round($energy)), $energy < 0 ? 'overmark' : 'undermark'),
            'energyDescr' => __('t_ingame.empire.energy'),
            'energyTooltip' => __('t_ingame.empire.energy_tooltip', [
                'produced' => AppUtil::formatNumber((int)round($body->energyProduction()->get())),
                'consumed' => AppUtil::formatNumber((int)round($body->energyConsumption()->get())),
            ]),
            'diameter' => AppUtil::formatNumber($body->getPlanetDiameter()),
            'diameterDescr' => __('t_ingame.empire.diameter'),
            'diameterTooltip' => '',
            // Le code client montre le diametre au lieu de l energie quand ce champ vaut 3 : c est le cas d une lune.
            'type' => $moons ? 3 : 1,
            'production' => $this->production($body),
            'levels' => [],
            'active' => [],
        ];

        foreach ($stock as $key => $amount) {
            $full = $storage[$key] > 0 && $amount >= $storage[$key];
            $column[$key] = (int)round($amount);
            $column[$key . '_html'] = $this->span(
                AppUtil::formatNumber((int)round($amount)),
                'tooltipRight' . ($full ? ' overmark' : ''),
                __('t_ingame.empire.storage_tooltip', ['capacity' => AppUtil::formatNumber((int)round($storage[$key]))]),
            );
        }
        $column['res_energy'] = (int)round($energy);
        $column['res_energy_html'] = $column['energy'];

        $this->fillBuildings($column, $body, $objects);
        $this->fillResearch($column, $objects['research'], $research);
        $this->fillUnits($column, $body, $objects);

        if ($species !== null) {
            $this->fillLifeforms($column, $body, $at, $species, $lifeformObjects, $contributing);
        }

        return $column;
    }

    /**
     * La production horaire, journaliere et hebdomadaire des quatre premieres lignes du groupe de production : le code
     * client la lit par `production.hourly[identifiant - 1]` pour composer l infobulle du total.
     *
     * @return array{hourly: array<int, int>, daily: array<int, int>, weekly: array<int, int>}
     */
    private function production(PlanetService $body): array
    {
        $hourly = [
            $body->getMetalProductionPerHour(),
            $body->getCrystalProductionPerHour(),
            $body->getDeuteriumProductionPerHour(),
            $body->energyProduction()->get(),
        ];

        $production = ['hourly' => [], 'daily' => [], 'weekly' => []];
        foreach ($hourly as $index => $value) {
            $production['hourly'][$index] = (int)round($value);
            $production['daily'][$index] = (int)round($value * 24);
            $production['weekly'][$index] = (int)round($value * 168);
        }

        return $production;
    }

    /**
     * Les batiments : le niveau, et ce que la file en dit. Un niveau en construction se lit sans sa couleur —
     * `12 → 13` —, un niveau en attente aussi — `12 (+2)`.
     *
     * @param array<int|string, mixed> $column
     * @param array<string, array<int, GameObject>> $objects
     */
    private function fillBuildings(array &$column, PlanetService $body, array $objects): void
    {
        $queue = $this->buildingQueue->retrieveQueue($body);
        $current = $queue->getCurrentlyBuildingFromQueue();
        $waiting = [];
        foreach ($queue->getQueuedFromQueue() as $item) {
            $waiting[$item->object->machine_name] = ($waiting[$item->object->machine_name] ?? 0) + 1;
        }

        /*
         * **Un appel par corps, pas un par ligne.** `getObjectLevel()` retrouve l objet par son nom machine, et ce
         * nom se cherche dans tout le catalogue : dix-sept lignes fois quatorze colonnes faisaient deux cent
         * trente-huit parcours, 241 ms a la mesure. `getBuildingArray()` rend la meme chose en un seul passage,
         * 9,7 ms pour les quatorze corps. Une clef absente vaut zero — c est le contrat de la methode.
         */
        $levels = $body->getBuildingArray();

        foreach (['supply', 'station'] as $group) {
            foreach ($objects[$group] as $object) {
                $level = $levels[$object->machine_name] ?? 0;
                $column[(string)$object->id] = $level;

                if ($current !== null && $current->object->machine_name === $object->machine_name) {
                    $column[$object->id . '_html'] = $this->span(
                        $level . ' → ' . $current->level_target,
                        'active tooltipRight',
                        __('t_ingame.empire.building_in_progress', ['time' => AppUtil::formatTimeDuration($current->time_countdown)]),
                    );

                    continue;
                }

                if (isset($waiting[$object->machine_name])) {
                    $column[$object->id . '_html'] = $this->span(
                        $level . ' (+' . $waiting[$object->machine_name] . ')',
                        'loop tooltipRight',
                        __('t_ingame.empire.building_queued'),
                    );

                    continue;
                }

                $price = $this->priceOf($object->machine_name, $level, $body);
                $affordable = $price->metal->get() <= $column['res_metal']
                    && $price->crystal->get() <= $column['res_crystal']
                    && $price->deuterium->get() <= $column['res_deuterium'];

                $column[$object->id . '_html'] = $this->span((string)$level, $affordable ? 'undermark' : 'overmark');
            }
        }
    }

    /**
     * La recherche : la meme valeur sur chaque colonne, puisqu elle appartient au compte.
     *
     * @param array<int|string, mixed> $column
     * @param array<int, GameObject> $objects
     * @param array{levels: array<string, int>, building: string|null, target: int, queued: array<string, bool>} $research
     */
    private function fillResearch(array &$column, array $objects, array $research): void
    {
        foreach ($objects as $object) {
            $level = $research['levels'][$object->machine_name] ?? 0;
            $column[(string)$object->id] = $level;

            if ($research['building'] === $object->machine_name) {
                $column[$object->id . '_html'] = $this->span(
                    $level . ' → ' . $research['target'],
                    'active tooltipRight',
                    __('t_ingame.empire.research_in_progress'),
                );

                continue;
            }

            if (isset($research['queued'][$object->machine_name])) {
                $column[$object->id . '_html'] = $this->span((string)$level, 'loop tooltipRight', __('t_ingame.empire.research_queued'));
            }
        }
    }

    /**
     * Les vaisseaux et les defenses : la quantite, et ce que le chantier ajoute.
     *
     * @param array<int|string, mixed> $column
     * @param array<string, array<int, GameObject>> $objects
     */
    private function fillUnits(array &$column, PlanetService $body, array $objects): void
    {
        $building = [];
        foreach ($this->unitQueue->retrieveQueue($body)->queue as $item) {
            $building[$item->object->machine_name] = ($building[$item->object->machine_name] ?? 0) + $item->object_amount_remaining;
        }

        /* Meme raison que pour les niveaux : deux collections par corps valent mieux que 378 recherches par nom. */
        $amounts = [];
        foreach ([...$body->getShipUnits()->units, ...$body->getDefenseUnits()->units] as $entry) {
            $amounts[$entry->unitObject->machine_name] = $entry->amount;
        }

        foreach (['shipyard', 'defense'] as $group) {
            foreach ($objects[$group] as $object) {
                $amount = $amounts[$object->machine_name] ?? 0;
                $column[(string)$object->id] = $amount;
                $column[$object->id . '_html'] = isset($building[$object->machine_name])
                    ? AppUtil::formatNumber($amount) . ' ' . $this->span(
                        '(+' . AppUtil::formatNumber($building[$object->machine_name]) . ')',
                        'active tooltipRight',
                        __('t_ingame.empire.units_in_progress'),
                    )
                    : AppUtil::formatNumber($amount);
            }
        }
    }

    /**
     * Le groupe des formes de vie.
     *
     * **Quatre faits distincts par technologie, et ils ne s excluent pas** : l emplacement (occupe ou non), le niveau
     * atteint, le fait de contribuer reellement, et une recherche en cours. Une technologie deja active peut etre en
     * cours de recherche vers le niveau suivant ; une technologie posee sur un palier ferme garde son niveau sans
     * rien apporter ; un niveau survit a une remise a zero de palier et se retrouve alors sans emplacement. La
     * cellule les rend **par sa forme**, pas par sa couleur : `12` active, `(12)` posee sans effet, `[12]` sans
     * emplacement, et ` → 13` ajoute par-dessus quand une recherche avance.
     *
     * @param array<int|string, mixed> $column
     * @param array{buildings: array<int, LifeformObject>, technologies: array<int, LifeformObject>} $lifeformObjects
     * @param array<int, array<int, true>> $contributing
     */
    private function fillLifeforms(
        array &$column,
        PlanetService $body,
        int $at,
        Species $species,
        array $lifeformObjects,
        array $contributing,
    ): void {
        $planetId = $body->getPlanetId();
        $column['lf_species'] = 1;
        $column['lf_species_html'] = e((string)__('t_lifeforms.species.' . $species->machineName()));

        $figures = $body->isPlanet() ? $this->lifeformBanner->planetFigures($body, $species, $at) : null;
        $held = $body->isPlanet() ? $this->lifeformBanner->heldOn($body, $at) : null;

        if ($figures === null) {
            $column['lf_population'] = 0;
            $column['lf_population_html'] = $this->span('—', 'tooltipRight', __('t_ingame.empire.lifeform_absent'));
            $column['lf_food'] = 0;
            $column['lf_food_html'] = '—';
        } else {
            $state = match (true) {
                $held !== null => __('t_ingame.empire.lifeform_held'),
                $figures['hungry'] > 0 => __('t_ingame.empire.lifeform_hungry'),
                $figures['full'] => __('t_ingame.empire.lifeform_full'),
                default => '',
            };

            $column['lf_population'] = (int)round($figures['population']);
            $column['lf_population_html'] = $this->span(
                $figures['population_formatted'],
                'tooltipRight' . ($figures['hungry'] > 0 ? ' overmark' : ''),
                __('t_ingame.empire.population_tooltip', [
                    'space' => $figures['living_space_formatted'],
                    'growth' => $figures['growth_hour_formatted'],
                ]) . $state,
            );
            $column['lf_food'] = (int)round($figures['food']);
            $column['lf_food_html'] = $this->span(
                $figures['food_formatted'],
                'tooltipRight' . ($figures['food_balance_hour'] < 0 ? ' overmark' : ''),
                __('t_ingame.empire.food_tooltip', [
                    'capacity' => $figures['food_storage_formatted'],
                    'balance' => $figures['food_balance_hour_formatted'],
                ]) . $state,
            );
        }

        $levels = $this->lifeformLevels->buildingLevelsOf($planetId);
        $running = $this->lifeformQueue->running($planetId, LifeformKind::Building);
        foreach ($lifeformObjects['buildings'] as $object) {
            $level = $levels[$object->id] ?? 0;
            $column[(string)$object->id] = $level;
            $column['levels'][$object->id] = $level;
            $column[$object->id . '_html'] = $running !== null && (int)$running->object_id === $object->id
                ? $this->span($level . ' → ' . (int)$running->target_level, 'active tooltipRight', __('t_ingame.empire.building_in_progress', [
                    'time' => AppUtil::formatTimeDuration(max(0, (int)$running->time_end - $at)),
                ]))
                : (string)$level;
        }

        $occupancy = array_flip($this->lifeformResearch->occupancyOf($planetId));
        $technologyLevels = $this->lifeformLevels->technologyLevelsOf($planetId);
        $researching = $this->lifeformQueue->running($planetId, LifeformKind::Technology);
        foreach ($lifeformObjects['technologies'] as $object) {
            $level = $technologyLevels[$object->id] ?? 0;
            $slot = $occupancy[$object->id] ?? null;
            $active = isset($contributing[$planetId][$object->id]);
            $inResearch = $researching !== null && (int)$researching->object_id === $object->id;

            $column[(string)$object->id] = $active ? 1 : 0;
            $column['active'][$object->id] = $active;
            $column[$object->id . '_html'] = $this->technologyCell($level, $slot, $active, $inResearch, $researching === null ? 0 : (int)$researching->target_level);
        }

        $column['lf_effects'] = 0;
        $column['lf_effects_html'] = $this->span('—', 'tooltipRight', __('t_ingame.empire.effects_are_account_wide'));
    }

    /**
     * Une cellule de technologie : la forme dit l etat, la couleur ne fait que le redire.
     */
    private function technologyCell(int $level, int|null $slot, bool $active, bool $inResearch, int $target): string
    {
        $arrow = $inResearch ? ' → ' . $target : '';

        if ($active) {
            $text = $level . $arrow;
            $title = __('t_ingame.empire.tech_active', ['slot' => (string)$slot]);
        } elseif ($slot !== null) {
            $text = '(' . $level . ')' . $arrow;
            $title = __('t_ingame.empire.tech_selected', ['slot' => (string)$slot]);
        } elseif ($level > 0) {
            $text = '[' . $level . ']' . $arrow;
            $title = __('t_ingame.empire.tech_developed');
        } else {
            $text = '0' . $arrow;
            $title = __('t_ingame.empire.tech_none');
        }

        if ($inResearch) {
            $title .= ' ' . __('t_ingame.empire.tech_in_research', ['target' => (string)$target]);
        }

        $classes = 'tooltipRight';
        if ($inResearch) {
            $classes .= ' active';
        } elseif ($active) {
            $classes .= ' undermark';
        } elseif ($slot !== null) {
            $classes .= ' loop';
        }

        return $this->span($text, $classes, $title);
    }

    /**
     * Les intitules de chaque ligne : court pour la colonne de gauche, complet en infobulle.
     *
     * @param array<string, array<int, GameObject>> $objects
     * @param array{buildings: array<int, LifeformObject>, technologies: array<int, LifeformObject>} $lifeformObjects
     * @return array<string, mixed>
     */
    private function translations(array $objects, array $lifeformObjects): array
    {
        $labels = [];
        foreach (self::RESOURCE_KEYS as $key) {
            $label = __('t_ingame.empire.' . $key);
            $labels[$key] = $label;
            $labels[$key . '_full'] = $label;
        }

        foreach ($objects as $group) {
            foreach ($group as $object) {
                $labels[(string)$object->id] = $this->shorten($object->title);
                $labels[$object->id . '_full'] = $object->title;
            }
        }

        foreach (['lf_species', 'lf_population', 'lf_food', 'lf_effects'] as $key) {
            $labels[$key] = __('t_ingame.empire.' . $key);
            $labels[$key . '_full'] = __('t_ingame.empire.' . $key . '_full');
        }

        foreach ([...$lifeformObjects['buildings'], ...$lifeformObjects['technologies']] as $object) {
            $title = __('t_lifeforms.' . $object->machineName . '.title');
            $labels[(string)$object->id] = $this->shorten($title);
            $labels[$object->id . '_full'] = $title;
        }

        return [
            'header' => __('t_ingame.empire.title'),
            'reset' => __('t_ingame.empire.reset_order'),
            'summary' => __('t_ingame.empire.summary'),
            'planetsTab' => __('t_ingame.empire.tab_planets'),
            'moonsTab' => __('t_ingame.empire.tab_moons'),
            'groups' => [
                'resources' => __('t_ingame.empire.group_resources'),
                'supply' => __('t_ingame.empire.group_supply'),
                'station' => __('t_ingame.empire.group_station'),
                'research' => __('t_ingame.empire.group_research'),
                'shipyard' => __('t_ingame.empire.group_shipyard'),
                'defense' => __('t_ingame.empire.group_defense'),
                'lifeforms' => __('t_ingame.empire.group_lifeforms'),
            ],
            'planets' => $labels,
            'production' => [
                'hourly' => __('t_ingame.empire.per_hour'),
                'daily' => __('t_ingame.empire.per_day'),
                'weekly' => __('t_ingame.empire.per_week'),
            ],
        ];
    }

    /**
     * Les clefs de chaque groupe, dans l ordre des lignes.
     *
     * @param array<string, array<int, GameObject>> $objects
     * @param array{buildings: array<int, LifeformObject>, technologies: array<int, LifeformObject>} $lifeformObjects
     * @return array<string, array<int, string>>
     */
    private function groupKeys(array $objects, array $lifeformObjects, Species|null $species): array
    {
        $keys = ['resources' => self::RESOURCE_KEYS];
        foreach (self::GROUPS as $group) {
            if ($group === 'resources' || $group === 'lifeforms') {
                continue;
            }
            $keys[$group] = array_map(static fn (GameObject $object): string => (string)$object->id, $objects[$group]);
        }

        if ($species === null) {
            return $keys;
        }

        $lifeforms = ['lf_species', 'lf_population', 'lf_food'];
        foreach ([...$lifeformObjects['buildings'], ...$lifeformObjects['technologies']] as $object) {
            $lifeforms[] = (string)$object->id;
        }
        $lifeforms[] = 'lf_effects';
        $keys['lifeforms'] = $lifeforms;

        return $keys;
    }

    /**
     * La colonne des totaux, pour les lignes dont le code client ne saurait pas quoi faire.
     *
     * Une moyenne n a de sens que sur un niveau : le serveur la nomme (« niveau moyen sur n corps »). Une technologie
     * ne s additionne pas — elle dit **sur combien de corps elle contribue**. Les effets, eux, appartiennent au compte
     * et figurent **une seule fois**.
     *
     * @param array<int, PlanetService> $bodies
     * @param array{buildings: array<int, LifeformObject>, technologies: array<int, LifeformObject>} $lifeformObjects
     * @param array<int, array<int, int>> $buildingLevels
     * @param array<int, int> $activeCount
     * @return array<int|string, Cellule>
     */
    private function summary(
        PlayerService $player,
        array $bodies,
        array $lifeformObjects,
        array $buildingLevels,
        array $activeCount,
        Species|null $species,
    ): array {
        $count = count($bodies);
        $summary = [];

        if ($species === null || $count === 0) {
            return $summary;
        }

        $summary['lf_species'] = [
            'html' => e((string)__('t_lifeforms.species.' . $species->machineName())),
            'title' => (string)__('t_ingame.empire.species_is_account_wide'),
        ];

        foreach ($lifeformObjects['buildings'] as $object) {
            $levels = $buildingLevels[$object->id] ?? [0];
            $summary[(string)$object->id] = [
                'html' => 'ø ' . round(array_sum($levels) / max(1, count($levels)), 1),
                'title' => (string)__('t_ingame.empire.average_level', ['count' => (string)$count]),
            ];
        }

        foreach ($lifeformObjects['technologies'] as $object) {
            $summary[(string)$object->id] = [
                'html' => (string)__('t_ingame.empire.active_on', ['active' => (string)($activeCount[$object->id] ?? 0), 'count' => (string)$count]),
                'title' => (string)__('t_ingame.empire.active_on_tooltip'),
            ];
        }

        $effects = $this->lifeformEffects->effectsOf($player);
        $lines = [];
        foreach ($effects as $effect) {
            $lines[] = $effect['label'] . ' : ' . round($effect['total'], 2) . ' %'
                . ($effect['capped'] ? ' ' . (string)__('t_ingame.empire.effect_capped') : '');
        }
        $summary['lf_effects'] = [
            'html' => (string)__('t_ingame.empire.effect_count', ['count' => (string)count($effects)]),
            'title' => $lines === [] ? (string)__('t_ingame.empire.no_effect') : implode(' · ', $lines),
        ];

        return $summary;
    }

    /**
     * Le prix du niveau suivant, calcule **par le jeu** et retenu le temps d une photographie.
     *
     * `ObjectService::getObjectPrice()` coute trois parcours du catalogue — 3,4 ms a la mesure, soit 800 ms pour dix-sept
     * lignes sur quatorze colonnes. Or ses entrees sont exactement trois : l objet, le niveau courant, et la remise de
     * formes de vie du corps, qu il lit par `LifeformBonusResolver::forPlanet()`. Deux corps dont ces trois entrees
     * coincident ont donc le meme prix, quelle que soit la formule — c est un fait de **signature**, pas de calcul :
     * la remise ne lit rien d autre du corps. La clef les nomme toutes les trois, et l empreinte de la remise est
     * l ensemble complet de ses parts.
     *
     * Si `lifeformDiscount()` venait un jour a lire autre chose du corps, cette retenue deviendrait fausse : c est la
     * seule hypothese qu elle fait, et elle est ecrite ici pour etre retrouvee.
     */
    private function priceOf(string $machineName, int $level, PlanetService $body): Resources
    {
        $bonus = $this->lifeformBonuses->forPlanet($body->getPlanetId());
        $key = $machineName . '#' . $level . '#' . md5(serialize($bonus->all()));

        if (!isset($this->prices[$key])) {
            $this->prices[$key] = ObjectService::getObjectPrice($machineName, $body);
        }

        return $this->prices[$key];
    }

    /**
     * Un intitule de colonne de gauche : 172 px, donc tronque, le complet restant en infobulle.
     */
    private function shorten(string $title): string
    {
        return mb_strlen($title) > 24 ? mb_substr($title, 0, 22) . '…' : $title;
    }

    /**
     * Le code client injecte ces chaines telles quelles : tout ce qu elles portent est deja echappe.
     */
    private function span(string $text, string $classes = '', string $title = ''): string
    {
        $attributes = $classes === '' ? '' : ' class="' . e($classes) . '"';
        if ($title !== '') {
            $attributes .= ' title="' . e($title) . '"';
        }

        return '<span' . $attributes . '>' . $text . '</span>';
    }
}
