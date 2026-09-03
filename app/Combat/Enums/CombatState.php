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
 *     Ralliement ─→ Actif ─→ Resolution ─→ Termine
 *
 * `Ralliement` couvre les soixante secondes qui suivent l'arrivee de la premiere attaque.
 * **Rien n'y est encore calcule** — c'est toute la difference avec la conception precedente,
 * ou le resultat etait fige des l'arrivee.
 *
 * Cette fenetre existe pour les vagues. Dans OGame, on lance plusieurs attaques a quelques
 * secondes d'intervalle sur la meme cible ; un combat qui dure et une vague qui
 * arrive cinq secondes plus tard sont en conflit direct. La fenetre les reconcilie : les
 * flottes qui arrivent pendant ces soixante secondes rejoignent **la meme bataille**, et la
 * photo n'est prise qu'a la fermeture. Un instantane, un calcul, un resultat — la garantie
 * centrale du systeme est intacte, et les vagues restent jouables.
 *
 * **La fenetre ne se prolonge jamais.** Une arrivee tardive ne la rouvre pas et ne la
 * repousse pas : elle dure soixante secondes a partir de la premiere attaque, un point c'est
 * tout. Sans cette regle, un attaquant pourrait la maintenir ouverte indefiniment en faisant
 * arriver une sonde toutes les cinquante secondes.
 *
 * Le verrou du corps celeste se pose des la premiere arrivee, donc des l'entree en
 * `Ralliement`.
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
     * La fenetre de ralliement est ouverte : les flottes se rassemblent, rien n'est calcule.
     */
    case Rallying = 'rallying';

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
            self::Rallying => [self::Active, self::Cancelled],
            // **Un combat actif s'annule aussi — jamais par un joueur.** `canBeCancelledFor()` refuse
            // toute cause qu'un joueur pourrait provoquer ; ce qui reste est la sortie
            // d'exploitation : un combat que le reglement ne sait plus appliquer et qui, laisse tel
            // quel, tiendrait son corps pour toujours. L'annulation rend les flottes et libere le
            // corps ; la bataille calculee n'est jamais appliquee.
            self::Active => [self::Resolving, self::Cancelled],
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
     * Le verrou couvre `Rallying` autant qu'`Active`, et il se pose des la premiere arrivee.
     * Pendant le ralliement rien n'est encore calcule, mais les forces presentes sont deja
     * celles qui composeront la photo : laisser partir une flotte dans cette fenetre la ferait
     * echapper a une bataille dont elle fait partie.
     *
     * `Resolving` reste verrouille : le resultat s'applique, et rien ne doit bouger pendant
     * qu'on retire des pertes et distribue un butin.
     */
    public function locksTargetBody(): bool
    {
        return match ($this) {
            self::Rallying, self::Active, self::Resolving => true,
            self::Resolved, self::Cancelled => false,
        };
    }

    /**
     * Get whether the attacking fleet may still be recalled.
     *
     * Regle arretee : le rappel **volontaire** n'est possible qu'avant l'arrivee. Des qu'une
     * flotte est admise dans un combat, elle y est engagee et ne peut plus en sortir.
     *
     * A ne pas confondre avec le demi-tour automatique d'une flotte arrivee trop tard : celui-la
     * n'est pas un rappel demande par le joueur, c'est le serveur qui constate qu'elle n'a plus
     * rien a faire la. Le joueur ne choisit ni l'un ni l'autre.
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
     * qui ferme la fenetre de ralliement a tout retrait : elle dure une minute, pendant
     * laquelle un attaquant voit arriver les renforts du defenseur. Un rappel accepte la
     * reviendrait a laisser fuir celui qui a compris qu'il allait perdre.
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
