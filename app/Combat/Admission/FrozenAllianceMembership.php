<?php

namespace OGame\Combat\Admission;

/**
 * Qui appartenait a l'alliance gouvernante **au moment de l'ouverture**.
 *
 * ## Le defaut que cet objet ferme
 *
 * `RallyCandidateReader` reconstruisait l'appartenance depuis `alliance_members`, en filtrant sur
 * `joined_at <= ouverture`. Le raisonnement paraissait juste et il etait faux : **une sortie
 * supprime la ligne**. Un allie parfaitement admissible a l'ouverture devenait donc inadmissible a
 * la fermeture s'il quittait l'alliance entre-temps.
 *
 * Cela contredit une regle deja arretee et deja eprouvee : *un changement d'alliance apres
 * l'ouverture ne change rien*. J'avais ecrit dans le commentaire du lecteur qu'ecarter un joueur
 * parti « est juste, il est parti » — c'etait substituer mon jugement a une decision prise.
 *
 * ## Pourquoi une photographie, et non une meilleure requete
 *
 * Aucune interrogation de l'etat courant ne peut repondre a une question sur le passe quand
 * l'historique n'existe pas. La seule reponse correcte est de **photographier a l'ouverture** ce
 * dont la fermeture aura besoin, puis de relire cette photographie.
 *
 * C'est le meme principe que le reste du systeme : les faits qui gouvernent un combat sont ecrits
 * avec lui, jamais relus dans un monde qui a change depuis.
 */
final readonly class FrozenAllianceMembership
{
    /**
     * @param int|null $allianceId L'alliance qui gouverne, ou null si l'ouvreur n'en avait pas.
     * @param array<int, true> $members Les proprietaires membres a l'ouverture, indexes par identifiant.
     */
    private function __construct(
        public int|null $allianceId,
        private array $members,
    ) {
    }

    /**
     * La photographie prise a l'ouverture.
     *
     * @param int|null $allianceId
     * @param array<int, int> $memberUserIds
     * @return self
     */
    public static function of(int|null $allianceId, array $memberUserIds): self
    {
        if ($allianceId === null) {
            // Sans alliance qui gouverne, personne d'autre que le createur ne rejoint : une liste
            // de membres n'aurait aucun sens, et en accepter une laisserait croire le contraire.
            return new self(null, []);
        }

        $indexes = [];

        foreach ($memberUserIds as $userId) {
            $indexes[$userId] = true;
        }

        return new self($allianceId, $indexes);
    }

    /**
     * Aucune alliance ne gouverne ce combat.
     */
    public static function none(): self
    {
        return new self(null, []);
    }

    /**
     * La photographie telle qu'elle a ete persistee.
     *
     * @param array<string, mixed>|null $stored
     * @return self
     */
    public static function fromStorage(array|null $stored): self
    {
        if ($stored === null || !isset($stored['alliance_id'])) {
            return self::none();
        }

        $allianceId = $stored['alliance_id'];
        $membres = $stored['members'] ?? [];

        return self::of(
            is_int($allianceId) ? $allianceId : null,
            is_array($membres) ? array_values(array_filter($membres, 'is_int')) : []
        );
    }

    /**
     * Ce qu'il faut ecrire avec le combat.
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'alliance_id' => $this->allianceId,
            'members' => array_keys($this->members),
        ];
    }

    /**
     * L'alliance a inscrire sur une candidate de ce proprietaire.
     *
     * Rend l'alliance gouvernante s'il en etait membre a l'ouverture, `null` sinon. Ce qu'il est
     * devenu depuis ne compte pas : c'est tout l'objet de cette classe.
     */
    public function allianceFor(int $userId): int|null
    {
        return isset($this->members[$userId]) ? $this->allianceId : null;
    }

    /**
     * Les proprietaires membres a l'ouverture.
     *
     * @return array<int, int>
     */
    public function memberUserIds(): array
    {
        $identifiants = array_keys($this->members);
        sort($identifiants);

        return $identifiants;
    }
}
