<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\ActorKind;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;

/**
 * Les faits economiques d'une flotte attaquante, en unites entieres.
 *
 * ## Deux familles de faits, et pourquoi elles ne se verifient pas de la meme facon
 *
 * **Les faits structurels** — identifiant de mission, proprietaire, composition en vaisseaux,
 * cargaison transportee, fret libre — sont visibles par le moteur au moment du calcul. Ils peuvent
 * donc etre recalcules et compares : si la composition a change entre la photographie et le combat,
 * la comparaison le montre.
 *
 * **Les faits geles** — genre d'acteur, classe Decouvreur — ne doivent surtout pas etre recalcules.
 * Les relire reviendrait a interroger les modeles vivants a la resolution, c'est-a-dire a defaire
 * ce que le gel garantit : un joueur qui change de classe pendant un combat ne
 * doit rien y changer. Ils viennent donc du contexte, et de lui seul.
 */
final readonly class AttackerFleetSnapshot
{
    /**
     * @param int $fleetMissionId
     * @param int $ownerId
     * @param ActorKind $actorKind Fait gele.
     * @param bool $isInitiator
     * @param bool $isDiscoverer Fait gele.
     * @param int $freeCargo Fret libre, en unites entieres.
     * @param array<string, int> $units Nombre de vaisseaux par nom machine.
     * @param array<string, int> $carried Ressources deja transportees, en unites entieres.
     */
    private function __construct(
        public int $fleetMissionId,
        public int $ownerId,
        public ActorKind $actorKind,
        public bool $isInitiator,
        public bool $isDiscoverer,
        public int $freeCargo,
        public array $units,
        public array $carried,
    ) {
    }

    /**
     * La photographie d'une flotte.
     *
     * @param AttackerFleet $fleet
     * @param ActorKind $actorKind
     * @param bool $isDiscoverer
     * @param int $freeCargo
     * @return self
     */
    public static function of(AttackerFleet $fleet, ActorKind $actorKind, bool $isDiscoverer, int $freeCargo): self
    {
        return new self(
            $fleet->fleetMissionId,
            $fleet->ownerId,
            $actorKind,
            $fleet->isInitiator,
            $isDiscoverer,
            $freeCargo,
            self::unitsOf($fleet),
            [
                'metal' => self::wholeUnitsOf($fleet->cargoResources->metal->get()),
                'crystal' => self::wholeUnitsOf($fleet->cargoResources->crystal->get()),
                'deuterium' => self::wholeUnitsOf($fleet->cargoResources->deuterium->get()),
            ],
        );
    }

    /**
     * Les faits, sous la forme qui entre dans l'empreinte.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fleet_mission_id' => $this->fleetMissionId,
            'owner_id' => $this->ownerId,
            'actor_kind' => $this->actorKind->value,
            'is_initiator' => $this->isInitiator,
            'is_discoverer' => $this->isDiscoverer,
            'free_cargo' => $this->freeCargo,
            'units' => $this->units,
            'carried' => $this->carried,
        ];
    }

    /**
     * La part que le moteur peut recalculer et confronter a la photographie.
     *
     * **Le fret libre n en fait pas partie**, et c est delibere : il depend du niveau
     * d hyperespace du proprietaire, qui peut monter pendant un combat
     * persistant. Le comparer ferait echouer un combat parfaitement legitime au motif qu un joueur
     * a termine une recherche. Il reste dans la photographie — c est un fait gele — mais il n est
     * pas confronte.
     *
     * Ce qui est confronte ne peut pas bouger entre la photographie et le calcul : identifiants,
     * proprietaire, role d initiateur, composition en vaisseaux, cargaison transportee.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    public static function structuralPartOf(array $facts): array
    {
        return [
            'fleet_mission_id' => $facts['fleet_mission_id'] ?? null,
            'owner_id' => $facts['owner_id'] ?? null,
            'is_initiator' => $facts['is_initiator'] ?? null,
            'units' => $facts['units'] ?? null,
            'carried' => $facts['carried'] ?? null,
        ];
    }

    /**
     * La meme part, lue directement sur une flotte que le moteur tient en main.
     *
     * @param AttackerFleet $fleet
     * @return array<string, mixed>
     */
    public static function structuralFactsOfFleet(AttackerFleet $fleet): array
    {
        return [
            'fleet_mission_id' => $fleet->fleetMissionId,
            'owner_id' => $fleet->ownerId,
            'is_initiator' => $fleet->isInitiator,
            'units' => self::unitsOf($fleet),
            'carried' => [
                'metal' => self::wholeUnitsOf($fleet->cargoResources->metal->get()),
                'crystal' => self::wholeUnitsOf($fleet->cargoResources->crystal->get()),
                'deuterium' => self::wholeUnitsOf($fleet->cargoResources->deuterium->get()),
            ],
        ];
    }

    /**
     * Le nombre de vaisseaux par nom machine, trie.
     *
     * Le tri n'est pas cosmetique : `UnitCollection` conserve l'ordre d'ajout, et deux flottes
     * identiques constituees dans un ordre different donneraient sinon deux empreintes.
     *
     * @param AttackerFleet $fleet
     * @return array<string, int>
     */
    private static function unitsOf(AttackerFleet $fleet): array
    {
        $vaisseaux = [];

        foreach ($fleet->units->units as $unite) {
            $vaisseaux[$unite->unitObject->machine_name] = $unite->amount;
        }

        ksort($vaisseaux, SORT_STRING);

        return $vaisseaux;
    }

    /**
     * Un montant flottant ramene a des unites entieres.
     *
     * **Un fait gele n accepte aucun negatif.** La correction du petit artefact d arrondi appartient
     * a la frontiere des modeles vivants ; ici, un negatif affirmerait une composition que personne
     * n a observee — une flotte ne transporte pas une dette.
     *
     * @param float $amount
     * @return int
     */
    private static function wholeUnitsOf(float $amount): int
    {
        // Un fait gele ne tolere aucun negatif, et une cargaison de flotte n atteint jamais la
        // plage de precision degradee : il n y a donc rien a signaler ici.
        return ResourceBoundary::wholeUnitsOfFrozenFact($amount, 'carried')->units;
    }
}
