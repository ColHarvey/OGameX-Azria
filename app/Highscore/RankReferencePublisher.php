<?php

namespace OGame\Highscore;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use OGame\Enums\HighscoreTypeEnum;

/**
 * Publie la reference du classement, et la sert aux pages.
 *
 * ## La regle de rotation, tranchee par Keven le 22 septembre 2026
 *
 * Les rangs sont republies toutes les cinq minutes ; la reference, elle, **une fois par journee
 * serveur**, au premier calcul reussi de cette journee. La comparaison se fait sur `published_day`,
 * jamais sur un ecart de vingt-quatre heures : une periode glissante decalerait la bascule un peu plus
 * chaque jour. `published_at` garde l instant REEL — un passage qui aboutit a 00 h 05 affiche 00 h 05.
 *
 * ## Tout bascule d un coup, ou rien ne bascule
 *
 * Une transaction unique remplace la reference **entiere** : toutes les categories, les deux portees.
 * Une panne en cours de publication conserve donc integralement la derniere reference valide, jamais
 * un melange de categories neuves et anciennes. La journee n est marquee que dans cette meme
 * transaction : un refus laisse la rotation due, et le passage suivant la retente.
 *
 * ## Deux generateurs simultanes
 *
 * La ligne d etat est prise en `lockForUpdate()` avant toute lecture : le second generateur attend,
 * puis relit `published_day`, constate que la journee est faite et ne publie rien. **Limite dite :
 * `lockForUpdate()` ne compile a rien sous SQLite** — la serialisation n est prouvee que par le bac
 * MariaDB (`tests/MariaDb/HighscoreRankReferenceRaceTest.php`).
 *
 * Cette serialisation protege la PUBLICATION, pas l ecriture des rangs elle-meme, qui n a jamais eu de
 * garde et n en recoit pas ici. C est pourquoi la lecture exige, avant de photographier, que chaque
 * classement soit une suite complete de 1 a N : un passage interrompu, ou deux passages qui se
 * chevauchent, laissent des trous ou des doublons, et un tel classement est refuse au lieu d etre fige
 * pour une journee entiere.
 *
 * ## Ce que la reference contient
 *
 * Un rang PUBLIE par sujet, pour les seules categories qui en ont un. Les regles d exclusion (compte
 * systeme, PNJ, administrateurs caches) et de departage des egalites ne sont pas rejouees : elles sont
 * deja dans le rang ecrit par la tache. Consequence assumee : rendre les administrateurs visibles
 * decale tout le monde en dessous d eux, et ce mouvement-la est reel, donc rapporte.
 */
final class RankReferencePublisher
{
    public const SCOPE_PLAYER = 'player';
    public const SCOPE_ALLIANCE = 'alliance';

    private const STATE_ID = 1;
    private const STATE_TABLE = 'highscore_rank_reference_state';
    private const REFERENCE_TABLE = 'highscore_rank_references';

    /**
     * Ou lire le rang courant de chaque portee.
     *
     * @var array<string, array{table: string, key: string}>
     */
    private const SOURCES = [
        self::SCOPE_PLAYER => ['table' => 'highscores', 'key' => 'player_id'],
        self::SCOPE_ALLIANCE => ['table' => 'alliance_highscores', 'key' => 'alliance_id'],
    ];

