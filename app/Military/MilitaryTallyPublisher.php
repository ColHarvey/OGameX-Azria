<?php

namespace OGame\Military;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Models\User;
use OGame\Services\HighscoreService;
use OGame\Services\SettingsService;

/**
 * Publie les trois classements cumulés — valeurs et rangs, joueurs et alliances — depuis un seul état agrégé.
 *
 * ## Un état, une transaction
 *
 * 1. L'agrégation compte d'abord ce qui attend (`MilitaryTallyAggregator`), dans ses propres transactions.
 * 2. Puis **une seule lecture** des compteurs nourrit tout : points des joueurs, sommes des alliances, rangs.
 * 3. Valeurs, rangs et date d'actualisation s'écrivent dans **une seule transaction**. Un lecteur voit l'ancienne
 *    publication entière ou la nouvelle entière, jamais une partie de l'une avec l'autre ; une panne avant la
 *    validation laisse l'ancienne intacte.
 *
 * Les autres classements gardent leurs tâches (`GenerateHighscores`, `GenerateAllianceHighscores`,
 * `GenerateHighscoreRanks`) ; ces trois-là ne sont écrits que par ici.
 *
 * ## Qui est classé
 *
 * Les règles de la tâche des rangs : le compte système et les comptes pilotés par le serveur au rang 0, les
 * administrateurs aussi quand le réglage les cache ; les autres par valeur décroissante, puis par ancienneté du
 * compte, puis par identifiant — un départage stable. Les alliances par valeur décroissante, ancienneté,
 * identifiant. La valeur d'une alliance est la somme des points de ses **membres actuels** : elle peut diminuer.
 *
 * ## Avant l'activation
 *
 * Rien n'est publié : pas de date, et les trois classements restent indisponibles.
 */
final class MilitaryTallyPublisher
{
    /** Le réglage qui garde l'instant de la dernière publication. */
    public const string PUBLISHED_KEY = 'military_tallies_published_at';

    /** Chaque classement publié ici, et la colonne de compteur qui le nourrit. */
    private const array COLONNES = [
        'military_built' => 'built_value',
        'military_destroyed' => 'destroyed_value',
        'military_lost' => 'lost_value',
    ];

    /**
     * @param (Closure(): void)|null $beforeCommit Couture d'essai : appelée dans la transaction de publication, après
     *        toutes ses écritures, juste avant la validation.
     */
    public function __construct(
        private MilitaryTallyAggregator $aggregator,
        private MilitaryTallyRecorder $recorder,
        private SettingsService $settings,
        private Closure|null $beforeCommit = null,
    ) {
    }

    /**
     * L'instant de la dernière publication, ou `null` s'il n'y en a jamais eu.
     */
    public static function publishedAt(): int|null
    {
        $valeur = (int)(DB::table('settings')->where('key', self::PUBLISHED_KEY)->value('value') ?? 0);

        return $valeur > 0 ? $valeur : null;
    }

