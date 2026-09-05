<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Causality\AppliedEffectReceipt;
use OGame\Combat\Causality\CausalEvent;
use OGame\Combat\Causality\CausalEventOrder;
use OGame\Combat\Causality\DecisionOrder;
use OGame\Combat\Decisions\CombatSituation;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\EffectOrderKey;
use OGame\Models\FleetMission;

/**
 * Les faits d'un corps, lus sous verrou et rendus au reconciliateur tels qu'il les attend.
 *
 * ## Une seule lecture pour l'ouverture et pour la fermeture
 *
 * L'ouverture demande **ce que l'etat du corps reflete deja** — les effets appliques avant elle —,
 * la fermeture demande **tout ce qui s'est decide ou produit** jusqu'a son curseur. Les deux lectures
 * viennent d'ici, avec les memes identites, les memes genres et les memes empreintes d'effet :
 * c'est ce qui permet a la provenance de reconnaitre a la fermeture ce qu'elle a vu a l'ouverture.
 * Une empreinte calculee ailleurs, autrement, ferait rejouer un effet deja compte.
 *
 * ## Les quatre sources
 *
 * - **Missions de flotte** vers le corps, aller et retour, tous genres : transports, deploiements,
 *   retours, attaques, renforts, missiles, espionnage. Le reconciliateur classe chacune ; ce lecteur
 *   ne decide rien. Les missiles sont des missions de flotte (genre 10) : la source « salves de
 *   missiles » est interrogee par la meme lecture, et dite telle quelle.
 * - **Files de construction** du corps (batiments, vaisseaux et defenses) et **file de recherche**
 *   de son proprietaire : engagees a leur debut, produites a leur fin.
 *
 * ## Ce que ce lecteur n'est pas
 *
 * Il n'applique rien et ne decide rien : la regle vit dans `CausalOrderReconciler`, les effets dans
 * leurs gestionnaires canoniques. Il retient les lignes lues pour que la fermeture retrouve, par
 * identite, la mission dont elle doit compter la cargaison ou qu'elle doit livrer.
 */
final class CausalEventReader
{
    public const string FLEET_ARRIVAL_KIND = 'fleet_arrival:v1';

    public const string UNIT_QUEUE_KIND = 'unit_queue_completion:v1';

    public const string BUILDING_QUEUE_KIND = 'building_queue_completion:v1';

    public const string RESEARCH_KIND = 'research_completion:v1';

    /** @var array<string, FleetMission> */
    private array $missions = [];

    /**
     * Tous les evenements dont l'effet tombe **au plus tard** au curseur, sous verrou.
     *
     * ## Pourquoi le curseur est inclusif, alors que les barrieres sont strictes
     *
     * Les deux barrieres appartiennent au reconciliateur, et a lui seul : les refaire ici en
     * filtrant a `<` dupliquerait sa regle, et deux copies d'une meme regle divergent. Ce que la
     * lecture decide n'est pas ce qui entre dans la photographie, mais ce que la partition couvre —
     * ce qu'on affirme avoir vu. Un evenement dont l'effet tombe exactement a la fermeture est donc
     * lu, puis **exclu** par le reconciliateur, qui dit pourquoi.
     *
     * C'est aussi ce qui rend une fenetre nulle lisible : l'initiatrice arrive a l'instant meme de
     * l'ouverture, qui est alors celui de la fermeture. Filtree a `<`, elle manquait, et le
     * reconciliateur refusait a juste titre une tranche qui omettait le fait fondateur.
     *
     * @return array<int, CausalEvent>
     */
    public function eventsToward(int $body, int $ownerId, int $cursorAt, CausalEventOrder $order, int $openerMissionId): array
    {
        $evenements = [];
        $this->missions = [];

        $missions = FleetMission::query()
            ->where('planet_id_to', $body)
            ->where('time_arrival', '<=', $cursorAt)
            ->orderBy('time_arrival')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($missions as $mission) {
            $evenement = $this->fleetArrivalEvent($mission, $order, $openerMissionId);
            $this->missions[$evenement->identity] = $mission;
            $evenements[] = $evenement;
        }

        foreach (self::completionsDue('unit_queues', [$body], $cursorAt) as $file) {
            $evenements[] = self::queueEvent(CombatEventIdentity::forUnitQueueCompletion($file->id), self::UNIT_QUEUE_KIND, $file, $body, $order, CombatEventType::QueueCompletion, [SnapshotContribution::TargetDefences]);
        }

        // Un batiment n'apporte rien a la photographie du combat, mais la source est lue et classee :
        // une source qu'on n'interroge pas produit une tranche plausible et incomplete.
        foreach (self::completionsDue('building_queues', [$body], $cursorAt) as $file) {
            $evenements[] = self::queueEvent(CombatEventIdentity::forBuildingQueueCompletion($file->id), self::BUILDING_QUEUE_KIND, $file, $body, $order, CombatEventType::QueueCompletion, []);
        }

        foreach (self::completionsDue('research_queues', self::bodiesOf($ownerId), $cursorAt) as $file) {
            $evenements[] = self::queueEvent(CombatEventIdentity::forResearchCompletion($file->id), self::RESEARCH_KIND, $file, $body, $order, CombatEventType::ResearchCompletion, [SnapshotContribution::CombatTechnology]);
        }

        return $evenements;
    }

