<?php

namespace OGame\Combat\MoonDestruction;

/**
 * Une regle de destruction de lune, dans une version donnee.
 *
 * ## Pourquoi une version, alors que les formules ne changent pas aujourd'hui
 *
 * Un plan gele vit longtemps : il est ecrit a la fermeture du ralliement et relu a l'echeance, puis
 * consulte par les rapports et les audits bien apres. Le jour ou les formules changeront, tous les
 * plans deja ecrits doivent rester **lisibles et valides** — pas reinterpretes a la lumiere d'une
 * regle qui n'existait pas quand ils ont ete calcules.
 *
 * La version persistee **selectionne l'implementation**. Elle n'est jamais comparee a la version
 * courante pour decider si le plan est bon : c'est la meme lecon que le pipeline de pillage.
 */
interface MoonDestructionRule
{
    /**
     * L'identifiant stable de cette version.
     */
    public function version(): string;

    /**
     * La probabilite, en pourcentage, que la lune soit detruite.
     *
     * @param int $moonDiameter
     * @param int $deathstarCount Les survivantes de **cette** mission, jamais un total mis en commun.
     * @return float
     */
    public function destructionChance(int $moonDiameter, int $deathstarCount): float;

    /**
     * La probabilite, en pourcentage, que la flotte perde toutes ses etoiles de la mort.
     *
     * @param int $moonDiameter
     * @return float
     */
    public function deathstarLossChance(int $moonDiameter): float;

    /**
     * Si un tirage l'emporte sur une probabilite.
     *
     * Dans le contrat, parce que la comparaison fait partie de la regle : passer de `<=` a `<`
     * changerait l'equilibre autant qu'une formule.
     *
     * @param int $roll
     * @param float $chance
     * @return bool
     */
    public function succeeds(int $roll, float $chance): bool;
}