    /**
     * Agrège, puis publie. Rend faux si la collecte n'est pas activée : rien n'est alors écrit.
     */
    public function publish(): bool
    {
        if ($this->recorder->collectingSince() === null) {
            return false;
        }

        $this->aggregator->aggregate();

        DB::transaction(function (): void {
            // **Une seule lecture des compteurs** : tout ce qui suit en dérive, rien n'est relu en chemin.
            /** @var array<int, array{built_value: int, destroyed_value: int, lost_value: int}> $compteurs */
            $compteurs = [];
            foreach (DB::table('military_tallies')->get(['player_id', 'built_value', 'destroyed_value', 'lost_value']) as $ligne) {
                $compteurs[(int)$ligne->player_id] = [
                    'built_value' => (int)$ligne->built_value,
                    'destroyed_value' => (int)$ligne->destroyed_value,
                    'lost_value' => (int)$ligne->lost_value,
                ];
            }

            $this->publishPlayers($compteurs);
            $this->publishAlliances($compteurs);

            $maintenant = Date::now();
            if (DB::table('settings')->where('key', self::PUBLISHED_KEY)->exists()) {
                DB::table('settings')->where('key', self::PUBLISHED_KEY)->update(['value' => (string)$maintenant->timestamp, 'updated_at' => $maintenant]);
            } else {
                DB::table('settings')->insert(['key' => self::PUBLISHED_KEY, 'value' => (string)$maintenant->timestamp, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
            }

            if ($this->beforeCommit !== null) {
                ($this->beforeCommit)();
            }
        });

        foreach (self::COLONNES as $classement => $compteur) {
            HighscoreService::forgetCachedPagesOf(HighscoreTypeEnum::{$classement});
        }

        return true;
    }

    /**
     * @param array<int, array{built_value: int, destroyed_value: int, lost_value: int}> $compteurs
     */
    private function publishPlayers(array $compteurs): void
    {
        $administrateursCaches = [];
        if (!$this->settings->highscoreAdminVisible()) {
            foreach (DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('model_has_roles.model_type', User::class)
                ->where('roles.name', 'admin')
                ->pluck('model_has_roles.model_id') as $identifiant) {
                $administrateursCaches[(int)$identifiant] = true;
            }
        }

        $lignes = DB::table('highscores')
            ->join('users', 'highscores.player_id', '=', 'users.id')
            ->orderBy('highscores.id')
            ->get(['highscores.id', 'highscores.player_id', 'users.username', 'users.is_npc', 'users.created_at']);

        $ecritures = [];
        $classables = [];

        foreach ($lignes as $ligne) {
            $joueur = (int)$ligne->player_id;
            $identifiant = (int)$ligne->id;

            foreach (self::COLONNES as $classement => $compteur) {
                $ecritures[$identifiant][$classement] = MilitaryValue::pointsOf($compteurs[$joueur][$compteur] ?? 0);
                $ecritures[$identifiant][$classement . '_rank'] = 0;
            }

            $horsClassement = (string)$ligne->username === User::SYSTEM_ACCOUNT_USERNAME
                || (bool)$ligne->is_npc
                || isset($administrateursCaches[$joueur]);

            if (!$horsClassement) {
                $classables[] = ['id' => $identifiant, 'anciennete' => (string)($ligne->created_at ?? ''), 'departage' => $joueur];
            }
        }

        $this->rank($classables, $ecritures);

        foreach ($ecritures as $identifiant => $valeurs) {
            DB::table('highscores')->where('id', $identifiant)->update($valeurs);
        }
    }

    /**
     * @param array<int, array{built_value: int, destroyed_value: int, lost_value: int}> $compteurs
     */
    private function publishAlliances(array $compteurs): void
    {
        /** @var array<int, array<string, int>> $sommes */
        $sommes = [];
        foreach (DB::table('users')->whereNotNull('alliance_id')->get(['id', 'alliance_id']) as $membre) {
            foreach (self::COLONNES as $classement => $compteur) {
                $sommes[(int)$membre->alliance_id][$classement] = ($sommes[(int)$membre->alliance_id][$classement] ?? 0)
                    + MilitaryValue::pointsOf($compteurs[(int)$membre->id][$compteur] ?? 0);
            }
        }

        $lignes = DB::table('alliance_highscores')
            ->join('alliances', 'alliance_highscores.alliance_id', '=', 'alliances.id')
            ->orderBy('alliance_highscores.id')
            ->get(['alliance_highscores.id', 'alliance_highscores.alliance_id', 'alliances.created_at']);

        $ecritures = [];
        $classables = [];

        foreach ($lignes as $ligne) {
            $alliance = (int)$ligne->alliance_id;
            $identifiant = (int)$ligne->id;

            foreach (self::COLONNES as $classement => $compteur) {
                $ecritures[$identifiant][$classement] = $sommes[$alliance][$classement] ?? 0;
                $ecritures[$identifiant][$classement . '_rank'] = 0;
            }

            $classables[] = ['id' => $identifiant, 'anciennete' => (string)($ligne->created_at ?? ''), 'departage' => $alliance];
        }

        $this->rank($classables, $ecritures);

        foreach ($ecritures as $identifiant => $valeurs) {
            DB::table('alliance_highscores')->where('id', $identifiant)->update($valeurs);
        }
    }

    /**
     * Range les lignes classables de chaque classement : valeur décroissante, ancienneté, identifiant.
     *
     * @param list<array{id: int, anciennete: string, departage: int}> $classables
     * @param array<int, array<string, int>> $ecritures
     */
    private function rank(array $classables, array &$ecritures): void
    {
        foreach (array_keys(self::COLONNES) as $classement) {
            $ordre = $classables;

            usort($ordre, static fn (array $a, array $b): int => [$ecritures[$b['id']][$classement], $a['anciennete'], $a['departage']]
                <=> [$ecritures[$a['id']][$classement], $b['anciennete'], $b['departage']]);

            $rang = 1;
            foreach ($ordre as $ligne) {
                $ecritures[$ligne['id']][$classement . '_rank'] = $rang++;
            }
        }
    }
}
