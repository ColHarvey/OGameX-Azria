<?php

namespace OGame\Lifeforms\Presentation;

use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\Services\LifeformDiscoveryService;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Models\Lifeforms\LifeformDiscovery;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Les vols de decouverte tels que la Galaxie les propose : une icone ADN par position, comme le jeu officiel.
 *
 * Le bundle de la Galaxie porte deja tout le cote client (`getDiscoveryLinkIcon`, `discoverPlanet`) : pour chaque
 * ligne il cherche dans `availableMissions` une mission de type `constants.discover`, lit `canSend` (vrai, ou la
 * raison a montrer), `discoveryCount` (les vols disponibles) et `link` (ou envoyer le vol). Cette classe compose ces
 * trois faits pour les quinze positions d un systeme, en UNE lecture des vols du compte vers ce systeme.
 *
 * Ce que le bouton de la page des decouvertes fait par un formulaire, l icone le fait par une demande AJAX ; les deux
 * passent par `LifeformDiscoveryService::launch()`, qui reste le seul juge. Les raisons montrees ici sont donc une
 * PREVISION du refus, pour que l icone soit grise avant le clic — le refus reel vient toujours du service.
 */
final class GalaxyDiscoveries
{
    /** Le type de mission que le bundle compare a `constants.discover` : celui du jeu officiel. */
    public const int MISSION_TYPE = 18;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly LifeformInstallationService $installation,
        private readonly LifeformDiscoveryService $discoveries,
        private readonly LifeformLevels $levels,
    ) {
    }

    /**
     * L etat des decouvertes pour un systeme affiche.
     *
     * @return array{enabled: bool, count: int, header: string, missions: array<int, array{missionType: int, canSend: true|string, discoveryCount: int, link: string, name: string}>}
     */
    public function forSystem(PlayerService $player, int $galaxy, int $system, int $now): array
    {
        $espece = $this->settings->lifeformsEnabled() ? $this->installation->speciesOf($player->getId()) : null;
        if ($espece === null) {
            return ['enabled' => false, 'count' => 0, 'header' => $this->header(0), 'missions' => []];
        }

        $compte = $this->discoveries->accrueQuota($player->getId(), $now);
        $disponibles = $compte === null ? 0 : (int)$compte->discoveries_available;

        // Une seule raison generale, valable pour toutes les positions, avant les raisons propres a une position.
        $generale = null;
        $planete = $player->planets->current();
        $centre = LifeformCatalogue::buildingWithEffect($espece, LifeformEffect::LF_RESEARCH_TIME_REDUCTION);
        if (!$planete->isPlanet()) {
            $generale = (string)__('t_lifeforms_ui.buildings.not_on_a_moon');
        } elseif ($centre === null || ($this->levels->buildingLevelsOf($planete->getPlanetId())[$centre->id] ?? 0) < 1) {
            $generale = (string)__('t_ingame.galaxy.discovery_locked');
        } elseif ($disponibles < 1) {
            $generale = (string)__('t_lifeforms_ui.refused.quota_exhausted');
        }

        // Les vols du compte vers ce systeme : en cours (icone « en approche »), ou trop recents (sept jours).
        $parPosition = [];
        $vols = LifeformDiscovery::query()
            ->where('user_id', $player->getId())
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('started_at', '>', $now - LifeformDiscoveryRules::REEXPLORATION_DELAY)
            ->get(['position', 'status']);
        foreach ($vols as $vol) {
            $position = (int)$vol->position;
            $enCours = (string)$vol->status === 'running';
            if ($enCours || !isset($parPosition[$position])) {
                $parPosition[$position] = $enCours ? 'running' : 'recent';
            }
        }

        $missions = [];
        for ($position = 1; $position <= 15; $position++) {
            $raison = $generale;
            if ($raison === null && isset($parPosition[$position])) {
                $raison = (string)__($parPosition[$position] === 'running' ? 't_ingame.galaxy.discovery_underway' : 't_ingame.galaxy.discovery_unavailable');
            }
            $missions[$position] = [
                'missionType' => self::MISSION_TYPE,
                'canSend' => $raison ?? true,
                'discoveryCount' => $disponibles,
                'link' => route('lifeforms.discoveries.galaxy'),
                'name' => (string)__('t_ingame.galaxy.discovery_send'),
            ];
        }

        return ['enabled' => true, 'count' => $disponibles, 'header' => $this->header($disponibles), 'missions' => $missions];
    }

    /** Le compteur de l en-tete de la Galaxie, tel que la page le rend et que la reponse d un vol le remet. */
    public function header(int $disponibles): string
    {
        return __('t_ingame.galaxy.discoveries') . ': ' . $disponibles;
    }
}
