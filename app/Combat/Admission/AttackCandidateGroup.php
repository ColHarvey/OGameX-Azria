<?php

namespace OGame\Combat\Admission;

use InvalidArgumentException;

/**
 * Une candidate, seule ou en groupe atomique.
 *
 * ## Pourquoi un groupe, et pas une flotte
 *
 * Une attaque ACS deja en vol arrive **ensemble**. La decouper — admettre trois de ses flottes et
 * en renvoyer deux — briserait une attaque coordonnee que ses joueurs ont organisee et payee. Elle
 * est donc **entierement admise ou entierement renvoyee**.
 *
 * Une flotte solitaire est un groupe d'une seule mission : le meme code la traite, sans cas
 * particulier.
 *
 * ## L'identite stable
 *
 * Elle sert de depart d'egalite quand deux groupes arrivent a la meme seconde, et elle doit venir
 * d'une valeur persistee — l'identifiant d'union pour un groupe, celui de la mission pour une
 * flotte seule. Jamais de l'ordre de lecture de la base.
 */
final readonly class AttackCandidateGroup
{
    /**
     * @param string $groupIdentity L'identite stable du groupe.
     * @param array<int, CandidateMission> $missions Ses missions, au moins une.
     */
    public function __construct(
        public string $groupIdentity,
        public array $missions,
    ) {
        if ($groupIdentity === '') {
            throw new InvalidArgumentException(
                'Un groupe candidat sans identite stable ne pourrait pas departager deux arrivees simultanees '
                . 'autrement que par l ordre de lecture de la base.'
            );
        }

        if ($missions === []) {
            throw new InvalidArgumentException(
                'Un groupe candidat vide n a rien a admettre ni a renvoyer.'
            );
        }
    }

    /**
     * Une flotte seule, traitee comme un groupe d'une mission.
     */
    public static function ofASingleFleet(CandidateMission $mission): self
    {
        return new self('mission:' . $mission->missionId, [$mission]);
    }

    /**
     * L'arrivee planifiee du groupe : la **derniere** de ses missions.
     *
     * Un groupe arrive quand sa derniere flotte arrive. Prendre la premiere ferait admettre un
     * groupe dont la queue tombe apres la fermeture.
     */
    public function scheduledArrivalAt(): int
    {
        $dernier = 0;

        foreach ($this->missions as $mission) {
            $dernier = max($dernier, $mission->scheduledArrivalAt);
        }

        return $dernier;
    }

    /**
     * Combien de flottes ce groupe consomme.
     */
    public function fleetCount(): int
    {
        return count($this->missions);
    }

    /**
     * Les joueurs distincts de ce groupe.
     *
     * @return array<int, int>
     */
    public function distinctPlayers(): array
    {
        $joueurs = [];

        foreach ($this->missions as $mission) {
            $joueurs[$mission->userId] = true;
        }

        $identifiants = array_keys($joueurs);
        sort($identifiants);

        return $identifiants;
    }

    /**
     * La comparaison qui ordonne les groupes : arrivee planifiee, puis identite stable.
     */
    public function compareTo(self $other): int
    {
        return [$this->scheduledArrivalAt(), $this->groupIdentity]
            <=> [$other->scheduledArrivalAt(), $other->groupIdentity];
    }
}
