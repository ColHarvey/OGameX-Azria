<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Facades\DB;
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
 * chemins prennent maintenant le **verrou de la ligne du compte**, et il vient en tete de l'ordre
 * global : compte, puis barriere, instance, union, missions, corps de retour. Aucun chemin ne prend
 * la barriere avant le compte, donc l'ordre ne se croise pas.
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
        if (self::heldPendingDeletion($userId)) {
            throw new Exception('Ce compte est en cours de suppression : il ne peut plus lancer de flotte.');
        }
    }

    /**
     * Tient le compte et rend son etat de suppression, lu sur la ligne.
     *
     * @param int $userId
     * @return bool
     */
    public static function heldPendingDeletion(int $userId): bool
    {
        return DB::table('users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->value('deletion_pending_since') !== null;
    }

    /**
     * Tient le compte et pose le drapeau, sans rien decider d'autre.
     *
     * **Cette ecriture doit etre validee avant l'inventaire.** Une defaillance pendant la
     * suppression doit laisser un compte en attente que la commande de reprise peut reprendre ; si
     * le drapeau vivait dans la meme transaction que la purge, il disparaitrait avec elle et le
     * compte redeviendrait un compte ordinaire au milieu d'un retrait commence.
     *
     * @param int $userId
     * @param int $now
     * @return void
     */
    public static function markPending(int $userId, int $now): void
    {
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
