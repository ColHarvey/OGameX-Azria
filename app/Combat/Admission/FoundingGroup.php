<?php

namespace OGame\Combat\Admission;

use InvalidArgumentException;

/**
 * Le groupe qui a ouvert le combat, et l'alliance qui le gouverne.
 *
 * ## Deux mecanismes, et il ne faut pas les confondre
 *
 *     ACS formelle, avant le combat -> meme joueur, copains ET membres d'alliance
 *     ralliement automatique         -> meme joueur, ou alliance figee de l'ouvreur
 *
 * Un copain deja present dans l'union formelle appartient donc au **groupe fondateur** : il a ete
 * admis avant, par les regles d'OGameX, et rien ne l'en retire. Mais un copain hors alliance ne peut
 * pas **rejoindre automatiquement** pendant la fenetre : le ralliement est reserve a l'alliance.
 *
 * C'est ce qui preserve l'ACS du jeu sans elargir la regle nouvelle.
 *
 * ## L'identite du createur est immuable
 *
 * `FleetUnionService::handleFleetRecall()` transfere aujourd'hui la propriete de l'union au nouveau
 * slot 1. Cette propriete-la est mouvante ; **l'alliance qui gouverne le combat ne l'est pas**. Elle
 * est figee a l'ouverture, a partir du createur d'alors, et un transfert de slot ne la change
 * jamais.
 *
 * Si le createur rappelle sa flotte, les membres deja lances continuent — mais plus personne ne
 * rejoint.
 */
final readonly class FoundingGroup
{
    /**
     * @param int $creatorUserId Le createur de l'union, fige a l'ouverture.
     * @param int|null $governingAllianceId Son alliance a l'ouverture, ou null s'il n'en avait pas.
     * @param array<int, CandidateMission> $members Les missions deja dans le groupe fondateur.
     * @param AdmissionBudget $budget Les plafonds, figes avec le combat.
     * @param bool $stillAcceptsNewMembers Faux si le createur a rappele sa flotte.
     */
    public function __construct(
        public int $creatorUserId,
        public int|null $governingAllianceId,
        public array $members,
        public AdmissionBudget $budget,
        public bool $stillAcceptsNewMembers = true,
    ) {
        if ($creatorUserId < 1) {
            throw new InvalidArgumentException(
                'Un groupe fondateur a toujours un createur persiste : c est de lui que vient l alliance qui '
                . 'gouverne le combat.'
            );
        }

        if ($members === []) {
            throw new InvalidArgumentException(
                'Un groupe fondateur sans membre n aurait ouvert aucun combat.'
            );
        }
    }

    /**
     * Combien de flottes le groupe fondateur consomme deja.
     *
     * **L'ouvreur compte**, comme dans le code actuel : il recoit `union_slot = 1` et reste une
     * mission active, donc `activeFleetMissions()->count()` l'inclut.
     */
    public function fleetCount(): int
    {
        return count($this->members);
    }

    /**
     * Les joueurs distincts deja presents.
     *
     * Plusieurs flottes d'un meme joueur consomment plusieurs flottes et **un seul joueur** — c'est
     * le comportement mesure de `FleetUnionService::joinUnion()`.
     *
     * @return array<int, int>
     */
    public function distinctPlayers(): array
    {
        $joueurs = [];

        foreach ($this->members as $membre) {
            $joueurs[$membre->userId] = true;
        }

        $identifiants = array_keys($joueurs);
        sort($identifiants);

        return $identifiants;
    }

    /**
     * Si ce joueur peut rejoindre automatiquement pendant la fenetre.
     *
     * **Les copains n'y sont pas.** Ils gardent l'ACS formelle, organisee avant le combat ; le
     * ralliement automatique est reserve au createur lui-meme et a son alliance figee.
     *
     * @param CandidateMission $mission
     * @return bool
     */
    public function admitsAutomatically(CandidateMission $mission): bool
    {
        if ($mission->userId === $this->creatorUserId) {
            return true;
        }

        if ($this->governingAllianceId === null) {
            return false;
        }

        return $mission->allianceIdAtOpening === $this->governingAllianceId;
    }
}
