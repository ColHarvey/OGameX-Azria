<?php

namespace OGame\Combat\Admission;

use OGame\Combat\Support\CombatRallyWindow;

/**
 * La limite au-dela de laquelle une candidate arrive trop tard.
 *
 * ## Pourquoi une valeur, et pas un instant nu
 *
 * Le selecteur est appele **deux fois** dans la vie d'un combat, et la limite n'est pas la meme :
 *
 * - a l'ouverture, il sert a **calculer** l'echeance : la limite est alors le plafond de la fenetre,
 *   soixante secondes, parce qu'on cherche justement qui pourrait la fixer ;
 * - a la fermeture, il **prononce** le verdict : la limite est l'echeance que la barriere porte,
 *   celle qui a ete persistee a l'ouverture et que seul un rappel peut avoir raccourcie.
 *
 * Confondre les deux laissait entrer, dans une photographie prise a l'echeance, une candidate qui
 * n'arrivait que bien plus tard : il suffisait qu'un rappel libere sa place entre les deux passages.
 * Elle entrait alors dans une bataille avant meme d'etre arrivee.
 *
 * Deux fabriques nommees plutot qu'un drapeau : l'appelant dit ce qu'il fait, et le selecteur n'a
 * pas a savoir dans quelle phase il est appele. Un retard du travailleur n'y change rien — les deux
 * instants sont des faits persistes, jamais l'horloge de celui qui passe.
 */
final readonly class AdmissionCeiling
{
    private function __construct(public int $instant)
    {
    }

    /**
     * Le plafond de la fenetre : on cherche les candidates susceptibles de fixer l'echeance.
     */
    public static function whilePlanningTheWindow(int $openedAt): self
    {
        return new self($openedAt + CombatRallyWindow::WINDOW_SECONDS);
    }

    /**
     * L'echeance que la barriere porte : on prononce le verdict, et rien n'entre apres elle.
     */
    public static function theDeadlineTheBarrierHolds(int $closesAt): self
    {
        return new self($closesAt);
    }
}
