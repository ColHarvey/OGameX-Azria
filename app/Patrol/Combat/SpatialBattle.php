<?php

namespace OGame\Patrol\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Causality\CausalEventOrder;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Enums\UnitCharacteristicsRule;
use OGame\Combat\Exceptions\UnknownAdmissionHistory;
use OGame\Combat\Services\CombatEntryCharacteristicsRegistry;
use OGame\Combat\Support\CombatantFrozenAtEntry;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\LootContext;
use OGame\Combat\Support\LootContextForMission;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\History\ClassHistoryReader;
use OGame\Hull\DamagedHulls;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;

/**
 * Une bataille en espace libre : une flotte attaquante contre une patrouille posee.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE CETTE CLASSE ASSEMBLE, ET CE QU ELLE N INVENTE PAS
 *
 * Le socle du combat spatial existait depuis le 10 septembre 2026 — le lieu synthetique
 * (`SpatialCombatSite`), les combattants aux caracteristiques gelees (`FrozenCombatant`), l ouverture
 * du champ (`SpatialFieldOpening`) — mais **rien ne l appelait**. Cinq classes eprouvees, zero
 * raccordement : une patrouille etait donc **invulnerable**, et le systeme entier ne servait a rien
 * du point de vue d un joueur.
 *
 * Cette classe est le raccordement. Elle n ajoute aucune regle : elle prend les pieces existantes,
 * les monte dans l ordre, et laisse le moteur partage decider de la bataille.
 *
 * ------------------------------------------------------------------------------------
 * LA BATAILLE EST INSTANTANEE, ET C EST UN CHOIX
 *
 * Le combat **progressif** existe pour les corps celestes, mais son generateur de tirages **n est
 * pas approuve** (reserve de Keven, 10 septembre 2026) : le socle spatial recoit ses tirages, il
 * n en fabrique aucun. Ouvrir ici un combat durable obligerait a choisir ce generateur en
 * production, sans decision — exactement ce que le socle refuse de faire.
 *
 * La bataille se resout donc **a l arrivee**, comme toute attaque du jeu quand
 * `persistent_combat_enabled` vaut non. Le progressif se branchera plus tard, sur les memes pieces,
 * quand le generateur sera tranche.
 *
 * ------------------------------------------------------------------------------------
 * LES DEUX CAMPS TIRENT AVEC CE QU ILS AVAIENT A L ADMISSION
 *
 * Cet en-tete affirmait que les deux camps entraient avec des `FrozenCombatant`. **Le code leur passait
 * des comptes vivants**, lus au traitement : une classe prise, une alliance quittee ou une recherche
 * achevee entre l arrivee et le passage d un travailleur changeait des tirs deja decides.
 *
 * Ils entrent desormais avec `CombatantFrozenAtEntry` — le combattant du combat durable, pas un second —,
 * composes par la derivation de `CombatEntryCharacteristicsRegistry` a l **arrivee physique** de
 * l attaquante : elle comme un evenement d arrivee, la patrouille, deja la, a la barriere de cet instant.
 * Seuls les trois niveaux et le bonus des classes sont geles ; le reste du compte (fret, manoeuvre de
 * Hamill) est lu vivant, exactement comme dans le combat durable.
 *
 * Avant la ligne de base des historiques, la premiere regle garde l ancien geste : le compte vivant. Un
 * historique inconnu leve `UnknownAdmissionHistory`, que l arrivee attrape pour suspendre sans rien
 * decider.
 */
final class SpatialBattle
{
    public function __construct(
        private readonly PlayerServiceFactory $players,
        private readonly SettingsService $settings,
        private CombatEntryCharacteristicsRegistry|null $entries = null,
        private ClassHistoryReader|null $history = null,
        private CausalEventOrderRegistry|null $orders = null,
    ) {
    }

    /**
     * Joue la bataille et rend son resultat, **sans rien ecrire**.
     *
     * L ecriture appartient au reglement : ce qui se passe ici est un calcul, et il doit pouvoir
     * etre rejoue, mesure et compare sans toucher a la base.
     *
     * @param FleetMission $attaquante La mission arrivee sur le point.
     * @param FleetMission $segment Le vol courant de la patrouille visee — c est lui qui porte les
     *                              unites et la cargaison.
     *
     * @throws UnknownAdmissionHistory quand l historique des classes ne sait pas ce qu un camp avait a
     *                                  l admission : la bataille ne se compose pas sur une supposition.
     */
    public function fight(FleetMission $attaquante, Patrol $cible, FleetMission $segment): BattleResult
    {
        // **L admission est l arrivee physique de l attaquante**, jamais l horloge du travailleur qui tient
        // la patrouille. La regle se choisit a cet instant, comme a l ouverture d un combat durable.
        $admission = (int)$attaquante->time_arrival;
        $regle = UnitCharacteristicsRule::forOpeningAt($admission, $this->history()->baselineInstant());

        // **L ordre causal se choisit une fois, a l entree de l operation**, par la frontiere nommee des
        // operations instantanees : la bataille commence maintenant et ne le relit plus. Aucune version
        // courante n est lue ici — la garde architecturale le verifie.
        $ordre = $this->orders()->forVersion(FrozenCombatVersionSet::atOperationStart($this->orders())->causalOrder);

        $attaquant = $this->attackingFleet($attaquante, $regle, $ordre);
        $site = $this->siteOf($cible, $segment, $regle, $ordre, $admission);
        $defenseurs = $this->defendingFleets($site, $segment);

        $lootContext = $this->lootContext($attaquant, $site, $attaquante);

        $moteur = new PhpBattleEngine(
            [$attaquant],
            $site,
            $defenseurs,
            $this->settings,
            $lootContext,
        );

        return $moteur->simulateBattle();
    }

