<?php

namespace OGame\Combat\Admission;

use OGame\Combat\Enums\FlightLeg;

/**
 * Comment les candidates d'un ralliement se rangent en groupes.
 *
 * ## Pourquoi une classe, et pas deux methodes privees
 *
 * L'ouverture et la fermeture posent la meme question, a deux moments : l'ouverture pour connaitre
 * l'echeance, la fermeture pour prononcer les admissions. Deux copies du meme regroupement
 * finiraient par differer — et un groupe forme d'un cote mais pas de l'autre ferait admettre a la
 * fermeture ce qui n'avait pas ete attendu a l'ouverture.
 *
 * Elle est sans etat : elle ne lit rien, n'ecrit rien, et rend toujours la meme chose pour les memes
 * faits. C'est ce qui permet de la faire tourner deux fois sans craindre qu'elle ait bouge entre les
 * deux.
 *
 * ## Les trois questions qu'elle repond
 *
 *     quelles candidates le selecteur peut-il juger ?   -> celles que la matrice lui delegue
 *     laquelle forme le groupe fondateur ?              -> l'union de l'ouvreur, ou l'ouvreur seul
 *     comment les autres se groupent-elles ?            -> par union, une attaque ACS arrive ensemble
 */
final class RallyGrouping
{
    /**
     * Les candidates dont la forme est celle que la matrice delegue au selecteur attaquant.
     *
     * **Ce n'est pas une regle d'admission**, c'est l'aiguillage. Un retour ou un transport arrive au
     * selecteur serait une entree contradictoire, et il leve — a juste titre. Les ecarter ici
     * reproduit ce que la matrice fait dans le flux reel, sans juger personne.
     *
     * @param array<int, CandidateMission> $candidates
     * @return array<int, CandidateMission>
     */
    public function fightingShapesOnly(array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (CandidateMission $c): bool => $c->leg === FlightLeg::Outbound && $c->mission->opensCombat()
        ));
    }

    /**
     * Separe le groupe fondateur du reste.
     *
     * L'union de l'ouvreur gouverne : ses missions forment le groupe fondateur. Sans union, c'est la
     * seule mission de l'ouvreur — **jamais la premiere ligne lue**, qui dependrait de l'ordre dans
     * lequel la base a rendu les candidates.
     *
     * @param array<int, CandidateMission> $candidates
     * @return array{0: array<int, CandidateMission>, 1: array<int, CandidateMission>}
     */
    public function splitFounding(array $candidates, int $openerMissionId, int|null $unionId): array
    {
        $fondatrices = [];
        $autres = [];

        foreach ($candidates as $candidate) {
            $estFondatrice = $unionId === null
                ? $candidate->missionId === $openerMissionId
                : $candidate->unionId === $unionId;

            if ($estFondatrice) {
                $fondatrices[] = $candidate;

                continue;
            }

            $autres[] = $candidate;
        }

        return [$fondatrices, $autres];
    }

    /**
     * Les candidates regroupees, dans un ordre deterministe.
     *
     * Une attaque ACS deja en vol arrive **ensemble** : la decouper — trois flottes admises, deux
     * renvoyees — briserait une attaque coordonnee que ses joueurs ont organisee et payee.
     *
     * L'ordre des groupes est fixe par identite stable, jamais par l'ordre de lecture : deux
     * fermetures du meme combat doivent prononcer les memes admissions.
     *
     * @param array<int, CandidateMission> $candidates
     * @return array<int, AttackCandidateGroup>
     */
    public function intoGroups(array $candidates): array
    {
        $parUnion = [];
        $groupes = [];

        foreach ($candidates as $candidate) {
            if ($candidate->unionId === null) {
                $groupes[] = AttackCandidateGroup::ofASingleFleet($candidate);

                continue;
            }

            $parUnion[$candidate->unionId][] = $candidate;
        }

        ksort($parUnion);

        foreach ($parUnion as $unionId => $missions) {
            $groupes[] = new AttackCandidateGroup('union:' . $unionId, $missions);
        }

        usort(
            $groupes,
            static fn (AttackCandidateGroup $a, AttackCandidateGroup $b): int => $a->compareTo($b)
        );

        return $groupes;
    }
}
