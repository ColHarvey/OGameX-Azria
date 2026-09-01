<?php

namespace OGame\Services\Npc;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use OGame\Models\NpcThreat;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * La rancune qu'un joueur s'est attiree, et ce qu'elle autorise les factions a lui faire.
 *
 * Ce service repond a la deuxieme des trois questions du systeme. Il ne mesure que les
 * actes du joueur envers les factions et sa proximite avec elles : jamais son score, jamais
 * son anciennete — ce sont les affaires de l'eligibilite.
 */
class NpcThreatService
{
    /**
     * Gains de menace par acte, avant multiplicateur de proximite.
     *
     * REGLE INTANGIBLE : une cle deja en production ne change jamais de sens. Le dernier
     * motif enregistre sert a expliquer le raid dans le rapport de combat ; en reaffecter
     * un reecrirait l'histoire que le joueur a deja lue.
     */
    private const array GAINS = [
        'espionage' => 3,
        'attack_lost' => 5,
        'attack_won' => 12,
        'fleet_wiped' => 15,
        'base_destroyed' => 25,
        'debris_harvest' => 2,
    ];

    /**
     * Paliers de menace et ce qu'ils autorisent.
     */
    public const string BAND_IGNORED = 'ignored';
    public const string BAND_RECON = 'recon';
    public const string BAND_RAID_LIGHT = 'raid_light';
    public const string BAND_RAID_MILITARY = 'raid_military';
    public const string BAND_RETALIATION = 'retaliation';

    public function __construct(
        private SettingsService $settings,
        private NpcPopulationService $population
    ) {
    }

    /**
     * Get a player's current threat, decay already applied.
     *
     * La decroissance n'est pas une tache de fond qui balaierait tous les joueurs a chaque
     * tick : elle se rattrape au moment ou la menace est lue. Un joueur qui ne se connecte
     * pas voit quand meme sa rancune fondre, puisque le calcul part de la date du dernier
     * amortissement et non du nombre de fois ou on l'a regarde.
     */
    public function threatOf(PlayerService $player): int
    {
        $row = NpcThreat::where('user_id', $player->getId())->first();

        if ($row === null) {
            return 0;
        }

        $this->applyDecay($row);

        return min($row->threat, $this->ceilingFor($player));
    }

    /**
     * Get the rancour a player has actually piled up, before exposure bounds it.
     *
     * Distincte de threatOf(), qui rend la valeur effective. Les deux comptent : la premiere
     * dit ce que le joueur a fait, la seconde ce qu'il risque aujourd'hui. Un joueur loin de
     * toute base peut avoir cent points au compteur et n'en risquer que quarante.
     */
    public function accumulatedFor(PlayerService $player): int
    {
        $row = NpcThreat::where('user_id', $player->getId())->first();

        if ($row === null) {
            return 0;
        }

        $this->applyDecay($row);

        return (int)$row->threat;
    }

    /**
     * Get the highest threat this player can currently reach.
     *
     * C'est ici que le voisinage compte. Un joueur puissant installe dans le meme systeme
     * qu'une base est le probleme des pirates et atteindra tout leur repertoire. Un joueur
     * tout juste au-dessus du seuil, a trois galaxies de la, restera une curiosite qu'on
     * surveille : on ne monte pas une expedition punitive a l'autre bout de l'univers pour
     * lui.
     */
    public function ceilingFor(PlayerService $player): int
    {
        $max = $this->settings->npcThreatMax();
        $threshold = max(1, $this->population->threshold());
        $exposure = $this->proximityMultiplierFor($player) * ($player->getCachedGeneralScore() / $threshold);

        if ($exposure >= 2.0) {
            return $max;
        }

        if ($exposure >= 1.2) {
            return (int)round($max * 0.7);
        }

        return (int)round($max * 0.4);
    }

    /**
     * Get the multiplier that the nearest hostile base applies to this player's threat.
     *
     * Un pirate ne reagit pas de la meme facon a une agression venue de l'autre bout de
     * l'univers et a celle du voisin de palier.
     */
    public function proximityMultiplierFor(PlayerService $player): float
    {
        $nearest = $this->nearestBaseCoordinate($player);

        if ($nearest === null) {
            return 1.0;
        }

        foreach ($player->planets->all() as $planet) {
            $coordinate = $planet->getPlanetCoordinates();

            if ($coordinate->galaxy === $nearest->galaxy && $coordinate->system === $nearest->system) {
                return $this->settings->npcProximitySystem();
            }
        }

        foreach ($player->planets->all() as $planet) {
            if ($planet->getPlanetCoordinates()->galaxy === $nearest->galaxy) {
                return $this->settings->npcProximityGalaxy();
            }
        }

        return 1.0;
    }

