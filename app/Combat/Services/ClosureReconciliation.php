<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Causality\CausalEventSliceClaim;
use OGame\Combat\Causality\CausalEventSource;
use OGame\Combat\Causality\CausalOrderReconciler;
use OGame\Combat\Causality\CausalWindow;
use OGame\Combat\Causality\PartitionBarrier;
use OGame\Combat\Causality\ReconciledEvent;
use OGame\Combat\Causality\VerifiedCompleteEventSlice;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\EffectOrderKey;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\SnapshotContributionSet;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\CombatInstance;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use RuntimeException;

/**
 * La fermeture reconciliee : les faits relus sous verrou, le reconciliateur qui decide, les
 * gestionnaires canoniques qui appliquent, et la reserve protegee qui en resulte.
 *
 * ## Une seule decision autoritative
 *
 * Aucune regle temporelle ne vit ici. La tranche est lue par `CausalEventReader`, verifiee complete
 * contre la barriere de partition, et c'est `CausalOrderReconciler` — pur, versionne — qui dit de
 * chaque evenement s'il entre dans la photographie, s'il doit d'abord etre applique, ou s'il y est
 * deja. Ce service execute ce plan, dans l'ordre des effets, et rien d'autre.
 *
 * ## Ce qui est applique, et par qui
 *
 * Un evenement « a appliquer » est livre par son gestionnaire canonique — `updateMission()` pour une
 * flotte, les files du corps et de son proprietaire pour les constructions et les recherches —,
 * chacun idempotent : ce que le travailleur a deja fait ne se refait pas, ce qu'il n'a pas encore
 * fait se fait ici, sous les verrous de la fermeture. Les arrivees qui ouvrent un combat ou
 * renforcent la defense ne sont pas livrees ici : les selecteurs d'admission les decident, sous la
 * meme fenetre, et les inscrivent eux-memes.
 *
 * ## La reserve protegee
 *
 * L'etat d'ouverture, plus la cargaison de chaque livraison admissible qui n'y etait pas deja. Jamais
 * le stock vivant : la production, les livraisons decidees apres l'ouverture, tout ce que le monde a
 * fait depuis reste au monde. Le reglement, lui, debitera contre le stock vivant avec son minimum.
 */
final class ClosureReconciliation
{
    public function __construct(
        private CausalEventReader $reader = new CausalEventReader(),
        private CausalOrderReconciler $reconciler = new CausalOrderReconciler(),
        private CausalEventOrderRegistry|null $orders = null,
        private PlanetServiceFactory|null $planets = null,
        private PlayerServiceFactory|null $players = null,
    ) {
    }

    public function reconcile(CombatInstance $combat, int $targetBodyId, int $openedAt, int $closedAt): ReconciledClosure
    {
        $ouverture = OpeningStateRecorder::protectedStateOf($combat);
        $ordre = ($this->orders ?? CausalEventOrderRegistry::default())->forVersion((string)$combat->causal_order_version);
        $fenetre = new CausalWindow($openedAt, $closedAt, $ordre);
        // **Le curseur de la partition couvre la fermeture elle-meme.** Une barriere porte le rang zero,
        // que tout evenement reel depasse : posee a `$closedAt`, elle ne couvrirait que ce qui precede
        // strictement, et la tranche ne pourrait pas se dire complete au sujet d'un effet tombant
        // exactement a la fermeture — l'initiatrice d'une fenetre nulle, par exemple. Posee juste
        // apres, elle couvre ce que la lecture a vu, et le reconciliateur exclut ensuite ce qui doit
        // l'etre.
        $barriere = new PartitionBarrier((int)$combat->id, $targetBodyId, EffectOrderKey::barrierAt($closedAt + 1, $ordre));
        $proprietaire = (int)(DB::table('planets')->where('id', $targetBodyId)->value('user_id') ?? 0);

        $evenements = $this->reader->eventsToward($targetBodyId, $proprietaire, $closedAt, $ordre, (int)$combat->mission_id);
        $tranche = VerifiedCompleteEventSlice::verifiedUnderLock(
            CausalEventSliceClaim::assembledFrom($evenements, CausalEventSource::cases()),
            $barriere
        );
        $photographie = $this->reconciler->reconcile(
            $ouverture,
            CombatEventIdentity::forFleetArrival((int)$combat->mission_id),
            $fenetre,
            $tranche
        );

        $appliques = $this->apply($photographie->toApply(), $targetBodyId, $proprietaire);

        return new ReconciledClosure(
            $photographie,
            $this->protectedResourcesOf($combat, $photographie->inTheSnapshot()),
            $appliques,
            $this->inclusionsOf($photographie->inTheSnapshot()),
        );
    }

