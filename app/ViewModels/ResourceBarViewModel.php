<?php

namespace OGame\ViewModels;

use Illuminate\Support\Facades\Date;
use OGame\Facades\AppUtil;
use OGame\Lifeforms\Presentation\LifeformBanner;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use stdClass;

/**
 * Le bandeau des ressources, dit une seule fois.
 *
 * ## Deux lecteurs, une source
 *
 * Le bandeau en haut de chaque page est lu par deux chemins : le rendu de la page (le HTML du
 * gabarit et l'objet que `reloadResources()` recoit au chargement) et, depuis le 12 septembre
 * 2026, la resynchronisation en direct (`/ajax/resourcebox`). Deux constructions separees
 * auraient fini par diverger — un libelle change d'un cote, un plafond oublie de l'autre — et le
 * joueur aurait vu le bandeau **sauter** a chaque synchronisation. Cette classe est la seule qui
 * sache composer ces faits ; les deux chemins la lisent.
 *
 * ## Ce que le compteur du navigateur attend
 *
 * `ResourceTicker.reload()` lit `resources.<nom>.amount`, `.storage`, `.production` (par seconde)
 * et `.tooltip` ; il ajoute la production chaque seconde et plafonne au stockage. L'energie et la
 * matiere noire ne comptent pas : ce sont des soldes, pas des stocks — elles ne changent que par
 * un fait discret (bâtiment fini, achat), que la synchronisation apporte.
 *
 * `honorScore` et `techs` sont des restes du gabarit d'origine (OGame officiel) : aucune
 * mecanique d'Azria ne les alimente, ils gardent leurs valeurs d'origine pour ne rien changer.
 */
final class ResourceBarViewModel
{
    /** L'objet d'origine du gabarit portait ce score ; rien ne le calcule. */
    private const HONOR_SCORE_OF_THE_TEMPLATE = 11;

    /**
     * @param array<string, array<string, mixed>> $resources les faits que le gabarit lit
     * @param array<string, mixed> $ticker l'objet que `reloadResources()` recoit
     */
    private function __construct(public readonly array $resources, public readonly array $ticker)
    {
    }

    /**
     * Le bandeau d'un corps — celui qu'on nomme, sinon le corps courant du compte.
     *
     * **La production est projetee, jamais persistee.** `updateResources(save_planet: false)`
     * applique la formule du jeu — production par seconde depuis `time_last_update`, plafonnee au
     * stockage — sur l'objet en memoire et n'ecrit rien. Le rendu d'une page, lui, passe par
     * `globalgame` qui a deja sauvegarde : l'appel y est alors sans effet, les valeurs etant deja
     * a jour. La meme classe sert donc les deux chemins sans que l'un ecrive pour l'autre.
     */
    public static function of(PlayerService $player, PlanetService|null $body = null): self
    {
        $planet = $body ?? $player->planets->current();
        $planet->updateResources(false);

        // **La population et la nourriture appartiennent au bandeau**, pas seulement au gabarit : le compteur du
        // jeu lit `resources.population.tooltip` des que la page porte `#population_box`, donc sans elles dans la
        // charge la resynchronisation levait et le bandeau restait fige (journal §155.23).
        $resources = self::resourcesOf($planet, $player, app(LifeformBanner::class)->figuresOf($player, $planet));

        $ticker = self::tickerOf($resources);

        // **La cle a molette de la liste des planetes voyage avec le bandeau** : la page l amorce, la veille la
        // relit par la meme route, qui n ecrit rien. Voir `PlanetListConstructionViewModel`.
        $ticker['planetList'] = PlanetListConstructionViewModel::of($player);
        // L'alarme d'attaque du bandeau suit ce meme objet : la page la pose, la resynchronisation la tient a jour
        // (annonce d'un mouvement de flotte par Echo, ou veille de trente secondes), sans passer par `globalgame`
        // — une lecture, comme tout ce que ce point d'entree rend (journal §156).
        $ticker['attack'] = ['hostile' => FleetMissionService::playerIsUnderAttack($player)];

        // **De quel corps, et de quand.** Le corps etait deja nomme dans la demande ; l'instant manquait, et sans
        // lui une reponse partie avant une depense pouvait etre appliquee apres elle, remettant a l'ecran le stock
        // d'avant. Le navigateur refuse desormais toute reponse plus ancienne que la derniere appliquee pour ce
        // meme corps. La precision est la microseconde : deux reponses du meme corps peuvent naitre dans la meme
        // seconde.
        $ticker['body'] = $planet->getPlanetId();
        $ticker['generated_at'] = (float)Date::now()->format('U.u');

        return new self($resources, $ticker);
    }

