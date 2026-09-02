<?php

namespace OGame\Combat\Enums;

/**
 * Les etats d'un combat, et les seuls passages permis entre eux.
 *
 * Conception pure : aucune table, aucune migration. Ce fichier decrit ce qu'un combat *peut*
 * faire, et c'est cette description qui dictera le schema — pas l'inverse.
 *
 * Le cycle nominal :
 *
 *     EnAttente ─→ Actif ─→ Resolution ─→ Termine
 *
 * `EnAttente` couvre la fenetre entre l'arrivee de la flotte et le debut du combat : le
 * resultat y est deja calcule et fige, mais rien n'est encore applique. C'est le moment ou le
 * verrou se pose.
 *
 * `Resolution` est court mais indispensable : il marque qu'un processus applique le resultat.
 * Sans lui, deux workers pourraient croire tous deux qu'un combat `Actif` arrive a echeance
 * doit etre resolu, et produire deux rapports, deux champs de debris, deux retours.
 *
 * `Cancelled` n'est pas un echec, et n'est **jamais** a la main d'un joueur : c'est la
 * disparition de la cible, celle de l'attaquant, une decision d'administration, ou une photo
 * incoherente. L'annulation exige une cause prise dans `CombatCancellationCause`, et aucune
 * n'est declenchable par un rappel.
 *
 * Un combat deja `Active` ne peut plus etre annule du tout — c'est la contrepartie de la
 * regle « un combat engage ne se rappelle pas ».
 */
enum CombatState: string
{
    /**
     * Le resultat est calcule et fige, le combat n'a pas commence.
     */
    case Pending = 'pending';

    /**
     * Le combat se deroule. Le corps celeste vise est verrouille.
     */
    case Active = 'active';

    /**
     * Un processus applique le resultat. Verrou pose contre toute seconde application.
     */
    case Resolving = 'resolving';

    /**
     * Tout est applique : rapports, pertes, butin, debris, retours.
     */
    case Resolved = 'resolved';

    /**
     * Le combat n'aura pas lieu : rappel avant arrivee, ou cible disparue.
     */
    case Cancelled = 'cancelled';

    /**
     * Get the states this one may move to.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Cancelled],
            self::Active => [self::Resolving],
            self::Resolving => [self::Resolved],
            // Deux etats terminaux : rien n'en sort, et c'est ce qui rend la resolution
            // idempotente. Un job relance retrouve un combat deja `Resolved` et n'a rien a
            // faire, au lieu de rejouer le resultat.
            self::Resolved, self::Cancelled => [],
        };
    }

    /**
     * Get whether this state may move to the given one.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Get whether the combat is over, whatever its outcome.
     */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Get whether the targeted celestial body is locked in this state.
     *
     * Le verrou couvre `Pending` autant qu'`Active` : entre l'arrivee de la flotte et le
     * premier round, le resultat est deja fige. Laisser partir une flotte dans cette fenetre
     * la ferait echapper a une bataille qui la compte deja parmi les defenseurs.
     *
     * `Resolving` reste verrouille : le resultat s'applique, et rien ne doit bouger pendant
     * qu'on retire des pertes et distribue un butin.
     */
    public function locksTargetBody(): bool
    {
        return match ($this) {
            self::Pending, self::Active, self::Resolving => true,
            self::Resolved, self::Cancelled => false,
        };
    }

    /**
     * Get whether the attacking fleet may still be recalled.
     *
     * Regle arretee : le rappel n'est possible qu'**avant l'arrivee**, donc avant qu'un combat
     * existe. Des qu'il en existe un — meme a l'etat `Pending`, avant le premier round — la
     * flotte y est engagee et son resultat est deja calcule.
     *
     * Aucun etat ne rend donc vrai : il n'y a pas d'etat de combat dans lequel un rappel soit
     * acceptable.
     */
    public function allowsRecall(): bool
    {
        return false;
    }

    /**
     * Get whether this state may be cancelled for the given cause.
     *
     * L'annulation **exige une cause**, et aucune cause n'est a la main d'un joueur. C'est ce
     * qui ferme la fenetre entre l'arrivee et le premier round : elle est courte, mais le
     * resultat y est deja calcule, donc deja connaissable par qui saurait le lire. Un rappel
     * accepte a ce moment-la reviendrait a effacer une bataille perdue d'avance.
     *
     * Exiger une valeur plutot que documenter une intention : on ne peut pas oublier de la
     * fournir, et il n'en existe aucune qui conviendrait a un rappel.
     */
    public function canBeCancelledFor(CombatCancellationCause $cause): bool
    {
        if ($cause->isPlayerInitiated()) {
            return false;
        }

        return $this->canTransitionTo(self::Cancelled);
    }
}
