<?php

namespace OGame\Enums;

enum CharacterClass: int
{
    case COLLECTOR = 1;
    case GENERAL = 2;
    case DISCOVERER = 3;

    /**
     * Get the display name of the character class.
     */
    public function getName(): string
    {
        return match($this) {
            self::COLLECTOR => 'Collectionneur',
            self::GENERAL => 'General',
            self::DISCOVERER => 'Explorateur',
        };
    }

    /**
     * Get the machine name (CSS class) of the character class.
     */
    public function getMachineName(): string
    {
        return match($this) {
            self::COLLECTOR => 'miner',
            self::GENERAL => 'warrior',
            self::DISCOVERER => 'explorer',
        };
    }

    /**
     * Get the class-specific ship ID.
     */
    public function getClassShipId(): int
    {
        return match($this) {
            self::COLLECTOR => 217, // Crawler
            self::GENERAL => 218, // Reaper
            self::DISCOVERER => 219, // Pathfinder
        };
    }

    /**
     * Get the class-specific ship name.
     */
    public function getClassShipName(): string
    {
        return match($this) {
            self::COLLECTOR => 'Foreuse',
            self::GENERAL => 'Faucheur',
            self::DISCOVERER => 'Eclaireur',
        };
    }

    /**
     * Get the cost to change to this class (in Dark Matter).
     */
    public function getChangeCost(): int
    {
        return 500000;
    }

    /**
     * Get all character class bonuses as an array.
     *
     * @return array<int, string>
     */
    public function getBonuses(): array
    {
        return match($this) {
            self::COLLECTOR => [
                '+25% de production des mines',
                '+10% de production d\'energie',
                '+100% de vitesse pour les transporteurs',
                '+25% de capacite pour les transporteurs',
                '+50% de bonus de foreuse',
                '+10% de foreuses utilisables avec le Geologue',
                'Surcharge des foreuses jusqu\'a 150%',
                '+10% de reduction sur l\'acceleration (construction)',
            ],
            self::GENERAL => [
                '+100% de vitesse pour les vaisseaux de combat',
                '+100% de vitesse pour les recycleurs',
                '-50% de consommation de deuterium pour tous les vaisseaux',
                '+20% de capacite pour les recycleurs et eclaireurs',
                'Faible chance de detruire instantanement une Etoile de la mort avec un chasseur leger lors d\'un combat.',
                'Epaves lors des attaques (transport vers la planete de depart)',
                '+2 niveaux de recherches militaires',
                '+2 emplacements de flotte',
                '+5 cases lunaires supplementaires',
                'Reglages detailles de vitesse de flotte',
                '+10% de reduction sur l\'acceleration (chantier spatial)',
            ],
            self::DISCOVERER => [
                '-25% de temps de recherche',
                'Gains accrus lors des expeditions reussies',
                '+10% de cases sur les planetes colonisees',
                'Les champs de debris crees en expedition sont visibles dans la vue Galaxie.',
                '+2 expeditions',
                '-50% de risque de rencontre hostile en expedition',
                '+20% de portee du phalange',
                '75% de butin sur les joueurs inactifs',
                '+10% de reduction sur l\'acceleration (recherche)',
            ],
        };
    }

    /**
     * Get the ship description for this class.
     */
    public function getShipDescription(): string
    {
        return match($this) {
            self::COLLECTOR => "La foreuse est un large vehicule de tranchee qui augmente la production des mines et des synthetiseurs. Elle est plus agile qu'elle n'en a l'air mais reste fragile. Chaque foreuse augmente la production de metal, de cristal et de deuterium de 0,02%. En tant que Collectionneur, la production augmente davantage. Le bonus total maximal depend du niveau global de vos mines.",
            self::GENERAL => "Il n'existe guere plus destructeur qu'un vaisseau de classe Faucheur. Ces batiments combinent puissance de feu, boucliers solides, vitesse et capacite, avec la faculte unique de recolter une partie du champ de debris juste apres un combat. Cette capacite ne s'applique toutefois pas aux combats contre les pirates ou les extraterrestres.",
            self::DISCOVERER => "Les eclaireurs sont rapides et spacieux. Leur conception est optimisee pour s'aventurer en territoire inconnu. Ils peuvent decouvrir et recolter des champs de debris pendant les expeditions, et y trouver des objets. Le rendement total s'en trouve accru.",
        };
    }
}
