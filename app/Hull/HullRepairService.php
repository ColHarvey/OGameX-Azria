<?php

namespace OGame\Hull;

use Illuminate\Support\Facades\DB;
use OGame\Models\HullRepairOrder;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * Le second service du chantier spatial : reparer des survivants endommages, contre paiement.
 *
 * ------------------------------------------------------------------------------------
 * CE SERVICE NE TOUCHE PAS AUX EPAVES
 *
 * Le dock rend deux services que la consigne demande de garder « clairement separes » :
 *
 *   (A) **recuperer des epaves** — gratuit, inchange, `WreckFieldService` ;
 *   (B) **reparer des survivants** — payant, ici.
 *
 * Ils ont chacun leur emplacement et ne se bloquent pas l un l autre. C etait une tentation — un
 * dock, un chantier — mais bloquer (A) pendant (B) aurait **modifie le systeme d epaves**, ce que la
 * consigne interdit. Rien de ce fichier ne lit ni n ecrit une ligne de `wreck_fields`.
 *
 * ------------------------------------------------------------------------------------
 * OU VIVENT LES UNITES PENDANT LEUR REPARATION
 *
 * **Elles ne quittent pas le corps.** La colonne entiere de leur type ne bouge pas : elles sont
 * physiquement la, et elles se battront si le corps est attaque — le dock ne doit pas etre un abri.
 *
 * Ce qui bouge, c est leur **niveau de degats**, qui quitte `planets.damaged_hulls` pour le champ
 * `units` de l ordre. L invariant devient donc, par type :
 *
 *     degats du corps + unites confiees au dock  <=  effectif du corps
 *
 * Et la consequence est heureuse : une reparation qui va **jusqu au bout** n a rien a reecrire —
 * les unites reviennent intactes, et une unite intacte ne se stocke pas.
 *
 * ------------------------------------------------------------------------------------
 * TROIS FINS ANTICIPEES, UN SEUL CHEMIN
 *
 * Annulation par le joueur, perte du dock, ouverture d un combat sur le corps : les trois font
 * **exactement la meme chose**, par `endEarly()` — figer la coque interpolee a cet instant, rendre
 * les niveaux de degats au corps, rembourser la part non faite.
 *
 * Il n y a donc qu un mecanisme de fin anticipee a ecrire, et un seul a eprouver. C est aussi ce qui
 * ferme, **structurellement**, le risque que la consigne redoutait : une fin de reparation ne peut
 * pas ressusciter une unite detruite au combat, parce qu au moment ou le combat s ouvre **il n y a
 * plus d ordre**. Le probleme est supprime, pas surveille.
 *
 * ------------------------------------------------------------------------------------
 * L IDEMPOTENCE N EST PAS SURVEILLEE, ELLE EST STRUCTURELLE
 *
 * La progression est une **fonction pure du temps** (`HullRepairOrder::repairedShareAt()`) : aucune
 * ecriture periodique, donc rien qui puisse s appliquer deux fois. Le seul effet a n avoir lieu
 * qu une fois — rendre les unites — est garde par une mise a jour **conditionnelle** sur le statut :
 * le second appelant ne trouve aucune ligne a changer et sort sans rien faire.
 */
