<?php

namespace OGame\Hull;

use OGame\Models\HullRepairOrder;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;

/**
 * Ce que le joueur voit du second service du chantier spatial.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE CE PANNEAU REFUSE DE DIRE
 *
 * **Une moyenne n est pas la sante d un vaisseau.** « Vos 20 croiseurs sont a 82 % » decrit une
 * flotte qui n existe pas : douze sont intacts et huit sont a moitie detruits. Le panneau rend donc
 * la **repartition par palier**, et le libelle que la consigne demande — « 20 croiseurs, dont 8
 * endommages » — au lieu d un pourcentage unique.
 *
 * **Rien n est expose aux adversaires ni aux allies.** Ce panneau ne se compose que pour le
 * proprietaire du corps, depuis ses propres colonnes ; aucune route ne le rend pour un tiers. C est
 * la meme regle que le renseignement de surveillance, ou un fait interdit reste **absent** plutot
 * que present a zero.
 *
 * ------------------------------------------------------------------------------------
 * LA RAISON D UN BOUTON INDISPONIBLE EST UNE DONNEE
 *
 * Un bouton grise sans explication est un defaut d interface. Chaque refus possible porte donc une
 * clef de traduction, et le panneau la rend : pas de dock, dock occupe, rien d endommage,
 * ressources insuffisantes.
 */
final class HullRepairPanel
{
    public function __construct(
        private readonly HullRepairService $reparations,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Le panneau du corps courant, ou `null` si le chantier est desarme.
     *
     * **`null` plutot qu un panneau vide** : tant que l interrupteur est a non, ce service n existe
     * pas pour le joueur, et la vue ne doit pas afficher un bloc « rien a reparer » qui annoncerait
     * une fonction absente.
     *
     * @return array<string, mixed>|null
     */
    public function forPlanet(PlanetService $planet): array|null
    {
        if (!$this->settings->hullDamageEnabled()) {
            return null;
        }

        $niveauDock = $planet->getObjectLevel('space_dock');
        $ordre = $this->reparations->runningOrderOn($planet->getPlanetId());
        $degats = $planet->damagedHulls();

        return [
            'dock_level' => $niveauDock,
            'fleet' => $this->fleetLines($planet, $degats),
            'order' => $ordre === null ? null : $this->orderLines($ordre),
            'unavailable_because' => $this->unavailableBecause($niveauDock, $ordre, $degats),
        ];
    }

    /**
     * L etat de la flotte, type par type : combien en tout, combien d endommages, et **a quels
     * paliers** — jamais une moyenne.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fleetLines(PlanetService $planet, DamagedHulls $degats): array
    {
        $lignes = [];

        foreach ($planet->getShipUnits()->units as $unite) {
            $type = $unite->unitObject->machine_name;
            $abimes = $degats->damagedCountOf($type);

            if ($abimes === 0) {
                continue;
            }

            $paliers = [];

            foreach ($degats->levelsOf($type) as $niveau => $nombre) {
                $paliers[] = [
                    // La coque **restante**, en pour-cent, arrondie pour l affichage seulement : la
                    // valeur qui decide reste les points de base.
                    'hull_percent' => round((DamagedHulls::FULL_DAMAGE - $niveau) / 100, 1),
                    'damage_basis_points' => $niveau,
                    'count' => $nombre,
                ];
            }

            $lignes[] = [
                'machine_name' => $type,
                'title' => $unite->unitObject->title,
                'total' => $unite->amount,
                'damaged' => $abimes,
                'levels' => $paliers,
            ];
        }

        return $lignes;
    }

    /**
     * L ordre en cours : ce qu il repare, ou il en est, et quand il finit.
     *
     * **L avancement est calcule, jamais lu** : c est une fonction pure du temps, donc deux lectures
     * successives sont forcement d accord et aucun travailleur n a besoin d etre passe.
     *
     * @return array<string, mixed>
     */
    private function orderLines(HullRepairOrder $ordre): array
    {
        $maintenant = (int)now()->timestamp;
        $unites = DamagedHulls::fromStorage($ordre->units);
        $tenues = [];

        foreach ($unites->all() as $type => $niveaux) {
            $tenues[] = [
                'machine_name' => $type,
                'title' => ObjectService::getUnitObjectByMachineName($type)->title,
                'count' => array_sum($niveaux),
            ];
        }

        return [
            'id' => (int)$ordre->id,
            'units' => $tenues,
            'progress_percent' => round($ordre->repairedShareAt($maintenant) * 100, 1),
            // **Des secondes restantes, jamais un instant absolu** : c est la regle deja suivie par
            // le panneau de combat, et elle evite d exposer l horloge du serveur.
            'seconds_remaining' => max(0, $ordre->completed_at - $maintenant),
            'paid' => [
                'metal' => (int)$ordre->cost_metal,
                'crystal' => (int)$ordre->cost_crystal,
                'deuterium' => (int)$ordre->cost_deuterium,
            ],
            // Ce qu une annulation rendrait **maintenant**, pour que le joueur decide en connaissance.
            'refund_if_cancelled_now' => $this->refundNow($ordre, $maintenant),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function refundNow(HullRepairOrder $ordre, int $maintenant): array
    {
        $reste = 1.0 - $ordre->repairedShareAt($maintenant);

        return [
            'metal' => (int)floor($ordre->cost_metal * $reste),
            'crystal' => (int)floor($ordre->cost_crystal * $reste),
            'deuterium' => (int)floor($ordre->cost_deuterium * $reste),
        ];
    }

    /**
     * Pourquoi le bouton « reparer » ne peut pas etre utilise, ou `null` s il le peut.
     */
    private function unavailableBecause(int $niveauDock, HullRepairOrder|null $ordre, DamagedHulls $degats): string|null
    {
        if ($niveauDock < 1) {
            return 'no_dock';
        }

        if ($ordre !== null) {
            return 'dock_busy';
        }

        if ($degats->isEmpty()) {
            return 'nothing_damaged';
        }

        return null;
    }
}
