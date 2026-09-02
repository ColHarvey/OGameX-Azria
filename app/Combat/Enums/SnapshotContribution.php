<?php

namespace OGame\Combat\Enums;

/**
 * Ce qu'un participant apporte a la photographie du champ de bataille.
 *
 * **Ces contributions se cumulent**, et c'est pour cela qu'elles sont une enumeration d'elements
 * plutot que le resultat lui-meme. Une flotte de retour apporte des vaisseaux **et** une
 * cargaison ; un renfort defensif charge apporte les deux aussi. Une enumeration a valeur unique
 * aurait oblige a inventer une variante par combinaison — `DefensiveFleetWithCargo`, et ainsi de
 * suite — c'est-a-dire a multiplier les cas au lieu de les composer.
 *
 * Elles ne se calculent pas au meme endroit et ne se reappliquent pas de la meme facon a la
 * resolution : c'est ce qui rend la resolution par difference possible, puisqu'on sait exactement
 * quelle grandeur chaque participant a fait bouger.
 */
enum SnapshotContribution: string
{
    /**
     * Des vaisseaux du cote attaquant.
     */
    case AttackingFleet = 'attacking_fleet';

    /**
     * Des vaisseaux du cote defenseur.
     *
     * Deux provenances seulement, et elles sont **disjointes** :
     *
     * - la **garnison** deja stationnee, lue une seule fois avec l'etat de la cible ;
     * - une **Defense ACS retenue**, qui reste un participant separe parce qu'elle ne se pose pas
     *   comme une flotte ordinaire.
     *
     * Une flotte de retour ou de deploiement livree avant la barriere **appartient a la
     * garnison** : elle y a ete versee, donc elle est deja comptee. La declarer ici la compterait
     * deux fois — cent en garnison plus trente livres donneraient cent soixante au lieu de cent
     * trente. Elle se declare avec `DeliveredFleet`.
     */
    case DefendingFleet = 'defending_fleet';

    /**
     * Une flotte livree avant la photographie, deja fondue dans la garnison.
     *
     * Marqueur d'audit, symetrique de `DeliveredCargo` : il dit quelle mission a amene ces
     * vaisseaux, ce qu'un total de garnison ne raconte pas. **Il n'ajoute aucune unite** — elles
     * sont deja dans le total lu avec l'etat de la cible.
     */
    case DeliveredFleet = 'delivered_fleet';

    /**
     * Le solde de ressources de la cible, photographie **une seule fois**.
     *
     * **Un etat global, pas un apport par flotte.** Le compter une fois par flotte chargee
     * gonflerait la photographie a chaque livraison : cent en caisse, plus vingt, plus trente,
     * plus quarante donnerait deux cent trente ou deux cent quatre-vingts au lieu de cent
     * quatre-vingt-dix. Les livraisons dues sont appliquees **avant** la photographie, qui ne fait
     * que constater le solde qui en resulte.
     *
     * D'ou la contrainte de construction : cette contribution n'est admise que depuis
     * `SnapshotSource::ExistingTargetState`.
     */
    case TargetResources = 'target_resources';

    /**
     * La cargaison d'une mission, livree avant la photographie.
     *
     * Elle ne s'ajoute pas au solde photographie — elle y figure **deja**, puisque la livraison a
     * ete appliquee avant. Cette contribution existe pour l'audit : elle dit quelle mission a
     * apporte quoi, ce qu'un solde global ne peut pas raconter.
     */
    case DeliveredCargo = 'delivered_cargo';

    /**
     * Les defenses au sol de la cible, telles qu'elles sont **au moment de la capture**.
     *
     * Un missile arrive avant la barriere a deja fait ses degats : la photographie constate ce
     * qu'il en reste. Retrancher ensuite sa destruction une seconde fois donnerait quarante la ou
     * il y en a soixante-dix. Comme pour la garnison et le solde, l'etat est lu **une fois**, et
     * la provenance des degats releve de l'audit.
     */
    case TargetDefences = 'target_defences';

    /**
     * Si cette contribution est une flotte qui se battra.
     *
     * Seules celles-la peuvent retenir la fenetre de ralliement ouverte : des ressources livrees
     * ne font attendre aucune bataille.
     *
     * Les marqueurs d'audit — `DeliveredFleet`, `DeliveredCargo` — n'en sont pas : ils ne portent
     * aucune unite, ils disent seulement d'ou vient ce qui est deja compte ailleurs.
     */
    public function isFightingFleet(): bool
    {
        return $this === self::AttackingFleet || $this === self::DefendingFleet;
    }

    /**
     * Si cette contribution n'est qu'une trace, sans aucune unite ni ressource propre.
     */
    public function isAuditMarkerOnly(): bool
    {
        return $this === self::DeliveredFleet || $this === self::DeliveredCargo;
    }

    /**
     * Les contributions qu'une provenance a le droit de declarer.
     *
     * **Une table, plutot que des gardes ajoutees une par une.** Le double comptage a d'abord ete
     * corrige sur les ressources, puis retrouve a l'identique sur les vaisseaux : c'est le signe
     * qu'il fallait une regle generale et non deux rustines. Les trois ensembles sont disjoints,
     * et c'est cette disjonction qui garantit qu'aucune unite, aucune ressource et aucune defense
     * n'est comptee deux fois.
     *
     * @param SnapshotSource $source
     * @return array<int, self>
     */
    public static function allowedFor(SnapshotSource $source): array
    {
        return match ($source) {
            // L'etat global de la cible, lu une seule fois : ce qui est en caisse, les defenses au
            // sol, et la garnison — livraisons deja fondues dedans comprises.
            SnapshotSource::ExistingTargetState => [
                self::TargetResources,
                self::TargetDefences,
                self::DefendingFleet,
            ],
            // Un participant qui ne se pose pas dans la garnison : la vague offensive, ou la
            // Defense ACS qui reste distincte.
            SnapshotSource::SelectedRallyCandidate => [
                self::AttackingFleet,
                self::DefendingFleet,
            ],
            // Une arrivee de passage n'apporte que des traces : ce qu'elle a livre est deja dans
            // l'etat global ci-dessus.
            SnapshotSource::IncidentalArrival => [
                self::DeliveredFleet,
                self::DeliveredCargo,
            ],
        };
    }
}