    /**
     * Ce que l'etat du corps reflete deja a cet instant : un recu par effet deja applique.
     *
     * @return array<int, AppliedEffectReceipt>
     */
    public function receiptsAlreadyApplied(int $body, int $ownerId, int $combatInstanceId, int $capturedAt): array
    {
        $recus = [];

        foreach (FleetMission::query()->where('planet_id_to', $body)->where('processed', 1)->orderBy('id')->get() as $mission) {
            $identite = CombatEventIdentity::forFleetArrival((int)$mission->id);
            $recus[] = new AppliedEffectReceipt($identite, self::FLEET_ARRIVAL_KIND, self::fleetEffectFingerprint($mission), $body, (int)$mission->time_arrival, self::receiptId($combatInstanceId, $identite));
        }

        foreach (self::completionsApplied('unit_queues', [$body]) as $file) {
            $identite = CombatEventIdentity::forUnitQueueCompletion($file->id);
            $recus[] = new AppliedEffectReceipt($identite, self::UNIT_QUEUE_KIND, $file->effectFingerprint, $body, $file->completedAt, self::receiptId($combatInstanceId, $identite));
        }

        foreach (self::completionsApplied('building_queues', [$body]) as $file) {
            $identite = CombatEventIdentity::forBuildingQueueCompletion($file->id);
            $recus[] = new AppliedEffectReceipt($identite, self::BUILDING_QUEUE_KIND, $file->effectFingerprint, $body, $file->completedAt, self::receiptId($combatInstanceId, $identite));
        }

        foreach (self::completionsApplied('research_queues', self::bodiesOf($ownerId)) as $file) {
            $identite = CombatEventIdentity::forResearchCompletion($file->id);
            $recus[] = new AppliedEffectReceipt($identite, self::RESEARCH_KIND, $file->effectFingerprint, $body, $file->completedAt, self::receiptId($combatInstanceId, $identite));
        }

        return $recus;
    }

    /**
     * La mission qu'un evenement d'arrivee designe, telle qu'elle a ete lue sous verrou.
     */
    public function missionOf(string $identity): FleetMission|null
    {
        return $this->missions[$identity] ?? null;
    }

    public function fleetArrivalEvent(FleetMission $mission, CausalEventOrder $order, int $openerMissionId): CausalEvent
    {
        $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);
        $etape = $mission->parent_id === null ? FlightLeg::Outbound : FlightLeg::Return;
        $type = $genre === CombatMissionKind::Missile && $etape === FlightLeg::Outbound ? CombatEventType::MissileImpact : CombatEventType::FleetArrival;
        $situation = new CombatSituation($genre, $etape, ActorKind::Player, CombatState::Rallying);

