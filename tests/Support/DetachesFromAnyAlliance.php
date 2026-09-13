<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use OGame\History\ClassHistoryRecorder;

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
 * ## Ce que ce trait ne fait pas
 *
 * Il ne supprime aucune alliance : une alliance devenue vide est inerte, et l effacer emporterait
 * les rangs et les candidatures d une classe voisine qui n a rien demande.
 */
trait DetachesFromAnyAlliance
{
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