    /**
     * Publie la reference si la journee serveur n a pas encore la sienne.
     *
     * Ne leve jamais : la tache des rangs ne doit pas tomber parce qu une photographie a ete refusee.
     */
    public function publishIfDue(): RankReferenceOutcome
    {
        return DB::transaction(function (): RankReferenceOutcome {
            $etat = DB::table(self::STATE_TABLE)
                ->where('id', self::STATE_ID)
                ->lockForUpdate()
                ->first();

            if ($etat === null) {
                Log::warning('Reference du classement : ligne d etat absente, aucune publication.');

                return RankReferenceOutcome::StateMissing;
            }

            $maintenant = Date::now();
            $journee = $maintenant->format('Y-m-d');

            if ((string)($etat->published_day ?? '') === $journee) {
                return RankReferenceOutcome::NotDue;
            }

            /** @var array<int, array{scope: string, type: int, subject_id: int, rank: int}> $lignes */
            $lignes = [];
            /** @var array<string, array<int, int>> $couverture */
            $couverture = [];

            foreach (self::SOURCES as $portee => $source) {
                $colonnes = Schema::getColumnListing($source['table']);

                foreach (HighscoreTypeEnum::cases() as $type) {
                    $colonne = $type->name . '_rank';

                    // Une categorie dont la colonne n existe pas sur ce schema n est pas couverte, et ce
                    // n est pas une erreur : sous SQLite, un nom de colonne inconnu passerait pour une
                    // chaine litterale et le refus ne se verrait jamais.
                    if (!in_array($colonne, $colonnes, true)) {
                        continue;
                    }

                    $rangs = $this->rankedSubjectsOf($source['table'], $source['key'], $colonne);

                    if ($rangs === []) {
                        continue;
                    }

                    if (!$this->isAWholeRanking($rangs)) {
                        Log::warning('Reference du classement refusee : le classement lu n est pas une suite complete.', [
                            'portee' => $portee,
                            'categorie' => $type->name,
                            'sujets' => count($rangs),
                        ]);

                        return RankReferenceOutcome::RefusedIncoherentRanking;
                    }

                    foreach ($rangs as $sujet => $rang) {
                        $lignes[] = [
                            'scope' => $portee,
                            'type' => $type->value,
                            'subject_id' => $sujet,
                            'rank' => $rang,
                        ];
                    }

                    $couverture[$portee][] = $type->value;
                }
            }

            if ($couverture === []) {
                return RankReferenceOutcome::RefusedNothingToPublish;
            }

            DB::table(self::REFERENCE_TABLE)->delete();

            foreach (array_chunk($lignes, 500) as $paquet) {
                DB::table(self::REFERENCE_TABLE)->insert($paquet);
            }

            DB::table(self::STATE_TABLE)->where('id', self::STATE_ID)->update([
                'published_at' => $maintenant->timestamp,
                'published_day' => $journee,
                'covered' => json_encode($couverture, JSON_THROW_ON_ERROR),
                'generation' => (int)$etat->generation + 1,
                'updated_at' => $maintenant,
            ]);

            return RankReferenceOutcome::Published;
        });
    }

    /**
     * La reference telle qu une page l emploie : uniquement les sujets qu elle affiche.
     *
     * @param array<int, int> $subjectIds
     */
    public function referenceFor(string $scope, HighscoreTypeEnum $type, array $subjectIds): RankReferenceView
    {
        $etat = DB::table(self::STATE_TABLE)->where('id', self::STATE_ID)->first();

        if ($etat === null || $etat->published_at === null) {
            return RankReferenceView::unavailable();
        }

        $publieLe = $this->aWholeNumber($etat->published_at);

        if ($publieLe === null || $publieLe < 1) {
            return RankReferenceView::unavailable();
        }

        $couverture = json_decode((string)($etat->covered ?? ''), true);

        if (!is_array($couverture)
            || !isset($couverture[$scope])
            || !is_array($couverture[$scope])
            || !in_array($type->value, $couverture[$scope], true)) {
            return RankReferenceView::unavailable();
        }

        $rangs = [];

        if ($subjectIds !== []) {
            $lignes = DB::table(self::REFERENCE_TABLE)
                ->where('scope', $scope)
                ->where('type', $type->value)
                ->whereIn('subject_id', $subjectIds)
                ->get(['subject_id', 'rank']);

            foreach ($lignes as $ligne) {
                $sujet = $this->aWholeNumber($ligne->subject_id);
                $rang = $this->aWholeNumber($ligne->rank);

                if ($sujet === null || $rang === null || $rang < 1) {
                    continue;
                }

                $rangs[$sujet] = $rang;
            }
        }

        return RankReferenceView::published($publieLe, $rangs);
    }

    /**
     * Les sujets classes d une categorie, par identifiant.
     *
     * Un rang nul ou absent est exclu : c est la marque d un sujet hors classement, jamais une
     * position.
     *
     * @return array<int, int>
     */
    private function rankedSubjectsOf(string $table, string $key, string $column): array
    {
        $rangs = [];

        $lignes = DB::table($table)
            ->where($column, '>', 0)
            ->orderBy($column)
            ->get([$key, $column]);

        foreach ($lignes as $ligne) {
            $sujet = $this->aWholeNumber($ligne->{$key});
            $rang = $this->aWholeNumber($ligne->{$column});

            if ($sujet === null || $rang === null) {
                continue;
            }

            $rangs[$sujet] = $rang;
        }

        return $rangs;
    }

    /**
     * Un classement complet est exactement la suite 1, 2, ..., N.
     *
     * Ce controle refuse d un seul coup les trous et les doublons — les deux traces que laisse un
     * passage interrompu ou double.
     *
     * @param array<int, int> $ranks
     */
    private function isAWholeRanking(array $ranks): bool
    {
        $vus = array_values($ranks);
        sort($vus);

        foreach ($vus as $position => $rang) {
            if ($rang !== $position + 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Une valeur de colonne rendue en entier, ou rien.
     *
     * Le pilote rend un `int` ou une chaine de chiffres selon le moteur et la colonne : un cast seul
     * accepterait aussi bien `'12abc'` qu un flottant degrade.
     */
    private function aWholeNumber(mixed $value): int|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int)$value;
        }

        return null;
    }
}
