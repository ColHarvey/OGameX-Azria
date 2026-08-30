<?php

namespace OGame\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\User;
use RuntimeException;

/**
 * Recrutement des officiers, payes en matiere noire pour une duree determinee.
 *
 * Les dates d'expiration vivent en colonnes sur `users` : les methodes
 * PlayerService::hasGeologist() et consorts sont consultees a chaque calcul de
 * production, donc a chaque page. Une table dediee imposerait une jointure permanente.
 */
class OfficerService
{
    /**
     * Les cinq officiers, dans l'ordre d'affichage de la page de recrutement.
     *
     * @var array<int, string>
     */
    public const OFFICERS = ['commander', 'admiral', 'engineer', 'geologist', 'technocrat'];

    /**
     * Prix en matiere noire, par officier et par duree en jours.
     *
     * Reprend la grille d'OGame : une semaine ou trois mois, le tarif long valant
     * dix fois le court.
     *
     * @var array<string, array<int, int>>
     */
    private const PRICES = [
        'commander' => [7 => 12500, 90 => 125000],
        'admiral' => [7 => 5000, 90 => 50000],
        'engineer' => [7 => 5000, 90 => 50000],
        'geologist' => [7 => 12500, 90 => 125000],
        'technocrat' => [7 => 12500, 90 => 125000],
    ];

    /**
     * @param DarkMatterService $darkMatterService
     */
    public function __construct(private DarkMatterService $darkMatterService)
    {
    }

    /**
     * Durees proposees a l'achat, en jours.
     *
     * @return array<int, int>
     */
    public function getDurations(): array
    {
        return [7, 90];
    }

    /**
     * Prix d'un officier pour une duree donnee, ou null si la combinaison n'existe pas.
     *
     * @param string $officer
     * @param int $days
     * @return int|null
     */
    public function getPrice(string $officer, int $days): int|null
    {
        return self::PRICES[$officer][$days] ?? null;
    }

    /**
     * Date d'expiration d'un officier, ou null s'il n'est pas actif.
     *
     * @param User $user
     * @param string $officer
     * @return Carbon|null
     */
    public function getExpiry(User $user, string $officer): Carbon|null
    {
        $expiry = match ($officer) {
            'commander' => $user->commander_until,
            'admiral' => $user->admiral_until,
            'engineer' => $user->engineer_until,
            'geologist' => $user->geologist_until,
            'technocrat' => $user->technocrat_until,
            default => null,
        };

        if ($expiry === null || $expiry->isPast()) {
            return null;
        }

        return $expiry;
    }

    /**
     * Un officier est-il actuellement actif ?
     *
     * @param User $user
     * @param string $officer
     * @return bool
     */
    public function isActive(User $user, string $officer): bool
    {
        return $this->getExpiry($user, $officer) !== null;
    }

    /**
     * Nombre d'officiers actifs.
     *
     * @param User $user
     * @return int
     */
    public function countActive(User $user): int
    {
        $count = 0;
        foreach (self::OFFICERS as $officer) {
            if ($this->isActive($user, $officer)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Etat des cinq officiers pour l'affichage de la page de recrutement.
     *
     * @param PlayerService $player
     * @return array<string, array{officer: string, active: bool, expires_at: Carbon|null, prices: array<int, int>}>
     */
    public function getOverview(PlayerService $player): array
    {
        $user = $player->getUser();

        $overview = [];
        foreach (self::OFFICERS as $officer) {
            $overview[$officer] = [
                'officer' => $officer,
                'active' => $this->isActive($user, $officer),
                'expires_at' => $this->getExpiry($user, $officer),
                'prices' => self::PRICES[$officer],
            ];
        }

        return $overview;
    }

    /**
     * Recrute un officier pour la duree demandee.
     *
     * Si l'officier est deja actif, la duree s'ajoute a l'echeance en cours plutot
     * que de la remplacer : un joueur ne peut pas perdre du temps deja paye.
     *
     * @param PlayerService $player
     * @param string $officer
     * @param int $days
     * @return void
     * @throws RuntimeException Si l'officier, la duree ou le solde ne conviennent pas.
     */
    public function hire(PlayerService $player, string $officer, int $days): void
    {
        if (!in_array($officer, self::OFFICERS, true)) {
            throw new RuntimeException(__('t_ingame.premium.error_unknown_officer'));
        }

        $price = $this->getPrice($officer, $days);

        if ($price === null) {
            throw new RuntimeException(__('t_ingame.premium.error_unknown_duration'));
        }

        $user = $player->getUser();

        if (!$this->darkMatterService->canAfford($user, $price)) {
            throw new RuntimeException(__('t_ingame.premium.error_not_enough_dark_matter'));
        }

        DB::transaction(function () use ($user, $player, $officer, $days, $price) {
            $this->darkMatterService->debit(
                $user,
                $price,
                DarkMatterTransactionType::COMMANDING_STAFF->value,
                __('t_ingame.premium.transaction_description', [
                    'officer' => __('t_ingame.premium.officer_' . $officer),
                    'days' => $days,
                ])
            );

            $current = $this->getExpiry($user, $officer);
            $base = $current ?? Date::now();

            $user->setAttribute($officer . '_until', $base->copy()->addDays($days));
            $user->save();

            // Le PlayerService garde l'utilisateur en memoire : sans rechargement, les
            // bonus ne s'appliqueraient qu'au chargement de page suivant.
            $player->load($player->getId());
        });
    }
}
