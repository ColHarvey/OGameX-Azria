@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div id="lfresearchcomponent" class="maincontent">
        <div id="lfresearch">
            <header data-anchor="technologyDetails" data-technologydetails-size="large"
                    style="background-image:url({{ asset('img/headers/resources/' . $header_filename) }}.jpg);">
                <h2>{{ __('t_lifeforms_ui.research.title') }} - {{ $species_name }}</h2>
            </header>
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
                        <div class="populationSubHeading"><span>{{ __('t_lifeforms_ui.research.population', ['population' => $palier['population']]) }}</span></div>
                        <ul class="icons">
                            @foreach ($palier['slots'] as $s)
                                @if (!$s['unlocked'])
                                    <li class="technology tooltip hideTooltipOnMouseenter js_hideTipOnMobile" data-slot="{{ $s['slot'] }}"
                                        title="{{ __('t_lifeforms_ui.research.requires', ['population' => $s['required'], 'tier' => $tier]) }}"><span class="icon medium research-locked"></span></li>
                                @elseif ($s['object'] === null)
                                    <li class="technology hasDetails tooltip hideTooltipOnMouseenter js_hideTipOnMobile tpd-hideOnClickOutside lifeform-slot-empty" data-slot="{{ $s['slot'] }}"
                                        data-technology="{{ 9000 + $s['slot'] }}" data-status="on" data-is-spaceprovider=""
                                        aria-label="{{ __('t_lifeforms_ui.research.slot_empty') }}" title="{{ __('t_lifeforms_ui.research.slot_empty') }}"><span class="icon medium lifeform-slot-free" style="display: block; width: 100px; height: 100px; background: url('/img/icons/5395f4191666be14fe8d735eea568a.png') no-repeat; background-size: contain; opacity: .55;"></span></li>
                                @else
                                    @php $objet = $s['object']; @endphp
                                    <li class="technology lifeformTech{{ $objet->id }} hasDetails tooltip hideTooltipOnMouseenter js_hideTipOnMobile tpd-hideOnClickOutside" data-slot="{{ $s['slot'] }}"
                                        data-technology="{{ $objet->id }}" data-is-spaceprovider="" aria-label="{{ $s['title'] }}"
                                        @if ($s['building_now'])
                                            data-status="active" title="{{ $s['title'] }}<br/>{{ __('t_ingame.buildings.under_construction') }}"
                                        @elseif ($is_in_vacation_mode)
                                            data-status="disabled" title="{{ $s['title'] }}<br/>{{ __('t_ingame.buildings.vacation_mode_error') }}"
                                        @elseif (!$s['centre_open'])
                                            data-status="off" title="{{ $s['title'] }}<br/>{{ __('t_lifeforms_ui.research.centre_needed') }}"
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
                                            <time-counter><time class="countdown lfBuildingCountdown" data-segments="2">...</time></time-counter>
                                        @endif
                                        <span class="level" data-value="{{ $s['level'] }}" data-bonus="0">
                                            <span class="stockAmount">{{ $s['level'] }}</span>
                                            <span class="bonus"></span>
                                        </span>
                                    </span></li>
                                @endif
                            @endforeach
                        </ul>
                        <div class="buttons" style="text-align: center; margin-bottom: 8px;">
                            @if ($palier['can_reset'])
                                <form method="post" action="{{ route('lifeforms.research.reset') }}" style="display: inline;"
                                      onsubmit="return confirm({{ json_encode(__('t_lifeforms_ui.research.reset_confirm', ['tier' => $tier])) }});">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="tier" value="{{ $tier }}">
                                    <button type="submit" id="resetTier{{ $tier }}" class="btn_blue" data-tier="{{ $tier }}">{{ __('t_lifeforms_ui.research.reset', ['tier' => $tier]) }}</button>
                                </form>
                            @elseif ($palier['can_restore'])
                                <form method="post" action="{{ route('lifeforms.research.restore') }}" style="display: inline;">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="tier" value="{{ $tier }}">
                                    <button type="submit" id="restoreTier{{ $tier }}" class="btn_blue" data-tier="{{ $tier }}">{{ __('t_lifeforms_ui.research.restore', ['tier' => $tier]) }}</button>
                                </form>
                            @else
                                <span id="resetTier{{ $tier }}" class="smallFont tooltip" data-tier="{{ $tier }}" data-enabled="false"
                                      title="{{ $palier['research_in_progress'] ? __('t_lifeforms_ui.refused.research_in_progress') : ($palier['reset_available_at'] !== null && $palier['reset_available_at'] > \Illuminate\Support\Facades\Date::now()->timestamp ? __('t_lifeforms_ui.research.reset_cooldown') : __('t_lifeforms_ui.refused.nothing_to_reset')) }}">{{ __('t_lifeforms_ui.research.reset', ['tier' => $tier]) }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div id="productionboxlfresearchcomponent" class="productionboxlfresearch injectedComponent parent lfresearch" style="width: 654px; margin: 0 auto;">
                @include('ingame.lifeforms.partials.queue', ['queue_active' => $queue_active, 'queue_waiting' => $queue_waiting, 'kind' => 'technology'])
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
