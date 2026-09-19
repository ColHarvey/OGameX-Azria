<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use OGame\Facades\AppUtil;
use OGame\Galaxy\GalaxyHeaderCounters;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformAvailability;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOutcome;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Presentation\GalaxyDiscoveries;
use OGame\Lifeforms\Presentation\LifeformBanner;
use OGame\Lifeforms\Presentation\LifeformBonusPage;
use OGame\Lifeforms\Presentation\LifeformEffectPresenter;
use OGame\Lifeforms\Research\LifeformExperience;
use OGame\Lifeforms\Research\LifeformSlotRules;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformDiscoveryService;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Services\LifeformQuote;
use OGame\Lifeforms\Services\LifeformResearchService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformWelcome;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Les pages des formes de vie : le choix de l espece, et les batiments de la planete courante.
 *
 * Tout passe par les services : le controleur lit, presente et transmet, il ne decide de rien.
 * Toute page repond 404 quand l interrupteur est ferme — l entree du menu est cachee, et une
 * adresse gardee ne montre rien de plus.
 */
final class LifeformsController extends OGameController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly LifeformInstallationService $installation,
        private readonly LifeformQueueService $queue,
        private readonly LifeformLevels $levels,
        private readonly LifeformRuleRevisions $revisions,
        private readonly LifeformBanner $banner,
        private readonly LifeformEffectPresenter $effects,
        private readonly LifeformResearchService $research,
        private readonly LifeformDiscoveryService $discoveries,
        private readonly LifeformBonusPage $bonusPage,
        private readonly LifeformBonusResolver $resolver,
    ) {
    }

    /**
     * La page des formes de vie : le choix de l espece, ou l espece choisie et ses fiches.
     */
    public function index(PlayerService $player): View
    {
        $this->requireOpen();
        $this->setBodyId('lifeforms');

        $compte = $this->installation->accountOf($player->getId());
        $choisie = $compte === null ? null : Species::from((int)$compte->species);
        $progres = LifeformSpeciesProgress::query()->where('user_id', $player->getId())->get()->keyBy('species');

        $especes = [];
        foreach (Species::cases() as $espece) {
            $experience = $progres->get($espece->value);
            $points = $experience === null ? 0 : (int)$experience->experience;
            [$acquis, $requis] = LifeformExperience::progressOf($points);
            $especes[] = [
                'species' => $espece,
                'name' => __('t_lifeforms.species.' . $espece->machineName()),
                'lore' => __('t_lifeforms_ui.lore.' . $espece->machineName()),
                'usage' => __('t_lifeforms_ui.usage.' . $espece->machineName()),
                'chosen' => $choisie === $espece,
                'experience' => $points,
                'experience_level' => LifeformExperience::levelOf($points),
                'experience_progress' => $acquis,
                'experience_needed' => $requis,
                'experience_bonus' => LifeformExperience::bonusFraction(LifeformExperience::levelOf($points)) * 100,
                'buildings' => LifeformCatalogue::buildingsOf($espece),
                'technologies' => LifeformCatalogue::technologiesOf($espece),
            ];
        }

        return view('ingame.lifeforms.index', [
            'especes' => $especes,
            'choisie' => $choisie,
            'chosen_at' => $compte === null ? null : (int)$compte->chosen_at,
            'planet_name' => $player->planets->current()->getPlanetName(),
            'lifeforms_error' => session('lifeforms_error'),
        ]);
    }

    /**
     * Le choix de l espece : une fois, pour toutes les planetes.
     */
    public function select(Request $request, PlayerService $player): RedirectResponse
    {
        $this->requireOpen();
        $valide = $request->validate(['species' => ['required', 'integer', 'in:1,2,3,4']]);
        $espece = Species::from((int)$valide['species']);

        try {
            $this->installation->chooseSpecies($player->getId(), $espece, (int)Date::now()->timestamp);
        } catch (LifeformRefused $refus) {
            return redirect()->route('lifeforms.index')->with('lifeforms_error', __($refus->translationKey()));
        }

        return redirect()->route('lifeforms.index')->with('status', __('t_lifeforms_ui.selection.done', ['species' => __('t_lifeforms.species.' . $espece->machineName())]));
    }

    /**
     * « Plus tard » sur l invitation : memorise par compte et par version d accueil.
     */
    public function dismissWelcome(PlayerService $player): RedirectResponse
    {
        LifeformWelcome::query()->updateOrCreate(
            ['user_id' => $player->getId()],
            ['dismissed_version' => LifeformWelcome::VERSION, 'dismissed_at' => (int)Date::now()->timestamp]
        );

        return redirect()->route('overview.index');
    }

    /**
     * Les douze batiments de la planete courante.
     */
    public function buildings(PlayerService $player): View|RedirectResponse
    {
        $this->requireOpen();
        $this->setBodyId('lifeforms');
        $planet = $player->planets->current();
        $espece = $this->installation->speciesOf($player->getId());
        if ($espece === null) {
            return redirect()->route('lifeforms.index')->with('status', __('t_lifeforms_ui.buildings.choose_first'));
        }
        if (!$planet->isPlanet()) {
            return redirect()->route('overview.index')->with('status', __('t_lifeforms_ui.buildings.not_on_a_moon'));
        }
        $etat = $this->stateOf($planet);
        if ($etat === null) {
            return redirect()->route('lifeforms.index')->with('status', __('t_lifeforms_ui.buildings.choose_first'));
        }

        $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
        $enFile = $this->queue->queued($planet->getPlanetId(), LifeformKind::Building);
        $enCours = $enFile->firstWhere('status', 'running');
        $enAttente = $enFile->where('status', 'waiting')->values();
        $filePleine = $enAttente->count() >= LifeformQueueService::MAX_WAITING_PER_KIND;
        $vitesses = $this->revisions->live();
        $vacances = $player->isInVacationMode();

        // Les prerequis se jugent avec la file, comme le service les juge (audit, journal §155.27).
        $niveauxAvecFile = $this->queue->buildingLevelsWithQueue($planet->getPlanetId(), $niveaux);
        $tuiles = [];
        foreach (LifeformCatalogue::buildingsOf($espece) as $batiment) {
            $niveau = $niveaux[$batiment->id] ?? 0;
            $dejaEnFile = $enFile->where('object_id', $batiment->id)->count();
            $cible = $niveau + $dejaEnFile + 1;
            $devis = LifeformQuote::for($batiment, $cible, $niveaux, $planet->getObjectLevel('robot_factory'), $planet->getObjectLevel('nano_factory'), $vitesses);
            $tuiles[] = [
                'object' => $batiment,
                'title' => __('t_lifeforms.' . $batiment->machineName . '.title'),
                'level' => $niveau,
                'target_level' => $cible,
                'building_now' => $enCours !== null && (int)$enCours->object_id === $batiment->id,
                'building_target' => $enCours !== null && (int)$enCours->object_id === $batiment->id ? (int)$enCours->target_level : null,
                'available' => LifeformAvailability::isAvailable($batiment),
                'requirements_met' => $this->queue->requirementsMet($batiment, $niveauxAvecFile),
                'population_met' => $this->queue->populationMet($batiment, $cible, $etat),
                'enough_resources' => $planet->hasResources($devis->price),
                'queue_full' => $filePleine,
                'vacation' => $vacances,
            ];
        }

        return view('ingame.lifeforms.buildings', [
            'species' => $espece,
            'species_name' => __('t_lifeforms.species.' . $espece->machineName()),
            'planet_name' => $planet->getPlanetName(),
            'header_filename' => $this->headerOf($planet),
            'tiles' => $tuiles,
            'queue_active' => $enCours,
            'queue_waiting' => $enAttente,
            // La file de l autre genre : les deux boites vivent cote a cote sur chaque page, comme l officiel.
            'other_queue_active' => $this->queue->queued($planet->getPlanetId(), LifeformKind::Technology)->firstWhere('status', 'running'),
            // Les recherches en attente aussi : une liste vide les cachait depuis cette page (releve de Codex, §155.26).
            'other_queue_waiting' => $this->queue->queued($planet->getPlanetId(), LifeformKind::Technology)->where('status', 'waiting')->values(),
            'is_in_vacation_mode' => $vacances,
            'held' => $this->banner->heldOn($planet),
        ]);
    }

    /**
     * Le detail d un batiment, pour le panneau de la page (memes attentes que `technologydetails`).
     */
    public function buildingsAjax(Request $request, PlayerService $player): JsonResponse
    {
        $this->requireOpen();
        $planet = $player->planets->current();
        $espece = $this->installation->speciesOf($player->getId());
        $id = (int)$request->input('technology');
        if ($espece === null || !LifeformCatalogue::has($id) || !$planet->isPlanet()) {
            return response()->json(['success' => false, 'message' => __('t_lifeforms_ui.refused.unknown_object')], 404);
        }
        $objet = LifeformCatalogue::byId($id);
        $etat = $this->stateOf($planet);
        if ($objet->species !== $espece || $objet->kind !== LifeformKind::Building || $etat === null) {
            return response()->json(['success' => false, 'message' => __('t_lifeforms_ui.refused.wrong_species')], 404);
        }

        $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
        $niveau = $niveaux[$objet->id] ?? 0;
        $enFile = $this->queue->queued($planet->getPlanetId(), LifeformKind::Building);
        $enCours = $enFile->firstWhere('status', 'running');
        $cible = $niveau + $enFile->where('object_id', $objet->id)->count() + 1;
        $vitesses = $this->revisions->live();
        $devis = LifeformQuote::for($objet, $cible, $niveaux, $planet->getObjectLevel('robot_factory'), $planet->getObjectLevel('nano_factory'), $vitesses);
        $populationExigee = LifeformFormulas::populationRequired($objet, $cible);

        // Les prerequis se jugent avec la file, comme le service les juge (audit, journal §155.27).
        $niveauxAvecFile = $this->queue->buildingLevelsWithQueue($planet->getPlanetId(), $niveaux);
        $prerequis = [];
        foreach ($objet->requirements as $exige => $niveauExige) {
            $prerequis[] = [
                'title' => __('t_lifeforms.' . LifeformCatalogue::byId($exige)->machineName . '.title'),
                'level' => $niveauExige,
                'met' => ($niveauxAvecFile[$exige] ?? 0) >= $niveauExige,
            ];
        }

        // Un objet indisponible n a ni bouton ni raison : le panneau le dit en clair a la place des effets (§155.26).
        // L ordre des raisons est celui du service (vacances, prerequis, population, file pleine, puis les ressources,
        // que le service ne refuse pas mais dont le depart a besoin) : vignette, fiche et refus disent la meme chose.
        $raison = null;
        if ($player->isInVacationMode()) {
            $raison = __('t_ingame.ajax_object.vacation_mode');
        } elseif (!$this->queue->requirementsMet($objet, $niveauxAvecFile)) {
            $raison = __('t_ingame.buildings.requirements_not_met');
        } elseif (!$this->queue->populationMet($objet, $cible, $etat)) {
            $raison = __('t_lifeforms_ui.buildings.population_not_met', ['required' => AppUtil::formatNumber((int)ceil($populationExigee))]);
        } elseif ($enFile->where('status', 'waiting')->count() >= LifeformQueueService::MAX_WAITING_PER_KIND) {
            $raison = __('t_ingame.buildings.queue_full');
        } elseif (!$planet->hasResources($devis->price)) {
            $raison = __('t_ingame.buildings.not_enough_resources');
        }

        $html = view('ingame.lifeforms.ajax.building', [
            'object' => $objet,
            'title' => __('t_lifeforms.' . $objet->machineName . '.title'),
            'description' => __('t_lifeforms.' . $objet->machineName . '.description'),
            'current_level' => $niveau,
            'next_level' => $cible,
            'price' => $devis->price,
            'energy' => $devis->energy,
            'production_time' => AppUtil::formatTimeDuration($devis->duration),
            'population_required' => $populationExigee > 0 ? AppUtil::formatNumber((int)ceil($populationExigee)) : null,
            'population_current' => AppUtil::formatNumber((int)floor((float)$etat->population)),
            'requirements' => $prerequis,
            'effects' => $this->effects->effectsOf($objet, $niveaux, $espece, $vitesses->demography()),
            'planet' => $planet,
            'can_build' => $raison === null,
            'reason' => $raison,
            'available' => LifeformAvailability::isAvailable($objet),
            'active_item' => $enCours !== null && (int)$enCours->object_id === $objet->id ? $enCours : null,
            // Un travail court deja sur la planete : le bouton dit « Dans la file », comme la fiche classique.
            'queue_busy' => $enCours !== null,
        ])->render();

        return response()->json([
            'target' => 'technologydetails',
            'content' => ['technologydetails' => $html],
            'files' => ['js' => [], 'css' => []],
            'newAjaxToken' => csrf_token(),
        ]);
    }

    /**
     * Une demande de construction, comme les pages classiques la postent (`technologyId`, `_token`).
     */
    public function addBuildRequest(Request $request, PlayerService $player): JsonResponse
    {
        if (!$this->settings->lifeformsEnabled()) {
            return response()->json(['success' => false, 'message' => __('t_lifeforms_ui.refused.closed')]);
        }
        if ($player->isInVacationMode()) {
            return response()->json(['success' => false]);
        }
        if (!hash_equals($request->session()->token(), (string)$request->input('_token'))) {
            return response()->json(['success' => false, 'message' => 'Invalid token.']);
        }

        try {
            $this->queue->add($player->planets->current(), (int)$request->input('technologyId'), (int)Date::now()->timestamp);
        } catch (LifeformRefused $refus) {
            // Le script du jeu lit `errors[0].message` et reprend le jeton qu on lui rend.
            return response()->json(['success' => false, 'errors' => [['message' => __($refus->translationKey())]], 'newAjaxToken' => csrf_token()]);
        }

        return response()->json(['status' => 'success', 'message' => __('t_ingame.buildings.building_started')]);
    }

    /**
     * L annulation d un travail (`technologyId`, `listId`), remboursee s il courait.
     */
    public function cancelBuildRequest(Request $request, PlayerService $player): JsonResponse
    {
        try {
            $this->queue->cancel($player->planets->current(), (int)$request->input('listId'), (int)Date::now()->timestamp);
        } catch (LifeformRefused $refus) {
            return response()->json(['success' => false, 'message' => __($refus->translationKey())]);
        }

        return response()->json(['status' => 'success', 'message' => __('t_lifeforms_ui.buildings.canceled')]);
    }

    /**
     * Les dix-huit emplacements de recherche de la planete courante, en trois paliers.
     */
    public function research(PlayerService $player): View|RedirectResponse
    {
        $this->requireOpen();
        $this->setBodyId('lifeforms');
        $planet = $player->planets->current();
        $espece = $this->installation->speciesOf($player->getId());
        if ($espece === null) {
            return redirect()->route('lifeforms.index')->with('status', __('t_lifeforms_ui.buildings.choose_first'));
        }
        if (!$planet->isPlanet()) {
            return redirect()->route('overview.index')->with('status', __('t_lifeforms_ui.buildings.not_on_a_moon'));
        }
        $etat = $this->stateOf($planet);
        if ($etat === null) {
            return redirect()->route('lifeforms.index')->with('status', __('t_lifeforms_ui.buildings.choose_first'));
        }

        $maintenant = (int)Date::now()->timestamp;
        $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
        $niveauxTechnologies = $this->levels->technologyLevelsOf($planet->getPlanetId());
        $vitesses = $this->revisions->live();
        $profil = PlanetLifeformProfile::fromLevels($espece, $niveaux, $vitesses->demography());
        $reduction = $this->research->requirementReduction($espece, $niveaux);
        $emplacements = $this->research->slotsOf($planet->getPlanetId());
        $enFile = $this->queue->queued($planet->getPlanetId(), LifeformKind::Technology);
        $enCours = $enFile->firstWhere('status', 'running');
        $filePleine = $enFile->where('status', 'waiting')->count() >= LifeformQueueService::MAX_WAITING_PER_KIND;
        $centreOuvert = $this->queue->requirementsMet(LifeformCatalogue::technologiesOf($espece)[0], $niveaux);
        $vacances = $player->isInVacationMode();
        $autresEspeces = array_values(array_filter($this->research->discoveredSpeciesOf($player->getId()), fn (Species $s) => $s !== $espece));
        // La part d empire qui raccourcit les recherches de formes de vie (Intelligence en essaim efficace, §157).
        $essaim = $this->resolver->lifeformResearchTimeReductionOf($player->getId());

        $paliers = [];
        foreach ([1, 2, 3] as $palier) {
            $lignes = [];
            $objetsDuPalier = [];
            $dernierReset = 0;
            $precedents = false;
            foreach (LifeformSlotRules::slotsOfTier($palier) as $slot) {
                $ligne = $emplacements[$slot];
                $dernierReset = max($dernierReset, (int)($ligne->reset_at ?? 0));
                $precedents = $precedents || $ligne->previous_object_id !== null;
                $ouvert = $this->research->isUnlocked($slot, $etat, $profil, $reduction);
                $objet = $ligne->object_id === null ? null : LifeformCatalogue::byId((int)$ligne->object_id);
                if ($objet !== null) {
                    $objetsDuPalier[] = $objet->id;
                }
                $niveau = $objet === null ? 0 : ($niveauxTechnologies[$objet->id] ?? 0);
                $enCoursIci = $objet !== null && $enCours !== null && (int)$enCours->object_id === $objet->id;
                $peutRechercher = false;
                if ($objet !== null && $ouvert && $centreOuvert && !$filePleine && !$vacances && !$enCoursIci) {
                    $cible = $niveau + $enFile->where('object_id', $objet->id)->count() + 1;
                    $devis = LifeformQuote::for($objet, $cible, $niveaux, 0, 0, $vitesses, $essaim);
                    $peutRechercher = $planet->hasResources($devis->price);
                }
                $lignes[] = [
                    'slot' => $slot,
                    'position' => LifeformSlotRules::positionOf($slot),
                    'unlocked' => $ouvert,
                    'queue_full' => $filePleine,
                    'available' => $objet === null || LifeformAvailability::isAvailable($objet),
                    'required' => AppUtil::formatNumber((int)ceil(LifeformSlotRules::populationRequired($slot, $reduction))),
                    'object' => $objet,
                    'title' => $objet === null ? null : __('t_lifeforms.' . $objet->machineName . '.title'),
                    'level' => $niveau,
                    'building_now' => $enCoursIci,
                    'building_target' => $enCoursIci ? (int)$enCours->target_level : null,
                    'can_research' => $peutRechercher,
                    'centre_open' => $centreOuvert,
                ];
            }
            $rechercheDuPalier = $enFile->whereIn('object_id', $objetsDuPalier)->isNotEmpty();
            $paliers[$palier] = [
                'slots' => $lignes,
                'population' => AppUtil::formatNumber((int)floor($this->research->tierPopulationOf(LifeformSlotRules::slotOf($palier, 1), $etat, $profil))),
                // Le sous-titre est celui de l officiel (« Population : N ») ; l infobulle dit ce que N est — la population
                // de la planete au palier 1, les individus formes au palier 2 ou 3 et leur plafond (adaptation Azria).
                'population_tooltip' => match ($palier) {
                    1 => __('t_lifeforms_ui.research.population_tier1_tooltip'),
                    2 => __('t_lifeforms_ui.research.population_tier_tooltip', ['tier' => 2, 'capacity' => AppUtil::formatNumber((int)floor($profil->tier2Capacity))]),
                    default => __('t_lifeforms_ui.research.population_tier_tooltip', ['tier' => 3, 'capacity' => AppUtil::formatNumber((int)floor($profil->tier3Capacity))]),
                },
                'can_reset' => $objetsDuPalier !== [] && !$rechercheDuPalier && ($dernierReset === 0 || $maintenant - $dernierReset >= LifeformResearchService::RESET_COOLDOWN),
                'reset_available_at' => $dernierReset === 0 ? null : $dernierReset + LifeformResearchService::RESET_COOLDOWN,
                'can_restore' => $objetsDuPalier === [] && $precedents && $dernierReset > 0 && $maintenant - $dernierReset <= LifeformResearchService::RESTORE_WINDOW,
                'research_in_progress' => $rechercheDuPalier,
            ];
        }

        return view('ingame.lifeforms.research', [
            'held' => $this->banner->heldOn($planet),
            'species' => $espece,
            'species_name' => __('t_lifeforms.species.' . $espece->machineName()),
            'planet_name' => $planet->getPlanetName(),
            'header_filename' => $this->headerOf($planet),
            'tiers' => $paliers,
            'other_species' => $autresEspeces,
            'queue_active' => $enCours,
            'queue_waiting' => $enFile->where('status', 'waiting')->values(),
            'other_queue_active' => $this->queue->queued($planet->getPlanetId(), LifeformKind::Building)->firstWhere('status', 'running'),
            'other_queue_waiting' => $this->queue->queued($planet->getPlanetId(), LifeformKind::Building)->where('status', 'waiting')->values(),
            'is_in_vacation_mode' => $vacances,
            'lifeforms_error' => session('lifeforms_error'),
        ]);
    }

    /**
     * La fenetre superposee du choix d une technologie pour un emplacement ouvert et vide — la couche
     * `lfresearchlayer` du jeu officiel, que la feuille habille (fiches d espece, boutons verts). Le tile l ouvre
     * par `a.overlay` ; le panneau de detail de 300 px ne la contient plus (capture de Keven, journal §155.26).
     */
    public function slotOverlay(Request $request, PlayerService $player): View
    {
        $this->requireOpen();
        $planet = $player->planets->current();
        $espece = $this->installation->speciesOf($player->getId());
        $etat = $espece === null || !$planet->isPlanet() ? null : $this->stateOf($planet);
        if ($espece === null || $etat === null) {
            abort(404, __('t_lifeforms_ui.refused.no_species'));
        }
        $slot = (int)$request->input('slot');
        if ($slot < 1 || $slot > LifeformSlotRules::SLOTS) {
            abort(404, __('t_lifeforms_ui.refused.slot_locked'));
        }
        $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
        $vitesses = $this->revisions->live();
        $profil = PlanetLifeformProfile::fromLevels($espece, $niveaux, $vitesses->demography());
        $reduction = $this->research->requirementReduction($espece, $niveaux);
        $ligne = $this->research->slotsOf($planet->getPlanetId())[$slot];
        if ($ligne->object_id !== null || !$this->research->isUnlocked($slot, $etat, $profil, $reduction)) {
            abort(404, __('t_lifeforms_ui.refused.slot_locked'));
        }
        $palier = LifeformSlotRules::tierOf($slot);
        $position = LifeformSlotRules::positionOf($slot);
        $compte = $this->installation->accountOf($player->getId());
        $autres = [];
        foreach ($this->research->discoveredSpeciesOf($player->getId()) as $decouverte) {
            if ($decouverte === $espece) {
                continue;
            }
            $technologie = LifeformResearchService::technologyAt($decouverte, $palier, $position);
            $autres[] = [
                'species' => $decouverte,
                'species_name' => __('t_lifeforms.species.' . $decouverte->machineName()),
                'object' => $technologie,
                'title' => __('t_lifeforms.' . $technologie->machineName . '.title'),
                'description' => __('t_lifeforms.' . $technologie->machineName . '.description'),
                'taken' => $this->research->slotHolding($planet->getPlanetId(), $technologie->id) !== null,
                'available' => LifeformAvailability::isAvailable($technologie),
            ];
        }
        $locale = LifeformResearchService::technologyAt($espece, $palier, $position);

        return view('ingame.lifeforms.overlay.slot', [
            'slot' => $slot,
            'tier' => $palier,
            'position' => $position,
            'local' => $locale,
            'local_title' => __('t_lifeforms.' . $locale->machineName . '.title'),
            'local_description' => __('t_lifeforms.' . $locale->machineName . '.description'),
            'local_taken' => $this->research->slotHolding($planet->getPlanetId(), $locale->id) !== null,
            'local_available' => LifeformAvailability::isAvailable($locale),
            'others' => $autres,
            // Le tirage ne se propose que s il peut tirer quelque chose : le service ne tire pas une technologie indisponible.
            'random_available' => array_filter($autres, fn (array $autre): bool => $autre['available']) !== [],
            'artifact_cost' => LifeformSlotRules::ARTIFACT_COST[$palier],
            'artifacts' => $compte === null ? 0 : (int)$compte->artifacts,
        ]);
    }

    /**
     * Le detail d une technologie d un emplacement.
     */
    public function researchAjax(Request $request, PlayerService $player): JsonResponse
    {
        $this->requireOpen();
        $planet = $player->planets->current();
        $espece = $this->installation->speciesOf($player->getId());
        $id = (int)$request->input('technology');
        $etat = $espece === null || !$planet->isPlanet() ? null : $this->stateOf($planet);
        if ($espece === null || $etat === null) {
            return response()->json(['success' => false, 'message' => __('t_lifeforms_ui.refused.no_species')], 404);
        }
        $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
        $vitesses = $this->revisions->live();
        $profil = PlanetLifeformProfile::fromLevels($espece, $niveaux, $vitesses->demography());
        $reduction = $this->research->requirementReduction($espece, $niveaux);

        if (!LifeformCatalogue::has($id)) {
            return response()->json(['success' => false, 'message' => __('t_lifeforms_ui.refused.unknown_object')], 404);
        }
        $objet = LifeformCatalogue::byId($id);
        $emplacement = $this->research->slotHolding($planet->getPlanetId(), $objet->id);
        if ($objet->kind !== LifeformKind::Technology || $emplacement === null) {
            return response()->json(['success' => false, 'message' => __('t_lifeforms_ui.refused.wrong_slot')], 404);
        }
        $niveauxTechnologies = $this->levels->technologyLevelsOf($planet->getPlanetId());
        $niveau = $niveauxTechnologies[$objet->id] ?? 0;
        $enFile = $this->queue->queued($planet->getPlanetId(), LifeformKind::Technology);
        $enCours = $enFile->firstWhere('status', 'running');
        $cible = $niveau + $enFile->where('object_id', $objet->id)->count() + 1;
        $devis = LifeformQuote::for($objet, $cible, $niveaux, 0, 0, $vitesses, $this->resolver->lifeformResearchTimeReductionOf($player->getId()));
        $multiplicateur = $this->research->technologyBonusMultiplier($player->getId(), $objet->species, $niveaux);
        $ouvert = $this->research->isUnlocked((int)$emplacement->slot, $etat, $profil, $reduction);

        $effets = [];
        foreach ($objet->bonuses as $bonus) {
            if ($bonus->isUnassigned()) {
                continue;
            }
            $effets[] = [
                'code' => $bonus->code,
                'label' => LifeformEffectPresenter::labelOf($bonus),
                'now' => self::pourcent(LifeformFormulas::technologyBonusPercent($bonus, $niveau, $multiplicateur - 1)),
                'next' => self::pourcent(LifeformFormulas::technologyBonusPercent($bonus, $cible, $multiplicateur - 1)),
            ];
        }

        // Un objet indisponible n a ni bouton ni raison : le panneau le dit en clair a la place des effets (§155.26).
        $raison = null;
        if ($player->isInVacationMode()) {
            $raison = __('t_ingame.ajax_object.vacation_mode');
        } elseif (!$this->queue->requirementsMet($objet, $niveaux)) {
            $raison = __('t_lifeforms_ui.research.centre_needed');
        } elseif (!$ouvert) {
            $raison = __('t_lifeforms_ui.refused.slot_locked');
        } elseif ($enFile->where('status', 'waiting')->count() >= LifeformQueueService::MAX_WAITING_PER_KIND) {
            $raison = __('t_ingame.buildings.queue_full');
        } elseif (!$planet->hasResources($devis->price)) {
            $raison = __('t_ingame.buildings.not_enough_resources');
        }

        $html = view('ingame.lifeforms.ajax.technology', [
            'object' => $objet,
            'title' => __('t_lifeforms.' . $objet->machineName . '.title'),
            'description' => __('t_lifeforms.' . $objet->machineName . '.description'),
            'species_name' => __('t_lifeforms.species.' . $objet->species->machineName()),
            'slot' => (int)$emplacement->slot,
            'tier' => LifeformSlotRules::tierOf((int)$emplacement->slot),
            'current_level' => $niveau,
            'next_level' => $cible,
            'price' => $devis->price,
            'production_time' => AppUtil::formatTimeDuration($devis->duration),
            'population_required' => AppUtil::formatNumber((int)ceil(LifeformSlotRules::populationRequired((int)$emplacement->slot, $reduction))),
            'effects' => $effets,
            'planet' => $planet,
            'can_build' => $raison === null,
            'reason' => $raison,
            'available' => LifeformAvailability::isAvailable($objet),
            'active_item' => $enCours !== null && (int)$enCours->object_id === $objet->id ? $enCours : null,
            // Un travail court deja sur la planete : le bouton dit « Dans la file », comme la fiche classique.
            'queue_busy' => $enCours !== null,
        ])->render();

        return response()->json(['target' => 'technologydetails', 'content' => ['technologydetails' => $html], 'files' => ['js' => [], 'css' => []], 'newAjaxToken' => csrf_token()]);
    }

    /**
     * Le choix de la technologie d un emplacement : locale, tiree au sort, ou par artefacts.
     */
    public function chooseSlot(Request $request, PlayerService $player): RedirectResponse
    {
        $this->requireOpen();
        $valide = $request->validate(['slot' => ['required', 'integer', 'min:1', 'max:18'], 'choice' => ['required', 'string', 'regex:/^(local|random|[0-9]{5})$/']]);
        try {
            $this->research->choose($player->planets->current()->getPlanetId(), $player->getId(), (int)$valide['slot'], (string)$valide['choice'], (int)Date::now()->timestamp);
        } catch (LifeformRefused $refus) {
            return redirect()->route('lifeforms.research')->with('lifeforms_error', __($refus->translationKey()));
        }

        return redirect()->route('lifeforms.research')->with('status', __('t_lifeforms_ui.research.chosen_done', ['slot' => (int)$valide['slot']]));
    }

    public function resetTier(Request $request, PlayerService $player): RedirectResponse
    {
        $this->requireOpen();
        $valide = $request->validate(['tier' => ['required', 'integer', 'min:1', 'max:3']]);
        try {
            $this->research->resetTier($player->planets->current()->getPlanetId(), (int)$valide['tier'], (int)Date::now()->timestamp);
        } catch (LifeformRefused $refus) {
            return redirect()->route('lifeforms.research')->with('lifeforms_error', __($refus->translationKey()));
        }

        return redirect()->route('lifeforms.research')->with('status', __('t_lifeforms_ui.research.reset_done', ['tier' => (int)$valide['tier']]));
    }

    public function restoreTier(Request $request, PlayerService $player): RedirectResponse
    {
        $this->requireOpen();
        $valide = $request->validate(['tier' => ['required', 'integer', 'min:1', 'max:3']]);
        try {
            $this->research->restoreTier($player->planets->current()->getPlanetId(), (int)$valide['tier'], (int)Date::now()->timestamp);
        } catch (LifeformRefused $refus) {
            return redirect()->route('lifeforms.research')->with('lifeforms_error', __($refus->translationKey()));
        }

        return redirect()->route('lifeforms.research')->with('status', __('t_lifeforms_ui.research.restore_done', ['tier' => (int)$valide['tier']]));
    }

    private static function pourcent(float $valeur): string
    {
        $arrondi = round($valeur, 2);
        $texte = rtrim(rtrim(number_format($arrondi, 2, '.', ''), '0'), '.');

        return ($arrondi > 0 ? '+' : '') . $texte . ' %';
    }

    private function requireOpen(): void
    {
        if (!$this->settings->lifeformsEnabled()) {
            abort(404);
        }
    }

    private function stateOf(PlanetService $planet): LifeformPlanet|null
    {
        $etat = LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->first();
        if ($etat === null && $planet->isPlanet()) {
            // Une planete jamais peuplee (creee sous un ancien code, avant le raccordement) : rattrapee ici.
            $this->installation->installOnExistingPlanet($planet->getPlanetId(), (int)Date::now()->timestamp);
            $etat = LifeformPlanet::query()->where('planet_id', $planet->getPlanetId())->first();
        }

        return $etat;
    }

    private function headerOf(PlanetService $planet): string
    {
        // Le bandeau d image des pages de ressources (biome de la planete), faute d illustration propre.
        return $planet->getPlanetBiomeType();
    }

    /**
     * La page des bonus : l experience de chaque espece, puis chaque effet avec le detail qui le compose.
     */
    public function bonuses(PlayerService $player): View|RedirectResponse
    {
        $this->requireOpen();
        $this->setBodyId('lifeforms');
        if ($this->installation->speciesOf($player->getId()) === null) {
            return redirect()->route('lifeforms.index')->with('status', __('t_lifeforms_ui.buildings.choose_first'));
        }

        return view('ingame.lifeforms.bonuses', [
            'experience' => $this->bonusPage->experienceOf($player),
            'effets' => $this->bonusPage->effectsOf($player),
        ]);
    }

    /**
     * La page des decouvertes : quota, artefacts, lancement d un vol, vols en cours et derniers vols.
     */
    public function discoveries(PlayerService $player): View|RedirectResponse
    {
        $this->requireOpen();
        $this->setBodyId('lifeforms');
        $planet = $player->planets->current();
        $espece = $this->installation->speciesOf($player->getId());
        if ($espece === null) {
            return redirect()->route('lifeforms.index')->with('status', __('t_lifeforms_ui.buildings.choose_first'));
        }
        if (!$planet->isPlanet()) {
            return redirect()->route('overview.index')->with('status', __('t_lifeforms_ui.buildings.not_on_a_moon'));
        }
        if ($this->stateOf($planet) === null) {
            return redirect()->route('lifeforms.index')->with('status', __('t_lifeforms_ui.buildings.choose_first'));
        }

        $maintenant = (int)Date::now()->timestamp;
        $compte = $this->discoveries->accrueQuota($player->getId(), $maintenant);
        $niveaux = $this->levels->buildingLevelsOf($planet->getPlanetId());
        $centre = LifeformCatalogue::buildingWithEffect($espece, LifeformEffect::LF_RESEARCH_TIME_REDUCTION);
        $centreOuvert = $centre !== null && ($niveaux[$centre->id] ?? 0) >= 1;
        $reduction = $this->discoveries->envoysReduction($player->getId(), $planet->getPlanetId());
        $coefficient = $this->revisions->live()->discovery();
        $cout = LifeformDiscoveryRules::cost();
        $prochaineRecharge = $compte === null ? 0 : max(0, (int)($compte->discoveries_credited_until ?? $maintenant) + 86400 - $maintenant);
        $vitesse = fn (int $distance): string => AppUtil::formatTimeDuration(LifeformDiscoveryRules::duration($distance, $reduction, $coefficient));

        $enCours = [];
        foreach ($this->discoveries->runningOf($player->getId()) as $vol) {
            $enCours[] = [
                'coordinates' => (new Coordinate((int)$vol->galaxy, (int)$vol->system, (int)$vol->position))->asString(),
                'remaining' => max(0, (int)$vol->ends_at - $maintenant),
            ];
        }
        $termines = [];
        foreach ($this->discoveries->historyOf($player->getId()) as $vol) {
            $termines[] = [
                'coordinates' => (new Coordinate((int)$vol->galaxy, (int)$vol->system, (int)$vol->position))->asString(),
                'settled_at' => (int)($vol->settled_at ?? 0),
                'outcome' => $this->outcomeLabel(LifeformDiscoveryOutcome::fromStorage($vol->outcome)),
            ];
        }

        return view('ingame.lifeforms.discoveries', [
            'held' => $this->banner->heldOn($planet),
            'planet_name' => $planet->getPlanetName(),
            'header_filename' => $this->headerOf($planet),
            'species_name' => __('t_lifeforms.species.' . $espece->machineName()),
            'lifeforms_error' => session('lifeforms_error'),
            'centre_open' => $centreOuvert,
            'available' => $compte === null ? 0 : (int)$compte->discoveries_available,
            'per_day' => LifeformDiscoveryRules::QUOTA_PER_DAY,
            'next_refill' => AppUtil::formatTimeDuration($prochaineRecharge),
            'artifacts' => $compte === null ? 0 : (int)$compte->artifacts,
            'artifact_cap' => LifeformDiscoveryRules::ARTIFACT_CAP,
            'cost' => $cout,
            'durations' => ['same_system' => $vitesse(1000), 'same_galaxy' => $vitesse(2795), 'other_galaxy' => $vitesse(20000)],
            'reduction_percent' => rtrim(rtrim(number_format($reduction * 100, 2, '.', ''), '0'), '.'),
            'current' => $planet->getPlanetCoordinates(),
            'galaxies' => $this->settings->numberOfGalaxies(),
            'running' => $enCours,
            'history' => $termines,
            'vacation' => $player->isInVacationMode(),
        ]);
    }

    public function launchDiscovery(Request $request, PlayerService $player): RedirectResponse
    {
        $this->requireOpen();
        $valide = $request->validate([
            'galaxy' => ['required', 'integer', 'min:1', 'max:' . $this->settings->numberOfGalaxies()],
            'system' => ['required', 'integer', 'min:1', 'max:499'],
            'position' => ['required', 'integer', 'min:1', 'max:15'],
        ]);
        $cible = new Coordinate((int)$valide['galaxy'], (int)$valide['system'], (int)$valide['position']);
        $maintenant = (int)Date::now()->timestamp;
        try {
            $vol = $this->discoveries->launch($player->planets->current(), $cible, $maintenant);
        } catch (LifeformRefused $refus) {
            return redirect()->route('lifeforms.discoveries')->withInput()->with('lifeforms_error', __($refus->translationKey()));
        }

        return redirect()->route('lifeforms.discoveries')->with('status', __('t_lifeforms_ui.discoveries.launched', [
            'coordinates' => $cible->asString(),
            'duration' => AppUtil::formatTimeDuration((int)$vol->ends_at - $maintenant),
        ]));
    }

    /**
     * Un vol lance depuis l icone ADN de la Galaxie (journal §155.24).
     *
     * La reponse a la forme que `discoverPlanet()` du bundle lit : `newAjaxToken`, puis `response.success`,
     * `response.message` (affiche avec les coordonnees), `response.coordinates`, et `response.discovery` — la
     * possibilite d un nouveau vol vers les autres positions, le nombre de vols restants et le compteur de
     * l en-tete. Le bundle redemande ensuite le bandeau des ressources (le cout a ete debite) et la boite des
     * evenements. Le juge est le meme que celui du formulaire : `LifeformDiscoveryService::launch()`.
     */
    public function discoverFromGalaxy(Request $request, PlayerService $player, GalaxyDiscoveries $galaxie): JsonResponse
    {
        $valide = $request->validate([
            'galaxy' => ['required', 'integer', 'min:1', 'max:' . $this->settings->numberOfGalaxies()],
            'system' => ['required', 'integer', 'min:1', 'max:499'],
            'position' => ['required', 'integer', 'min:1', 'max:15'],
        ]);
        $cible = new Coordinate((int)$valide['galaxy'], (int)$valide['system'], (int)$valide['position']);
        $maintenant = (int)Date::now()->timestamp;
        $compteurs = GalaxyHeaderCounters::of($player);

        $reponse = [
            'coordinates' => ['galaxy' => $cible->galaxy, 'system' => $cible->system, 'position' => $cible->position],
            'planetType' => 1,
            'type' => 0,
            'shipsSent' => 1,
            'slots' => $compteurs['slotsUsed'],
            'probes' => $compteurs['probes'],
            'recyclers' => $compteurs['recyclers'],
            'missiles' => $compteurs['missiles'],
        ];

        try {
            if (!$this->settings->lifeformsEnabled()) {
                throw new LifeformRefused(LifeformRefused::CLOSED);
            }
            $vol = $this->discoveries->launch($player->planets->current(), $cible, $maintenant);
            $reponse['success'] = true;
            $reponse['message'] = __('t_lifeforms_ui.discoveries.launched', [
                'coordinates' => $cible->asString(),
                'duration' => AppUtil::formatTimeDuration((int)$vol->ends_at - $maintenant),
            ]);
        } catch (LifeformRefused $refus) {
            $reponse['success'] = false;
            $reponse['message'] = __($refus->translationKey());
        }

        // L etat des autres positions du systeme, APRES le vol : un quota epuise grise toutes les icones. Le bundle
        // grise TOUTES les autres icones sur une raison : c est donc l etat general (quota, centre, planete) qui est rendu,
        // jamais celui d une seule position — la position 1 prise grisait tout le systeme (journal §160).
        $etat = $galaxie->forSystem($player, $cible->galaxy, $cible->system, $maintenant);
        $reponse['discovery'] = [
            'canSendDiscovery' => $etat['general'],
            'discoveryCount' => $etat['count'],
            'galaxyHeader' => ['LOCA_GALAXY_LIFEFORM_DISCOVERY_COUNT' => $etat['header']],
        ];

        return response()->json(['newAjaxToken' => csrf_token(), 'response' => $reponse]);
    }

    /**
     * Le bouton « Decouvertes » de la barre de la Galaxie (`sendSystemDiscoveryMission()` du bundle officiel) : un vol
     * vers chaque position du systeme affiche que la Galaxie offre — ni chez soi, ni en approche, ni exploree depuis
     * moins de sept jours —, dans l ordre des positions, jusqu au quota ou aux ressources (journal §163). Chaque vol passe
     * par `LifeformDiscoveryService::launch()`, qui reste le seul juge ; une raison propre a une position saute la
     * position, une raison generale (quota, ressources, centre) arrete la salve.
     */
    public function discoverSystemFromGalaxy(Request $request, PlayerService $player, GalaxyDiscoveries $galaxie): JsonResponse
    {
        $valide = $request->validate([
            'galaxy' => ['required', 'integer', 'min:1', 'max:' . $this->settings->numberOfGalaxies()],
            'system' => ['required', 'integer', 'min:1', 'max:499'],
        ]);
        $galaxy = (int)$valide['galaxy'];
        $system = (int)$valide['system'];
        $maintenant = (int)Date::now()->timestamp;
        $compteurs = GalaxyHeaderCounters::of($player);

        $envoyees = [];
        $arret = null;
        // Une raison generale (centre, quota, module ferme) est deja portee par chaque position : rien ne part.
        $etat = $galaxie->forSystem($player, $galaxy, $system, $maintenant);
        foreach ($etat['missions'] as $position => $mission) {
            if ($mission['canSend'] !== true) {
                continue;
            }
            try {
                $this->discoveries->launch($player->planets->current(), new Coordinate($galaxy, $system, (int)$position), $maintenant);
                $envoyees[] = ['galaxy' => $galaxy, 'system' => $system, 'position' => (int)$position];
            } catch (LifeformRefused $refus) {
                // Une raison propre a la position (une course avec un autre onglet) saute la position ; une raison generale
                // (quota, ressources) arrete la salve — les positions suivantes seraient refusees de la meme facon.
                if (in_array($refus->reason, [LifeformRefused::OWN_PLANET, LifeformRefused::RECENTLY_EXPLORED, LifeformRefused::BAD_COORDINATES], true)) {
                    continue;
                }
                $arret = $refus;
                break;
            }
        }

        $reponse = [
            'coordinates' => ['galaxy' => $galaxy, 'system' => $system, 'position' => $envoyees[0]['position'] ?? 0],
            'sentToCoordinates' => $envoyees,
            'planetType' => 1,
            'type' => 0,
            'shipsSent' => count($envoyees),
            'slots' => $compteurs['slotsUsed'],
            'probes' => $compteurs['probes'],
            'recyclers' => $compteurs['recyclers'],
            'missiles' => $compteurs['missiles'],
            'success' => $envoyees !== [],
        ];
        if ($envoyees !== []) {
            $reponse['message'] = trans_choice('t_lifeforms_ui.discoveries.launched_many', count($envoyees), ['count' => count($envoyees), 'system' => $galaxy . ':' . $system]);
        } elseif ($arret !== null) {
            $reponse['message'] = __($arret->translationKey());
        } elseif ($etat['general'] !== true) {
            $reponse['message'] = $etat['general'];
        } else {
            $reponse['message'] = __('t_lifeforms_ui.discoveries.nothing_to_discover');
        }

        // L etat des positions APRES la salve, comme apres un vol seul : compteur de l en-tete, et raison generale s il y en a une.
        $apres = $galaxie->forSystem($player, $galaxy, $system, $maintenant);
        $reponse['discovery'] = [
            'canSendDiscovery' => $apres['general'],
            'discoveryCount' => $apres['count'],
            'galaxyHeader' => ['LOCA_GALAXY_LIFEFORM_DISCOVERY_COUNT' => $apres['header']],
        ];

        return response()->json(['newAjaxToken' => csrf_token(), 'response' => $reponse]);
    }

    private function outcomeLabel(LifeformDiscoveryOutcome $issue): string
    {
        $espece = $issue->species === null ? '' : __('t_lifeforms.species.' . $issue->species->machineName());
        $espece = is_string($espece) ? $espece : '';
        $clef = match (true) {
            $issue->kind === LifeformDiscoveryOutcome::ARTIFACTS && $issue->artifacts === 0 => 'outcome_artifacts_full',
            default => 'outcome_' . $issue->kind,
        };
        $texte = __('t_lifeforms_ui.discoveries.' . $clef, ['artifacts' => $issue->artifacts, 'experience' => $issue->experience, 'species' => $espece]);

        return is_string($texte) ? $texte : '';
    }

    /**
     * Les travaux de formes de vie de la planete courante, pour la vue generale.
     *
     * @return array{buildings_active: LifeformQueue|null, buildings_queue: array<int, LifeformQueue>, research_active: LifeformQueue|null}
     */
    public function overviewQueues(PlanetService $planet): array
    {
        $batiments = $this->queue->queued($planet->getPlanetId(), LifeformKind::Building);
        $recherches = $this->queue->queued($planet->getPlanetId(), LifeformKind::Technology);

        return [
            'buildings_active' => $batiments->firstWhere('status', 'running'),
            'buildings_queue' => $batiments->where('status', 'waiting')->values()->all(),
            'research_active' => $recherches->firstWhere('status', 'running'),
        ];
    }
}