    /**
     * Les faits bruts et formates, tels que le gabarit les affiche.
     *
     * @param array<string, mixed>|null $lifeforms les chiffres de la planete, quand le compte porte une espece
     * @return array<string, array<string, mixed>>
     */
    private static function resourcesOf(PlanetService $planet, PlayerService $player, array|null $lifeforms = null): array
    {
        $resources = [
            'metal' => [
                'amount' => $planet->metal()->get(),
                'amount_formatted' => $planet->metal()->getFormattedLong(),
                'production_hour' => $planet->getMetalProductionPerHour(),
                'production_hour_formatted' => AppUtil::formatNumber($planet->getMetalProductionPerHour()),
                'production_second' => $planet->getMetalProductionPerSecond(),
                'storage' => $planet->metalStorage()->get(),
                'storage_formatted' => $planet->metalStorage()->getFormattedLong(),
                'storage_almost_full' => self::almostFull($planet->metal()->get(), $planet->metalStorage()->get()),
            ],
            'crystal' => [
                'amount' => $planet->crystal()->get(),
                'amount_formatted' => $planet->crystal()->getFormattedLong(),
                'production_hour' => $planet->getCrystalProductionPerHour(),
                'production_hour_formatted' => AppUtil::formatNumber($planet->getCrystalProductionPerHour()),
                'production_second' => $planet->getCrystalProductionPerSecond(),
                'storage' => $planet->crystalStorage()->get(),
                'storage_formatted' => $planet->crystalStorage()->getFormattedLong(),
                'storage_almost_full' => self::almostFull($planet->crystal()->get(), $planet->crystalStorage()->get()),
            ],
            'deuterium' => [
                'amount' => $planet->deuterium()->get(),
                'amount_formatted' => $planet->deuterium()->getFormattedLong(),
                'production_hour' => $planet->getDeuteriumProductionPerHour(),
                'production_hour_formatted' => AppUtil::formatNumber($planet->getDeuteriumProductionPerHour()),
                'production_second' => $planet->getDeuteriumProductionPerSecond(),
                'storage' => $planet->deuteriumStorage()->get(),
                'storage_formatted' => $planet->deuteriumStorage()->getFormattedLong(),
                'storage_almost_full' => self::almostFull($planet->deuterium()->get(), $planet->deuteriumStorage()->get()),
            ],
            'energy' => [
                'amount' => $planet->energy()->get(),
                'amount_formatted' => $planet->energy()->getFormattedLong(),
                'production' => $planet->energyProduction()->get(),
                'production_formatted' => $planet->energyProduction()->getFormattedLong(),
                'consumption' => $planet->energyConsumption()->get(),
                'consumption_formatted' => $planet->energyConsumption()->getFormattedLong(),
            ],
            'darkmatter' => [
                'amount' => $player->getDarkMatter(),
                'amount_formatted' => AppUtil::formatNumber($player->getDarkMatter()),
            ],
        ];

        if ($lifeforms === null) {
            return $resources;
        }

        $population = (int)floor($lifeforms['population']);
        $food = (int)floor($lifeforms['food']);
        $resources['population'] = [
            'amount' => $population,
            // La tuile abrege comme les autres (« 15.353Mn ») : le compteur du navigateur reecrit la valeur avec
            // cette meme abreviation a chaque battement, et le nombre entier vit dans l'infobulle.
            'amount_formatted' => AppUtil::formatNumberLong($population),
            'storage' => $lifeforms['living_space'],
            'production_second' => $lifeforms['growth_hour'] / 3600,
            'tooltip' => self::populationTooltip($lifeforms),
            // Les faits que l'animation et la comparaison lisent ; le gabarit, lui, n'en a pas besoin.
            'inhabitants_fed' => $lifeforms['fed_capacity'],
            'full' => $lifeforms['full'],
        ];
        $resources['food'] = [
            'amount' => $food,
            'amount_formatted' => AppUtil::formatNumberLong($food),
            'storage' => (int)floor($lifeforms['food_storage']),
            'production_second' => $lifeforms['food_balance_hour'] / 3600,
            'tooltip' => self::foodTooltip($lifeforms),
            'production_hour' => $lifeforms['food_production_hour'],
            'consumption_hour' => $lifeforms['food_consumption_hour'],
            'runs_out_in' => $lifeforms['food_runs_out_in'],
        ];

        return $resources;
    }

