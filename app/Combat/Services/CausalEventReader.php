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

    /** @var array<string, QueuedCompletion> */
    private array $files = [];

    /** @var array<string, QueuedCompletion> */
    private array $batiments = [];

    /** @var array<string, QueuedCompletion> */
    private array $recherches = [];

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
        $this->files = [];
        $this->batiments = [];
        $this->recherches = [];

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

        // **Un lot d'unites produit unite par unite.** Le monde materialise une unite toutes les
        // `(fin − debut) / quantite` secondes : un lot qui finit apres le curseur a deja pose des unites
        // avant lui. La lecture prend donc tout lot commence avant le curseur, et date son effet a sa
        // **derniere unite terminee strictement avant le curseur** — un lot dont aucune unite n'est
        // terminee n'a pas d'effet dans la partition, et n'est pas lu.
        foreach (self::unitBatchesStartedBy([$body], $cursorAt) as $file) {
            $evenement = self::queueEvent(CombatEventIdentity::forUnitQueueCompletion($file->id), self::UNIT_QUEUE_KIND, $file, $body, $order, CombatEventType::QueueCompletion, [SnapshotContribution::TargetDefences], $file->completedAt <= $cursorAt ? $file->completedAt : $file->lastFinishInstantBy($cursorAt - 1));
            $this->files[$evenement->identity] = $file;
            $evenements[] = $evenement;
        }

        // Un batiment n'apporte rien a la photographie du combat, mais la source est lue et classee :
        // une source qu'on n'interroge pas produit une tranche plausible et incomplete.
        foreach (self::completionsDue('building_queues', [$body], $cursorAt) as $file) {
            $evenement = self::queueEvent(CombatEventIdentity::forBuildingQueueCompletion($file->id), self::BUILDING_QUEUE_KIND, $file, $body, $order, CombatEventType::QueueCompletion, [SnapshotContribution::TargetDefences]);
            $this->batiments[$evenement->identity] = $file;
            $evenements[] = $evenement;
        }

        foreach (self::completionsDue('research_queues', self::bodiesOf($ownerId), $cursorAt) as $file) {
            $evenement = self::queueEvent(CombatEventIdentity::forResearchCompletion($file->id), self::RESEARCH_KIND, $file, $body, $order, CombatEventType::ResearchCompletion, [SnapshotContribution::CombatTechnology]);
            $this->recherches[$evenement->identity] = $file;
            $evenements[] = $evenement;
        }

        return $evenements;
    }

    /**
     * Ce que l'etat du corps reflete deja a cet instant : un recu par effet **reellement present**.
     *
     * ## `processed` n'est pas un recu
     *
     * Une mission traitee n'a pas forcement livre quelque chose a ce corps. Une attaque traitee a
     * pille, pas depose ; une flotte refusee par un combat est traitee et **repartie**, sans avoir
     * rien remis. Emettre un recu pour elles ferait dire a la provenance « cet effet est deja dans
     * l'etat » alors qu'il n'y est pas : le reconciliateur renoncerait a l'appliquer, et la
     * photographie compterait une cargaison que le corps n'a jamais recue.
     *
     * Un recu n'est donc emis que si les deux conditions tiennent : le genre de la mission **livre**
     * quelque chose au corps (cargaison ou flotte, d'apres la matrice), et la mission ne porte pas de
     * disposition de retour — la marque, ecrite par le combat lui-meme, d'une flotte renvoyee.
     *
     * Les files ne donnent pas de recu ici : leur effet vit dans l'effectif du corps, qui n'est pas
     * encore photographie. Les inclure ferait porter a la provenance une affirmation que rien ne
     * verifie — une file achevee puis dont les unites ont ete perdues au combat suivant reste
     * `processed`, et son effet n'est plus la.
     *
     * @return array<int, AppliedEffectReceipt>
     */
    public function receiptsAlreadyApplied(int $body, int $ownerId, int $combatInstanceId, int $capturedAt): array
    {
        $recus = [];

        $renvoyees = DB::table('combat_fleet_dispositions')->pluck('fleet_mission_id')->map(static fn (mixed $id): int => (int)$id)->all();

        foreach (FleetMission::query()->where('planet_id_to', $body)->where('processed', 1)->orderBy('id')->get() as $mission) {
            if (!self::deliversToTheBody($mission) || in_array((int)$mission->id, $renvoyees, true)) {
                continue;
            }
            $identite = CombatEventIdentity::forFleetArrival((int)$mission->id);
            $recus[] = new AppliedEffectReceipt($identite, self::FLEET_ARRIVAL_KIND, self::fleetEffectFingerprint($mission), $body, (int)$mission->time_arrival, self::receiptId($combatInstanceId, $identite));
        }

        return $recus;
    }

    /**
     * Ce genre de mission depose-t-il quelque chose sur le corps a son arrivee ?
     *
     * La matrice le dit : une cargaison livree, une flotte livree. Une attaque, une expedition, un
     * espionnage n'y deposent rien — leur traitement ne laisse pas d'effet a refleter.
     */
    private static function deliversToTheBody(FleetMission $mission): bool
    {
        $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);
        $etape = $mission->parent_id === null ? FlightLeg::Outbound : FlightLeg::Return;
        $situation = new CombatSituation($genre, $etape, ActorKind::Player, CombatState::Rallying);

        foreach ($situation->possibleProjections() as $projection) {
            if ($projection === SnapshotContribution::DeliveredCargo || $projection === SnapshotContribution::DeliveredFleet) {
                return true;
            }
        }

        return false;
    }

    /**
     * La mission qu'un evenement d'arrivee designe, telle qu'elle a ete lue sous verrou.
     */
    public function missionOf(string $identity): FleetMission|null
    {
        return $this->missions[$identity] ?? null;
    }

    /**
     * La file d'unites qu'un evenement designe, telle qu'elle a ete lue sous verrou.
     */
    public function unitQueueOf(string $identity): QueuedCompletion|null
    {
        return $this->files[$identity] ?? null;
    }

    /**
     * Le batiment qu'un evenement designe. Un seul compte pour la bataille — le chantier spatial,
     * qui decide la part d'epave recuperable — mais tous sont lus : la completude de la tranche ne
     * se decide pas au cas par cas.
     */
    public function buildingQueueOf(string $identity): QueuedCompletion|null
    {
        return $this->batiments[$identity] ?? null;
    }

    /**
     * La recherche qu'un evenement designe, telle qu'elle a ete lue sous verrou.
     */
    public function researchOf(string $identity): QueuedCompletion|null
    {
        return $this->recherches[$identity] ?? null;
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
     * Les lots d'unites commences avant le curseur qui ont au moins une unite terminee strictement
     * avant lui — ou qui sont finis avant lui, meme deja traites : la fermeture compte leur apport
     * depuis l'avancement materialise a l'ouverture, et un lot fini avant l'ouverture apporte zero.
     *
     * @param array<int, int> $bodies
     * @return array<int, QueuedCompletion>
     */
    private static function unitBatchesStartedBy(array $bodies, int $cursorAt): array
    {
        if ($bodies === []) {
            return [];
        }

        $lignes = DB::table('unit_queues')
            ->whereIn('planet_id', $bodies)
            ->where('time_start', '>', 0)
            ->where('time_start', '<=', $cursorAt)
            ->orderBy('time_end')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lots = [];
        foreach ($lignes as $ligne) {
            $lot = QueuedCompletion::fromRow((array)$ligne);
            if ($lot->completedAt <= $cursorAt || $lot->unitsFinishedBy($cursorAt - 1) > 0) {
                $lots[] = $lot;
            }
        }

        return $lots;
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
    private static function queueEvent(string $identity, string $kind, QueuedCompletion $file, int $body, CausalEventOrder $order, CombatEventType $type, array $contributions, int|null $effectAt = null): CausalEvent
    {
        return new CausalEvent(
            $identity,
            $kind,
            new DecisionOrder($file->decidedAt, $file->id),
            EffectOrderKey::forEvent($effectAt ?? $file->completedAt, $type, $file->id, $order),
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
