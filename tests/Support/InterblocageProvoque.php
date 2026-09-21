<?php

namespace Tests\Support;

/**
 * Le compteur et l interrupteur d un interblocage provoque au banc.
 *
 * **Un objet, jamais un entier capture par reference** : l analyse statique tient une variable ainsi capturee
 * pour constante, et le temoin lirait toujours zero — un faux et un juste qui coincident.
 *
 * Il porte aussi la decision, pour que le banc n ait pas a la repeter : `doitLever()` compte le passage et dit
 * s il faut lever.
 */
class InterblocageProvoque
{
    /** Combien de fois la requete visee a ete tentee. */
    public int $vues = 0;

    /** Tant qu il est vrai, le conflit persiste. */
    public bool $actif = true;

    public function __construct(private int $plafond)
    {
    }

    /**
     * Compte ce passage et dit si l interblocage doit etre leve cette fois-ci.
     */
    public function doitLever(): bool
    {
        $this->vues++;

        return $this->actif && $this->vues <= $this->plafond;
    }
}