    /**
     * L'infobulle de la population : les memes lignes que le gabarit ecrivait lui-meme, composees ici une seule
     * fois — sinon la synchronisation remplacait l'infobulle du rendu par une autre, legerement differente.
     *
     * @param array<string, mixed> $lifeforms
     */
    private static function populationTooltip(array $lifeforms): string
    {
        return self::tooltip(__('t_lifeforms_ui.banner.population'), [
            [__('t_lifeforms_ui.banner.available'), '', (string)$lifeforms['population_formatted']],
            [__('t_lifeforms_ui.banner.tier2'), '', (string)$lifeforms['tier2_formatted']],
            [__('t_lifeforms_ui.banner.tier3'), '', (string)$lifeforms['tier3_formatted']],
            [__('t_lifeforms_ui.banner.living_space'), $lifeforms['full'] ? 'overmark' : '', (string)$lifeforms['living_space_formatted']],
            [__('t_lifeforms_ui.banner.satisfied'), 'undermark', (string)$lifeforms['satisfied_formatted']],
            [__('t_lifeforms_ui.banner.hungry'), $lifeforms['hungry'] > 0 ? 'overmark' : '', (string)$lifeforms['hungry_formatted']],
            [__('t_lifeforms_ui.banner.growth'), '', $lifeforms['growth_hour_formatted'] . '/h'],
            [__('t_lifeforms_ui.banner.sheltered'), 'middlemark', (string)$lifeforms['sheltered_formatted']],
        ]);
    }

    /**
     * @param array<string, mixed> $lifeforms
     */
    private static function foodTooltip(array $lifeforms): string
    {
        return self::tooltip(__('t_lifeforms_ui.banner.food'), [
            [__('t_lifeforms_ui.banner.available'), '', (string)$lifeforms['food_formatted']],
            [__('t_lifeforms_ui.banner.storage'), '', (string)$lifeforms['food_storage_formatted']],
            [__('t_lifeforms_ui.banner.production'), 'undermark', $lifeforms['food_production_hour_formatted'] . '/h'],
            [__('t_lifeforms_ui.banner.consumption'), 'overmark', $lifeforms['food_consumption_hour_formatted'] . '/h'],
            [__('t_lifeforms_ui.banner.consumed_in'), ($lifeforms['food_runs_out_in'] === null ? '' : 'overmark ') . 'timeTillFoodRunsOut', (string)$lifeforms['food_runs_out_formatted']],
        ]);
    }

