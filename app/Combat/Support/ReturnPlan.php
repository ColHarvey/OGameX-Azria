<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\ReturnDestinationKind;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;

/**
 * Ou une flotte renvoyee va reellement se poser.
 *
 * **Un plan, pas un oui ou non.** Une premiere version se contentait de demander « cette flotte
 * peut-elle rentrer ? ». La question est mal posee : l'absence du corps d'origine ne signifie pas
 * que la flotte est perdue.
 *
 * Le jeu prevoit deja les cas, et ils sont **ordonnes** :
 *
 * - une **planete** n'est jamais detruite par un combat ; elle peut etre abandonnee ou supprimee ;
 * - une **lune**, si. Une flotte partie d'une lune detruite ne disparait pas avec elle : elle
 *   revient sur la planete associee, qui occupe les memes coordonnees — c'est ce que
 *   `PlanetService::planet()` rend deja ;
 * - si le corps **et** le compte ont disparu, le mecanisme de recuperation existant ramene vers
 *   la planete mere avant qu'on ne conclue quoi que ce soit.
 *
 * `cannotReturn()` est donc le **dernier** recours, pas le premier reflexe : il est reserve a un
 * acteur qui n'a plus aucune destination legitime — typiquement une flotte pirate dont la base a
 * ete rasee. Conclure trop vite ferait disparaitre des vaisseaux de joueurs.
 *
 * ## Les invariants que cet objet fait respecter
 *
 * - **la destination appartient au proprietaire de la flotte.** Une flotte de repli ne se pose
 *   jamais chez quelqu'un d'autre, meme si ce corps occupe les coordonnees attendues ;
 * - **le plan porte tout ce qu'il faut pour etre relu** : identifiant, coordonnees, type de corps
 *   et motif du repli. Un identifiant seul obligerait a rededuire la cause, et le rapport ne
 *   pourrait pas expliquer au joueur pourquoi sa flotte s'est posee ailleurs ;
 * - **il est fige au moment de la decision**, sous verrou, puis enregistre avec le mouvement de
 *   retour. Le recalculer a chaque lecture exposerait la flotte a voir sa destination changer en
 *   vol.
 *
 * Si la destination retenue disparait malgre tout avant l'atterrissage, c'est le mecanisme normal
 * de recuperation qui rejoue sous verrou — jamais une perte silencieuse de la flotte.
 */
final readonly class ReturnPlan
{
    /**
     * @param ReturnDestinationKind $kind La nature de la destination retenue.
     * @param int|null $planetId Le corps celeste ou la flotte se posera, ou null si aucun.
     * @param Coordinate|null $coordinate Ses coordonnees, conservees pour l'audit et le rapport.
     * @param PlanetType|null $bodyType Planete ou lune.
     * @param int|null $ownerId Le proprietaire de la flotte, qui doit aussi posseder ce corps.
     * @param CombatReasonCode|null $reason Pourquoi aucun retour n'est possible, le cas echeant.
     */
    private function __construct(
        public ReturnDestinationKind $kind,
        public int|null $planetId,
        public Coordinate|null $coordinate,
        public PlanetType|null $bodyType,
        public int|null $ownerId,
        public CombatReasonCode|null $reason = null,
    ) {
        $aUneDestination = $kind !== ReturnDestinationKind::None;

        if ($aUneDestination && ($planetId === null || $coordinate === null || $bodyType === null || $ownerId === null)) {
            throw new InvalidArgumentException(
                'Un plan de retour avec destination doit porter son identifiant, ses coordonnees, son type de corps et son proprietaire.'
            );
        }

        if (!$aUneDestination && ($planetId !== null || $reason === null)) {
            throw new InvalidArgumentException(
                'Un plan sans destination ne peut pas designer un corps, et doit dire pourquoi la flotte ne rentre pas.'
            );
        }
    }

    /**
     * Le corps d'origine existe toujours : la flotte y revient.
     */
    public static function toOriginalBody(int $planetId, Coordinate $coordinate, PlanetType $bodyType, int $ownerId): self
    {
        return new self(ReturnDestinationKind::OriginalBody, $planetId, $coordinate, $bodyType, $ownerId);
    }

    /**
     * La lune de depart a ete detruite : la flotte se pose sur la planete associee.
     *
     * Le type est necessairement `Planet` — c'est le sens meme de ce repli.
     */
    public static function toAssociatedPlanet(int $planetId, Coordinate $coordinate, int $ownerId): self
    {
        return new self(ReturnDestinationKind::AssociatedPlanet, $planetId, $coordinate, PlanetType::Planet, $ownerId);
    }

    /**
     * Le corps de depart a disparu : le mecanisme de recuperation ramene a la planete mere.
     */
    public static function toHomeworld(int $planetId, Coordinate $coordinate, int $ownerId): self
    {
        return new self(ReturnDestinationKind::Homeworld, $planetId, $coordinate, PlanetType::Planet, $ownerId);
    }

    /**
     * Aucune destination legitime ne subsiste.
     */
    public static function cannotReturn(CombatReasonCode $reason): self
    {
        return new self(ReturnDestinationKind::None, null, null, null, null, $reason);
    }

    /**
     * Si la flotte a quelque part ou se poser.
     */
    public function isPossible(): bool
    {
        return $this->kind !== ReturnDestinationKind::None;
    }

    /**
     * Si la flotte se pose ailleurs que la ou elle etait partie.
     *
     * Ce que le rapport doit expliquer au joueur.
     */
    public function isFallback(): bool
    {
        return $this->kind === ReturnDestinationKind::AssociatedPlanet
            || $this->kind === ReturnDestinationKind::Homeworld;
    }
}
