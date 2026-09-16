@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div id="suppliescomponent" class="maincontent">
        <div id="supplies" class="lifeform-buildings">
            <header data-anchor="technologyDetails" data-technologydetails-size="large"
                    style="background-image:url({{ asset('img/headers/resources/' . $header_filename) }}.jpg);">
                <h2>{{ __('t_lifeforms_ui.buildings.title') }} - {{ $species_name }} - {{ $planet_name }}</h2>
            </header>
            <div id="technologydetails_wrapper">
                <div id="technologydetails_content"></div>
            </div>
            <div id="technologies">
                @include('ingame.lifeforms.partials.held', ['held' => $held ?? null])
                <h3>{{ __('t_lifeforms_ui.buildings.section') }}</h3>
                @if (!empty($figures))
                    <p class="smallFont lifeform-figures" style="margin: 0 0 8px 0;">
                        {{ __('t_lifeforms_ui.banner.population') }} : <span class="undermark">{{ $figures['population_formatted'] }}</span> / {{ $figures['living_space_formatted'] }}
                        · {{ __('t_lifeforms_ui.banner.food') }} : <span class="{{ $figures['food_balance_hour'] < 0 ? 'overmark' : 'undermark' }}">{{ $figures['food_formatted'] }}</span> ({{ $figures['food_balance_hour_formatted'] }}/h)
                    </p>
                @endif
                <ul id="producers" class="icons">
                    @foreach ($tiles as $tile)
                        @php $objet = $tile['object']; @endphp
                        <li class="technology lifeformTech{{ $objet->id }} hasDetails tooltip hideTooltipOnMouseenter js_hideTipOnMobile tpd-hideOnClickOutside"
                            data-technology="{{ $objet->id }}"
                            data-is-spaceprovider=""
                            aria-label="{{ $tile['title'] }}"
                            @if ($tile['building_now'])
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
                            @elseif (!$tile['enough_resources'])
                                data-status="disabled"
                                title="{{ $tile['title'] }}<br/>{{ __('t_ingame.buildings.not_enough_resources') }}"
                            @elseif ($tile['queue_full'])
                                data-status="disabled"
                                title="{{ $tile['title'] }}<br/>{{ __('t_ingame.buildings.queue_full') }}"
                            @else
                                data-status="on"
                                title="{{ $tile['title'] }}"
                            @endif
                        ><span class="icon lifeformsprite sprite_medium medium lifeformTech{{ $objet->id }}">
                            @if (!$tile['building_now'] && !$tile['vacation'] && $tile['requirements_met'] && $tile['population_met'] && $tile['enough_resources'] && !$tile['queue_full'])
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

            <div id="productionboxlfbuildingcomponent" class="productionboxlfbuilding injectedComponent parent supplies">
                @include('ingame.lifeforms.partials.queue', ['queue_active' => $queue_active, 'queue_waiting' => $queue_waiting, 'kind' => 'building'])
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
