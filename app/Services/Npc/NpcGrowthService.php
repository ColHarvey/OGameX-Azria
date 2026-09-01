<?php

namespace OGame\Services\Npc;

use Exception;
use OGame\Models\Resources;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use OGame\Services\UnitQueueService;

/**
 * Le developpement autonome des bases hostiles.
 *
 * Il n'y a pas d'economie a simuler ici, seulement une file a remplir. PlanetService::update()
 * fait avancer la file de construction et la file de chantier a la seule condition que le
 * proprietaire ne soit pas en vacances — et un compte pilote par le serveur ne l'est jamais.
 * Les mines d'une base montent donc a l'heure, ses vaisseaux sortent un par un au rythme de
 * son chantier, sans que personne ne se connecte.
 *
 * Consequence directe : un pirate evolue au rythme d'un joueur en x1, non pas parce qu'on
 * l'a calibre ainsi mais parce qu'il emprunte la meme economie et les memes durees. Il n'y a
 * aucun rythme a regler.
 */
class NpcGrowthService
{
    /**
     * Ce qui a ete decide pour une base lors d'un passage, a fin de journal.
     */
    public const string ACTION_NOTHING = 'rien';
    public const string ACTION_BUSY = 'file en cours';
    public const string ACTION_CAPPED = 'plafond atteint';
    public const string ACTION_BUILDING = 'batiment';
    public const string ACTION_UNITS = 'chantier';

    public function __construct(
        private SettingsService $settings,
        private NpcPopulationService $population,
        private BuildingQueueService $buildingQueue,
        private UnitQueueService $unitQueue
    ) {
    }

    /**
     * Bring one base up to date and give it its next job.
     *
     * @return array{action: string, detail: string} Ce qui a ete fait, pour le journal.
     */
    public function grow(PlanetService $planet): array
    {
        // Rattrape la production, les batiments termines et les vaisseaux livres. Sans cet
        // appel la base resterait figee entre deux visites de joueur.
        $planet->update();

        if (!$this->settings->npcGrowthEnabled()) {
            return ['action' => self::ACTION_NOTHING, 'detail' => 'croissance desactivee'];
        }

        if ($this->isBusy($planet)) {
            return ['action' => self::ACTION_BUSY, 'detail' => ''];
        }

        if ($this->isAtCeiling($planet)) {
            return ['action' => self::ACTION_CAPPED, 'detail' => $this->maturityOf($planet) . '%'];
        }

        $building = $this->nextBuilding($planet);

        if ($building !== null && $this->enqueueBuilding($planet, $building)) {
            return ['action' => self::ACTION_BUILDING, 'detail' => $building];
        }

        $unit = $this->nextUnit($planet);

        if ($unit !== null && $this->enqueueUnits($planet, $unit['machine_name'], $unit['amount'])) {
            return ['action' => self::ACTION_UNITS, 'detail' => $unit['amount'] . ' x ' . $unit['machine_name']];
        }

        return ['action' => self::ACTION_NOTHING, 'detail' => 'rien d abordable'];
    }

    /**
     * Get how far along its ceiling this base has grown, as a percentage.
     */
    public function maturityOf(PlanetService $planet): int
    {
        $ceiling = $this->powerCeiling();

        if ($ceiling <= 0) {
            return 100;
        }

        return (int)min(100, round($planet->getPlanetScore() / $ceiling * 100));
    }

    /**
     * Get whether this base has grown as far as the server currently allows.
     */
    public function isAtCeiling(PlanetService $planet): bool
    {
        return $planet->getPlanetScore() >= $this->powerCeiling();
    }

    /**
     * Get the score a base may not grow past.
     *
     * Sans plafond, une base laissee tranquille six mois deviendrait intouchable et le
     * contenu se transformerait en decor. Le plafond n'est pas fixe : il se recalcule depuis
     * la mediane des joueurs actifs, donc il monte quand le serveur progresse. Les bases
     * restent ainsi eternellement a portee sans jamais devenir triviales.
     */
    public function powerCeiling(): int
    {
        $median = $this->population->medianScore();

        if ($median <= 0) {
            // Serveur encore vide de scores : on laisse la base atteindre le seuil fixe, ce
            // qui la maintient modeste sans bloquer completement sa croissance.
            $median = max(1, $this->settings->npcMinScoreFixed());
        }

        return (int)round($median * $this->settings->npcMaturityRatio());
    }

    /**
     * Get whether the base already has work in progress.
     */
    private function isBusy(PlanetService $planet): bool
    {
        $player = $planet->getPlayer();

        if ($player !== null && $player->isBuildingShipsOrDefense()) {
            return true;
        }

        return $this->buildingQueue->retrieveQueue($planet)->isQueueFull()
            || count($this->buildingQueue->retrieveQueueItems($planet)) > 0;
    }

