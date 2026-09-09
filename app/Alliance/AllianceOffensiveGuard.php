<?php

namespace OGame\Alliance;

use OGame\Combat\Enums\CombatMissionKind;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;

/**
 * Aucune offensive entre membres d une meme alliance.
 *
 * ## La regle, et d ou elle vient
 *
 * Plan approuve du 9 septembre 2026, section 2 : « Bloquer les ordres offensifs contre tout bien
 * d un membre de la meme alliance : planete, lune, flotte/patrouille, missiles, destruction de lune
 * et groupes ACS. » Elle n existait pas : rien n empechait d attaquer la planete d un membre de sa
 * propre alliance, ni au lancement, ni a l arrivee.
 *
 * ## Un seul decideur, appele a deux moments
 *
 * Le plan exige les deux — « Controles au lancement et a l arrivee avant ouverture/admission, avec
 * garantie sous transaction a l endroit ou l action devient effective. Ne pas se contenter de
 * boutons grises. » Les deux moments appellent donc **cette** methode, jamais deux regles ecrites
 * separement : elles divergeraient au premier changement, et le lancement dirait non quand
 * l arrivee dirait oui.
 *
 * ## Ce qu elle ne decide pas
 *
 * Ni le camp d une bataille ouverte, ni l admissibilite d un renfort : ceux-la sont **figes a
 * l ouverture** et ne se relisent pas. Un allie qui rejoint apres coup n entre pas
 * retroactivement, et un depart ne change aucun camp.
 *
 * ## L interrupteur
 *
 * `alliance_offensive_protection_enabled`, a zero par defaut. Tant qu il vaut non, la regle
 * n existe pas et le jeu se comporte exactement comme avant — pas une lecture d alliance de plus.
 */
final class AllianceOffensiveGuard
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AllianceService $alliances,
    ) {
    }

    /**
     * Cette offensive est-elle interdite parce qu elle vise un membre de la meme alliance ?
     *
     * @param CombatMissionKind $kind le genre de l ordre — un genre non offensif n est jamais refuse
     * @param int $attackerId celui qui lance
     * @param int|null $targetOwnerId le proprietaire du bien vise, ou null s il n en a pas
     */
    public function forbids(CombatMissionKind $kind, int $attackerId, int|null $targetOwnerId): bool
    {
        if (!$this->settings->allianceOffensiveProtectionEnabled()) {
            return false;
        }

        if (!$kind->isOffensiveAgainstAnotherPlayer()) {
            return false;
        }

        // Un bien sans proprietaire — position vide, corps detruit — n appartient a aucune alliance.
        if ($targetOwnerId === null) {
            return false;
        }

        // Se viser soi-meme n est pas une affaire d alliance : d autres regles le refusent deja, et
        // repondre « votre alliance » ici serait faux.
        if ($targetOwnerId === $attackerId) {
            return false;
        }

        return $this->alliances->arePlayersInSameAlliance($attackerId, $targetOwnerId);
    }

    /*
     * ## Ou la coordination vit, et pourquoi pas ici
     *
     * Une variante `forbidsUnderLock()` a existe dans cette classe : elle verrouillait les lignes
     * `users` des deux combattants. **Elle a ete retiree parce qu elle inversait un ordre.** Mesure
     * faite : `PlayerService::update()` verrouille le compte **puis** les planetes, a presque chaque
     * page ; `updateFleetMissions()` verrouille les planetes **puis** les missions. Deux requetes
     * concurrentes du meme joueur — deux onglets — fermaient le cycle.
     *
     * La coordination vit desormais dans `PlayerCoordinationBarrier`, sur une table que rien d autre
     * ne verrouille, prise **en tete** par la porte des mouvements, le chemin administratif et
     * l adhesion. Cette classe-ci ne verrouille donc rien : elle decide, et le rendez-vous est pris
     * avant qu on l appelle.
     */
    /**
     * La clef du message que le joueur lira.
     *
     * **Elle ne revele rien qu il ne sache deja.** Le proprietaire d une planete est public dans la
     * Galaxie, et l appartenance a sa propre alliance l est aussi : dire « c est un membre de votre
     * alliance » n apprend donc rien. Une cible dont l identite n est PAS publique — une patrouille
     * detectee — passe par une porte qui verifie la detection **avant** toute protection, pour que
     * le refus ne devienne pas un detecteur gratuit.
     */
    public function reason(): string
    {
        return 't_ingame.alliance.refusal_offensive_against_a_member';
    }
}
