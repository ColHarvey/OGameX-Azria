<?php

namespace OGame\Combat\Services;

use OGame\Combat\Admission\AdmissionVerdict;

/**
 * Ce qu'une tentative de fermeture a fait, ou n'a pas fait.
 *
 * ## Trois issues, et aucune n'est une erreur
 *
 *     fermee        la photographie est prise, les admissions prononcees
 *     deja fermee   quelqu'un est passe avant : il n'y a rien a refaire
 *     trop tot      la fenetre court encore, des candidates sont attendues
 *
 * **« Deja fermee » n'est pas un echec.** Un message de file peut etre livre deux fois, un worker
 * peut reprendre apres un redemarrage. Lever dans ce cas ferait echouer un traitement qui a
 * pourtant abouti, et la reprise tournerait en boucle.
 *
 * **« Trop tot » non plus.** L'echeance a ete calculee a l'ouverture ; fermer avant elle exclurait
 * des flottes qu'on avait promis d'attendre. Le declencheur represente donc simplement.
 */
final readonly class RallyClosureOutcome
{
    /**
     * @param bool $closed Si cette tentative a ferme le ralliement.
     * @param string $reason Pourquoi, en un mot lisible dans un journal.
     * @param AdmissionVerdict|null $attackers Le verdict du camp attaquant, quand il a ete rendu.
     * @param AdmissionVerdict|null $defenders Le verdict du camp defenseur.
     */
    private function __construct(
        public bool $closed,
        public string $reason,
        public AdmissionVerdict|null $attackers = null,
        public AdmissionVerdict|null $defenders = null,
    ) {
    }

    /**
     * Le ralliement vient d'etre ferme par cette tentative.
     */
    public static function closed(AdmissionVerdict $attackers, AdmissionVerdict $defenders): self
    {
        return new self(true, 'fermee', $attackers, $defenders);
    }

    /**
     * Quelqu'un l'avait deja ferme.
     */
    public static function alreadyClosed(): self
    {
        return new self(false, 'deja fermee');
    }

    /**
     * La fenetre court encore.
     */
    public static function tooEarly(): self
    {
        return new self(false, 'trop tot');
    }

    /**
     * Le combat n'existe plus, ou n'a jamais existe.
     */
    public static function unknownCombat(): self
    {
        return new self(false, 'combat introuvable');
    }

    /**
     * Combien de flottes ont ete admises, les deux camps confondus.
     */
    public function admittedFleets(): int
    {
        $total = 0;

        foreach ([$this->attackers, $this->defenders] as $verdict) {
            foreach ($verdict?->admitted() ?? [] as $groupe) {
                $total += $groupe->fleetCount();
            }
        }

        return $total;
    }
}