    /**
     * La flotte attaquante, avec ses caracteristiques et ses coques gelees.
     */
    private function attackingFleet(FleetMission $mission, UnitCharacteristicsRule $regle, CausalEventOrder $ordre): AttackerFleet
    {
        $joueur = match ($regle) {
            UnitCharacteristicsRule::FrozenAtEntry => new CombatantFrozenAtEntry(
                (int)$mission->user_id,
                $this->entries()->atArrival((int)$mission->user_id, (int)$mission->id, (int)$mission->time_arrival, $ordre)
            ),
            UnitCharacteristicsRule::FirstRule => $this->players->make((int)$mission->user_id, true),
        };

        $flotte = new AttackerFleet();
        $flotte->units = $this->unitsOf($mission);
        $flotte->player = $joueur;
        $flotte->fleetMissionId = (int)$mission->id;
        $flotte->ownerId = (int)$mission->user_id;
        $flotte->cargoResources = new Resources(
            (float)$mission->metal,
            (float)$mission->crystal,
            (float)$mission->deuterium,
            0
        );
        $flotte->isInitiator = true;
        $flotte->fleetMission = $mission;

        // Les coques entamees voyagent avec la flotte (journal §118) : une flotte abimee attaque
        // abimee, ici comme partout ailleurs.
        $flotte->damagedHulls = DamagedHulls::fromStorage($mission->damaged_hulls);

        return $flotte;
    }

    /**
     * Le lieu : un point de l espace, qui ne porte ni garnison, ni stock, ni chantier.
     */
    private function siteOf(Patrol $cible, FleetMission $segment, UnitCharacteristicsRule $regle, CausalEventOrder $ordre, int $admission): SpatialCombatSite
    {
        // **La patrouille est deja la** quand l attaquante arrive : elle entre a la barriere de cet instant.
        $proprietaire = match ($regle) {
            UnitCharacteristicsRule::FrozenAtEntry => new CombatantFrozenAtEntry(
                (int)$cible->user_id,
                $this->entries()->atBarrier((int)$cible->user_id, $admission, $ordre, 'La patrouille ' . $cible->id)
            ),
            UnitCharacteristicsRule::FirstRule => $this->players->make((int)$cible->user_id, true),
        };

        return new SpatialCombatSite(
            $this->players,
            $this->settings,
            $proprietaire,
            new SpatialPoint((int)$segment->x_to, (int)$segment->y_to),
            (int)$cible->galaxy,
            (int)$cible->system,
        );
    }

    /**
     * Les flottes defenseuses : la garnison vide du point, puis la patrouille.
     *
     * **La garnison existe et reste vide.** Le moteur tient un compte par flotte et exige la flotte
     * d identifiant zero ; l omettre ferait diverger la composition du champ. Un point de l espace
     * n a evidemment rien au sol, et `SpatialCombatSite` le dit en rendant des collections vides.
     *
     * @return array<int, DefenderFleet>
     */
    private function defendingFleets(SpatialCombatSite $site, FleetMission $segment): array
    {
        $patrouille = new DefenderFleet();
        $patrouille->units = $this->unitsOf($segment);
        $patrouille->player = $site->getPlayer();
        $patrouille->fleetMissionId = (int)$segment->id;
        $patrouille->ownerId = (int)$segment->user_id;
        $patrouille->fleetMission = $segment;
        $patrouille->damagedHulls = DamagedHulls::fromStorage($segment->damaged_hulls);

        return [DefenderFleet::fromPlanet($site), $patrouille];
    }

