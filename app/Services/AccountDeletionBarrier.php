<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use OGame\Enums\AccountDeletionState;
use OGame\Models\User;

/**
 * La barriere qui serialise le lancement d'une flotte avec la suppression d'un compte.
 *
 * ## Pourquoi un drapeau ne suffisait pas
 *
 * L'etat « suppression en attente » vit sur le compte, et le lancement le consultait par le modele
 * que la requete tenait deja. Trois choses manquaient pour en faire une barriere.
 *
 * **La lecture n'etait pas fraiche.** `PlayerServiceFactory` garde ses instances et `PlanetService`
 * garde son `PlayerService` : un traitement qui avait charge le joueur **avant** la pose du drapeau
 * continuait a lire « non » longtemps apres son ecriture en base. La lecture se fait donc ici, sur
 * la ligne, jamais sur un modele charge plus tot.
 *
 * **Rien ne serialisait les deux operations.** Un lancement pouvait lire « non », la suppression
 * poser le drapeau et inventorier, puis la mission s'inserer apres l'inventaire — un combat ouvert
 * par une flotte que personne n'annulerait, et une barriere tenant un corps pour toujours. Les deux
 * chemins prennent maintenant le **verrou de la ligne du compte**.
 *
 * ## L'ordre, formule exactement
 *
 * La regle n'est pas « le compte avant tout », et l'annoncer ainsi etait faux : la porte des
 * mouvements et l'annulation commencent par la barriere sans jamais toucher la ligne d'un compte.
 * La regle est plus etroite, et c'est ce qui la rend vraie :
 *
 * > **Parmi les chemins qui prennent les deux, le compte vient avant la barriere.**
 *
 * Deux chemins seulement prennent la ligne d'un compte : le lancement d'une flotte et la suppression
 * d'un compte. Aucun autre ne la prend, donc aucun cycle ne peut se former — un chemin qui prendrait
 * la barriere puis un compte le fermerait, et c'est cela qu'une garde de source surveille.
 *
 * **Le lancement rapide n'avait pas d'enveloppe.** Le chemin ordinaire de la flotte ouvre une
 * transaction ; celui de la Galaxie, non. Le verrou serait relache avant l'insertion de la mission,
 * et la course reviendrait par la fenetre. C'est pourquoi le controle et la creation vivent dans la
 * meme transaction, ouverte par le service de flotte lui-meme.
 *
 * ## Ce que cette barriere ne ferme pas
 *
 * Elle ferme le lancement. **Une mission deja partie** n'en depend pas : elle peut atteindre le
 * travailleur et ouvrir un combat apres l'inventaire. C'est le plan de retrait qui traite ce
 * cas-la, en differant la suppression tant qu'une flotte du compte peut encore ouvrir ou rejoindre
 * un combat.
 *
 * Et sous SQLite, `lockForUpdate()` ne compile a rien : ce fichier decrit l'ordre et la portee des
 * verrous, il ne les prouve pas. La preuve appartient a MariaDB, dans les deux sens.
 */
final class AccountDeletionBarrier
{
    /**
     * Tient le compte et refuse si sa suppression est en cours.
     *
     * Le verrou est garde jusqu'a la fin de la transaction appelante : c'est ce qui empeche une
     * suppression de s'intercaler entre ce controle et l'ecriture qui suit.
     *
     * @param int $userId
     * @return void
     *
     * @throws Exception Si le compte est en suppression en attente.
     */
    public static function refuseIfTheAccountIsBeingDeleted(int $userId): void
    {
        if (self::heldState($userId) === AccountDeletionState::Pending) {
            throw new Exception('Ce compte est en cours de suppression : il ne peut plus lancer de flotte.');
        }
    }

    /**
     * Tient le compte et rend ce que sa ligne dit de sa suppression.
     *
     * ## Pourquoi un etat, et pas un booleen
     *
     * Le controle rendait « en attente ou non », et confondait **une ligne absente** avec une ligne
     * presente sans drapeau. Apres la validation du drapeau, deux chemins peuvent entrer : la
     * suppression qui vient de le poser, et la commande de reprise. L'un efface le compte ; l'autre
     * prend le verrou ensuite, ne trouve plus rien, et poursuivrait avec son modele et sa liste de
     * corps perimes — en effacant des lignes qui ne lui appartiennent plus.
     *
     * @param int $userId
     * @return AccountDeletionState
     */
    public static function heldState(int $userId): AccountDeletionState
    {
        $ligne = DB::table('users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->first(['deletion_pending_since']);

        if ($ligne === null) {
            return AccountDeletionState::Absent;
        }

        return $ligne->deletion_pending_since === null
            ? AccountDeletionState::NotPending
            : AccountDeletionState::Pending;
    }

    /**
     * Tient le compte et pose le drapeau, sans rien decider d'autre.
     *
     * **Cette ecriture doit etre validee avant l'inventaire.** Une defaillance pendant la
     * suppression doit laisser un compte en attente que la commande de reprise peut reprendre ; si
     * le drapeau vivait dans la meme transaction que la purge, il disparaitrait avec elle et le
     * compte redeviendrait un compte ordinaire au milieu d'un retrait commence.
     *
     * **Et `DB::transaction()` ne suffit pas a le garantir** : appele sous une transaction
     * existante, Laravel n'ouvre qu'un point de sauvegarde, et un retour arriere exterieur emporte
     * le drapeau avec le reste. C'est pourquoi l'appel est refuse hors du niveau zero : la garantie
     * ne peut pas dependre de l'habitude des appelants.
     *
     * @param int $userId
     * @param int $now
     * @return void
     *
     * @throws Exception Si une transaction est deja ouverte.
     */
    public static function markPending(int $userId, int $now): void
    {
        if (DB::transactionLevel() > 0) {
            throw new Exception(
                'Le drapeau de suppression se valide dans sa propre transaction : appele sous une transaction '
                . 'existante, il ne serait qu un point de sauvegarde, et un retour arriere exterieur l effacerait.'
            );
        }

        DB::transaction(function () use ($userId, $now): void {
            $compte = User::query()->whereKey($userId)->lockForUpdate()->first();

            if (!$compte instanceof User) {
                return;
            }

            if ($compte->deletion_pending_since === null) {
                $compte->deletion_pending_since = $now;
            }

            $compte->deletion_deferred_reason = null;
            $compte->save();
        });
    }
}
