<?php

namespace OGame\Combat\Admission;

use OGame\Combat\Exceptions\CorruptedFrozenMembership;

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
 *
 * ## Ce que la liste contient, et ce qu'elle ne contient pas
 *
 * **Elle ne represente pas tous les membres de l'alliance.** Elle ne porte que les proprietaires
 * exterieurs qui visaient deja ce corps dans la fenetre, au moment de l'ouverture — ceux dont
 * l'appartenance aura une consequence a la fermeture. L'ouvreur, lui, est connu separement par le
 * groupe fondateur ; il n'a pas besoin d'y figurer.
 *
 * Consequence directe, et elle est voulue : **une alliance gouvernante avec zero membre photographie
 * est une photographie correcte**, pas une lecture ratee. Elle dit « une alliance gouverne, et
 * personne d'autre ne visait la cible ». La refuser ferait echouer une ouverture parfaitement
 * legitime — le cas le plus courant, celui d'une attaque solitaire lancee par un joueur allie.
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
     * **Elle refuse plutot que de corriger.** Une liste de membres sans alliance qui gouverne, un
     * identifiant negatif, un doublon : chacun signale que l'appelant ne dit pas ce qu'il croit
     * dire. Les accepter en les nettoyant rendrait une photographie plausible et fausse, que plus
     * personne ne pourrait distinguer d'une vraie.
     *
     * @param int|null $allianceId L'alliance gouvernante, ou null si l'ouvreur n'en avait pas.
     * @param array<int, int> $memberUserIds Les proprietaires membres a la seconde de l'ouverture.
     *
     * @throws CorruptedFrozenMembership Si les deux faits se contredisent.
     */
    public static function of(int|null $allianceId, array $memberUserIds): self
    {
        if ($allianceId === null) {
            if ($memberUserIds !== []) {
                // Membres sans alliance : l'un des deux faits est faux, et rien ne dit lequel.
                // Effacer la liste — ce que faisait cette methode — choisissait le silence.
                throw new CorruptedFrozenMembership(
                    'des membres ont ete photographies sans alliance qui gouverne',
                    $memberUserIds
                );
            }

            return new self(null, []);
        }

        if ($allianceId <= 0) {
            throw new CorruptedFrozenMembership(
                'l identifiant d alliance n est pas strictement positif',
                $allianceId
            );
        }

        $indexes = [];

        foreach ($memberUserIds as $userId) {
            if (!is_int($userId) || $userId <= 0) {
                throw new CorruptedFrozenMembership(
                    'un membre n est pas un identifiant strictement positif',
                    $userId
                );
            }

            if (isset($indexes[$userId])) {
                // Un doublon ne change pas qui est admissible, mais il change l'empreinte des faits
                // geles : deux photographies du meme instant cesseraient d'etre comparables.
                throw new CorruptedFrozenMembership('un membre apparait deux fois', $userId);
            }

            $indexes[$userId] = true;
        }

        ksort($indexes);

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
     * **Colonne nulle veut dire « aucune alliance ne gouverne »**, et c'est la seule facon de le
     * dire : un JSON portant `alliance_id: null` serait une seconde representation du meme etat, et
     * deux formes pour un meme fait finissent par diverger.
     *
     * @param array<string, mixed>|null $stored
     *
     * @throws CorruptedFrozenMembership Si la structure lue n'est pas celle qui a ete ecrite.
     */
    public static function fromStorage(array|null $stored): self
    {
        if ($stored === null) {
            return self::none();
        }

        $inconnues = array_diff(array_keys($stored), ['alliance_id', 'members']);

        if ($inconnues !== []) {
            throw new CorruptedFrozenMembership(
                'la structure porte des cles inconnues (' . implode(', ', $inconnues) . ')',
                $stored
            );
        }

        if (!array_key_exists('alliance_id', $stored) || !array_key_exists('members', $stored)) {
            throw new CorruptedFrozenMembership('la structure est incomplete', $stored);
        }

        $allianceId = $stored['alliance_id'];
        $membres = $stored['members'];

        if (!is_int($allianceId)) {
            throw new CorruptedFrozenMembership('l identifiant d alliance n est pas un entier', $stored);
        }

        // `array_is_list()`, et non une comparaison a `range()` : sur une liste vide, `range(0, -1)`
        // rend `[0, -1]`, et cette photographie parfaitement legitime — une alliance qui gouverne
        // sans autre pretendant a la cible — etait refusee.
        if (!is_array($membres) || !array_is_list($membres)) {
            throw new CorruptedFrozenMembership('les membres ne forment pas une liste', $stored);
        }

        // Pas de `array_values()` : `array_is_list()` vient de garantir que c en est une.
        return self::of($allianceId, $membres);
    }

    /**
     * Ce qu'il faut ecrire avec le combat.
     *
     * Les identifiants sont **tries** : la meme photographie doit produire le meme JSON, sinon
     * l'empreinte des faits geles dependrait de l'ordre dans lequel la base a rendu les lignes.
     *
     * @return array<string, mixed>|null Nul quand aucune alliance ne gouverne.
     */
    public function toStorage(): array|null
    {
        if ($this->allianceId === null) {
            return null;
        }

        return [
            'alliance_id' => $this->allianceId,
            'members' => $this->memberUserIds(),
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
