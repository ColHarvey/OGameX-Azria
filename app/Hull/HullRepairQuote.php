<?php

namespace OGame\Hull;

use OGame\Models\Resources;
use OGame\Services\ObjectService;
use RuntimeException;

/**
 * Ce que coute et ce que dure la reparation d un lot d unites endommagees.
 *
 * ------------------------------------------------------------------------------------
 * LES DEUX FORMULES, ET POURQUOI CELLES-LA
 *
 *   cout  = prix de construction x part de coque manquante x k(niveau)      [au prorata M / C / D]
 *   duree = 40 x racine(valeur reparee) x f(niveau)                         [bornee par l univers]
 *
 * **Le cout est proportionnel** parce que c est la seule forme qui rende la reparation lisible : la
 * moitie d une coque coute la moitie du prix, multipliee par un facteur qui recompense le niveau du
 * dock. La consigne exigeait que cout et duree dependent « de la valeur de l unite et de l ampleur
 * des degats, pas du seul nombre » : les deux facteurs sont exactement ceux-la.
 *
 * **La duree est une racine carree, et le premier essai etait mauvais.** Adosser la duree a celle du
 * chantier naval rendait une Etoile de la Mort irreparable — un mois de dock — et surtout obligeait a
 * **lire le chantier naval du joueur**, donc une valeur vivante dans un devis qui doit etre gele. La
 * racine carree est la forme que ce dock emploie **deja** pour les epaves
 * (`sqrt(shipCount * 30) * 10`) : elle comprime l echelle d elle-meme et ne depend d aucun batiment
 * tiers.
 *
 * ------------------------------------------------------------------------------------
 * LE REPERE QUI REND CES NOMBRES JUSTES
 *
 * Un vaisseau **detruit** rend aujourd hui 31,5 % de sa valeur en epave au niveau 1, 39,2 % au
 * niveau 15 (mesure du journal §118.2). Reparer une unite a moitie coute 25 % de son prix au
 * niveau 1 et 15 % au niveau 15 : **toujours preferable a la perdre, jamais gratuit**. C est ce
 * rapport qui a ete choisi ; les decimales des deux tables le servent.
 */
final class HullRepairQuote
{
    /**
     * L echelle de la duree. Choisie pour qu un croiseur a moitie detruit demande une petite heure
     * au niveau 1, et que cent croiseurs tiennent sous le plafond de l univers.
     */
    private const int DURATION_SCALE = 40;

    /**
     * `k` — la part du prix de construction que coute une reparation complete.
     *
     * @var array<int, float>
     */
    private const array COST_FACTORS = [
        1 => 0.50, 2 => 0.49, 3 => 0.48, 4 => 0.47, 5 => 0.45,
        6 => 0.44, 7 => 0.43, 8 => 0.42, 9 => 0.40, 10 => 0.39,
        11 => 0.38, 12 => 0.36, 13 => 0.34, 14 => 0.32, 15 => 0.30,
    ];

    /**
     * `f` — la part du temps de base que dure une reparation.
     *
     * @var array<int, float>
     */
    private const array DURATION_FACTORS = [
        1 => 0.50, 2 => 0.48, 3 => 0.46, 4 => 0.44, 5 => 0.42,
        6 => 0.40, 7 => 0.38, 8 => 0.36, 9 => 0.35, 10 => 0.33,
        11 => 0.32, 12 => 0.30, 13 => 0.28, 14 => 0.26, 15 => 0.25,
    ];

    private function __construct(
        public readonly DamagedHulls $units,
        public readonly Resources $cost,
        public readonly int $durationSeconds,
        public readonly int $dockLevel,
        public readonly int $repairedValue,
    ) {
    }

    /**
     * Le devis pour ces unites, a ce niveau de dock.
     *
     * @param int $minimumSeconds plancher de l univers (`hull_repair_min_minutes`)
     * @param int $maximumSeconds plafond de l univers (`hull_repair_max_hours`)
     */
    public static function for(
        DamagedHulls $units,
        int $dockLevel,
        int $minimumSeconds,
        int $maximumSeconds,
    ): self {
        if ($units->isEmpty()) {
            throw new RuntimeException('A repair quote was asked for no damaged unit at all.');
        }

        $niveau = self::clampLevel($dockLevel);
        $k = self::COST_FACTORS[$niveau];
        $f = self::DURATION_FACTORS[$niveau];

        $metal = 0.0;
        $crystal = 0.0;
        $deuterium = 0.0;
        $valeurReparee = 0.0;

        foreach ($units->all() as $type => $niveaux) {
            $prix = ObjectService::getUnitObjectByMachineName($type)->price->resources;

            foreach ($niveaux as $degats => $nombre) {
                // La part manquante, exactement telle qu elle est stockee : aucun aller-retour.
                $part = ($degats / DamagedHulls::FULL_DAMAGE) * $nombre;

                $metal += $prix->metal->get() * $part;
                $crystal += $prix->crystal->get() * $part;
                $deuterium += $prix->deuterium->get() * $part;

                $valeurReparee += ($prix->metal->get() + $prix->crystal->get() + $prix->deuterium->get()) * $part;
            }
        }

        // **Jamais sous-facturer** : une colonne de ressources ne porte pas de fraction, et le depot
        // refuse deja les fractions aux frontieres economiques. L arrondi va donc vers le haut.
        $cout = new Resources(
            (int)ceil($metal * $k),
            (int)ceil($crystal * $k),
            (int)ceil($deuterium * $k),
            0,
        );

        $duree = (int)round(self::DURATION_SCALE * sqrt(max(0.0, $valeurReparee)) * $f);
        $duree = max($minimumSeconds, min($maximumSeconds, $duree));

        return new self($units, $cout, $duree, $niveau, (int)round($valeurReparee));
    }

    /**
     * L empreinte du devis — ce qui permet de refuser une reponse perimee.
     *
     * Un joueur ouvre le dock, choisit ses unites, part se faire attaquer, revient et confirme : le
     * devis affiche ne decrit plus le monde. La confirmation renvoie cette empreinte, le serveur la
     * recalcule sur l etat **du moment**, et refuse si elle a bouge. Elle couvre les unites, le cout,
     * la duree et le niveau du dock : tout ce dont le joueur a cru accepter la valeur.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string)json_encode([
            'units' => $this->units->toStorage(),
            'cost' => [
                $this->cost->metal->get(),
                $this->cost->crystal->get(),
                $this->cost->deuterium->get(),
            ],
            'duration' => $this->durationSeconds,
            'dock' => $this->dockLevel,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Le niveau de dock ramene dans la table. Le niveau 0 n arrive pas ici — sans dock il n y a pas
     * de reparation — mais le borner evite qu une donnee aberrante choisisse un facteur au hasard.
     */
    private static function clampLevel(int $dockLevel): int
    {
        return max(1, min(15, $dockLevel));
    }
}
