<?php

namespace OGame\Alliance;

use Illuminate\Support\Facades\DB;
use OGame\Services\SettingsService;

/**
 * Le rendez-vous que prennent, avant tout autre verrou, les chemins qui peuvent se contredire.
 *
 * ## Ce qu il coordonne
 *
 * Trois chemins decident de choses qui doivent rester coherentes entre elles :
 *
 *  - l **adhesion** a une alliance, qui refuse de reunir deux adversaires d une bataille active ;
 *  - l **arrivee** d une flotte, qui ouvre un combat et refuse de le faire contre un allie ;
 *  - le **traitement administratif** d une mission bloquee, qui emprunte la meme porte.
 *
 * Sans rendez-vous, deux d entre eux lisent le monde en meme temps, chacun conclut qu il peut agir,
 * et les deux ecrivent : un combat s ouvre entre deux membres d une meme alliance.
 *
 * ## Trois regles d acquisition, et aucune n est facultative
 *
 * 1. **Avant tout le reste.** Une barriere prise apres les corps, ou apres une mission, ne
 *    coordonnerait rien et fermerait un cycle avec les chemins qui prennent ces verrous-la en
 *    premier. C est exactement ce qui a fait retirer la tentative precedente.
 * 2. **Par identifiant croissant.** Deux chemins qui prennent les memes deux joueurs dans deux
 *    ordres differents s interbloquent ; l ordre stable l empeche.
 * 3. **La ligne existe avant d etre prise.** Verrouiller une ligne absente ne verrouille rien —
 *    le fantome que le depot connait deja. Elle est donc creee, puis relue `for update`.
 *
 * ## Ce que cette classe ne fait pas
 *
 * Elle ne decide rien. Elle ne porte aucun etat : la ligne n a pas de drapeau, pas d horodatage
 * utile, rien qu un identifiant. Ce qu elle donne, c est l ordre — et sous SQLite `lockForUpdate()`
 * ne compile a rien, donc elle ne donne rien du tout : la preuve appartient au bac MariaDB.
 */
final class PlayerCoordinationBarrier
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Prend le rendez-vous de ces joueurs, dans l ordre stable.
     *
     * A appeler **en premier** dans la transaction du chemin appelant. Sans transaction ouverte, le
     * verrou n a aucun effet — c est un fait de la base, pas une opinion, et l appelant doit le
     * savoir.
     *
     * Inerte tant que la protection d alliance n est pas armee : ce rendez-vous n existe que pour
     * elle, et le jeu ne doit pas payer un verrou de plus pour une regle eteinte.
     *
     * @param int ...$userIds les joueurs concernes ; les doublons et les zeros sont ignores
     */
    public function hold(int ...$userIds): void
    {
        if (!$this->settings->allianceOffensiveProtectionEnabled()) {
            return;
        }

        $joueurs = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        if ($joueurs === []) {
            return;
        }

        sort($joueurs);

        // **La ligne existe avant d etre prise.** `insertOrIgnore` est atomique par ligne : deux
        // chemins qui la creent en meme temps n en font pas deux, et celui qui perd n echoue pas.
        $maintenant = now();

        DB::table('player_coordination_barriers')->insertOrIgnore(array_map(
            static fn (int $id): array => ['user_id' => $id, 'created_at' => $maintenant, 'updated_at' => $maintenant],
            $joueurs
        ));

        foreach ($joueurs as $joueur) {
            DB::table('player_coordination_barriers')->where('user_id', $joueur)->lockForUpdate()->first();
        }
    }
}
