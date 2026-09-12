<?php

namespace OGame\ViewModels;

use OGame\Facades\AppUtil;
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
        $resources = self::resourcesOf($planet, $player);

        return new self($resources, self::tickerOf($resources));
    }

    /**
     * Les faits bruts et formates, tels que le gabarit les affiche.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function resourcesOf(PlanetService $planet, PlayerService $player): array
    {
        return [
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

        $ticker['techs'] = new stdClass();
        $ticker['honorScore'] = self::HONOR_SCORE_OF_THE_TEMPLATE;

        return $ticker;
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

        foreach ($rows as [$label, $class, $value]) {
            $html .= '<tr><th>' . e($label) . '</th><td><span class="' . $class . '">' . $value . '</span></td></tr>';
        }

        return e($title) . '|<table class="resourceTooltip">' . $html . '</table>';
    }

    private static function almostFull(float|int $amount, float|int $storage): bool
    {
        return $amount >= $storage * 0.9 && $amount < $storage;
    }
}
