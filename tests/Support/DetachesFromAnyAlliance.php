<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\History\ClassHistoryRecorder;
use OGame\Models\AllianceMember;
use OGame\Models\User;

/**
 * Detache des joueurs de toute alliance heritee d un voisin, avant d en fonder une.
 *
 * ## Le defaut que ce trait ferme, et il a coute un run
 *
 * Un essai qui monte une alliance prend presque toujours son partenaire dans le monde existant —
 * le proprietaire de la planete etrangere « voisine ». Ce joueur est **partage par toutes les
 * classes d un meme processus**, et plusieurs classes fondent des alliances sans les defaire.
 * `applyToAlliance()` refuse alors : « User is already in an alliance », et la classe rougit pour
 * l etat d une voisine.
 *
 * Le run `34346418655` l a montre : ajouter deux classes d essais a suffi a redistribuer les
 * classes entre processus et a mettre un laissez-pour-compte sur le chemin de
 * `AllianceOffensiveProtectionTest`. Le defaut ne venait pas du code du jeu.
 *
 * ## Pourquoi etablir plutot que supposer
 *
 * « Un essai etablit ce qu il exige, il ne l affirme pas. » Chercher un joueur libre serait plus
 * fragile encore — la planete voisine, elle, n est pas choisie par l essai. On fabrique donc le
 * monde exige : ces joueurs n appartiennent a aucune alliance, ne portent aucune candidature en
 * attente, et aucune echeance de depart ne les retient.
 *
 * ## Ce que le detachement ne fait pas
 *
 * Il ne supprime aucune alliance : une alliance devenue vide est inerte, et l effacer emporterait
 * les rangs et les candidatures d une classe voisine qui n a rien demande. Une classe qui a **fonde**
 * ses alliances les defait, elle, par `dissolveTheBenchAlliances()`.
 *
 * ## La colonne et sa ligne, toujours ensemble
 *
 * Le gel d une flotte a son admission relit l appartenance dans l historique et confronte la derniere
 * ligne a la colonne du compte. Une colonne remise a vide sans sa ligne est exactement l anomalie que
 * cette confrontation existe pour voir : la fermeture du prochain ralliement qui gele ce compte se
 * **suspend**. Huit demontages le faisaient, et le compte touche etait le proprietaire de la planete
 * etrangere voisine, partage par tout le processus — c est le rouge MariaDB de `eb983eb9`
 * (`ClosureVersusTransportArrivalTest`, quinze secondes d attente sans raison) et l intermittent
 * « did not close at once » de `PersistentMoonDestructionTest`. Les deux ecrivains de ce trait
 * n ecrivent jamais la colonne seule ; `BenchCleanupKeepsTheClassHistoryCoherentTest` le tient.
 */
trait DetachesFromAnyAlliance
{
    /**
     * Defait, au demontage, les alliances qu un essai a fondees — comme le jeu les dissout : chaque membre en
     * sort avec sa ligne d historique, puis les inscriptions, les candidatures et l alliance partent.
     */
    protected function dissolveTheBenchAlliances(int|null ...$alliances): void
    {
        $alliances = array_values(array_filter($alliances, static fn (int|null $id): bool => $id !== null && $id > 0));

        if ($alliances === []) {
            return;
        }

        $membres = DB::table('users')->whereIn('alliance_id', $alliances)->pluck('id')->map(static fn (mixed $id): int => (int)$id)->all();
        $this->detachFromAnyAlliance(...$membres);

        DB::table('alliance_members')->whereIn('alliance_id', $alliances)->delete();
        DB::table('alliance_applications')->whereIn('alliance_id', $alliances)->delete();
        DB::table('alliances')->whereIn('id', $alliances)->delete();
    }

