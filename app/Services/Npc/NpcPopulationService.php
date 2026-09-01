<?php

namespace OGame\Services\Npc;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\User;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * L'etat du serveur vu par les factions hostiles : qui existe, qui compte, a partir de quand.
 *
 * Ce service repond a la premiere des trois questions du systeme — l'eligibilite — et il ne
 * repond qu'a celle-la. Il ignore tout de ce qu'un joueur a fait aux pirates (c'est la
 * menace) et de sa force militaire (c'est la puissance). Les melanger rendrait le systeme
 * impossible a equilibrer, parce qu'on ne saurait plus laquelle des trois a produit un raid.
 */
class NpcPopulationService
{
    /**
     * Un joueur intouchable : trop jeune, trop faible, ou absent.
     */
    public const string STATE_PROTECTED = 'protected';

    /**
     * Un joueur qui vient de franchir le seuil : observe, jamais attaque.
     */
    public const string STATE_SPOTTED = 'spotted';

    /**
     * Un joueur soumis au systeme complet.
     */
    public const string STATE_TARGETED = 'targeted';

    /**
     * Nombre de jours sans connexion au-dela duquel un joueur ne compte plus.
     *
     * Reprend le seuil de PlayerService::isInactive(), afin que « actif » veuille dire la
     * meme chose partout dans le jeu.
     */
    private const int INACTIVE_DAYS = 7;

    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Get the general scores of every active human player, sorted ascending.
     *
     * La definition de la population n'est pas un detail de filtrage : c'est elle qui
     * produit le seuil. Un classement de treize comptes dont sept sont morts donnerait une
     * mediane proche de zero, donc un seuil nul, donc un serveur entier eligible — a
     * commencer par le debutant que tout ce systeme cherche a proteger.
     *
     * @return array<int, int>
     */
    public function activeHumanScores(): array
    {
        $limit = Date::now()->subDays(self::INACTIVE_DAYS)->timestamp;

        $scores = DB::table('highscores')
            ->join('users', 'users.id', '=', 'highscores.player_id')
            ->where('users.is_npc', false)
            ->where('users.username', '!=', 'Legor')
            // users.time est une colonne texte qui contient un horodatage. L'addition force
            // la comparaison numerique, et elle le fait aussi bien sur MariaDB que sur le
            // SQLite des tests, la ou un CAST ... AS UNSIGNED n'existerait que sur l'un des
            // deux.
            ->whereRaw('users.time + 0 >= ?', [$limit])
            ->orderBy('highscores.general')
            ->pluck('highscores.general')
            ->all();

        return array_map('intval', $scores);
    }

    /**
     * Get the number of active human players.
     */
    public function activePlayerCount(): int
    {
        return count($this->activeHumanScores());
    }

    /**
     * Get the median general score of the active human population.
     */
    public function medianScore(): int
    {
        $scores = $this->activeHumanScores();
        $count = count($scores);

        if ($count === 0) {
            return 0;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $scores[$middle];
        }

        return (int)round(($scores[$middle - 1] + $scores[$middle]) / 2);
    }

    /**
     * Get the score a player must reach before the factions take an interest.
     *
     * Deux regimes, et le serveur bascule de l'un a l'autre tout seul.
     *
     * Sous un certain effectif la mediane saute par a-coups des que deux joueurs se
     * croisent : ce n'est pas de la volatilite mais un probleme d'echantillon, et
     * l'amortir ne le repare pas, cela retarde seulement le moment ou on s'en apercoit
     * tout en ajoutant un etat a stocker. On prend donc le seuil fixe configure, jusqu'a
     * ce qu'il y ait assez de monde pour qu'une mediane ait un sens.
     *
     * Au-dessus, la mediane — et non le maximum. Indexer sur le meilleur joueur laisserait
     * une seule personne fixer l'echelle du serveur entier : qu'elle parte en vacances ou
     * arrete, et tout le monde deviendrait eligible du jour au lendemain.
     */
    public function threshold(): int
    {
        $plancher = $this->settings->npcMinScoreFixed();

        if ($this->activePlayerCount() < $this->settings->npcMinActivePlayers()) {
            return $plancher;
        }

        // Le seuil fixe n'est pas une valeur de repli, c'est un plancher.
        //
        // Mesure faite le 31 aout 2026 sur le serveur reel, trois jours apres son ouverture :
        // treize joueurs actifs, dont sept encore a zero point faute d'avoir commence a
        // produire. La mediane valait donc zero, et le seuil aussi — la garde principale
        // grande ouverte, sur un serveur ou elle protege precisement ceux qui viennent
        // d'arriver. Seule la protection des quatorze jours tenait encore.
        //
        // La mediane reste la bonne mesure de l'echelle du serveur ; elle a simplement
        // besoin d'un sol. En dessous du seuil fixe, on n'est pas un joueur que les
        // factions regardent, quelle que soit la forme de la population.
        return max($plancher, (int)round($this->medianScore() * $this->settings->npcMedianRatio()));
    }

    /**
     * Get how the factions currently regard this player.
     *
     * Les trois etats se franchissent dans l'ordre et jamais a l'envers tant que les
     * conditions tiennent : protege, puis repere — observe mais pas attaque —, puis cible.
     */
    public function stateOf(PlayerService $player): string
    {
        $user = $player->getUser();

        if ($player->isInVacationMode() || $user->is_npc) {
            return self::STATE_PROTECTED;
        }

        if ($player->isInactive()) {
            return self::STATE_PROTECTED;
        }

        if ($player->getCachedGeneralScore() < $this->threshold()) {
            return self::STATE_PROTECTED;
        }

        $registeredFor = $this->daysSinceRegistration($user);

        if ($registeredFor < $this->settings->npcNewPlayerDays()) {
            return self::STATE_PROTECTED;
        }

        if ($registeredFor < $this->settings->npcNewPlayerDays() + $this->settings->npcSpottedDays()) {
            return self::STATE_SPOTTED;
        }

        return self::STATE_TARGETED;
    }

    /**
     * Get whether a raid may ever be sent against this player.
     */
    public function canBeRaided(PlayerService $player): bool
    {
        return $this->stateOf($player) === self::STATE_TARGETED;
    }

    /**
     * Get whether the factions may at least spy on this player.
     */
    public function canBeSpied(PlayerService $player): bool
    {
        $state = $this->stateOf($player);

        return $state === self::STATE_SPOTTED || $state === self::STATE_TARGETED;
    }

    /**
     * Get how many whole days ago this account was created.
     *
     * Il n'existe pas de reactivation de compte dans ce depot : created_at ne bouge jamais.
     * Si cela changeait, c'est cette date qu'il faudrait revoir et non le reste.
     */
    public function daysSinceRegistration(User $user): int
    {
        if ($user->created_at === null) {
            return PHP_INT_MAX;
        }

        return (int)$user->created_at->diffInDays(Date::now());
    }

    /**
     * Get how many bases the server should currently maintain for a faction.
     */
    public function targetBaseCount(): int
    {
        $fromPopulation = intdiv($this->activePlayerCount(), $this->settings->npcPlayersPerBase());

        return max(
            $this->settings->npcBaseCountMin(),
            min($this->settings->npcBaseCountMax(), $fromPopulation)
        );
    }
}
