<?php

namespace OGame\Lifeforms\Score;

/**
 * **Les deux regles du classement des formes de vie qui ne sont PAS tranchees.**
 *
 * Elles vivent ici, nommees, plutot que dissoutes dans une ligne de calcul. Consigne de Keven, 20 septembre
 * 2026 : « Je ne veux pas d un comportement temporaire silencieux pour les reductions ou les technologies
 * depossedees. Si le chemin de production rencontre reellement ces cas avant notre decision, leur traitement doit
 * rester explicitement identifie comme non tranche plutot que choisi arbitrairement. »
 *
 * Ce que ce fichier **n est pas** : une decision. Les valeurs ci-dessous sont des provisoires assumes, choisis pour
 * ne rien supposer de neuf — ils reprennent la convention deja en place pour les batiments classiques. Chacun est
 * tenu par un temoin dont le nom dit qu il epingle un provisoire : le jour ou la mesure tranche, c est ce temoin-la
 * qui tombe et qui rappelle qu une decision etait attendue.
 *
 * Les deux mesures se font sur un vrai compte OGame ; le protocole est sur le bureau de Keven
 * (`mesures-classement-ogame.html`). Aucune source publique ne repond : ni le forum officiel, ni Gameforge, ni
 * OWiki ; le wiki Fandom est inaccessible.
 */
final class LifeformScoreOpenRules
{
    /**
     * **Question 1 — les reductions de cout.** Un bonus de forme de vie reduit le prix d un niveau. OGame
     * crediterait-il les points du cout **nominal** du catalogue, ou de ce qui a ete **reellement paye** ?
     *
     * Provisoire : le cout nominal, comme les batiments classiques d Azria, qui comptent eux aussi le cout du
     * catalogue et non la facture. C est le seul choix qui ne suppose rien de nouveau.
     */
    public const string COST_REDUCTIONS = 'Reductions de cout : nominal ou paye ? Mesure en attente sur un compte OGame.';

    /**
     * La reduction employee par le calcul du score tant que la question 1 n est pas tranchee : **aucune**.
     */
    public const float SCORING_REDUCTION = 0.0;

    /**
     * **Question 2 — une technologie depossedee.** Un reset de palier met `object_id` a `null` mais conserve les
     * niveaux, et une technologie reprise retrouve son niveau — Keven l a confirme cote OGame. Le classement
     * continue-t-il de compter ces niveaux pendant que la technologie n est plus selectionnee ?
     *
     * Provisoire : oui, ils comptent. Les ressources ont ete depensees, et c est ce que mesure un point.
     */
    public const string DISPOSSESSED_TECHNOLOGY = 'Technologie depossedee : les points survivent-ils ? Mesure en attente sur un compte OGame.';

    /**
     * Le comportement employe tant que la question 2 n est pas tranchee : tous les niveaux enregistres comptent.
     */
    public const bool COUNTS_DISPOSSESSED_LEVELS = true;

    /**
     * Les deux questions, pour qu un rapport ou une page d administration puisse les dire sans les recopier.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::COST_REDUCTIONS, self::DISPOSSESSED_TECHNOLOGY];
    }
}
