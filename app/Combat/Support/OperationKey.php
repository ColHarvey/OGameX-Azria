<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\OperationKind;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;

/**
 * L'identite d'une operation, sous laquelle ses diagnostics sont scelles.
 *
 * ## Ce qu'elle rend impossible
 *
 * `phase|sujet|ressource` n'est unique qu'a l'interieur d'une resolution : `attacker_reaper||metal`
 * designe le meme incident dans deux combats differents. L'identite pleinement qualifiee d'une
 * occurrence est donc la paire **cle d'operation + identite locale**, et deux enveloppes de cles
 * differentes refusent de fusionner.
 *
 * ## Pourquoi le genre n'est jamais fourni a cote de l'identifiant
 *
 * Une fabrique qui recevrait les deux separement accepterait une paire incoherente : la mission de
 * recyclage numero 12 scellee comme une attaque immediate. Le genre est donc **derive du modele**,
 * jamais passe en parametre — les deux sortent de la meme ligne, ou la cle n'existe pas.
 *
 * La derivation s'appuie sur `CombatMissionKind::fromMissionType()`, qui connait deja
 * exhaustivement les types du jeu. Recreer ici une seconde table d'entiers aurait produit une
 * classification concurrente — et j'y avais oublie l'attaque groupee, dont chaque resolution aurait
 * alors ete refusee.
 *
 * ## Pourquoi un retour n'ouvre pas d'operation
 *
 * Une flotte qui rentre conserve son `mission_type` : le retour n'est pas un genre mais une etape de
 * vol. Sans controle, elle serait scellee comme une **nouvelle** attaque, et ses diagnostics se
 * melangeraient a ceux de l'aller sous une cle qui n'est pas la sienne. Un `parent_id` present est
 * donc refuse, quel que soit le type.
 *
 * ## Vivant contre relecture
 *
 * Deux chemins, deux fabriques, et la difference se lit dans le code :
 *
 *     creation vivante -> l'identifiant est extrait d'un modele persiste
 *     rehydratation    -> l'identite est relue depuis l'enveloppe persistee
 *
 * La seconde ne touche aucun modele : un combat doit rester lisible longtemps apres que sa mission
 * a disparu de la base.
 */
final readonly class OperationKey
{
    /**
     * @param OperationKind $kind Le genre d'operation.
     * @param int $identifier L'identifiant persiste : instance de combat ou mission de flotte.
     */
    private function __construct(
        public OperationKind $kind,
        public int $identifier,
    ) {
    }

    /**
     * La cle d'une operation qui commence, a partir de sa mission de flotte.
     *
     * @param FleetMission $mission Une mission persistee, sur sa branche aller.
     * @return self
     */
    public static function forFleetMission(FleetMission $mission): self
    {
        if (!$mission->exists) {
            throw new InvalidArgumentException(
                'Une cle d operation exige une mission persistee : ce modele n a jamais ete enregistre, et '
                . 'son identifiant changerait au premier enregistrement.'
            );
        }

        $identifiant = (int)$mission->id;

        if ($identifiant < 1) {
            throw new InvalidArgumentException(
                'Une mission enregistree sans identifiant ne peut pas identifier une operation : plusieurs '
                . 'operations porteraient alors la meme cle, et leurs diagnostics fusionneraient.'
            );
        }

        // Un retour garde le type de l'aller. Le laisser ouvrir une operation melangerait les
        // diagnostics du retour a ceux de l'aller, sous une cle qui n'est pas la sienne.
        if ($mission->parent_id !== null) {
            throw new InvalidArgumentException(
                'Une mission de retour n ouvre pas d operation : elle conserve le type de son aller, et la '
                . 'sceller comme une operation nouvelle confondrait deux etapes du meme vol.'
            );
        }

        return new self(self::kindOf($mission), $identifiant);
    }

    /**
     * La cle d'un combat persistant, a partir de son instance.
     *
     * Le genre est impose : une instance de combat ne peut rien etre d'autre.
     *
     * @param CombatInstance $instance
     * @return self
     */
    public static function forCombatInstance(CombatInstance $instance): self
    {
        if (!$instance->exists) {
            throw new InvalidArgumentException(
                'Une cle d operation exige une instance de combat persistee : ce modele n a jamais ete '
                . 'enregistre.'
            );
        }

        $identifiant = (int)$instance->id;

        if ($identifiant < 1) {
            throw new InvalidArgumentException(
                'Une instance de combat enregistree sans identifiant ne peut pas identifier une operation.'
            );
        }

        return new self(OperationKind::PersistentCombat, $identifiant);
    }

    /**
     * La cle relue depuis une enveloppe persistee.
     *
     * **Aucun modele n'est consulte.** Un combat doit rester lisible longtemps apres que sa mission
     * a disparu de la base : exiger le modele vivant rendrait l'audit dependant de donnees qui, par
     * nature, finissent par etre purgees.
     *
     * **`mixed`, et non `int`.** Sans `strict_types` au site d'appel — aucun fichier du depot ne le
     * declare — un parametre `int` accepte la chaine « 42 » et le flottant 42.0 en les
     * convertissant. Une cle d'idempotence relue depuis une colonne texte serait alors
     * reconstruite sans que personne ne sache d'ou venait le nombre.
     *
     * @param OperationKind $kind
     * @param mixed $identifier
     * @return self
     */
    public static function rehydrate(OperationKind $kind, mixed $identifier): self
    {
        if (!is_int($identifier)) {
            throw new InvalidArgumentException(
                'Une cle d operation relue exige un identifiant entier ; « ' . get_debug_type($identifier)
                . ' » n en est pas un, et le convertir cacherait d ou vient le nombre.'
            );
        }

        if ($identifier < 1) {
            throw new InvalidArgumentException(
                'Une cle d operation relue exige un identifiant strictement positif ; « ' . $identifier
                . ' » ne designe aucune ligne.'
            );
        }

        return new self($kind, $identifier);
    }

    /**
     * Si deux cles designent exactement la meme operation.
     *
     * **Le genre compte autant que le nombre** : la mission 42 et l'instance de combat 42 sont deux
     * operations differentes.
     *
     * @param self $other
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->identifier === $other->identifier;
    }

    /**
     * La cle, sous la forme qu'un journal peut porter.
     */
    public function asString(): string
    {
        return $this->kind->value . ':' . $this->identifier;
    }

    /**
     * Le genre d'operation d'une mission, derive de son type.
     *
     * @param FleetMission $mission
     * @return OperationKind
     */
    private static function kindOf(FleetMission $mission): OperationKind
    {
        $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);

        return match ($genre) {
            CombatMissionKind::Attack, CombatMissionKind::AcsAttack => OperationKind::ImmediateAttack,
            CombatMissionKind::Recycle => OperationKind::Recycling,
            CombatMissionKind::MoonDestruction => OperationKind::MoonDestruction,
            default => throw new InvalidArgumentException(
                'La mission de genre « ' . $genre->value . ' » ne produit pas de diagnostics de conversion : '
                . 'lui donner une cle d operation laisserait croire qu elle en scelle.'
            ),
        };
    }
}
