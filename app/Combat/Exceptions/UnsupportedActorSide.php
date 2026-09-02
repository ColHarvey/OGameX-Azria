<?php

namespace OGame\Combat\Exceptions;

use OGame\Combat\Enums\UnsupportedSideReason;
use RuntimeException;

/**
 * Un camp attaquant qu'aucune regle de pillage ne couvre.
 *
 * ## Pourquoi refuser plutot que choisir
 *
 * `cargo_weighted_v1` appliquee a un camp mixte traiterait le fret pirate comme du fret
 * non-Decouvreur : le taux serait calcule, plausible, et faux — personne n'a jamais decide ce qu'un
 * camp mixte doit piller. Choisir une regle dans un bloc `else` reviendrait a ecrire une mecanique
 * de jeu par accident.
 *
 * ## Une seule famille, et c'est le point
 *
 * Camp vide, camp mixte, presence du compte systeme : trois raisons, **une seule exception**. Trois
 * classes distinctes inviteraient a trois `catch`, et la quatrieme composition inventee un jour
 * traverserait la frontiere sans repli — laissant la mission non traitee, rejouee a chaque passage
 * de l'ordonnanceur.
 *
 * ## Ce que cette exception n'est pas
 *
 * Une panne a rattraper generiquement. Le site d'execution qui l'attrape doit l'attraper **elle**,
 * et rien d'autre : transformer toute exception en absence de butin masquerait une vraie erreur
 * sous une regle de jeu.
 */
class UnsupportedActorSide extends RuntimeException
{
    /**
     * @param UnsupportedSideReason $reason Ce qui rend ce camp irrecevable.
     * @param string $message
     */
    private function __construct(
        public readonly UnsupportedSideReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param UnsupportedSideReason $reason
     * @param array<int, string> $actorKinds Les genres rencontres, par identifiant de mission.
     * @return self
     */
    public static function because(UnsupportedSideReason $reason, array $actorKinds): self
    {
        $description = [];

        foreach ($actorKinds as $missionId => $kind) {
            $description[] = $missionId . ':' . $kind;
        }

        return new self(
            $reason,
            'Aucune regle de pillage ne couvre ce camp attaquant (' . $reason->value . ') : '
            . (count($description) > 0 ? implode(', ', $description) : 'aucun attaquant')
            . '. Un camp melangeant joueurs et acteurs pilotes par le serveur, vide, ou comprenant le compte '
            . 'systeme, exige une regle decidee explicitement.'
        );
    }
}