    /**
     * Les faits de pillage, photographies avant le premier tir.
     *
     * **Ce qui est pillable en espace libre est la cargaison, jamais un stock au sol** : il n y a pas
     * de sol. La reserve de carburant de la patrouille est protegee — elle vit sur la ligne de la
     * patrouille, pas sur le segment —, et seul l excedent transporte peut changer de mains.
     */
    private function lootContext(AttackerFleet $attaquant, SpatialCombatSite $site, FleetMission $attaquante): LootContext
    {
        return LootContextForMission::lootingOrDegraded(
            [$attaquant],
            $site,
            'spatial',
            (int)$attaquante->id,
            // **La version vient de l allocation gelee au debut de l operation**, jamais du
            // registre courant. Demander "la version d aujourd hui" ici semblerait sans danger —
            // la bataille se joue dans la seconde — mais c est exactement ce que la garde de source
            // interdit, et elle a raison : le jour ou cette bataille deviendra progressive, le choix
            // se ferait au milieu du calcul sans que personne ne s en apercoive.
            FrozenLootAllocation::atOperationStart(),
        );
    }

    private function entries(): CombatEntryCharacteristicsRegistry
    {
        return $this->entries ??= resolve(CombatEntryCharacteristicsRegistry::class);
    }

    private function history(): ClassHistoryReader
    {
        return $this->history ??= resolve(ClassHistoryReader::class);
    }

    private function orders(): CausalEventOrderRegistry
    {
        return $this->orders ??= CausalEventOrderRegistry::default();
    }

    /**
     * Les unites que porte une mission.
     */
    private function unitsOf(FleetMission $mission): UnitCollection
    {
        $unites = new UnitCollection();

        foreach (ObjectService::getShipObjects() as $vaisseau) {
            $nombre = (int)($mission->{$vaisseau->machine_name} ?? 0);

            if ($nombre > 0) {
                $unites->addUnit($vaisseau, $nombre);
            }
        }

        return $unites;
    }

    /**
     * Les debris qu une bataille en espace libre laisse **sur son point**.
     *
     * Ils ne tombent pas sur une planete : il n y en a pas. Ils restent la ou la bataille a eu lieu,
     * et un recycleur devra s y rendre. La ligne est creee ou completee **atomiquement**, parce que
     * deux batailles peuvent se terminer au meme point dans la meme seconde.
     */
    public function leaveDebrisAt(int $galaxy, int $system, SpatialPoint $point, Resources $debris): void
    {
        if ($debris->sum() <= 0) {
            return;
        }

        // **Deux gestes portables plutot qu un `upsert` qui ne l est pas.**
        //
        // `values(metal)` est une syntaxe MySQL : SQLite la refuse net, et le premier essai l a
        // montre. Ce depot connait deja ce piege — `orderByRaw('FIELD(...)')` fait tomber la vue
        // generale sous SQLite — et la lecon est la meme : ce qui doit tourner des deux cotes
        // s ecrit avec ce que les deux comprennent.
        //
        // Le couple tient la course sans depender du dialecte :
        //
        //   1. `insertOrIgnore` cree la ligne **a zero** si elle n existe pas, et ne fait rien
        //      sinon — deux batailles simultanees au meme point n en creent qu une, la contrainte
        //      d unicite arbitrant ;
        //   2. l addition est ensuite faite **en base**, jamais par une relecture suivie d une
        //      ecriture, donc deux ajouts concurrents s additionnent au lieu de s ecraser.
        DB::table('space_debris_fields')->insertOrIgnore([
            'galaxy' => $galaxy,
            'system' => $system,
            'x' => $point->x,
            'y' => $point->y,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('space_debris_fields')
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('x', $point->x)
            ->where('y', $point->y)
            ->update([
                'metal' => DB::raw('metal + ' . (float)$debris->metal->get()),
                'crystal' => DB::raw('crystal + ' . (float)$debris->crystal->get()),
                'deuterium' => DB::raw('deuterium + ' . (float)$debris->deuterium->get()),
                'updated_at' => now(),
            ]);
    }

    /**
     * Les unites survivantes d une flotte, telles que le resultat les decrit.
     */
    public static function survivorsOf(BattleResult $resultat, int $fleetMissionId, bool $attaquante): UnitCollection
    {
        $resultats = $attaquante ? $resultat->attackerFleetResults : $resultat->defenderFleetResults;

        foreach ($resultats as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte->unitsResult;
            }
        }

        return new UnitCollection();
    }

    /**
     * Les coques entamees des survivants d une flotte.
     */
    public static function survivorHullsOf(BattleResult $resultat, int $fleetMissionId, bool $attaquante): DamagedHulls
    {
        $resultats = $attaquante ? $resultat->attackerFleetResults : $resultat->defenderFleetResults;

        foreach ($resultats as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte->survivorHulls();
            }
        }

        return DamagedHulls::none();
    }

    /**
     * Seuls les vaisseaux comptent en espace libre : il n y a pas de defense au sol a recycler.
     */
    public static function onlyShips(UnitCollection $unites): UnitCollection
    {
        $vaisseaux = new UnitCollection();

        foreach ($unites->units as $unite) {
            if ($unite->unitObject->type === GameObjectType::Ship && $unite->amount > 0) {
                $vaisseaux->addUnit($unite->unitObject, $unite->amount);
            }
        }

        return $vaisseaux;
    }
}
