<?php

namespace OGame\Patrol\Combat;

use OGame\Combat\Services\PhotographedDefender;
use OGame\Combat\Support\FrozenFact;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Services\ObjectService;

/**
 * Ce qu un combat en espace libre gele de la patrouille qui defend.
 *
 * ------------------------------------------------------------------------------------
 * UN ETAT INITIAL, PAS UN VERDICT
 *
 * Cette photographie ne calcule rien. Elle dit ce qui **est la** au moment ou la bataille
 * s ouvre : les unites posees, ce qu elles portent, la reserve de carburant, et les
 * niveaux qui gouvernent les tirs. C est de cet etat que l avanceur partira pour jouer le
 * premier round, et c est entre deux rounds que de nouveaux arrivants s y ajouteront.
 *
 * Figer un resultat complet ici reviendrait a decider la bataille avant le premier tir, et
 * a rendre inutile tout renfort — exactement ce que le moteur progressif existe pour eviter.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI GELER, PLUTOT QUE RELIRE LE MONDE
 *
 * Le monde bouge pendant qu on se bat : une flotte peut etre reconstituee ailleurs, une
 * technologie finir de se rechercher, une classe changer. Si le moteur relisait ces valeurs
 * a chaque round, la meme bataille rendrait deux resultats selon l instant ou un travailleur
 * a eu la main — et un joueur pourrait renforcer ses tirs **retroactivement** en terminant
 * une recherche pendant le combat.
 *
 * Le bonus de classe est photographie **tel qu il vaut**, pas la classe : c est la valeur
 * que le moteur additionne, et c est elle qui doit rester stable.
 *
 * ------------------------------------------------------------------------------------
 * LA RESERVE ET LA CARGAISON SONT DEUX CHOSES
 *
 * La reserve de carburant paie le retour ; la cargaison est ce que la patrouille transporte.
 * Les melanger ferait piller le carburant du retour, ou protegerait une cargaison qui ne
 * doit pas l etre (revue 121, R6 : seule la reserve du retour des survivants est protegee,
 * l excedent est pillable). Elles sont donc portees separement des le gel.
 *
 * ------------------------------------------------------------------------------------
 * UNE PORTE DE CONFIANCE, DONC AUCUN TRANSTYPAGE
 *
 * `fromFrozenFacts()` relit un document qui a fait un aller-retour en base. `(int)` accepte
 * `'4'`, `4.7` et `true` et les rend tous egaux a 4 : un document abime passerait pour un
 * document valide, et la bataille se jouerait sur des effectifs que personne n a ecrits.
 * `FrozenFact` exige le type, et refuse sinon.
 */
final readonly class FrozenSpatialDefence
{
    /**
     * @param int $patrolId La patrouille qui defend.
     * @param int $ownerId Son proprietaire — celui de la patrouille, jamais celui d un corps.
     * @param int $missionId La flotte posee qui porte les unites et la cargaison.
     * @param UnitCollection $units L effectif au premier round.
     * @param array{metal: int, crystal: int, deuterium: int} $cargo Ce que la flotte transporte.
     * @param int $fuelReserve La reserve de deuterium qui paie le retour, en unites entieres.
     * @param PhotographedDefender $defender Les niveaux qui gouvernent les tirs.
     */
    public function __construct(
        public int $patrolId,
        public int $ownerId,
        public int $missionId,
        public UnitCollection $units,
        public array $cargo,
        public int $fuelReserve,
        public PhotographedDefender $defender,
    ) {
    }

    /**
     * Le document a ecrire dans l etat d ouverture.
     *
     * @return array<string, mixed>
     */
    public function toFrozenFacts(): array
    {
        return [
            'kind' => 'spatial_defence',
            'patrol_id' => $this->patrolId,
            'owner_id' => $this->ownerId,
            'mission_id' => $this->missionId,
            'units' => $this->units->toArray(),
            'cargo' => $this->cargo,
            'fuel_reserve' => $this->fuelReserve,
            'defender' => $this->defender->toFrozenFacts(),
        ];
    }

    /**
     * Le document relu, ou un refus.
     *
     * @param array<string, mixed> $facts
     */
    public static function fromFrozenFacts(array $facts): self
    {
        $genre = FrozenFact::string($facts, 'kind');

        if ($genre !== 'spatial_defence') {
            throw new CorruptedSpatialDefence(
                'A frozen spatial defence was asked to read a « ' . $genre .' » document: a body '
                . 'photograph and a free-space one do not describe the same world.'
            );
        }

        $cargo = FrozenFact::array($facts, 'cargo');

        return new self(
            FrozenFact::int($facts, 'patrol_id'),
            FrozenFact::int($facts, 'owner_id'),
            FrozenFact::int($facts, 'mission_id'),
            self::unitsFrom(FrozenFact::array($facts, 'units')),
            [
                'metal' => FrozenFact::int($cargo, 'metal'),
                'crystal' => FrozenFact::int($cargo, 'crystal'),
                'deuterium' => FrozenFact::int($cargo, 'deuterium'),
            ],
            FrozenFact::int($facts, 'fuel_reserve'),
            PhotographedDefender::fromFrozenFacts(FrozenFact::array($facts, 'defender')),
        );
    }

    /**
     * L effectif reconstruit, unite par unite.
     *
     * **Un nom inconnu est refuse, jamais ignore.** Un vaisseau retire du jeu entre le gel et la
     * relecture ferait disparaitre des unites d un effectif deja fige : la bataille se jouerait
     * alors a effectif reduit sans que personne ne l ait decide.
     *
     * @param array<string, mixed> $units
     */
    private static function unitsFrom(array $units): UnitCollection
    {
        $effectif = new UnitCollection();

        foreach ($units as $nom => $nombre) {
            if (!is_string($nom) || !is_int($nombre)) {
                throw new CorruptedSpatialDefence(
                    'A frozen spatial defence carries a unit line that is not « name => whole amount ».'
                );
            }

            if ($nombre < 1) {
                throw new CorruptedSpatialDefence(
                    'A frozen spatial defence carries ' . $nombre . ' × ' . $nom . ': a photographed '
                    . 'roster holds what stands, and nothing else.'
                );
            }

            $effectif->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $effectif;
    }
}