    /**
     * Remet en accord l historique d appartenance d un compte que l essai a **volontairement** contredit — la colonne
     * ecrite seule, pour eprouver le lecteur — en ecrivant la ligne que la colonne attend, datee apres la derniere.
     *
     * Le detachement ne le fait pas : il ne voit que les comptes dont la colonne porte encore une alliance, et un
     * compte deja vide dont l historique finit sur une alliance lui echappe. Mesure sur les deux temoins du
     * nettoyage eux-memes, au second passage du detecteur (journal §154.11).
     */
    protected function leaveTheMembershipHistoryCoherentFor(int $userId): void
    {
        $colonne = DB::table('users')->where('id', $userId)->value('alliance_id');
        $derniere = DB::table('alliance_membership_history')
            ->where('user_id', $userId)
            ->latest('changed_at')
            ->orderByDesc('id')
            ->first(['alliance_id', 'changed_at']);

        $alliance = $colonne === null ? null : (int)$colonne;

        if ($derniere !== null && ($derniere->alliance_id === null ? null : (int)$derniere->alliance_id) === $alliance) {
            return;
        }

        // **Apres la derniere ligne, jamais avant** : l essai a pu ramener l horloge en arriere.
        $instant = max((int)Date::now()->timestamp, $derniere === null ? 0 : (int)$derniere->changed_at + 1);
        $horloge = Date::now();
        Date::setTestNow(Date::createFromTimestamp($instant));

        try {
            DB::transaction(static function () use ($userId, $alliance): void {
                resolve(ClassHistoryRecorder::class)->membership($userId, $alliance, 'test');
            });
        } finally {
            Date::setTestNow($horloge);
        }
    }

    /**
     * Fait entrer un compte dans une alliance du banc sans passer par la candidature — la colonne, l inscription
     * et la ligne d historique dans une transaction, comme `AllianceService::acceptApplication()` les ecrit.
     *
     * Deux bancs ecrivaient `$user->alliance_id` puis `save()` « pour contourner l echeance » : le compte
     * portait alors une alliance que son historique ne connaissait pas.
     */
    protected function joinTheBenchAlliance(User $membre, int $allianceId): void
    {
        DB::transaction(static function () use ($membre, $allianceId): void {
            /** @phpstan-ignore assign.propertyType */
            $membre->alliance_id = $allianceId;
            $membre->alliance_left_at = null;
            $membre->save();

            AllianceMember::query()->create([
                'alliance_id' => $allianceId,
                'user_id' => (int)$membre->id,
                'rank_id' => null,
                'joined_at' => now(),
            ]);

            resolve(ClassHistoryRecorder::class)->membership((int)$membre->id, $allianceId, ClassHistoryRecorder::CAUSE_JOIN);
        });
    }

    protected function detachFromAnyAlliance(int ...$joueurs): void
    {
        $joueurs = array_values(array_filter($joueurs, static fn (int $id): bool => $id > 0));

        if ($joueurs === []) {
            return;
        }

        DB::table('alliance_members')->whereIn('user_id', $joueurs)->delete();

        // Une candidature en attente suffit a faire refuser la suivante.
        DB::table('alliance_applications')->whereIn('user_id', $joueurs)->delete();

        // L echeance de depart aussi : elle dure desormais sept jours, et un joueur qu une voisine
        // a fait sortir d une alliance serait retenu par elle.
        // **Le depart et sa ligne d historique, ensemble**, comme le jeu les ecrit. Un compte detache sans
        // sa ligne ferait suspendre le prochain combat durable ou il entre : `ClassHistoryReader` verrait
        // un historique qui finit sur une alliance que la colonne ne porte plus.
        DB::transaction(static function () use ($joueurs): void {
            $partants = DB::table('users')->whereIn('id', $joueurs)->whereNotNull('alliance_id')->pluck('id');

            DB::table('users')->whereIn('id', $joueurs)->update([
                'alliance_id' => null,
                'alliance_left_at' => null,
                'alliance_cooldown_until' => null,
            ]);

            foreach ($partants as $partant) {
                resolve(ClassHistoryRecorder::class)->membership((int)$partant, null, ClassHistoryRecorder::CAUSE_LEAVE);
            }
        });
    }
}