        return new CausalEvent(
            CombatEventIdentity::forFleetArrival((int)$mission->id),
            self::FLEET_ARRIVAL_KIND,
            new DecisionOrder((int)$mission->time_departure, (int)$mission->id),
            EffectOrderKey::forEvent((int)$mission->time_arrival, $type, (int)$mission->id, $order),
            (int)$mission->planet_id_to,
            CombatSituation::scopeOf($genre, $etape),
            $situation->possibleProjections(),
            self::fleetEffectFingerprint($mission),
            self::fleetEffectFingerprint($mission),
            true,
            (int)$mission->processed === 1,
            (int)$mission->id === $openerMissionId,
        );
    }

    /**
     * L'empreinte de l'effet d'une mission : ce qu'elle livre, jamais ce qui dit si elle l'a fait.
     */
    public static function fleetEffectFingerprint(FleetMission $mission): string
    {
        $faits = $mission->getAttributes();
        foreach (['processed', 'processed_hold', 'created_at', 'updated_at', 'combat_instance_id'] as $variable) {
            unset($faits[$variable]);
        }
        ksort($faits);

        return hash('sha256', (string)json_encode($faits, JSON_THROW_ON_ERROR));
    }

    /**
     * Les achevements de cette file dont l'effet tombe au plus tard au curseur, sous verrou.
     *
     * `time_start > 0` ecarte les lignes qu'aucune decision n'a engagees : une file creee sans
     * depart n'a pas d'instant de decision, et lui en inventer un la ferait entrer ou sortir de la
     * photographie selon la valeur choisie.
     *
     * @param array<int, int> $bodies
     * @return array<int, QueuedCompletion>
     */
    private static function completionsDue(string $table, array $bodies, int $cursorAt): array
    {
        if ($bodies === []) {
            return [];
        }

        $lignes = DB::table($table)
            ->whereIn('planet_id', $bodies)
            ->where('time_end', '<=', $cursorAt)
            ->where('time_start', '>', 0)
            ->orderBy('time_end')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return array_map(static fn (object $ligne): QueuedCompletion => QueuedCompletion::fromRow((array)$ligne), $lignes->all());
    }

    /**
     * Les achevements de cette file deja produits dans le monde.
     *
     * @param array<int, int> $bodies
     * @return array<int, QueuedCompletion>
     */
    private static function completionsApplied(string $table, array $bodies): array
    {
        if ($bodies === []) {
            return [];
        }

        $lignes = DB::table($table)->whereIn('planet_id', $bodies)->where('processed', 1)->orderBy('id')->get();

        return array_map(static fn (object $ligne): QueuedCompletion => QueuedCompletion::fromRow((array)$ligne), $lignes->all());
    }

    /**
     * @return array<int, int>
     */
    private static function bodiesOf(int $ownerId): array
    {
        if ($ownerId < 1) {
            return [];
        }

        return DB::table('planets')->where('user_id', $ownerId)->pluck('id')->map(static fn (mixed $id): int => (int)$id)->all();
    }

    /**
     * @param array<int, SnapshotContribution> $contributions
     */
    private static function queueEvent(string $identity, string $kind, QueuedCompletion $file, int $body, CausalEventOrder $order, CombatEventType $type, array $contributions): CausalEvent
    {
        return new CausalEvent(
            $identity,
            $kind,
            new DecisionOrder($file->decidedAt, $file->id),
            EffectOrderKey::forEvent($file->completedAt, $type, $file->id, $order),
            $body,
            TargetScope::CelestialBody,
            $contributions,
            $file->effectFingerprint,
            $file->effectFingerprint,
            true,
            $file->alreadyApplied,
            false,
        );
    }

    private static function receiptId(int $combatInstanceId, string $identity): string
    {
        return substr(hash('sha256', 'ouverture:' . $combatInstanceId . ':' . $identity), 0, 36);
    }
}