    /**
     * L'objet que `reloadResources()` recoit, au chargement comme a chaque synchronisation.
     *
     * @param array<string, array<string, mixed>> $resources
     * @return array<string, mixed>
     */
    private static function tickerOf(array $resources): array
    {
        $ticker = ['resources' => []];

        foreach (['metal', 'crystal', 'deuterium'] as $name) {
            $facts = $resources[$name];
            $ticker['resources'][$name] = [
                'amount' => $facts['amount'],
                'storage' => $facts['storage'],
                'baseProduction' => 0,
                'production' => $facts['production_second'],
                'tooltip' => self::stockTooltip($name, $facts),
                'classesListItem' => '',
            ];
        }

        $energy = $resources['energy'];
        $ticker['resources']['energy'] = [
            'amount' => $energy['amount'],
            'tooltip' => self::tooltip(__('t_ingame.layout.res_energy'), [
                [__('t_ingame.layout.res_available') . ':', '', $energy['amount_formatted']],
                [__('t_ingame.layout.res_current_production') . ':', $energy['production'] > 0 ? 'undermark' : 'overmark', ($energy['production'] > 0 ? '+' : '') . $energy['production_formatted']],
                [__('t_ingame.layout.res_consumption'), $energy['consumption'] > 0 ? 'overmark' : '', ($energy['consumption'] > 0 ? '-' : '') . $energy['consumption_formatted']],
            ]),
            'classesListItem' => '',
        ];

        $darkmatter = $resources['darkmatter'];
        $ticker['resources']['darkmatter'] = [
            'amount' => $darkmatter['amount'],
            'tooltip' => self::tooltip(__('t_ingame.layout.res_dark_matter'), [
                [__('t_ingame.layout.res_available') . ':', '', $darkmatter['amount_formatted']],
            ]),
            'classesListItem' => '',
        ];

        // Les deux tuiles des formes de vie : le compteur les tient comme des stocks — une production par seconde
        // (la croissance, le bilan de nourriture) plafonnee au stockage (l'espace vital, le grenier).
        foreach (['population', 'food'] as $name) {
            if (!isset($resources[$name])) {
                continue;
            }
            $facts = $resources[$name];
            $ticker['resources'][$name] = [
                'amount' => $facts['amount'],
                'storage' => $facts['storage'],
                'baseProduction' => 0,
                'production' => $facts['production_second'],
                'tooltip' => $facts['tooltip'],
                'classesListItem' => '',
            ];
        }

        $ticker['techs'] = new stdClass();
        $ticker['honorScore'] = self::HONOR_SCORE_OF_THE_TEMPLATE;

        // **Les faits, a cote des tuiles et non dedans** : le compteur herite ne lit que les clefs qu'il connait,
        // et une structure a part ne peut pas reveiller une de ses branches avec une valeur dont la semantique
        // n'est pas la sienne.
        $ticker['facts'] = self::factsOf($resources);

        return $ticker;
    }