final class HullRepairService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Le devis pour ces unites, sur ce corps. Aucune ecriture, aucun verrou : c est un calcul.
     */
    public function quoteFor(PlanetService $planet, DamagedHulls $selection): HullRepairQuote
    {
        return HullRepairQuote::for(
            $selection,
            $this->dockLevelOf($planet),
            max(0, $this->settings->hullRepairMinMinutes()) * 60,
            max(1, $this->settings->hullRepairMaxHours()) * 3600,
        );
    }

    /**
     * Les unites endommagees d un corps qui sont **disponibles** a la reparation : celles qui ne
     * sont pas deja confiees au dock.
     */
    public function repairableOn(PlanetService $planet): DamagedHulls
    {
        return $planet->damagedHulls();
    }

    /**
     * L ordre en cours sur ce corps, s il y en a un.
     */
    public function runningOrderOn(int $planetId): HullRepairOrder|null
    {
        return HullRepairOrder::where('active_on_planet_id', $planetId)->first();
    }

    /**
     * Les unites qu un ordre en cours immobilise, par type — ce que la disponibilite au depart doit
     * soustraire.
     *
     * @return array<string, int>
     */
    public function unitsHeldAtDock(int $planetId): array
    {
        $ordre = $this->runningOrderOn($planetId);

        if ($ordre === null) {
            return [];
        }

        $tenues = [];

        foreach (DamagedHulls::fromStorage($ordre->units)->all() as $type => $niveaux) {
            $tenues[$type] = array_sum($niveaux);
        }

        return $tenues;
    }

    /**
     * Confirme un ordre de reparation : paie, reserve, ecrit — ou refuse, sans rien laisser derriere.
     *
     * **Tout est revalide ici, sous transaction et sous verrou.** Le devis affiche au joueur a pu
     * vieillir : les unites peuvent etre parties, avoir ete detruites, le dock avoir change de
     * niveau, les ressources avoir ete depensees ailleurs, un autre ordre avoir ete confirme entre
     * l affichage et le clic. Chacun de ces cas rend une raison nommee, jamais un echec muet.
     *
     * @param string $empreinte l empreinte du devis affiche, pour refuser une reponse perimee
     * @return HullRepairOrder l ordre cree
     */
    public function confirm(PlanetService $planet, DamagedHulls $selection, string $empreinte, int $maintenant): HullRepairOrder
    {
        if (!$this->settings->hullDamageEnabled()) {
            throw new RuntimeException('hull_repair.refused.disabled');
        }

        if ($selection->isEmpty()) {
            throw new RuntimeException('hull_repair.refused.nothing_selected');
        }

        $planetId = $planet->getPlanetId();
        $proprietaire = $planet->getPlayer();

        // Un corps sans proprietaire ne repare rien. Le cas existe — une planete detruite en garde
        // la ligne — et le dire vaut mieux que d appeler une methode sur rien.
        if ($proprietaire === null) {
            throw new RuntimeException('hull_repair.refused.body_gone');
        }

        $ownerId = $proprietaire->getId();

        return DB::transaction(function () use ($planet, $planetId, $ownerId, $selection, $empreinte, $maintenant): HullRepairOrder {
            /** @var Planet|null $ligne */
            $ligne = Planet::where('id', $planetId)->lockForUpdate()->first();

            if ($ligne === null) {
                throw new RuntimeException('hull_repair.refused.body_gone');
            }

            if ((int)$ligne->user_id !== $ownerId) {
                throw new RuntimeException('hull_repair.refused.not_owner');
            }

            // Le dock est relu **sous le verrou** : c est lui qui fixe le devis, et il a pu changer.
            $niveauDock = (int)$ligne->space_dock;

            if ($niveauDock < 1) {
                throw new RuntimeException('hull_repair.refused.no_dock');
            }

            // Un ordre a la fois : la colonne unique arbitre, mais le dire ici donne une raison
            // lisible plutot qu une violation de contrainte.
            if (HullRepairOrder::where('active_on_planet_id', $planetId)->exists()) {
                throw new RuntimeException('hull_repair.refused.dock_busy');
            }

            // Les unites demandees doivent **exactement** exister, au niveau de degats demande.
            $disponibles = DamagedHulls::fromStorage($ligne->damaged_hulls);
            $restant = $this->subtractExactly($disponibles, $selection, $ligne);

            $devis = HullRepairQuote::for(
                $selection,
                $niveauDock,
                max(0, $this->settings->hullRepairMinMinutes()) * 60,
                max(1, $this->settings->hullRepairMaxHours()) * 3600,
            );

            // **Le devis relu doit etre celui que le joueur a accepte.** Sans cela, un changement de
            // niveau de dock entre l affichage et le clic ferait payer un prix jamais montre.
            if ($empreinte !== '' && !hash_equals($devis->fingerprint(), $empreinte)) {
                throw new RuntimeException('hull_repair.refused.quote_stale');
            }

            if (!$planet->deductResourcesAtomic($devis->cost)) {
                throw new RuntimeException('hull_repair.refused.not_enough_resources');
            }

            // Les degats confies quittent le corps pour l ordre. L effectif, lui, ne bouge pas : les
            // unites restent physiquement presentes, et vulnerables.
            $ligne->damaged_hulls = $restant->toStorage();
            $ligne->save();

            return HullRepairOrder::create([
                'planet_id' => $planetId,
                'player_id' => $ownerId,
                'active_on_planet_id' => $planetId,
                'units' => $selection->toStorage(),
                'cost_metal' => (int)$devis->cost->metal->get(),
                'cost_crystal' => (int)$devis->cost->crystal->get(),
                'cost_deuterium' => (int)$devis->cost->deuterium->get(),
                'dock_level' => $devis->dockLevel,
                'started_at' => $maintenant,
                'completed_at' => $maintenant + $devis->durationSeconds,
                'status' => HullRepairOrder::STATUS_REPAIRING,
            ]);
        });
    }

    /**
     * Termine les ordres arrives a echeance. Les unites redeviennent **intactes**, donc il n y a
     * rien a reecrire dans les degats du corps : c est le cas heureux du modele.
     *
     * @return int le nombre d ordres reellement regles par cet appel
     */
    public function settleDue(int $maintenant): int
    {
        $regles = 0;

        $echus = HullRepairOrder::where('status', HullRepairOrder::STATUS_REPAIRING)
            ->where('completed_at', '<=', $maintenant)
            ->orderBy('id')
            ->get();

        foreach ($echus as $ordre) {
            if ($this->settle($ordre, $maintenant)) {
                $regles++;
            }
        }

        return $regles;
    }

    /**
     * Regle un ordre echu, **une seule fois**.
     *
     * La garde n est pas une lecture suivie d une ecriture — deux processus la passeraient tous les
     * deux. C est une mise a jour **conditionnelle sur le statut**, dont on demande ensuite qui l a
     * emportee : le perdant ne trouve rien a changer et sort sans effet.
     */
    public function settle(HullRepairOrder $ordre, int $maintenant): bool
    {
        return DB::transaction(function () use ($ordre, $maintenant): bool {
            $pris = HullRepairOrder::where('id', $ordre->id)
                ->where('status', HullRepairOrder::STATUS_REPAIRING)
                ->update([
                    'status' => HullRepairOrder::STATUS_SETTLED,
                    'settled_at' => $maintenant,
                    'active_on_planet_id' => null,
                ]);

            if ($pris === 0) {
                // Deja regle par un autre passage : cet appel-ci n a rien fait, et le dire est la
                // seule reponse juste.
                //
                // **Le nombre de lignes est fiable ici, et il ne l est pas partout** : MariaDB
                // compte les lignes *changees*, SQLite les lignes *trouvees*. Cette mise a jour fait
                // passer le statut de `repairing` a `settled`, donc la ligne change reellement des
                // qu elle est prise — les deux moteurs s accordent. Le bail du diffuseur, lui,
                // reecrivait la meme seconde et ne changeait rien : il a fallu redemander qui tient.
                return false;
            }

            // Les unites reviennent intactes : rien a ecrire dans `damaged_hulls`. L effectif du
            // corps n a jamais bouge — elles n en etaient pas parties.
            return true;
        });
    }

    /**
     * **Les trois fins anticipees**, par un seul chemin : annulation du joueur, dock perdu, combat.
     *
     * Le travail deja fait reste acquis, le reste est rembourse (decision 4, 10 septembre 2026).
     * La conservation s enonce en une ligne : *paye = travail rendu + rembourse*.
     *
     * @param string $parceQue une des constantes `HullRepairOrder::BECAUSE_*`
     * @return bool vrai si cet appel a bien mis fin a l ordre
     */
    public function endEarly(HullRepairOrder $ordre, string $parceQue, int $maintenant): bool
    {
        return DB::transaction(function () use ($ordre, $parceQue, $maintenant): bool {
            /** @var HullRepairOrder|null $relu */
            $relu = HullRepairOrder::where('id', $ordre->id)->lockForUpdate()->first();

            if ($relu === null || !$relu->isRunning()) {
                return false;
            }

            $part = $relu->repairedShareAt($maintenant);

            if ($part >= 1.0) {
                // L echeance est passee pendant qu on decidait : c est un reglement, pas une
                // annulation. Le joueur a paye pour un travail entierement fait.
                $relu->status = HullRepairOrder::STATUS_SETTLED;
                $relu->settled_at = $maintenant;
                $relu->active_on_planet_id = null;
                $relu->save();

                return true;
            }

            /** @var Planet|null $ligne */
            $ligne = Planet::where('id', $relu->planet_id)->lockForUpdate()->first();

            if ($ligne !== null) {
                // Les unites reviennent avec la coque **atteinte** : les degats de depart, reduits
                // de la part du travail accomplie.
                $rendus = $this->partiallyRepaired(DamagedHulls::fromStorage($relu->units), $part);

                $ligne->damaged_hulls = DamagedHulls::fromStorage($ligne->damaged_hulls)
                    ->merge($rendus)
                    ->toStorage();

                // Le remboursement : la part **non faite** de ce qui avait ete paye. L arrondi va
                // vers le bas, dans le sens qui ne cree pas de ressource.
                $reste = 1.0 - $part;

                $ligne->metal = (float)$ligne->metal + floor($relu->cost_metal * $reste);
                $ligne->crystal = (float)$ligne->crystal + floor($relu->cost_crystal * $reste);
                $ligne->deuterium = (float)$ligne->deuterium + floor($relu->cost_deuterium * $reste);

                $ligne->save();
            }

            $relu->status = HullRepairOrder::STATUS_CANCELLED;
            $relu->ended_because = $parceQue;
            $relu->settled_at = $maintenant;
            $relu->active_on_planet_id = null;
            $relu->save();

            return true;
        });
    }

    /**
     * Met fin a l ordre en cours sur ce corps, s il y en a un — le point d entree des chemins qui ne
     * connaissent que la planete : l ouverture d un combat, la perte du dock.
     */
    public function endAnyRunningOn(int $planetId, string $parceQue, int $maintenant): bool
    {
        $ordre = $this->runningOrderOn($planetId);

        if ($ordre === null) {
            return false;
        }

        return $this->endEarly($ordre, $parceQue, $maintenant);
    }

    /**
     * Les degats restants apres qu une part du travail a ete accomplie.
     *
     * Un palier a 5 000 points de base repare a 40 % devient 3 000. Le plancher a 1 evite qu un
     * arrondi transforme une unite presque reparee en unite intacte **gratuitement** : tant que le
     * travail n est pas fini, il reste quelque chose a payer.
     */
    private function partiallyRepaired(DamagedHulls $depart, float $part): DamagedHulls
    {
        if ($part <= 0.0) {
            return $depart;
        }

        $restants = [];

        foreach ($depart->all() as $type => $niveaux) {
            foreach ($niveaux as $degats => $nombre) {
                $reste = (int)round($degats * (1.0 - $part));

                if ($reste <= 0) {
                    $reste = 1;
                }

                $restants[$type][$reste] = ($restants[$type][$reste] ?? 0) + $nombre;
            }
        }

        return DamagedHulls::of($restants);
    }

    /**
     * Retire la selection des degats disponibles, en exigeant qu elle y soit **exactement**.
     *
     * Une selection qui demanderait plus d unites a un palier qu il n en porte est refusee, jamais
     * rabotee : accepter en silence ferait payer pour des unites qui n existent pas.
     */
    private function subtractExactly(DamagedHulls $disponibles, DamagedHulls $selection, Planet $ligne): DamagedHulls
    {
        $reste = $disponibles->all();

        foreach ($selection->all() as $type => $niveaux) {
            // L unite doit exister dans le jeu, et le corps doit en porter au moins autant.
            ObjectService::getUnitObjectByMachineName($type);

            foreach ($niveaux as $degats => $nombre) {
                $present = $reste[$type][$degats] ?? 0;

                if ($present < $nombre) {
                    throw new RuntimeException('hull_repair.refused.units_gone');
                }

                $laisse = $present - $nombre;

                if ($laisse === 0) {
                    unset($reste[$type][$degats]);
                } else {
                    $reste[$type][$degats] = $laisse;
                }
            }

            if (($reste[$type] ?? []) === []) {
                unset($reste[$type]);
            }

            // L effectif reel doit couvrir ce qu on pretend reparer.
            if ((int)$ligne->{$type} < array_sum($selection->levelsOf($type))) {
                throw new RuntimeException('hull_repair.refused.units_gone');
            }
        }

        return DamagedHulls::of($reste);
    }

    private function dockLevelOf(PlanetService $planet): int
    {
        return $planet->getObjectLevel('space_dock');
    }
}
