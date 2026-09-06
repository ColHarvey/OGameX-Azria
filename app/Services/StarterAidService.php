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
     * **Le jour 7 donne un officier, choisi par le joueur, pour sept jours** (decision de Keven,
     * 6 septembre 2026). Le jeu d'origine offre l'etat-major entier pendant trois jours ; ici, un
     * seul officier mais plus longtemps, et c'est le joueur qui decide lequel. La recompense est
     * restee fermee tant que le recrutement n'existait pas sur cet univers.
     *
     * Un officier deja en poste voit son terme **repousse et non remplace** : la regle vit dans
     * `OfficerService`, partagee avec l'embauche, pour qu'offrir sept jours a qui vient d'en acheter
     * quatre-vingt-dix ne lui en coute pas quatre-vingt-trois.
     *
     * Le drapeau `available` a disparu avec le dernier jour ferme : un drapeau qu'aucune valeur ne
     * met a faux est un chemin que rien n'eprouve. Le jour ou une recompense devra etre retenue, il
     * se reintroduira avec l'essai qui le justifie.
     *
     * @var array<int, array{metal: int, crystal: int, deuterium: int, dark_matter: int, units: array<string, int>, officer_days: int}>
     */
    private const REWARDS = [
        1 => ['metal' => 3000, 'crystal' => 2000, 'deuterium' => 0, 'dark_matter' => 0, 'units' => [], 'officer_days' => 0],
        2 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 500, 'units' => [], 'officer_days' => 0],
        3 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 600, 'units' => [], 'officer_days' => 0],
        4 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'units' => ['rocket_launcher' => 5], 'officer_days' => 0],
        5 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 700, 'units' => [], 'officer_days' => 0],
        6 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 800, 'units' => [], 'officer_days' => 0],
        7 => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'dark_matter' => 0, 'units' => [], 'officer_days' => 7],
    ];

    /**
     * @param DarkMatterService $darkMatterService
     * @param OfficerService $officerService
     */
    public function __construct(
        private DarkMatterService $darkMatterService,
        private OfficerService $officerService,
    ) {
    }

    /**
     * Retourne l'etat des sept recompenses pour un joueur.
     *
     * @param PlayerService $player
     * @return array<int, array{day: int, state: string, unlocks_in_days: int, reward: array<string, mixed>, summary: string, officer_choices: array<int, string>}>
     */
    public function getOverview(PlayerService $player): array
    {
        $claimed = StarterAidClaim::where('user_id', $player->getId())->pluck('day')->all();

        $overview = [];
        for ($day = 1; $day <= self::TOTAL_DAYS; $day++) {
            $reward = self::REWARDS[$day];

            if (in_array($day, $claimed, true)) {
                $state = 'claimed';
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
                // **La liste des choix vient du service, pas de la vue.** Elle doit etre exactement
                // celle que `claim()` acceptera : deux listes finiraient par diverger, et le joueur
                // verrait un portrait que le serveur refuse.
                'officer_choices' => $reward['officer_days'] > 0 ? OfficerService::OFFICERS : [],
            ];
        }

        return $overview;
    }

    /**
     * Reclame la recompense d'un jour donne et la credite sur la planete courante.
     *
     * @param PlayerService $player
     * @param int $day
     * @param string|null $officer L'officier choisi, exige par les recompenses qui en donnent un.
     * @return void
     * @throws RuntimeException Si la recompense n'est pas reclamable, ou si le choix ne convient pas.
     */
    public function claim(PlayerService $player, int $day, string|null $officer = null): void
    {
        if (!isset(self::REWARDS[$day])) {
            throw new RuntimeException(__('t_ingame.rewards.error_unknown'));
        }

        $reward = self::REWARDS[$day];

        if (!$this->isUnlocked($player, $day)) {
            throw new RuntimeException(__('t_ingame.rewards.error_locked'));
        }

        // **Le choix se valide avant d'ecrire quoi que ce soit.** L'enregistrement de la creance est
        // pose des l'entree dans la transaction et sa contrainte d'unicite est definitive : un choix
        // refuse plus tard laisserait la recompense marquee prise et l'officier jamais nomme.
        if ($reward['officer_days'] > 0 && !in_array($officer, OfficerService::OFFICERS, true)) {
            throw new RuntimeException(__('t_ingame.rewards.error_officer_required'));
        }

        DB::transaction(function () use ($player, $day, $reward, $officer) {
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
                // **L'addition est faite par la base, jamais sur le modele.** `addResources()`
                // repart du stock charge avant la transaction et reecrit la colonne avec une valeur
                // absolue : une depense validee entre-temps serait effacee, et le joueur garderait
                // sa construction *et* les ressources qu'elle a coutees.
                $planet->addResourcesAtomic(new Resources($reward['metal'], $reward['crystal'], $reward['deuterium'], 0));
            }

            foreach ($reward['units'] as $machineName => $amount) {
                // Meme raison pour les unites — et la propriete est verifiee dans l'ecriture, ce qui
                // interdit de crediter un corps qui a change de mains. Un refus annule la
                // transaction, donc la creance : la recompense reste reclamable.
                if (!$planet->addUnitAtomicIfStillOwnedBy($machineName, $amount, $player->getId())) {
                    throw new RuntimeException(__('t_ingame.rewards.error_body_changed'));
                }
            }

            if ($reward['dark_matter'] > 0) {
                $this->darkMatterService->credit(
                    $player->getUser(),
                    $reward['dark_matter'],
                    DarkMatterTransactionType::STARTER_AID->value,
                    __('t_ingame.rewards.transaction_description', ['day' => $day])
                );
            }

            // **Un seul officier, celui que le joueur a nomme**, octroye sans contrepartie : aucune
            // ecriture de matiere noire ne doit apparaitre dans son historique pour un cadeau.
            // `$officer` est deja valide — la garde est au-dessus, hors transaction.
            if ($reward['officer_days'] > 0 && $officer !== null) {
                $this->officerService->grant($player, $officer, $reward['officer_days']);
            }
        });
    }

    /**
     * Resume lisible du contenu d'une recompense, par exemple "3 000 metal, 2 000 cristal".
     *
     * @param array{metal: int, crystal: int, deuterium: int, dark_matter: int, units: array<string, int>, officer_days: int} $reward
     * @return string
     */
    public function describe(array $reward): string
    {
        $parts = [];

        if ($reward['officer_days'] > 0) {
            $parts[] = trans_choice('t_ingame.rewards.gain_officers', $reward['officer_days'], ['days' => $reward['officer_days']]);
        }

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