    /**
     * **Les faits derriere chaque infobulle**, en nombres — la structure dediee que le navigateur compare.
     *
     * ## Pourquoi elle existe
     *
     * Le module comparait la **chaine rendue** pour decider s'il fallait refaire les infobulles. Cette chaine porte
     * le stock courant, qui bouge chaque seconde : la condition etait donc toujours vraie, et l'infobulle que le
     * joueur lisait etait detruite toutes les trente secondes (journal §184.4). Comparer des faits reglerait ce
     * point — mais retirer la chaine sans rien publier rendrait **aveugle** : pour l'energie, la production et la
     * consommation n'existaient QUE dans la chaine ; pour la nourriture, seul un bilan etait publie, jamais ses
     * deux termes. Ce sont ces faits-la, ici, sous des noms **qui ne sont pas ceux du compteur herite** — dont la
     * semantique differe et qu'on ne doit pas reveiller.
     *
     * ## Ce que `per_second` et `stable_for` promettent
     *
     * `per_second` est le taux **effectif** a cet instant : la croissance vaut deja zero quand la planete est
     * pleine ou affamee, c'est la regle du jeu qui le dit, pas le navigateur. `stable_for` est le nombre de
     * secondes pendant lesquelles ce taux reste valable — jusqu'au plafond, jusqu'au grenier plein, ou jusqu'a la
     * derniere bouchee. Au-dela, **le navigateur n'interpole plus** : il tient la valeur et redemande l'etat, au
     * lieu d'afficher une progression que le serveur ne confirmerait pas. `null` veut dire « rien en vue ».
     *
     * @param array<string, array<string, mixed>> $resources
     * @return array<string, array<string, mixed>>
     */
    private static function factsOf(array $resources): array
    {
        $faits = [];

        foreach (['metal', 'crystal', 'deuterium'] as $name) {
            $faits[$name] = [
                'storage' => $resources[$name]['storage'],
                'production_hour' => $resources[$name]['production_hour'],
            ];
        }

        $faits['energy'] = [
            'production' => $resources['energy']['production'],
            'consumption' => $resources['energy']['consumption'],
        ];

        // La matiere noire n'affiche que son montant : aucun fait ne la gouverne, et elle ne s'anime jamais.
        $faits['darkmatter'] = [];

        if (!isset($resources['population'])) {
            return $faits;
        }

        $population = (float)$resources['population']['amount'];
        $espaceVital = (float)$resources['population']['storage'];
        $croissance = (float)$resources['population']['production_second'];
        $nourriture = (float)$resources['food']['amount'];
        $grenier = (float)$resources['food']['storage'];
        $bilan = (float)$resources['food']['production_second'];
        $epuisement = $resources['food']['runs_out_in'];

        // La croissance s'arrete au plafond ; et si la nourriture s'epuise avant, elle s'arrete la.
        $popStable = null;
        if ($croissance > 0.0) {
            $popStable = max(0.0, ($espaceVital - $population) / $croissance);
            if ($epuisement !== null) {
                $popStable = min($popStable, (float)$epuisement);
            }
        }

        // La nourriture change de regime quand elle atteint zero (famine) ou son grenier (production perdue).
        $foodStable = null;
        if ($bilan < 0.0) {
            $foodStable = max(0.0, $nourriture / -$bilan);
        } elseif ($bilan > 0.0) {
            $foodStable = max(0.0, ($grenier - $nourriture) / $bilan);
        }

        $faits['population'] = [
            'cap' => $espaceVital,
            'per_second' => $croissance,
            'stable_for' => $popStable,
            'inhabitants_fed' => $resources['population']['inhabitants_fed'],
            'full' => $resources['population']['full'],
        ];
        $faits['food'] = [
            'cap' => $grenier,
            'per_second' => $bilan,
            'stable_for' => $foodStable,
            'production_hour' => $resources['food']['production_hour'],
            'consumption_hour' => $resources['food']['consumption_hour'],
            'runs_out_in' => $epuisement,
        ];

        return $faits;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function stockTooltip(string $name, array $facts): string
    {
        $productionRising = $facts['production_hour'] > 0;

        return self::tooltip(__('t_ingame.layout.res_' . $name), [
            [__('t_ingame.layout.res_available') . ':', '', $facts['amount_formatted']],
            [__('t_ingame.layout.res_storage_capacity'), '', $facts['storage_formatted']],
            [__('t_ingame.layout.res_current_production') . ':', $productionRising ? 'undermark' : 'overmark', ($productionRising ? '+' : '') . $facts['production_hour_formatted']],
            [__('t_ingame.layout.res_den_capacity') . ':', 'overermark', '0'],
        ]);
    }

    /**
     * Le titre puis le tableau, separes par `|` comme le gabarit et le compteur du navigateur
     * l'attendent (`changeTooltip()` decoupe sur ce caractere).
     *
     * @param list<array{0: string, 1: string, 2: string}> $rows libelle, classe, valeur
     */
    private static function tooltip(string $title, array $rows): string
    {
        $html = '';

        // **Les valeurs sont echappees elles aussi.** Ce sont aujourd'hui des nombres formates et des durees, donc
        // rien d'hostile ; mais cette chaine est desormais ecrite dans le DOM d'une infobulle **ouverte** par le
        // navigateur (mise a jour en place, sans reconstruction), et ce qui entre dans le DOM s'echappe a la source.
        foreach ($rows as [$label, $class, $value]) {
            $html .= '<tr><th>' . e($label) . '</th><td><span class="' . e($class) . '">' . e($value) . '</span></td></tr>';
        }

        return e($title) . '|<table class="resourceTooltip">' . $html . '</table>';
    }

    private static function almostFull(float|int $amount, float|int $storage): bool
    {
        return $amount >= $storage * 0.9 && $amount < $storage;
    }
}
