<?php

namespace OGame\Services;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\DailyReward;
use OGame\Models\User;

/**
 * La recompense quotidienne de connexion : mille de matiere noire, **a reclamer a la main**, une fois par jour.
 *
 * Fonctionnalite propre a Azria (demande de Keven, 20 septembre 2026), sans equivalent officiel : elle n imite
 * aucune regle d OGame et ne se presente pas comme telle.
 *
 * ## Les regles, et ce qu elles excluent
 *
 * - **Une par compte et par journee**, jamais par planete.
 * - **Renouvellement a minuit**, dans le fuseau configure du jeu — pas vingt-quatre heures apres la derniere
 *   reclamation. Lundi 23 h 55 on reclame ; mardi 00 h 00 la suivante est disponible.
 * - **Aucun cumul, aucun rattrapage** : une journee non reclamee est perdue au renouvellement. On ne regarde
 *   jamais les journees passees.
 * - **Se connecter ne credite rien** : il faut le geste.
 *
 * ## Ce qui rend le double credit impossible
 *
 * Pas une lecture suivie d une ecriture — deux onglets liraient tous deux « pas encore ». C est la **contrainte
 * unique (compte, journee)** de `daily_rewards` : la seconde insertion est refusee par le moteur, quel que soit
 * l ordre des processus. Le credit ne part qu apres une insertion **reussie**, dans la meme transaction.
 *
 * Et si le reseau perd la reponse, une nouvelle tentative retombe sur la meme ligne : elle rend l etat deja
 * reclame **sans recrediter**.
 *
 * ## Ce que ce service ne touche pas
 *
 * La regeneration periodique (`dark_matter_regen_*`, colonne `dark_matter_last_regen`), les recompenses
 * d evenement, et la matiere noire achetee. Le credit passe par `DarkMatterService::credit()` — le circuit de la
 * matiere noire **gratuite**, celui qui verrouille la ligne du compte et enregistre une transaction.
 */
class DailyRewardService
{
    // **La meme regle que Laravel pour reconnaitre un conflit de concurrence.** Reecrire cette
    // classification ferait deriver mon verdict de celui qui decide des reprises : les deux doivent
    // s accorder, sinon une erreur reprise serait rendue comme definitive, ou l inverse.
    use DetectsConcurrencyErrors;

    /** Le compte a ete credite maintenant. */
    public const string CLAIMED = 'claimed';

    /** Il l avait deja ete aujourd hui : rien n a bouge. */
    public const string ALREADY = 'already';

    /** La fonctionnalite est fermee. */
    public const string CLOSED = 'closed';

    /**
     * **La base etait trop disputee pour trancher, et l issue reste inconnue.**
     *
     * Ce que le service sait : **cette tentative-ci** a ete annulee et n a rien ecrit. Ce qu il ne sait
     * pas : si une autre demande du meme compte a abouti entre-temps. Promettre « rien n a ete credite »
     * serait donc affirmer plus que ce qui est etabli (correction de Keven, 20 septembre 2026) ; le
     * message dit « impossible de confirmer », et le client relit l etat du serveur.
     *
     * Redemander reste sans risque : la contrainte unique interdit un second credit, et la demande
     * suivante rendra `ALREADY` si la journee a bien ete prise.
     */
    public const string BUSY = 'busy';

    /** Le nombre de tentatives accordees a une reclamation avant de rendre `BUSY`. */
    public const int ATTEMPTS = 5;

    public function __construct(
        private DarkMatterService $darkMatterService,
        private SettingsService $settingsService,
    ) {
    }

