<?php

namespace OGame\Combat\MoonDestruction;

use InvalidArgumentException;
use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Support\FrozenFact;

/**
 * La lune visee, telle qu'elle etait a la fermeture du ralliement.
 *
 * ## Pourquoi la conserver alors qu'elle est peut-etre detruite
 *
 * Un rapport doit rester lisible apres la disparition de son sujet. Si l'identite n'etait qu'une
 * cle etrangere, la destruction rendrait le rapport muet — le joueur lirait qu'une lune a ete
 * detruite sans savoir laquelle, ou l'ecran echouerait a l'afficher.
 *
 * Le diametre en fait partie : c'est **l'entree des deux probabilites**, et le geler garantit qu'un
 * audit puisse recalculer les chances longtemps apres, sans consulter un corps qui n'existe plus.
 * Le diametre vivant ne doit jamais entrer dans l'application d'un plan gele.
 *
 * ## Planete et lune restent deux corps
 *
 * L'identifiant designe la lune, pas ses coordonnees. Deux corps partagent une position ; un combat
 * sur l'un ne verrouille pas l'autre, et une mission visant la planete ne detruit jamais la lune
 * associee.
 */
final readonly class FrozenMoonIdentity
{
    /**
     * @param int $moonId L'identifiant exact de la lune, pas celui de sa planete.
     * @param string $coordinates Ses coordonnees, conservees pour l'audit et le rapport.
     * @param string $name Son nom au moment du gel.
     * @param int $diameter Son diametre, entree des deux probabilites.
     */
    public function __construct(
        public int $moonId,
        public string $coordinates,
        public string $name,
        public int $diameter,
    ) {
        if ($moonId < 1) {
            throw new InvalidArgumentException(
                'Un plan de destruction vise une lune persistee : sans son identifiant, rien ne distingue la '
                . 'lune de la planete qui partage ses coordonnees.'
            );
        }

        if ($diameter < 1) {
            throw new InvalidArgumentException(
                'Un diametre nul ou negatif ne peut pas avoir servi aux probabilites.'
            );
        }
    }

    /**
     * L'identite, sous une forme comparable apres serialisation.
     *
     * @return array<string, int|string>
     */
    public function toFrozenFacts(): array
    {
        return [
            'moon_id' => $this->moonId,
            'coordinates' => $this->coordinates,
            'name' => $this->name,
            'diameter' => $this->diameter,
        ];
    }

    /**
     * L'identite relue, sans conversion.
     *
     * **Cette methode castait.** `(int)$facts['moon_id']` acceptait une chaine, un flottant, un
     * booleen, et en faisait un identifiant plausible. Or l'identite de la lune entre dans
     * l'empreinte du plan : relue autrement qu'ecrite, elle rend un rejeu different de l'original
     * sans que rien ne le dise.
     *
     * @param array<string, mixed> $facts
     *
     * @throws CorruptedFrozenMoonPlan Si un fait n'a pas le type sous lequel il a ete ecrit.
     */
    public static function fromFrozenFacts(array $facts): self
    {
        return new self(
            FrozenFact::int($facts, 'moon_id'),
            FrozenFact::string($facts, 'coordinates'),
            FrozenFact::string($facts, 'name'),
            FrozenFact::int($facts, 'diameter'),
        );
    }
}