    /**
     * Choose the next building this base should raise, or null when it should build units.
     *
     * Les regles sont volontairement lisibles et deterministes : on ne cherche pas une IA
     * qui reflechisse, mais une IA qui prenne de bonnes decisions avec des regles simples.
     * L'ordre des tests est l'ordre des priorites, et le premier qui s'applique gagne.
     */
    private function nextBuilding(PlanetService $planet): string|null
    {
        $metal = $planet->getObjectLevel('metal_mine');
        $crystal = $planet->getObjectLevel('crystal_mine');
        $deuterium = $planet->getObjectLevel('deuterium_synthesizer');
        $solar = $planet->getObjectLevel('solar_plant');
        $robot = $planet->getObjectLevel('robot_factory');
        $shipyard = $planet->getObjectLevel('shipyard');

        // L'energie d'abord : sans elle les mines tournent au ralenti et tout le reste est
        // vain.
        if ($planet->energy()->get() < 0) {
            return 'solar_plant';
        }

        // Une usine de robots tot rend tout le reste plus rapide.
        if ($robot < 4) {
            return 'robot_factory';
        }

        // Les trois mines gardent un ecart constant : c'est le profil d'un joueur qui sait
        // ce qu'il fait, et il produit une base credible a l'espionnage.
        if ($metal < $crystal + 2) {
            return 'metal_mine';
        }

        if ($crystal < $deuterium + 2) {
            return 'crystal_mine';
        }

        if ($deuterium < $metal - 4) {
            return 'deuterium_synthesizer';
        }

        if ($solar < $metal) {
            return 'solar_plant';
        }

        // Le stockage suit, sinon la production plafonne et le butin cesse de croitre.
        if ($planet->getObjectLevel('metal_store') < intdiv($metal, 3)) {
            return 'metal_store';
        }

        if ($planet->getObjectLevel('crystal_store') < intdiv($crystal, 3)) {
            return 'crystal_store';
        }

        // Le chantier n'arrive qu'une fois l'economie lancee : une base ne peut pas defendre
        // ce qu'elle n'a pas encore.
        if ($metal >= 8 && $shipyard < 6) {
            return 'shipyard';
        }

        return null;
    }

    /**
     * Choose what this base should build in its shipyard.
     *
     * @return array{machine_name: string, amount: int}|null
     */
    private function nextUnit(PlanetService $planet): array|null
    {
        if ($planet->getObjectLevel('shipyard') < 1) {
            return null;
        }

        // Les defenses passent en premier tant qu'elles sont maigres : une base sans defense
        // se fait ramasser avant d'avoir eu le temps d'exister.
        if ($planet->getObjectAmount('rocket_launcher') < 20) {
            return ['machine_name' => 'rocket_launcher', 'amount' => 5];
        }

        // Les pirates ont besoin de soutes : sans cargos, un raid ne rapporte rien et la
        // regle du butin limite par le fret le rend inoffensif.
        if ($planet->getObjectAmount('small_cargo') < 10) {
            return ['machine_name' => 'small_cargo', 'amount' => 2];
        }

        if ($planet->getObjectAmount('light_laser') < 10) {
            return ['machine_name' => 'light_laser', 'amount' => 2];
        }

        return ['machine_name' => 'light_fighter', 'amount' => 3];
    }

    /**
     * Put one building level in the queue when the base can pay for it.
     */
    private function enqueueBuilding(PlanetService $planet, string $machineName): bool
    {
        if (!ObjectService::objectRequirementsMet($machineName, $planet)) {
            return false;
        }

        $price = ObjectService::getObjectPrice($machineName, $planet);

        if (!$this->canSpend($planet, $price)) {
            return false;
        }

        try {
            $object = ObjectService::getObjectByMachineName($machineName);
            $this->buildingQueue->add($planet, $object->id);
        } catch (Exception) {
            // Champs epuises, file pleine, prerequis tombes entre-temps : la base attendra le
            // prochain passage. Rien de ceci ne merite d'interrompre le tick des autres bases.
            return false;
        }

        return true;
    }

    /**
     * Put an order in the shipyard when the base can pay for it.
     */
    private function enqueueUnits(PlanetService $planet, string $machineName, int $amount): bool
    {
        if (!ObjectService::objectRequirementsMet($machineName, $planet)) {
            return false;
        }

        $price = ObjectService::getObjectPrice($machineName, $planet);
        $total = $price->multiply($amount);

        if (!$this->canSpend($planet, $total)) {
            return false;
        }

        try {
            $object = ObjectService::getObjectByMachineName($machineName);
            $this->unitQueue->add($planet, $object->id, $amount);
        } catch (Exception) {
            return false;
        }

        return true;
    }

    /**
     * Get whether the base can pay this price and still have fuel left to fly.
     *
     * Une base qui depense jusqu'a son dernier deuterium construit tres bien et ne part
     * jamais : la flotte est la, le carburant non, et le tick propose indefiniment des
     * raids que le jeu refuse ensuite. La reserve est donc prelevee avant tout achat.
     */
    private function canSpend(PlanetService $planet, Resources $price): bool
    {
        if (!$planet->hasResources($price)) {
            return false;
        }

        $remaining = (int)$planet->deuterium()->get() - (int)$price->deuterium->get();

        return $remaining >= $this->fuelReserveFor($planet);
    }

    /**
     * Get how much deuterium a base keeps aside for its next sortie.
     *
     * Proportionnee au chantier : une base sans vaisseaux n'a rien a faire voler, une base
     * bien fournie doit pouvoir partir. Le chiffre reste modeste — il s'agit de ne pas etre
     * a sec, pas de thesauriser.
     */
    private function fuelReserveFor(PlanetService $planet): int
    {
        $ships = $planet->getShipUnits()->getAmount();

        if ($ships === 0) {
            return 0;
        }

        return min(50000, 200 * $ships);
    }
}