    /**
     * La journee du serveur a laquelle un instant appartient : une DATE, dans le fuseau configure du jeu.
     *
     * C est le seul endroit qui decide « quel jour on est ». L horloge du navigateur n entre jamais ici.
     */
    public function serverDay(Carbon $now): string
    {
        return $now->copy()->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * L instant du prochain renouvellement : minuit suivant, dans le fuseau du jeu.
     */
    public function nextRenewal(Carbon $now): Carbon
    {
        return $now->copy()->setTimezone(config('app.timezone'))->addDay()->startOfDay();
    }

    /**
     * L etat que la page doit montrer, decide par le serveur seul.
     *
     * @return array{enabled: bool, amount: int, claimed: bool, seconds_remaining: int, server_now: int, timezone: string}
     */
    public function stateFor(User $user, Carbon $now): array
    {
        $ouverte = $this->settingsService->dailyRewardEnabled();
        $restant = max(0, $this->nextRenewal($now)->getTimestamp() - $now->getTimestamp());

        return [
            'enabled' => $ouverte,
            'amount' => $this->settingsService->dailyRewardAmount(),
            'claimed' => $ouverte && $this->alreadyClaimed($user, $now),
            // **Des secondes, jamais un instant absolu** : le navigateur compte a rebours sans jamais decider.
            'seconds_remaining' => $restant,
            'server_now' => $now->getTimestamp(),
            'timezone' => (string)config('app.timezone'),
        ];
    }

    /**
     * La recompense d aujourd hui a-t-elle deja ete reclamee ?
     */
    public function alreadyClaimed(User $user, Carbon $now): bool
    {
        return DailyReward::query()
            ->where('user_id', $user->id)
            ->where('reward_date', $this->serverDay($now))
            ->exists();
    }

    /**
     * Reclamer la recompense du jour.
     *
     * Quatre issues, et aucune n est une erreur pour le joueur :
     *
     * - `CLOSED`  — la fonctionnalite est fermee ;
     * - `ALREADY` — le compte l avait deja pour cette journee, y compris quand deux demandes arrivent ensemble
     *               et que la base refuse la seconde ;
     * - `BUSY`    — la base etait trop disputee pour trancher : **cette tentative** n a rien ecrit, et l issue
     *               d une eventuelle demande concurrente reste inconnue. Redemander est sans risque ;
     * - `CLAIMED` — le compte vient d etre credite.
     *
     * **Aucun credit n a lieu hors d une insertion reussie.** L insertion et le credit vivent dans la meme
     * transaction : si le credit echoue, la ligne disparait avec lui et une nouvelle tentative reste possible.
     */
    public function claim(User $user, Carbon $now): string
    {
        if (!$this->settingsService->dailyRewardEnabled()) {
            return self::CLOSED;
        }

        $journee = $this->serverDay($now);
        $montant = $this->settingsService->dailyRewardAmount();

        // **Le compteur passe par un objet, jamais par un entier capture par reference** : PHPStan tient une
        // variable ainsi capturee pour constante et ne verrait jamais la valeur incrementee dans la fermeture.
        $essais = new class () {
            public int $tentatives = 0;
        };

        try {
            // **Cinq tentatives, et la raison tient a InnoDB.** Quatre demandes simultanees insertent la meme
            // clef unique ; le moteur prend un verrou de proximite pour verifier l unicite, et des trois
            // demandeurs il en designe une victime avec l erreur 1213. Mesure au bac le 20 septembre 2026 :
            // un processus sur quatre mourait ainsi, et le joueur lisait une erreur au lieu de « deja
            // reclamee » — alors meme que l invariant tenait, un seul credit ayant eu lieu.
            //
            // Une reprise est sure ici, et pas seulement commode : la victime a ete **annulee**, donc rien
            // n a ete credite. A la tentative suivante, soit le gagnant a valide et l insertion tombe sur la
            // clef unique — le `catch` relit et rend ALREADY —, soit elle attend son verrou puis tombe sur la
            // meme clef. Aucun nombre de tentatives ne peut produire deux credits : c est la contrainte
            // unique qui l interdit, pas le compte de reprises.
            //
            // Laravel ne reprend que les erreurs de concurrence : une panne provoquee entre les deux
            // ecritures remonte toujours du premier coup, et le temoin d atomicite reste valable.
            return DB::transaction(function () use ($user, $now, $journee, $montant, $essais): string {
                $essais->tentatives++;

                // **L insertion d abord.** C est elle qui tranche : si une autre demande a deja pris cette
                // journee, le moteur refuse ici, avant tout credit.
                DailyReward::query()->create([
                    'user_id' => $user->id,
                    'reward_date' => $journee,
                    'amount' => $montant,
                    'claimed_at' => $now,
                ]);

                $this->darkMatterService->credit(
                    $user,
                    $montant,
                    DarkMatterTransactionType::DAILY_REWARD->value,
                    'Daily login reward for ' . $journee
                );

                return self::CLAIMED;
            }, self::ATTEMPTS);
        } catch (QueryException $erreur) {
            // **Une seconde demande pour la meme journee.** On ne devine pas : on relit. Si la ligne est la, la
            // recompense etait deja prise et rien ne doit etre credite.
            if ($this->alreadyClaimed($user, $now)) {
                return self::ALREADY;
            }

            // **Les tentatives sont epuisees et le conflit tient toujours.** La derniere transaction a ete
            // annulee, donc cette tentative-ci n a rien ecrit ; mais une demande concurrente du meme compte
            // a pu aboutir entre la relecture ci-dessus et cet instant. On ne promet donc pas « rien n a ete
            // credite » : on dit que l issue n est pas confirmee, et le client relit l etat du serveur.
            // Redemander est sans risque — l insertion reste la seule porte, la contrainte unique le seul juge.
            if ($this->causedByConcurrencyError($erreur)) {
                Log::warning('Recompense quotidienne : conflit tenace, issue non confirmee.', [
                    'user_id' => $user->id,
                    'reward_date' => $journee,
                    'tentatives' => $essais->tentatives,
                ]);

                return self::BUSY;
            }

            throw $erreur;
        } finally {
            // **La trace d une tentative annulee puis reprise.** Sans elle, une course verte ne dit pas si la
            // reprise a servi : peut-etre qu aucun interblocage ne s est produit (remarque de Keven,
            // 20 septembre 2026). Cette ligne-la, elle, ne s ecrit que si une tentative a bien ete rejouee.
            if ($essais->tentatives > 1) {
                Log::info('Recompense quotidienne : reclamation reprise apres un conflit.', [
                    'user_id' => $user->id,
                    'reward_date' => $journee,
                    'tentatives' => $essais->tentatives,
                ]);
            }
        }
    }
}
