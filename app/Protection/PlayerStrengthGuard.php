<?php

namespace OGame\Protection;

use OGame\Combat\Enums\CombatMissionKind;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\SettingsService;
use Throwable;

/**
 * La protection des debutants : un joueur trop fort ne frappe pas un joueur trop faible, ni l inverse.
 *
 * ## Ce qui existait, et ce qui manquait
 *
 * `PlayerService::isNewbie()` et `isStrong()` etaient ecrites depuis l origine, avec leurs seuils —
 * moins de 20 % des points de l autre, plus de 500 % des siens. Mais **elles n etaient lues qu a un
 * seul endroit** : la composition d une ligne de Galaxie, pour afficher une lettre de statut a cote
 * du nom. Aucun refus du serveur ne les consultait. Un joueur a cinq cent mille points pouvait donc
 * attaquer un debutant a deux cents, et rien ne l en empechait.
 *
 * Cette garde les branche enfin, sur les memes chemins que la protection d alliance.
 *
 * ## Les deux sens, et pourquoi un seul test suffit
 *
 * Les deux seuils sont **symetriques** : « moins de 20 % des points de l autre » est exactement
 * « l autre a plus de cinq fois les miens ». Interroger l attaquant dans les deux sens couvre donc
 * les deux directions — le faible qui viserait un geant comme le geant qui ecraserait un debutant.
 *
 * ## Ce que cette garde ne fait pas, et le dit
 *
 * - **Elle ne juge qu au lancement.** Les points bougent en permanence ; annuler en vol une attaque
 *   legale au depart punirait le joueur pour la croissance d un autre. C est aussi ce que fait le jeu
 *   d origine.
 * - **Elle ne protege pas un compte inactif.** Un inactif perd sa protection — c est la regle du jeu
 *   d origine, et c est ce qui rend la recolte possible. `isNewbie()` et `isStrong()` refusent deja
 *   de qualifier un attaquant inactif ; ce refus-ci ajoute la meme chose pour la cible.
 * - **Elle ne touche pas a l espionnage.** `isOffensiveAgainstAnotherPlayer()` l exclut, comme le jeu
 *   d origine : on peut toujours sonder un debutant.
 * - **Elle ne remplace pas la protection d alliance** : les deux se posent, et la premiere qui refuse
 *   parle.
 */
final class PlayerStrengthGuard
{
    public function __construct(
        private readonly SettingsService $settings = new SettingsService(),
        private readonly PlayerServiceFactory $players = new PlayerServiceFactory(),
    ) {
    }

    /**
     * Cette offensive est-elle interdite par l ecart de force entre les deux joueurs ?
     *
     * Rend `false` des que la question ne se pose pas : interrupteur desarme, genre non offensif,
     * joueur qui se vise lui-meme, cible inconnue ou inactive.
     */
    public function forbids(CombatMissionKind $kind, int $attackerId, int|null $targetOwnerId): bool
    {
        /*
         * **Le second terme est un court-circuit, pas une garde** — et sa mutation survit, ce qui
         * est juste. Avec deux scores egaux, `points < points x 0,2` et `points > points x 5` sont
         * faux tous les deux : se viser soi-meme n a jamais pu etre interdit par l ecart de force.
         * Il evite deux chargements de service, rien de plus.
         *
         * La garde d alliance a le meme test, et la-bas il est **indispensable** :
         * `arePlayersInSameAlliance(a, a)` rend vrai. Deux tests qui se ressemblent, deux raisons
         * differentes — ne pas en deduire l une de l autre.
         */
        if ($targetOwnerId === null || $targetOwnerId === $attackerId) {
            return false;
        }

        if (!$this->settings->newbieProtectionEnabled() || !$kind->isOffensiveAgainstAnotherPlayer()) {
            return false;
        }

        try {
            $attaquant = $this->players->make($attackerId, true);
            $cible = $this->players->make($targetOwnerId, true);
        } catch (Throwable) {
            // **Un joueur qu on ne peut pas lire ne fabrique pas une protection.** Refuser ici
            // bloquerait une attaque legitime sur un compte en cours de suppression ; laisser passer
            // la laisse aux autres gardes, qui savent dire pourquoi.
            return false;
        }

        // **Un compte inactif n est pas protege.** Le recolter est une mecanique du jeu, et la
        // protection des debutants n a jamais eu pour role de l empecher.
        if ($cible->isInactive()) {
            return false;
        }

        return $attaquant->isNewbie($cible) || $attaquant->isStrong($cible);
    }

    /**
     * La clef de la phrase que le joueur lira. Elle ne nomme ni les points ni les seuils : le refus
     * dit la regle, pas le classement de la cible.
     */
    public function reason(): string
    {
        return 't_ingame.protection.strength_difference';
    }
}
