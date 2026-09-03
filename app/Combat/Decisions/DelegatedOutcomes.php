<?php

namespace OGame\Combat\Decisions;

use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Exceptions\MissingDelegatedOutcome;

/**
 * Les reponses des mecanismes auxquels la matrice delegue.
 *
 * ## Elles sont exigees, jamais supposees
 *
 * Chaque lecture leve si la reponse n'a pas ete fournie. Rendre `null` aurait laisse l'appelant
 * choisir un comportement de repli, et un repli silencieux est exactement ce que la matrice existe
 * pour ne pas produire : une regle que personne n'a prononcee, appliquee quand meme.
 *
 * L'objet ne porte pas non plus de valeur par defaut « raisonnable ». « Admise » ferait entrer une
 * flotte que le selecteur aurait pu refuser ; « hors photographie » en exclurait une qui y avait
 * droit. Les deux sont des decisions de jeu, et elles appartiennent aux mecanismes qui les
 * prennent sous verrou.
 *
 * ## Ce qu'elle ne fait pas
 *
 * Elle ne verifie pas la coherence entre reponses : fournir une admission **et** un ordre causal
 * n'est pas une faute, seulement inutile. C'est le consommateur qui sait de laquelle il a besoin,
 * et lui seul.
 */
final readonly class DelegatedOutcomes
{
    /**
     * @param RallyAdmissionOutcome|null $rallyAdmission Ce que le selecteur du camp a prononce.
     * @param CausalAdmission|null $causalOrder Ce que l'ordre des evenements a tranche.
     * @param AssetRecoveryOutcome|null $assetRecovery Ou les actifs ont ete deposes.
     */
    private function __construct(
        private RallyAdmissionOutcome|null $rallyAdmission = null,
        private CausalAdmission|null $causalOrder = null,
        private AssetRecoveryOutcome|null $assetRecovery = null,
    ) {
    }

    /**
     * Aucune reponse : le cas d'une case que la matrice tranche seule.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * La reponse du selecteur d'un camp.
     */
    public static function ofRallyAdmission(RallyAdmissionOutcome $admission): self
    {
        return new self(rallyAdmission: $admission);
    }

    /**
     * La reponse de l'ordre des evenements.
     */
    public static function ofCausalOrder(CausalAdmission $admission): self
    {
        return new self(causalOrder: $admission);
    }

    /**
     * La reponse de la recuperation d'actifs.
     */
    public static function ofAssetRecovery(AssetRecoveryOutcome $recovery): self
    {
        return new self(assetRecovery: $recovery);
    }

    /**
     * Une arrivee qui se pose pendant le ralliement recoit les deux : son admission dans le camp,
     * et sa place dans la photographie.
     */
    public static function ofAdmissionAndCausalOrder(
        RallyAdmissionOutcome $admission,
        CausalAdmission $causalOrder,
    ): self {
        return new self(rallyAdmission: $admission, causalOrder: $causalOrder);
    }

    /**
     * Une candidate refusee dont l'origine a disparu pendant son vol.
     *
     * Le cas se produit : une attaque partie d'une lune detruite depuis. Le refus dit pourquoi la
     * mission s'arrete, la recuperation dit ou passe ce qu'elle transportait — sans quoi une flotte
     * chargee disparaitrait pour la seule raison qu'elle n'etait pas la bienvenue.
     */
    public static function ofAdmissionAndAssetRecovery(
        RallyAdmissionOutcome $admission,
        AssetRecoveryOutcome $recovery,
    ): self {
        return new self(rallyAdmission: $admission, assetRecovery: $recovery);
    }

    /**
     * Ce que le selecteur du camp a prononce. Leve si personne ne l'a fourni.
     */
    public function rallyAdmission(string $situation): RallyAdmissionOutcome
    {
        return $this->rallyAdmission ?? throw new MissingDelegatedOutcome('selecteur d admission', $situation);
    }

    /**
     * Ce que l'ordre des evenements a tranche. Leve si personne ne l'a fourni.
     */
    public function causalOrder(string $situation): CausalAdmission
    {
        return $this->causalOrder ?? throw new MissingDelegatedOutcome('ordre causal des evenements', $situation);
    }

    /**
     * Ou les actifs ont ete deposes. Leve si personne ne l'a fourni.
     */
    public function assetRecovery(string $situation): AssetRecoveryOutcome
    {
        return $this->assetRecovery ?? throw new MissingDelegatedOutcome('recuperation d actifs', $situation);
    }
}
