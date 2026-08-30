<?php

namespace OGame\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Resources;
use OGame\Models\StarterAidClaim;
use RuntimeException;

/**
 * Pack de bienvenue : sept recompenses echelonnees sur les sept premiers jours du compte.
 *
 * Rien n'est distribue automatiquement. Chaque recompense doit etre reclamee par le joueur
 * et reste disponible indefiniment tant qu'il ne l'a pas fait.
 */
class StarterAidService
{
    /**
     * Nombre total de recompenses du pack.
     */
    public const TOTAL_DAYS = 7;

    /**
     * Definition des recompenses, par jour.
     *
     * Les montants sont calibres sur l'economie de cet univers (x1) et non sur ceux d'OGame
     * officiel, nettement plus genereux : le jour 1 d'origine couvrirait a lui seul le cout
     * des deux mines montees au niveau 10.
     *
     * Le jour 7 offre l'etat-major pendant trois jours dans le jeu d'origine. Les officiers
     * n'etant pas implementes, il est desactive : 'available' repassera a true le jour ou ils
     * existeront, sans autre changement que le contenu de la recompense.
     *
     * @var array<int, array{metal: int, crystal: int, deuterium: int, dark_matter: int, units: array<string, int>, available: bool}>
     */
    private const REWARDS = [
        1 => ['metal' => 3000, 'crystal' => 2000, 'deuterium' => 0, 'dark_matter' => 0, 'units' => [], 'available' => true],
        2 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 500, 'units' => [], 'available' => true],
        3 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 600, 'units' => [], 'available' => true],
        4 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'units' => ['rocket_launcher' => 5], 'available' => true],
        5 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 700, 'units' => [], 'available' => true],
        6 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 800, 'units' => [], 'available' => true],
        7 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'units' => [], 'available' => false],
    ];

    /**
     * @param DarkMatterService $darkMatterService
     */
    public function __construct(private DarkMatterService $darkMatterService)
    {
    }

    /**
     * Retourne l'etat des sept recompenses pour un joueur.
     *
     * @param PlayerService $player
     * @return array<int, array{day: int, state: string, unlocks_in_days: int, reward: array<string, mixed>}>
     */
    public function getOverview(PlayerService $player): array
    {
        $claimed = StarterAidClaim::where('user_id', $player->getId())->pluck('day')->all();

        $overview = [];
        for ($day = 1; $day <= self::TOTAL_DAYS; $day++) {
            $reward = self::REWARDS[$day];

            if (in_array($day, $claimed, true)) {
                $state = 'claimed';
            } elseif (!$reward['available']) {
                $state = 'unavailable';
            } elseif ($this->isUnlocked($player, $day)) {
                $state = 'claimable';
            } else {
                $state = 'locked';
            }

            $overview[$day] = [
                'day' => $day,
                'state' => $state,
                'unlocks_in_days' => $this->daysUntilUnlock($player, $day),
                'reward' => $reward,
                'summary' => $this->describe($reward),
            ];
        }

        return $overview;
    }

    /**
     * Reclame la recompense d'un jour donne et la credite sur la planete courante.
     *
     * @param PlayerService $player
     * @param int $day
     * @return void
     * @throws RuntimeException Si la recompense n'est pas reclamable.
     */
    public function claim(PlayerService $player, int $day): void
    {
        if (!isset(self::REWARDS[$day])) {
            throw new RuntimeException(__('t_ingame.rewards.error_unknown'));
        }

        $reward = self::REWARDS[$day];

        if (!$reward['available']) {
            throw new RuntimeException(__('t_ingame.rewards.error_unavailable'));
        }

        if (!$this->isUnlocked($player, $day)) {
            throw new RuntimeException(__('t_ingame.rewards.error_locked'));
        }

        DB::transaction(function () use ($player, $day, $reward) {
            // L'enregistrement est cree AVANT de crediter : si la contrainte d'unicite rejette
            // l'insertion, la transaction est abandonnee et rien n'est distribue.
            try {
                StarterAidClaim::create([
                    'user_id' => $player->getId(),
                    'day' => $day,
                    'claimed_at' => Date::now(),
                ]);
            } catch (QueryException $e) {
                if ((int)$e->getCode() === 23000) {
                    throw new RuntimeException(__('t_ingame.rewards.error_already_claimed'));
                }

                throw $e;
            }

            $planet = $player->planets->current();

            // Somme plutot que trois comparaisons : aucune recompense ne donne actuellement
            // de deuterium, ce qui rendrait le test correspondant mort.
            if ($reward['metal'] + $reward['crystal'] + $reward['deuterium'] > 0) {
                $planet->addResources(new Resources($reward['metal'], $reward['crystal'], $reward['deuterium'], 0));
            }

            foreach ($reward['units'] as $machineName => $amount) {
                $planet->addUnit($machineName, $amount);
            }

            if ($reward['dark_matter'] > 0) {
                $this->darkMatterService->credit(
                    $player->getUser(),
                    $reward['dark_matter'],
                    DarkMatterTransactionType::STARTER_AID->value,
                    __('t_ingame.rewards.transaction_description', ['day' => $day])
                );
            }
        });
    }

    /**
     * Resume lisible du contenu d'une recompense, par exemple "3 000 metal, 2 000 cristal".
     *
     * @param array{metal: int, crystal: int, deuterium: int, dark_matter: int, units: array<string, int>, available: bool} $reward
     * @return string
     */
    public function describe(array $reward): string
    {
        $parts = [];

        foreach (['metal', 'crystal', 'deuterium', 'dark_matter'] as $key) {
            if ($reward[$key] > 0) {
                $parts[] = number_format($reward[$key], 0, ',', ' ') . ' ' . __('t_ingame.rewards.gain_' . $key);
            }
        }

        foreach ($reward['units'] as $machineName => $amount) {
            $parts[] = $amount . ' ' . __('t_ingame.rewards.gain_' . $machineName);
        }

        return implode(', ', $parts);
    }

    /**
     * La recompense du jour N se debloque N-1 jours apres l'inscription : le jour 1 est
     * disponible immediatement, le jour 7 apres six jours.
     *
     * @param PlayerService $player
     * @param int $day
     * @return bool
     */
    private function isUnlocked(PlayerService $player, int $day): bool
    {
        return $this->daysUntilUnlock($player, $day) === 0;
    }

    /**
     * Nombre de jours restants avant le deblocage, 0 si deja debloquee.
     *
     * @param PlayerService $player
     * @param int $day
     * @return int
     */
    private function daysUntilUnlock(PlayerService $player, int $day): int
    {
        // created_at est nullable au niveau du modele : un compte sans date est traite
        // comme venant d'etre cree, donc seul le jour 1 lui est ouvert.
        $createdAt = $player->getUser()->created_at ?? Date::now();
        $unlocksAt = $createdAt->copy()->addDays($day - 1);
        $now = Date::now();

        if ($now->greaterThanOrEqualTo($unlocksAt)) {
            return 0;
        }

        return (int)ceil($now->diffInSeconds($unlocksAt) / 86400);
    }
}
