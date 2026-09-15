<?php

namespace OGame\Lifeforms\Demography;

/**
 * Les constantes de la demographie des formes de vie — **regle Azria, version 1**.
 *
 * ## Pourquoi une regle Azria
 *
 * Le jeu officiel ne publie ni la croissance de la population, ni la production et la consommation
 * de nourriture, ni la famine. Le fichier maitre Gameforge donne les bases par espece (espace de
 * vie, nourriture, stockage, taux de croissance) sans dire comment elles se composent, et les pages
 * reelles capturees n offrent que trois releves. Keven a tranche le 15 septembre 2026 : les chiffres
 * officiels quand ils existent, sinon une regle equilibree et logique pour Azria. La voici, ecrite
 * pour etre lue, contestee et changee — en changeant la version.
 *
 * ## Les regles
 *
 * 1. **Espace de vie** = base × (N + 1) × facteur^N du logement (formule verifiee sur une page
 *    reelle : 922 au niveau 2 pour les Humains), augmente des bonus en pour cent (Gratte-ciel...).
 * 2. **Population de base** = espace de vie au niveau 0 (210 Humains, 150 Rock’tal, 500 Mechas,
 *    250 Kaelesh). C est la population a l installation, et **la famine ne descend jamais en dessous**.
 * 3. **Croissance** = espace de vie × (1 ÷ HEURES_POUR_REMPLIR) × vitesse economique × (1 + bonus
 *    de croissance), par heure, lineaire, jusqu a l espace de vie. Le bonus de croissance du logement
 *    suit la formule du fichier maitre (N^facteur × base, en pour cent) ; les autres sont lineaires.
 *    Calibrage : Gameforge annonce « de 0 a 100 % en environ 20 heures sur un serveur x4 », soit 80
 *    heures a x1 sans bonus ; un logement de niveau 20 remplit en douze heures.
 * 4. **Nourriture produite** = base × N × facteur^(N−1) de la ferme, × vitesse, × (1 + bonus) ;
 *    **consommee** = population × NOURRITURE_PAR_HABITANT × vitesse × (1 − reduction). Les deux
 *    suivent la vitesse : l equilibre (combien la ferme nourrit) ne depend pas de la vitesse, seul
 *    le rythme en depend. Calibrage de la consommation : une ferme de niveau 1 nourrit 588 habitants,
 *    la page reelle en montrait 590.
 * 5. **Stock de nourriture** = base × (N + 1) × facteur^N de la ferme, × (1 + bonus), plafonne.
 * 6. **Famine** : quand le stock est a zero et que la consommation depasse la production, la
 *    population redescend **aussitot** a ce que la production nourrit (jamais sous la population de
 *    base), et la croissance s arrete tant que la production ne depasse pas la consommation.
 * 7. **Paliers** : T2 = min(population, capacite T2), T3 = min(T2, capacite T3), ou une capacite est
 *    base × N × facteur^(N−1) du batiment de palier (20 000 000 au niveau 1 de l Academie).
 * 8. **Abri** : ABRI habitants sont toujours proteges lors d une attaque, plus la part que le
 *    Bouclier planetaire protege (3 % par niveau, plafond 90 %) — applique par la tranche combat.
 *
 * Tout est calcule par intervalles ou les taux sont constants (`DemographicClock`) : une heure
 * d avance vaut quatre quarts d heure, a l arrondi flottant pres.
 */
final class DemographicRules
{
    public const int VERSION = 1;

    /**
     * Heures pour remplir l espace de vie a x1, sans bonus de croissance.
     */
    public const float HOURS_TO_FILL = 80.0;

    /**
     * Nourriture consommee par habitant et par heure a x1.
     */
    public const float FOOD_PER_INHABITANT_PER_HOUR = 0.017;

    /**
     * Habitants toujours proteges lors d une attaque (« Bunker Space » sur la page reelle).
     */
    public const int SHELTERED = 100;
}
