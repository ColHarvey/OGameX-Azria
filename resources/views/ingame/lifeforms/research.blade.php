@extends('ingame.layouts.main')

@section('content')

    @include('ingame.lifeforms.partials.flash')

    <div id="lfresearchcomponent" class="maincontent">
        <div id="lfresearch">
            <header data-anchor="technologyDetails" data-technologydetails-size="large"
                    style="background-image:url({{ asset('img/headers/resources/' . $header_filename) }}.jpg);">
                <h2>{{ __('t_lifeforms_ui.research.title') }} - {{ $species_name }}</h2>
            </header>
            @include('ingame.lifeforms.partials.held', ['held' => $held ?? null])
            @if (!empty($lifeforms_error))
                <div id="additionalinformation"><p class="overmark" role="alert">{{ $lifeforms_error }}</p></div>
            @endif
            <div id="technologydetails_wrapper">
                <div id="technologydetails_content"></div>
            </div>
            <div id="technologies">
                @foreach ($tiers as $tier => $palier)
                    <div class="tier{{ $tier }}Container">
                        <h3>{{ __('t_lifeforms_ui.research.tier_title', ['tier' => $tier]) }}</h3>
                        <div class="populationSubHeading"><span class="tooltip" title="{{ $palier['population_tooltip'] }}">{{ __('t_lifeforms_ui.research.population', ['population' => $palier['population']]) }}</span></div>
                        <ul class="icons">
                            @foreach ($palier['slots'] as $s)
                                @if (!$s['unlocked'] && $s['object'] === null)
                                    <li class="technology tooltip hideTooltipOnMouseenter js_hideTipOnMobile" data-slot="{{ $s['slot'] }}"
                                        title="{{ __('t_lifeforms_ui.research.requires', ['population' => $s['required'], 'tier' => $tier]) }}"><span class="icon medium research-locked"></span></li>
                                @elseif ($s['object'] === null)
                                    {{-- Un emplacement libre ouvre la FENETRE du choix (`a.overlay`, comme l attaque de missiles), la
                                         couche officielle `lfresearchlayer` ; il n a pas de panneau de detail (journal §155.26). --}}
                                    {{-- Le choix reste permis sans centre et en vacances (seule la recherche les exige) : l icone
                                         hachuree (research-disallowed, feuille officielle) le dit, et l infobulle dit POURQUOI (audit,
                                         §155.27). Le cadenas noir de l emplacement libre (research-allowed, image officielle) etait
                                         invisible sur le fond #0d1014 (contraste 1,1:1) : un fond de vignette, le ton de survol de la
                                         feuille (#29313d), le rend lisible — adaptation Azria, l officiel n en montre aucune capture. --}}
                                    @php $refusLibre = $is_in_vacation_mode ? __('t_ingame.buildings.vacation_mode_error') : (!$s['centre_open'] ? __('t_lifeforms_ui.research.centre_needed') : null); @endphp
                                    <li class="technology tooltip hideTooltipOnMouseenter js_hideTipOnMobile tpd-hideOnClickOutside lifeform-slot-empty" data-slot="{{ $s['slot'] }}"
                                        data-technology="{{ 9000 + $s['slot'] }}" data-status="on" data-is-spaceprovider=""
                                        aria-label="{{ __('t_lifeforms_ui.research.slot_empty') }}" title="{{ __('t_lifeforms_ui.research.slot_empty') }}@if ($refusLibre !== null)<br/>{{ $refusLibre }}@endif"><a class="overlay" href="{{ route('lifeforms.research.slot.overlay', ['slot' => $s['slot']]) }}" data-overlay-modal="true" data-overlay-class="lfresearchlayer" data-overlay-width="702" data-overlay-title="{{ __('t_lifeforms_ui.research.choose_title', ['slot' => $s['slot'], 'tier' => $tier, 'position' => $s['position']]) }}" style="display: block; width: 100px; height: 100px;"><span class="icon medium {{ $refusLibre !== null ? 'research-disallowed' : 'research-allowed' }}" style="display: block;{{ $refusLibre === null ? ' background-color: #29313d;' : '' }}"></span></a></li>
                                @else
                                    @php $objet = $s['object']; @endphp
                                    <li class="technology lifeformTech{{ $objet->id }} hasDetails tooltip hideTooltipOnMouseenter js_hideTipOnMobile tpd-hideOnClickOutside" data-slot="{{ $s['slot'] }}"
                                        data-technology="{{ $objet->id }}" data-is-spaceprovider="" aria-label="{{ $s['title'] }}"
                                        @if (!$s['available'])
                                            data-status="off" data-unavailable="1" title="{{ $s['title'] }}<br/>{{ __('t_lifeforms_ui.refused.not_available') }}"
                                        @elseif ($s['building_now'])
                                            data-status="active" title="{{ $s['title'] }}<br/>{{ __('t_ingame.buildings.under_construction') }}"
                                        @elseif (!$s['unlocked'])
                                            {{-- La population du palier est retombee sous le seuil (pertes, famine) : la technologie reste
                                                 visible, avec son niveau, eteinte et dite — un cadenas la faisait disparaitre (audit, §155.27). --}}
                                            data-status="off" data-relocked="1" title="{{ $s['title'] }}<br/>{{ __('t_lifeforms_ui.research.requires', ['population' => $s['required'], 'tier' => $tier]) }}"
                                        @elseif ($is_in_vacation_mode)
                                            data-status="disabled" title="{{ $s['title'] }}<br/>{{ __('t_ingame.buildings.vacation_mode_error') }}"
                                        @elseif (!$s['centre_open'])
                                            data-status="off" title="{{ $s['title'] }}<br/>{{ __('t_lifeforms_ui.research.centre_needed') }}"
                                        @elseif ($s['queue_full'])
                                            data-status="disabled" title="{{ $s['title'] }}<br/>{{ __('t_ingame.buildings.queue_full', ['nombre' => $queue_waiting_allowed]) }}"
                                        @elseif (!$s['can_research'])
                                            data-status="disabled" title="{{ $s['title'] }}<br/>{{ __('t_ingame.buildings.not_enough_resources') }}"
                                        @else
                                            data-status="on" title="{{ $s['title'] }}"
                                        @endif
                                    ><span class="icon lifeformsprite sprite_medium medium lifeformTech{{ $objet->id }}">
                                        @if ($s['can_research'])
                                            <button class="upgrade tooltip hideOthers js_hideTipOnMobile"
                                                    aria-label="{{ __('t_lifeforms_ui.research.research_button', ['title' => $s['title'], 'level' => $s['level'] + 1]) }}"
                                                    title="{{ __('t_lifeforms_ui.research.research_button', ['title' => $s['title'], 'level' => $s['level'] + 1]) }}"
                                                    data-technology="{{ $objet->id }}" data-is-spaceprovider=""></button>
                                        @endif
                                        @if ($s['building_now'])
                                            <span class="targetlevel" data-value="{{ $s['building_target'] }}" data-bonus="0">{{ $s['building_target'] }}</span>
                                            <div class="cooldownBackground"></div>
                                            <time-counter><time class="countdown lfResearchCountdown" data-segments="2">...</time></time-counter>
                                        @endif
                                        <span class="level" data-value="{{ $s['level'] }}" data-bonus="0">
                                            <span class="stockAmount">{{ $s['level'] }}</span>
                                            <span class="bonus"></span>
                                        </span>
                                    </span></li>
                                @endif
                            @endforeach
                        </ul>
                        <div class="buttons">
                            @if ($palier['can_reset'])
                                <form method="post" action="{{ route('lifeforms.research.reset') }}" style="display: inline;"
                                      onsubmit="return confirm({{ json_encode(__('t_lifeforms_ui.research.reset_confirm', ['tier' => $tier])) }});">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="tier" value="{{ $tier }}">
                                    <a class="build-it" id="resetTier{{ $tier }}" data-tier="{{ $tier }}" href="#" onclick="this.closest('form').requestSubmit(); return false;"><span>{{ __('t_lifeforms_ui.research.reset', ['tier' => $tier]) }}</span></a>
                                </form>
                            @elseif ($palier['can_restore'])
                                <form method="post" action="{{ route('lifeforms.research.restore') }}" style="display: inline;">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="tier" value="{{ $tier }}">
                                    <a class="build-it" id="restoreTier{{ $tier }}" data-tier="{{ $tier }}" href="#" onclick="this.closest('form').requestSubmit(); return false;"><span>{{ __('t_lifeforms_ui.research.restore', ['tier' => $tier]) }}</span></a>
                                </form>
                            @else
                                <a id="resetTier{{ $tier }}" class="build-it_disabled tooltip" data-tier="{{ $tier }}" data-enabled="false"
                                      title="{{ $palier['research_in_progress'] ? __('t_lifeforms_ui.refused.research_in_progress') : ($palier['reset_available_at'] !== null && $palier['reset_available_at'] > \Illuminate\Support\Facades\Date::now()->timestamp ? __('t_lifeforms_ui.research.reset_cooldown') : __('t_lifeforms_ui.refused.nothing_to_reset')) }}"><span>{{ __('t_lifeforms_ui.research.reset', ['tier' => $tier]) }}</span></a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Les deux boites de file cote a cote, batiments puis recherches, comme la page officielle. --}}
            <div id="productionboxBottom" style="margin: 0 auto;">
                <div class="productionBoxBuildings boxColumn building">
                    <div id="productionboxlfbuildingcomponent" class="productionboxlfbuilding injectedComponent parent lfresearch">
                        @include('ingame.lifeforms.partials.queue', ['queue_active' => $other_queue_active, 'queue_waiting' => $other_queue_waiting, 'kind' => 'building'])
                    </div>
                </div>
                <div class="productionBoxResearch boxColumn research">
                    <div id="productionboxlfresearchcomponent" class="productionboxlfresearch injectedComponent parent lfresearch">
                        @include('ingame.lifeforms.partials.queue', ['queue_active' => $queue_active, 'queue_waiting' => $queue_waiting, 'kind' => 'technology'])
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="technologydetailscomponent" class="technologydetails injectedComponent parent lfresearch">
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
            var technologyDetailsEndpoint = "{{ route('lifeforms.research.ajax') }}";
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
