<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le badge d'administrateur du classement se pose tout seul, et se retire tout seul.
 *
 * ## Ce que ce temoin ferme
 *
 * Le badge suit le **role** du compte, pas une liste d'identifiants : `isAdmin()` demande
 * `hasRole('admin')`, et le classement le lit pour chaque joueur qu'il affiche. Donner le role
 * suffit a faire apparaitre le badge, le retirer suffit a le faire disparaitre.
 *
 * Un essai qui verifierait seulement sa presence laisserait passer un badge colle a tout le monde —
 * c'est pourquoi les deux etats sont mesures sur le meme compte, avant et apres.
 *
 * ## Le reglage qui conditionne tout
 *
 * `highscore_admin_visible` vaut **zero par defaut** : les comptes administrateurs sont retires du
 * classement avant meme d'etre affiches. Un badge parfait resterait alors invisible. L'essai leve
 * donc ce reglage — et le fait de devoir le lever est en soi ce qui documente la dependance.
 */
class HighscoreAdminBadgeTest extends AccountTestCase
{
    /**
     * Le badge apparait avec le role, et disparait avec lui.
     */
    public function testTheBadgeFollowsTheAdminRole(): void
    {
        $reglages = resolve(SettingsService::class);
        $visibiliteAvant = $reglages->highscoreAdminVisible();
        $reglages->set('highscore_admin_visible', '1');

        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur, 'The test planet has no owner.');
        $utilisateur = $joueur->getUser();
        $portaitLeRole = $utilisateur->hasRole('admin');

        try {
            if ($portaitLeRole) {
                $utilisateur->removeRole('admin');
            }

            $this->assertStringNotContainsString(
                'badge-admin.png',
                $this->rowOf($this->currentUserId),
                'A player without the admin role was already wearing the badge.'
            );

            $utilisateur->assignRole('admin');

            $this->assertStringContainsString(
                'badge-admin.png',
                $this->rowOf($this->currentUserId),
                'The admin role was granted but the badge did not appear: it is not read from the role.'
            );
        } finally {
            // L'epreuve rend le compte et le reglage tels qu'elle les a trouves : la base d'un
            // processus est partagee, et ce qu'elle laisserait changerait ce que mesure la voisine.
            if (!$portaitLeRole && $utilisateur->hasRole('admin')) {
                $utilisateur->removeRole('admin');
            }

            $reglages->set('highscore_admin_visible', $visibiliteAvant ? '1' : '0');
            Cache::flush();
        }
    }

    /**
     * Le classement rendu, cache vide.
     *
     * Ses pages sont gardees cinq minutes : sans cette remise a zero, le second rendu servirait le
     * premier et l'essai passerait en ne mesurant rien.
     */
    private function rowOf(int $userId): string
    {
        Cache::flush();

        $reponse = $this->get('/highscore');
        $reponse->assertStatus(200);

        // **On lit la ligne, jamais la page.** Le classement montre tous les joueurs, et la base d un
        // processus en garde d autres — dont un administrateur laisse par une classe voisine. Chercher
        // le badge dans la page entiere le trouverait chez quelqu un d autre.
        $page = (string)$reponse->getContent();
        $debut = strpos($page, 'id="position' . $userId . '"');
        $this->assertNotFalse($debut, 'The player has no row in the highscore.');

        $fin = strpos($page, '</tr>', $debut);

        return substr($page, $debut, $fin === false ? null : $fin - $debut);
    }
}