    /**
     * Add threat for something the player just did to a faction.
     *
     * Un joueur protege n'accumule rien. Sans cette regle, un debutant curieux qui sonde
     * une base pendant sa periode de grace verrait la note tomber le jour ou elle expire,
     * pour des actes qu'il avait commis en se croyant a l'abri.
     */
    public function add(PlayerService $player, string $reason, Coordinate|null $at = null): int
    {
        if (!isset(self::GAINS[$reason])) {
            return $this->threatOf($player);
        }

        if ($this->population->stateOf($player) === NpcPopulationService::STATE_PROTECTED) {
            return 0;
        }

        $multiplier = $at !== null
            ? $this->proximityMultiplierForCoordinate($player, $at)
            : $this->proximityMultiplierFor($player);

        $gain = (int)round(self::GAINS[$reason] * $multiplier);

        $row = NpcThreat::firstOrNew(['user_id' => $player->getId()]);
        $this->applyDecay($row);

        $row->user_id = $player->getId();
        // Cast explicite : sur une ligne encore jamais enregistree, l'attribut n'existe pas
        // et vaut null, la valeur par defaut du schema n'ayant pas encore ete appliquee.
        $row->threat = min((int)$row->threat + $gain, $this->settings->npcThreatMax());
        $row->last_motive = $reason;

        if ($row->last_decay_at === null) {
            $row->last_decay_at = Date::now();
        }

        $row->save();

        return min($row->threat, $this->ceilingFor($player));
    }

    /**
     * Get which band of behaviour the player's threat currently unlocks.
     */
    public function bandOf(PlayerService $player): string
    {
        $threat = $this->threatOf($player);
        $max = $this->settings->npcThreatMax();

        return match (true) {
            $threat > $max * 0.8 => self::BAND_RETALIATION,
            $threat > $max * 0.6 => self::BAND_RAID_MILITARY,
            $threat > $max * 0.4 => self::BAND_RAID_LIGHT,
            $threat > $max * 0.2 => self::BAND_RECON,
            default => self::BAND_IGNORED,
        };
    }

    /**
     * Get the last thing this player did that the factions took note of.
     */
    public function lastMotiveOf(PlayerService $player): string|null
    {
        $row = NpcThreat::where('user_id', $player->getId())->first();

        return $row?->last_motive;
    }

    /**
     * Get the moment the next threat point will be forgotten.
     */
    public function nextDecayAt(PlayerService $player): Carbon|null
    {
        $row = NpcThreat::where('user_id', $player->getId())->first();

        if ($row === null || $row->threat === 0 || $row->last_decay_at === null) {
            return null;
        }

        return $row->last_decay_at->copy()->addHours($this->settings->npcThreatDecayHours());
    }

    /**
     * Record that a raid has just been sent against this player.
     */
    public function recordRaid(PlayerService $player, string $motive): void
    {
        $row = NpcThreat::firstOrNew(['user_id' => $player->getId()]);
        $row->user_id = $player->getId();
        $row->last_raid_at = Date::now();
        $row->last_motive = $motive;
        $row->save();
    }

    /**
     * Get the moment this player last suffered a raid, null when never.
     */
    public function lastRaidAt(PlayerService $player): Carbon|null
    {
        $row = NpcThreat::where('user_id', $player->getId())->first();

        return $row?->last_raid_at;
    }

    /**
     * Forget threat points in proportion to the time elapsed since the last decay.
     */
    private function applyDecay(NpcThreat $row): void
    {
        if ($row->threat <= 0) {
            $row->last_decay_at = Date::now();

            return;
        }

        $since = $row->last_decay_at;

        if ($since === null) {
            $row->last_decay_at = Date::now();

            return;
        }

        $hours = $this->settings->npcThreatDecayHours();
        $elapsed = (int)$since->diffInHours(Date::now());
        $points = intdiv($elapsed, $hours);

        if ($points <= 0) {
            return;
        }

        $row->threat = max(0, $row->threat - $points);
        $row->last_decay_at = $since->copy()->addHours($points * $hours);

        if ($row->exists) {
            $row->save();
        }
    }

    /**
     * Get the coordinate of the hostile base nearest to any of the player's planets.
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
                $distance = $this->distanceInSystems(
                    $own,
                    new Coordinate((int)$base->galaxy, (int)$base->system, (int)$base->planet)
                );

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = new Coordinate((int)$base->galaxy, (int)$base->system, (int)$base->planet);
                }
            }
        }

        return $best;
    }

    /**
     * Get the proximity multiplier between a player and one precise coordinate.
     */
    private function proximityMultiplierForCoordinate(PlayerService $player, Coordinate $target): float
    {
        foreach ($player->planets->all() as $planet) {
            $own = $planet->getPlanetCoordinates();

            if ($own->galaxy === $target->galaxy && $own->system === $target->system) {
                return $this->settings->npcProximitySystem();
            }
        }

        foreach ($player->planets->all() as $planet) {
            if ($planet->getPlanetCoordinates()->galaxy === $target->galaxy) {
                return $this->settings->npcProximityGalaxy();
            }
        }

        return 1.0;
    }

    /**
     * Get a coarse distance in systems, galaxies counting as a large constant.
     */
    private function distanceInSystems(Coordinate $from, Coordinate $to): int
    {
        if ($from->galaxy !== $to->galaxy) {
            return abs($from->galaxy - $to->galaxy) * 20000;
        }

        return abs($from->system - $to->system);
    }
}
