@php /** @var \OGame\ViewModels\FleetEventRowViewModel $fleet_event_row */ @endphp
{{-- Un vol d exploration (formes de vie) dans le deroulant des evenements, sur le patron d une mission ordinaire
     (`eventrow.blade.php`, aller sans retour) : meme colonnes, meme compte a rebours, meme `checkevents` a l echeance.
     L icone est celle de la Galaxie (sprite ADN), le vaisseau d exploration n est pas une unite : ni rappel, ni
     cargaison (journal §163). --}}
<tr class="eventFleet lifeformDiscoveryEvent" id="eventRow-{{ $fleet_event_row->id }}"
    data-mission-type="{{ $fleet_event_row->mission_type }}"
    data-return-flight="false"
    data-arrival-time="{{ $fleet_event_row->mission_time_arrival }}"
>
    <td class="countDown">
        <span id="counter-eventlist-{{ $fleet_event_row->id }}" class="friendly textBeefy">
            load...
        </span>
    </td>
    <td class="arrivalTime">{{ date('H:i:s', $fleet_event_row->mission_time_arrival) }} {{ __('t_ingame.layout.eventbox_clock') }}</td>
    <td class="missionFleet">
        <span class="planetDiscoverIcons planetDiscoverDefault tooltipHTML" style="display: inline-block; vertical-align: middle;"
              title="{{ __('t_ingame.layout.eventbox_own_fleet') }} | {{ $fleet_event_row->mission_label }}"></span>
    </td>

    <td class="originFleet">
        @switch ($fleet_event_row->origin_planet_type)
            @case (OGame\Models\Enums\PlanetType::Planet)
                <figure class="planetIcon planet js_hideTipOnMobile"
                        title="Planet"></figure>{{ $fleet_event_row->origin_planet_name }}
                @break
            @case (OGame\Models\Enums\PlanetType::Moon)
                <figure class="planetIcon moon js_hideTipOnMobile"
                        title="Moon"></figure>{{ $fleet_event_row->origin_planet_name }}
                @break
        @endswitch
    </td>
    <td class="coordsOrigin">
        <a href="{{ route('galaxy.index', ['galaxy' => $fleet_event_row->origin_planet_coords->galaxy, 'system' => $fleet_event_row->origin_planet_coords->system]) }}"
           target="_top">
            [{{ $fleet_event_row->origin_planet_coords->asString() }}]
        </a>
    </td>

    <td class="detailsFleet">
        <span>{{ $fleet_event_row->fleet_unit_count }}</span>
    </td>
    <td class="icon_movement">
        <span class="tooltip tooltipRight tooltipClose"
              title="&lt;div class=&quot;htmlTooltip&quot;&gt;
    &lt;h1&gt;@lang('Fleet details'):&lt;/h1&gt;
    &lt;div class=&quot;splitLine&quot;&gt;&lt;/div&gt;
            &lt;table cellpadding=&quot;0&quot; cellspacing=&quot;0&quot; class=&quot;fleetinfo&quot;&gt;
            &lt;tr&gt;
                &lt;th colspan=&quot;3&quot;&gt;@lang('Ships'):&lt;/th&gt;
            &lt;/tr&gt;
                &lt;tr&gt;
                    &lt;td colspan=&quot;2&quot;&gt;{{ __('t_ingame.galaxy.discovery_title') }}:&lt;/td&gt;
                    &lt;td class=&quot;value&quot;&gt;{{ $fleet_event_row->fleet_unit_count }}&lt;/td&gt;
                &lt;/tr&gt;
            &lt;/table&gt;
    &lt;/div&gt;
">
            &nbsp;
        </span>
    </td>

    <td class="destFleet">
        @if ($fleet_event_row->destination_planet_type === OGame\Models\Enums\PlanetType::Planet)
            <figure class="planetIcon planet js_hideTipOnMobile"
                    title="Planet"></figure>{{ $fleet_event_row->destination_planet_name }}
        @endif
    </td>
    <td class="destCoords">
        <a href="{{ route('galaxy.index', ['galaxy' => $fleet_event_row->destination_planet_coords->galaxy, 'system' => $fleet_event_row->destination_planet_coords->system]) }}"
           target="_top">
            [{{ $fleet_event_row->destination_planet_coords->asString() }}]
        </a>
    </td>
    <td class="sendMail">
    </td>
    <td class="sendProbe">
    </td>
    <td class="sendMail">
    </td>
</tr>

<script type="text/javascript">
    (function ($) {
        // Le meme compte a rebours que les missions ordinaires : a l echeance, `checkevents` dit si la ligne disparait.
        var wrappedCountdown = function() {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });

            new eventboxCountdown(
                $("#counter-eventlist-{{ $fleet_event_row->id }}"),
                    {{ $fleet_event_row->mission_time_arrival }} - {{ time() }},
                $("#eventListWrap"),
                "{{ route('fleet.eventlist.checkevents') }}",
                [{{ $fleet_event_row->id }}]
            );
        };
        wrappedCountdown();
    })(jQuery);
</script>
