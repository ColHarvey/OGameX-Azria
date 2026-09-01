<?php

namespace OGame\Services\Npc;

use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Tout ce qu'un joueur doit pouvoir lire sur sa propre situation face aux factions.
 *
 * Un joueur qui voit sa jauge a 74 et lit « base detruite, x2 meme systeme, prochaine
 * decroissance dans 42 minutes » comprend le systeme entier en dix secondes. Il peut alors
 * choisir : se calmer, demenager, ou assumer. C'est la difference entre une mecanique et
 * une punition arbitraire.
 */
class NpcThreatPanelService
{
    public function __construct(
        private SettingsService $settings,
        private NpcThreatService $threat,
        private NpcPopulationService $population
    ) {
    }

    /**
     * Build everything the threat panel needs for one player.
     *
     * @return array<string, mixed>
     */
    public function forPlayer(PlayerService $player): array
    {
        if (!$this->settings->npcEnabled()) {
            return ['visible' => false];
        }

        $value = $this->threat->threatOf($player);
        $ceiling = $this->threat->ceilingFor($player);
        $max = $this->settings->npcThreatMax();
        $band = $this->threat->bandOf($player);
        $state = $this->population->stateOf($player);

        $nearest = $this->nearestBaseCoordinate($player);

        return [
            'visible' => true,
            'value' => $value,
            // La rancune reellement accumulee, avant que l'exposition ne la borne. Un joueur
            // peut avoir cent points au compteur et n'en risquer que quarante parce qu'il
            // vit loin de toute base : n'afficher que l'un des deux donnerait une image
            // fausse — soit de ce qu'il a fait, soit de ce qu'il risque.
            'accumulated' => $this->threat->accumulatedFor($player),
            'ceiling' => $ceiling,
            'max' => $max,
            'percent' => $max > 0 ? (int)round($value / $max * 100) : 0,
            'mark' => $this->markFor($band),
            'band' => $band,
            'band_label' => __('t_ingame.npc.band_' . $band),
            'band_description' => $state === NpcPopulationService::STATE_PROTECTED
                ? __('t_ingame.npc.state_protected')
                : __('t_ingame.npc.band_' . $band . '_desc'),
            'state' => $state,
            'nearest' => $nearest?->asString(),
            // Les trois composantes separement : la vue en fait un lien vers la galaxie,
            // comme le classement le fait pour la planete d'un joueur.
            'nearest_galaxy' => $nearest?->galaxy,
            'nearest_system' => $nearest?->system,
            'nearest_position' => $nearest?->position,
            'proximity' => number_format($this->threat->proximityMultiplierFor($player), 1, ',', ' '),
            'next_decay' => $this->threat->nextDecayAt($player)?->format('d/m H:i'),
        ];
    }

    /**
     * Get the coordinates of the closest hostile base, as a label.
     *
     * Le joueur doit pouvoir relier son multiplicateur a quelque chose de concret : « x2 »
     * ne veut rien dire tant qu'on ne sait pas qui est le voisin.
     */
    private function nearestBaseCoordinate(PlayerService $player): Coordinate|null
    {
        $bases = Planet::query()
            ->join('users', 'users.id', '=', 'planets.user_id')
            ->where('users.is_npc', true)
            ->where('planets.destroyed', 0)
            ->select('planets.galaxy', 'planets.system', 'planets.planet')
            ->get();

        if ($bases->isEmpty()) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($player->planets->all() as $planet) {
            $own = $planet->getPlanetCoordinates();

            foreach ($bases as $base) {
                $coordinate = new Coordinate((int)$base->galaxy, (int)$base->system, (int)$base->planet);

                $distance = $own->galaxy === $coordinate->galaxy
                    ? abs($own->system - $coordinate->system)
                    : abs($own->galaxy - $coordinate->galaxy) * 20000;

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $coordinate;
                }
            }
        }

        return $best;
    }

    /**
     * Get the theme's severity name that matches a threat band.
     *
     * Le jeu dispose deja de trois niveaux de gravite, employes partout ailleurs : undermark
     * en vert, middlemark en orange, overmark en rouge. Ils servent aussi bien a colorer un
     * texte qu'a choisir la teinte d'une barre de remplissage. Reutiliser ces trois noms
     * plutot que d'inventer des couleurs garantit que le panneau suivra le theme du joueur,
     * y compris si celui-ci en change.
     */
    private function markFor(string $band): string
    {
        return match ($band) {
            NpcThreatService::BAND_RETALIATION, NpcThreatService::BAND_RAID_MILITARY => 'overmark',
            NpcThreatService::BAND_RAID_LIGHT => 'middlemark',
            default => 'undermark',
        };
    }
}
