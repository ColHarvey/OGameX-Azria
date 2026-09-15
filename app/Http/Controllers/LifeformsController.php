<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use OGame\Facades\AppUtil;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Presentation\LifeformBanner;
use OGame\Lifeforms\Presentation\LifeformEffectPresenter;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Services\LifeformQuote;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformWelcome;
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
            $especes[] = [
                'species' => $espece,
                'name' => __('t_lifeforms.species.' . $espece->machineName()),
                'lore' => __('t_lifeforms_ui.lore.' . $espece->machineName()),
                'usage' => __('t_lifeforms_ui.usage.' . $espece->machineName()),
                'chosen' => $choisie === $espece,
                'experience' => $experience === null ? 0 : (int)$experience->experience,
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
                'requirements_met' => $this->queue->requirementsMet($batiment, $niveaux),
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
            'figures' => $this->banner->planetFigures($planet, $espece),
            'is_in_vacation_mode' => $vacances,
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

        $prerequis = [];
        foreach ($objet->requirements as $exige => $niveauExige) {
            $prerequis[] = [
                'title' => __('t_lifeforms.' . LifeformCatalogue::byId($exige)->machineName . '.title'),
                'level' => $niveauExige,
                'met' => ($niveaux[$exige] ?? 0) >= $niveauExige,
            ];
        }

        $raison = null;
        if ($player->isInVacationMode()) {
            $raison = __('t_ingame.ajax_object.vacation_mode');
        } elseif (!$this->queue->requirementsMet($objet, $niveaux)) {
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
            'active_item' => $enCours !== null && (int)$enCours->object_id === $objet->id ? $enCours : null,
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
