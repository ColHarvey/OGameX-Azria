<?php

namespace OGame\Combat\Support;

use OGame\Combat\Exceptions\ContradictoryResourceDiagnostic;

/**
 * Ce qu'une operation a rencontre en convertissant des ressources, comme donnees.
 *
 * ## Pourquoi les diagnostics voyagent au lieu d'etre journalises
 *
 * Un calculateur qui journalise n'est plus rejouable : rejouer les memes faits geles produirait un
 * second journal, et recharger un resultat relancerait la normalisation. Le pipeline **rend** donc
 * ce qu'il a rencontre, et c'est l'orchestrateur le plus exterieur qui ecrit, une fois.
 *
 * Ce n'est pas un detail de style. Une seule resolution de combat traverse la distribution **six
 * fois** — le butin, les Faucheurs des deux camps, le plafonnement de leur place, et deux plafonds
 * de cargaison de retour. Une journalisation posee en amont produirait six lignes pour une
 * operation.
 *
 * ## Un multiensemble, pas un ensemble
 *
 * Deux incidents identiques en apparence — meme code, meme ressource, meme valeur — peuvent venir de
 * deux etapes differentes. Ce sont **deux occurrences reelles**, et les fondre remplacerait six
 * avertissements par un seul, incomplet.
 *
 * La deduplication porte donc sur l'**identite** de l'occurrence, pas sur son contenu :
 *
 *     meme identite + meme contenu        -> une occurrence
 *     identites differentes, meme contenu -> deux occurrences, occurrenceCount = 2
 *     meme identite + contenu different   -> violation d'invariant
 *
 * Le troisieme cas est refuse plutot qu'arbitre : garder l'un des deux effacerait un incident reel.
 *
 * ## La fusion est associative et deterministe
 *
 * L'ordre dans lequel les etapes remontent leurs diagnostics ne doit pas changer le journal final.
 * Les occurrences sont donc indexees par identite et triees, et fusionner A puis B donne le meme
 * resultat que B puis A.
 */
final readonly class ResourceNormalizationDiagnostics
{
    /**
     * Un solde negatif inferieur a une unite, ramene a zero.
     */
    public const string NEGATIVE_ARTIFACT_NORMALIZED = 'negative_artifact_normalized';

    /**
     * Un solde au-dela de la plage ou un flottant distingue chaque entier.
     */
    public const string PRECISION_DEGRADED = 'precision_degraded';

    /**
     * @param array<string, ResourceDiagnostic> $occurrences Indexees par identite, triees.
     */
    private function __construct(public array $occurrences)
    {
    }

    /**
     * Aucun incident.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Une occurrence unique.
     *
     * @param ResourceDiagnostic $diagnostic
     * @return self
     */
    public static function of(ResourceDiagnostic $diagnostic): self
    {
        return new self([$diagnostic->identity() => $diagnostic]);
    }

    /**
     * L'union de deux collections.
     *
     * **Associative et deterministe** : l'ordre dans lequel les etapes remontent leurs diagnostics
     * ne change pas le resultat.
     *
     * @param self $other
     * @return self
     */
    public function mergedWith(self $other): self
    {
        $fusion = $this->occurrences;

        foreach ($other->occurrences as $identite => $diagnostic) {
            $existant = $fusion[$identite] ?? null;

            if ($existant !== null && !$existant->saysTheSameAs($diagnostic)) {
                throw ContradictoryResourceDiagnostic::because(
                    $identite,
                    $existant->code . '/' . $existant->units,
                    $diagnostic->code . '/' . $diagnostic->units
                );
            }

            $fusion[$identite] = $diagnostic;
        }

        ksort($fusion, SORT_STRING);

        return new self($fusion);
    }

    /**
     * Si quelque chose merite d'etre signale.
     */
    public function any(): bool
    {
        return count($this->occurrences) > 0;
    }

    /**
     * Le nombre d'occurrences distinctes.
     */
    public function count(): int
    {
        return count($this->occurrences);
    }

    /**
     * Les incidents, regroupes par code puis par ressource, avec leur compte et leurs provenances.
     *
     * **`occurrenceCount` et les provenances sont conserves.** Sans eux, six avertissements
     * deviendraient un avertissement incomplet : on saurait qu'une precision s'est degradee sur le
     * metal, sans savoir si c'est arrive une fois ou six, ni ou.
     *
     * @return array<string, array<string, array{occurrenceCount: int, units: array<int, int>, provenances: array<int, string>}>>
     */
    public function groupedByCode(): array
    {
        $groupes = [];

        foreach ($this->occurrences as $diagnostic) {
            $code = $diagnostic->code;
            $ressource = $diagnostic->resource;

            if (!isset($groupes[$code][$ressource])) {
                $groupes[$code][$ressource] = ['occurrenceCount' => 0, 'units' => [], 'provenances' => []];
            }

            $groupes[$code][$ressource]['occurrenceCount']++;
            $groupes[$code][$ressource]['units'][] = $diagnostic->units;
            $groupes[$code][$ressource]['provenances'][] = $diagnostic->subject === ''
                ? $diagnostic->phase
                : $diagnostic->phase . ':' . $diagnostic->subject;
        }

        ksort($groupes, SORT_STRING);

        foreach ($groupes as $code => $ressources) {
            ksort($ressources, SORT_STRING);
            $groupes[$code] = $ressources;
        }

        return $groupes;
    }
}
