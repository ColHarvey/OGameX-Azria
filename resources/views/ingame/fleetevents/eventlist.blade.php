<div id="eventListWrap">
    <div id="eventHeader">
        <a class="close_details eventToggle" href="javascript:toggleEvents();">
        </a>
        <h2>{{ __('t_ingame.layout.eventbox_events') }}</h2>
    </div>
    <table id="eventContent">
        <tbody>
        {{-- Parse the fleet events as separate rows --}}
        @foreach ($fleet_events as $fleet_event)
            @if ($fleet_event->is_union_summary)
                @include ('ingame.fleetevents.eventrow-union', ['fleet_event_row' => $fleet_event])
            @else
                @include ('ingame.fleetevents.eventrow', ['fleet_event_row' => $fleet_event])
            @endif
        @endforeach
        </tbody>
    </table>
    <div id="eventFooter"></div>
</div>
<script type="text/javascript">
    var timeDelta = 1713793145000 - (new Date()).getTime();
    var LocalizationStrings = window.LocalizationStrings || {};
    LocalizationStrings.timeunits = {"short": {
        "year": "{{ __('t_ingame.layout.timeunit_year') }}",
        "month": "{{ __('t_ingame.layout.timeunit_month') }}",
        "week": "{{ __('t_ingame.layout.timeunit_week') }}",
        "day": "{{ __('t_ingame.layout.timeunit_day') }}",
        "hour": "{{ __('t_ingame.layout.timeunit_hour') }}",
        "minute": "{{ __('t_ingame.layout.timeunit_minute') }}",
        "second": "{{ __('t_ingame.layout.timeunit_second') }}"
    }};
    $("a.icon_link.recallFleet").click(function (e) {
        e.preventDefault();
        var fleetId = $(this).attr("data-fleet-id");
        errorBoxDecision(
            "Recall",
            "Recall fleet",
            "yes",
            "No",
            function() {
                $.post(ajaxRecallFleetURI, {fleet_mission_id: fleetId, _token: '{{ csrf_token() }}'}, (data) => {
                    token = data.newAjaxToken

                    if (data.success) {
                        let currentUrl = window.location.href
                        let params = new URLSearchParams(currentUrl)
                        let currentComponent = params.get("component")

                        switch (currentComponent) {
                            case "movement":
                                window.location.reload()
                                return;
                            case "galaxy":
                                if (
                                    submitForm &&
                                    typeof submitForm === "function" &&
                                    typeof galaxy !== "undefined" &&
                                    typeof system !== "undefined"
                                ) {
                                    submitForm();
                                }
                                break;
                        }

                        getAjaxEventbox()
                        refreshFleetEvents()
                    }
                })
            }
        );
        return false;
    });
</script>


