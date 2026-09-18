@extends('ingame.layouts.main')

@section('content')

    @include('ingame.lifeforms.partials.flash')

    <div id="lfbuildingscomponent" class="maincontent">
        {{-- `#lfbuildings` est le conteneur que la feuille du jeu taille pour CETTE page : meme cadre que `#supplies`,
             mais la grille y est en `space-between` avec son remplisseur de 422 px et 7 px de respiration en bas.
             La page empruntait `#supplies`, d ou des vignettes rangees de travers (journal §155.23). --}}
        <div id="lfbuildings" class="lifeform-buildings">
            <header data-anchor="technologyDetails" data-technologydetails-size="large"
                    style="background-image:url({{ asset('img/headers/resources/' . $header_filename) }}.jpg);">
                {{-- Un seul segment apres le titre : `#lfbuildings header h2` n a ni largeur ni `nowrap`, un titre
                     en trois parties passait a la ligne et debordait du bandeau. Le nom de la planete est deja
                     dans la liste de droite. --}}
                <h2>{{ __('t_lifeforms_ui.buildings.title') }} - {{ $species_name }}</h2>
            </header>
            <div id="technologydetails_wrapper">
                <div id="technologydetails_content"></div>
            </div>
            {{-- L avis de suspension se pose AVANT le cadre, comme sur la page des recherches : dedans, il repoussait la
                 barre de titre de la grille loin de l embout haut du cadre (`#technologies:before`). --}}
            @include('ingame.lifeforms.partials.held', ['held' => $held ?? null])
            <div id="technologies">
                <h3>{{ __('t_lifeforms_ui.buildings.section') }}</h3>
                {{-- Aucun paragraphe de chiffres ici : la population et la nourriture vivent dans la barre du haut,
                     avec leur infobulle complete. Les repeter en 9 px sans cadre coupait le bandeau de titre. --}}
                <ul id="producers" class="icons">
                    @foreach ($tiles as $tile)
                        @php $objet = $tile['object']; @endphp
                        <li class="technology lifeformTech{{ $objet->id }} hasDetails tooltip hideTooltipOnMouseenter js_hideTipOnMobile tpd-hideOnClickOutside"
                            data-technology="{{ $objet->id }}"
                            data-is-spaceprovider=""
                            aria-label="{{ $tile['title'] }}"
                            @if (!$tile['available'])
                                {{-- L effet n est pas applique : on ne vend pas un bonus qu on ne rend pas (journal §155.26). --}}
                                data-status="off" data-unavailable="1"
                                title="{{ $tile['title'] }}<br/>{{ __('t_lifeforms_ui.refused.not_available') }}"
                            @elseif ($tile['building_now'])
                                data-status="active"
                                title="{{ $tile['title'] }}<br/>{{ __('t_ingame.buildings.under_construction') }}"
                            @elseif ($tile['vacation'])
                                data-status="disabled"
                                title="{{ $tile['title'] }}<br/>{{ __('t_ingame.buildings.vacation_mode_error') }}"
                            @elseif (!$tile['requirements_met'])
                                data-status="off"
                                title="{{ $tile['title'] }}<br/>{{ __('t_ingame.buildings.requirements_not_met') }}"
                            @elseif (!$tile['population_met'])
                                data-status="disabled"
                                title="{{ $tile['title'] }}<br/>{{ __('t_lifeforms_ui.buildings.population_short') }}"
                            @elseif ($tile['queue_full'])
                                data-status="disabled"
                                title="{{ $tile['title'] }}<br/>{{ __('t_ingame.buildings.queue_full') }}"
                            @elseif (!$tile['enough_resources'])
                                data-status="disabled"
                                title="{{ $tile['title'] }}<br/>{{ __('t_ingame.buildings.not_enough_resources') }}"
                            @else
                                data-status="on"
                                title="{{ $tile['title'] }}"
                            @endif
                        ><span class="icon lifeformsprite sprite_medium medium lifeformTech{{ $objet->id }}">
                            @if ($tile['available'] && !$tile['building_now'] && !$tile['vacation'] && $tile['requirements_met'] && $tile['population_met'] && $tile['enough_resources'] && !$tile['queue_full'])
                                <button class="upgrade tooltip hideOthers js_hideTipOnMobile"
                                        aria-label="{{ __('t_ingame.buildings.expand_button', ['title' => $tile['title'], 'level' => $tile['target_level']]) }}"
                                        title="{{ __('t_ingame.buildings.expand_button', ['title' => $tile['title'], 'level' => $tile['target_level']]) }}"
                                        data-technology="{{ $objet->id }}" data-is-spaceprovider="1">
                                </button>
                            @endif
                            @if ($tile['building_now'])
                                <span class="targetlevel" data-value="{{ $tile['building_target'] }}" data-bonus="0">{{ $tile['building_target'] }}</span>
                                <div class="cooldownBackground"></div>
                                <time-counter><time class="countdown lfBuildingCountdown" data-segments="2">...</time></time-counter>
                            @endif
                            <span class="level" data-value="{{ $tile['level'] }}" data-bonus="0">
                                <span class="stockAmount">{{ $tile['level'] }}</span>
                                <span class="bonus"></span>
                            </span>
                        </span></li>
                    @endforeach
                </ul>
            </div>

            {{-- Les deux boites de file cote a cote, batiments puis recherches : `#productionboxBottom` (670 px, flex)
                 est ce que la page officielle des batiments de formes de vie montre sous sa grille. --}}
            <div id="productionboxBottom">
                <div class="productionBoxBuildings boxColumn building">
                    <div id="productionboxlfbuildingcomponent" class="productionboxlfbuilding injectedComponent parent supplies">
                        @include('ingame.lifeforms.partials.queue', ['queue_active' => $queue_active, 'queue_waiting' => $queue_waiting, 'kind' => 'building'])
                    </div>
                </div>
                <div class="productionBoxResearch boxColumn research">
                    <div id="productionboxlfresearchcomponent" class="productionboxlfresearch injectedComponent parent supplies">
                        @include('ingame.lifeforms.partials.queue', ['queue_active' => $other_queue_active, 'queue_waiting' => $other_queue_waiting, 'kind' => 'technology'])
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="technologydetailscomponent" class="technologydetails injectedComponent parent supplies">
        <script type="text/javascript">
            var loca = {!! json_encode([
                'LOCA_ALL_NOTICE' => __('t_ingame.buildings.loca_notice'),
                'LOCA_ALL_NETWORK_ATTENTION' => __('t_ingame.shared.caution'),
                'locaDemolishStructureQuestion' => __('t_ingame.buildings.loca_demolish'),
                'LOCA_ALL_YES' => __('t_ingame.shared.yes'),
                'LOCA_ALL_NO' => __('t_ingame.shared.no'),
                'LOCA_LIFEFORM_BONUS_CAP_REACHED_WARNING' => __('t_ingame.buildings.loca_lifeform_cap'),
            ]) !!};

            var scheduleBuildListEntryUrl = '{{ route('lifeforms.buildings.addbuildrequest.post') }}';
            var technologyDetailsEndpoint = "{{ route('lifeforms.buildings.ajax') }}";
            var selectCharacterClassEndpoint = "#";
            var deselectCharacterClassEndpoint = "#";

            {{-- **Les deux variables que le bundle lit sans garde** au clic de la fleche verte d une vignette
                 (`if (planetMoveInProgress)` puis `lastBuildingSlot.shouldWarnForTechnologyId(...)`, assets/ingame-*.js).
                 Absentes, le clic levait une ReferenceError et rien ne partait en construction (journal §155.23).
                 Les batiments et technologies de formes de vie ne consomment aucun emplacement de planete : aucun
                 avertissement de dernier emplacement ne s applique, d ou `showWarning` a faux. --}}
            var LOCA_PLANETMOVE_BREAKUP_WARNING = @json(__('t_ingame.buildings.planet_move_warning'));
            var LOCA_ALL_NETWORK_ATTENTION = @json(__('t_ingame.shared.caution'));
            var LOCA_ALL_YES = @json(__('t_ingame.shared.yes'));
            var LOCA_ALL_NO = @json(__('t_ingame.shared.no'));
            var planetMoveInProgress = {{ $planet_move_in_progress ? 'true' : 'false' }};
            var lastBuildingSlot = {
                "showWarning": false,
                "exemptTechnologyIds": [],
                "shouldWarnForTechnologyId": function (technologyId) { return false; }
            };

            var technologyDetails = new TechnologyDetails({
                technologyDetailsEndpoint: technologyDetailsEndpoint,
                selectCharacterClassEndpoint: selectCharacterClassEndpoint,
                deselectCharacterClassEndpoint: deselectCharacterClassEndpoint,
                loca: loca
            })
            technologyDetails.init()
        </script>
    </div>
@endsection