    /**
     * @param array<int, ReconciledEvent> $aAppliquer
     * @return array<int, string>
     */
    private function apply(array $aAppliquer, int $targetBodyId, int $ownerId): array
    {
        $appliques = [];
        $files = false;
        $recherches = false;

        foreach ($aAppliquer as $reconcilie) {
            $identite = $reconcilie->event->identity;
            $mission = $this->reader->missionOf($identite);

            if ($mission !== null) {
                $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);
                if ($mission->parent_id === null && ($genre->opensCombat() || $genre->reinforcesTheDefence())) {
                    continue;
                }
                resolve(FleetMissionService::class)->updateMission($mission);
                $appliques[] = $identite;
                continue;
            }

            // Une recherche appartient au proprietaire, une file au corps : leurs gestionnaires ne
            // sont pas les memes, et le prefixe de l'identite dit lequel — sans reconstruire l'identite
            // ni deviner d'apres l'ordre de lecture.
            if (str_starts_with($identite, CombatEventIdentity::prefixOf('research'))) {
                $recherches = true;
                $appliques[] = $identite;
                continue;
            }

            $files = true;
            $appliques[] = $identite;
        }

        if ($files) {
            $corps = $this->planets()->make($targetBodyId, true);
            if ($corps === null) {
                throw new RuntimeException('Le corps ' . $targetBodyId . ' a disparu pendant la fermeture.');
            }
            $corps->updateBuildingQueue();
            $corps->updateUnitQueue();
        }

        if ($recherches && $ownerId > 0) {
            $this->players()->make($ownerId, true)->updateResearchQueue();
        }

        return $appliques;
    }

    /**
     * @param array<int, ReconciledEvent> $dansLaPhotographie
     */
    private function protectedResourcesOf(CombatInstance $combat, array $dansLaPhotographie): Resources
    {
        $reserve = OpeningStateRecorder::openingResourcesOf($combat);
        $metal = (int)$reserve->metal->get();
        $cristal = (int)$reserve->crystal->get();
        $deuterium = (int)$reserve->deuterium->get();

        foreach ($dansLaPhotographie as $reconcilie) {
            if ($reconcilie->admission !== CausalAdmission::AppliedBeforeSnapshot) {
                continue;
            }
            if (!in_array(SnapshotContribution::DeliveredCargo, $reconcilie->event->contributions, true)) {
                continue;
            }
            $mission = $this->reader->missionOf($reconcilie->event->identity);
            if ($mission === null) {
                continue;
            }
            $metal += ResourceBoundary::wholeUnitsOfCarriedCargo((float)$mission->metal, 'metal', 'fermeture')->units;
            $cristal += ResourceBoundary::wholeUnitsOfCarriedCargo((float)$mission->crystal, 'crystal', 'fermeture')->units;
            $deuterium += ResourceBoundary::wholeUnitsOfCarriedCargo((float)$mission->deuterium, 'deuterium', 'fermeture')->units;
        }

        return new Resources($metal, $cristal, $deuterium, 0);
    }

    /**
     * Les inclusions que la fermeture ecrit : chaque evenement qui entre dans la photographie et y
     * apporte quelque chose, sauf les arrivees que les selecteurs inscrivent eux-memes.
     *
     * @param array<int, ReconciledEvent> $dansLaPhotographie
     * @return array<string, SnapshotContributionSet>
     */
    private function inclusionsOf(array $dansLaPhotographie): array
    {
        $inclusions = [];
        foreach ($dansLaPhotographie as $reconcilie) {
            $evenement = $reconcilie->event;
            if ($evenement->contributions === [] || $evenement->isFoundingInitiator) {
                continue;
            }
            $mission = $this->reader->missionOf($evenement->identity);
            if ($mission !== null && $mission->parent_id === null) {
                $genre = CombatMissionKind::fromMissionType((int)$mission->mission_type);
                if ($genre->opensCombat() || $genre->reinforcesTheDefence()) {
                    continue;
                }
            }
            $inclusions[$evenement->identity] = SnapshotContributionSet::of($evenement->contributions);
        }

        return $inclusions;
    }

    private function planets(): PlanetServiceFactory
    {
        return $this->planets ??= resolve(PlanetServiceFactory::class);
    }

    private function players(): PlayerServiceFactory
    {
        return $this->players ??= resolve(PlayerServiceFactory::class);
    }
}
