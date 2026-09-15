<?php

namespace OGame\Lifeforms\Catalogue;

/**
 * Un effet d un objet de forme de vie, tel que le fichier maitre le decrit : une valeur de base, un
 * facteur de croissance par niveau et, parfois, un plafond.
 *
 * ## Unites, lues sur les pages reelles du jeu
 *
 * - `base` est **en pour cent par niveau** pour tout effet en pourcentage (Repaire orbital : 4, soit
 *   +4 % de stockage par niveau ; Module d efficacite : 0,03) et **en unites** pour les quantites
 *   (espace de vie : 210 ; capacite de palier : 20 000 000).
 * - `max` est **une fraction** : 0,3 signifie « plafonne a 30 % » (page des bonus : « Max. -30% »
 *   pour le Module d efficacite dont le fichier porte 0,3).
 * - `factor` vaut 1 pour un effet lineaire ; sinon il entre dans la formule de l effet.
 *
 * `code` est le code d effet (voir `LifeformEffect`), `target` le nom machine de ce qu il vise quand
 * l effet est cible (une unite, une recherche, un batiment, une classe).
 */
final readonly class LifeformBonus
{
    public function __construct(
        public string $code,
        public string|null $target,
        public float $base,
        public float $factor,
        public float|null $max,
    ) {
    }

    /**
     * Un nombre du fichier maitre dont la signification n est pas etablie : garde, jamais applique.
     */
    public function isUnassigned(): bool
    {
        return $this->code === LifeformEffect::UNASSIGNED;
    }
}
