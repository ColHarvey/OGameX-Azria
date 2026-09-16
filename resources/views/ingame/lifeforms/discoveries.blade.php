@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div id="lfdiscoveriescomponent" class="maincontent">
        <div id="lifeforms">
            <header id="planet" data-anchor="technologyDetails"
                    style="background-image:url({{ asset('img/headers/resources/' . $header_filename) }}.jpg);">
                <h2>{{ __('t_lifeforms_ui.discoveries.title') }} - {{ $planet_name }}</h2>
            </header>

            <div id="technologies">
                @include('ingame.lifeforms.partials.held', ['held' => $held ?? null])
                @if (!empty($lifeforms_error))
                    <div class="fieldwrapper"><div class="smallFont overmark" role="alert">{{ $lifeforms_error }}</div></div>
                @endif

                <div class="content-box-s" id="lifeform-discoveries-summary">
                    <div class="header"><h3>{{ __('t_lifeforms_ui.discoveries.title') }} — {{ $species_name }}</h3></div>
                    <div class="content" style="padding: 12px;">
                        <p class="smallFont" style="margin: 0 0 8px 0;">{{ __('t_lifeforms_ui.discoveries.intro', ['metal' => number_format($cost->metal->get(), 0, ',', ' '), 'crystal' => number_format($cost->crystal->get(), 0, ',', ' '), 'deuterium' => number_format($cost->deuterium->get(), 0, ',', ' ')]) }}</p>
                        <ul style="margin: 0; padding-left: 18px;">
                            <li id="lifeform-discoveries-quota">{{ __('t_lifeforms_ui.discoveries.quota', ['available' => $available, 'per_day' => $per_day]) }}</li>
                            <li class="smallFont">{{ __('t_lifeforms_ui.discoveries.next_quota', ['when' => $next_refill]) }}</li>
                            <li id="lifeform-discoveries-artifacts">{{ __('t_lifeforms_ui.discoveries.artifacts', ['count' => $artifacts, 'cap' => $artifact_cap]) }}</li>
                        </ul>
                        @if (!$centre_open)
                            <p class="smallFont overmark" style="margin: 8px 0 0 0;">{{ __('t_lifeforms_ui.discoveries.centre_needed') }}</p>
                        @endif
                    </div>
                    <div class="footer"></div>
                </div>

                <div class="content-box-s" id="lifeform-discoveries-launch" style="margin-top: 12px;">
                    <div class="header"><h3>{{ __('t_lifeforms_ui.discoveries.launch_title') }}</h3></div>
                    <div class="content" style="padding: 12px;">
                        <form method="post" action="{{ route('lifeforms.discoveries.launch') }}" id="lifeform-discovery-form" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                            @csrf
                            <label style="display: flex; flex-direction: column; gap: 4px;">
                                <span class="smallFont">{{ __('t_lifeforms_ui.discoveries.galaxy') }}</span>
                                <input type="number" name="galaxy" min="1" max="{{ $galaxies }}" value="{{ old('galaxy', $current->galaxy) }}" required style="width: 70px;">
                            </label>
                            <label style="display: flex; flex-direction: column; gap: 4px;">
                                <span class="smallFont">{{ __('t_lifeforms_ui.discoveries.system') }}</span>
                                <input type="number" name="system" min="1" max="499" value="{{ old('system', $current->system) }}" required style="width: 70px;">
                            </label>
                            <label style="display: flex; flex-direction: column; gap: 4px;">
                                <span class="smallFont">{{ __('t_lifeforms_ui.discoveries.position') }}</span>
                                <input type="number" name="position" min="1" max="15" value="{{ old('position', $current->position) }}" required style="width: 70px;">
                            </label>
                            <button type="submit" class="btn_blue" @if (!$centre_open || $available < 1 || $vacation) disabled @endif>{{ __('t_lifeforms_ui.discoveries.launch') }}</button>
                        </form>
                        <p class="smallFont" style="margin: 8px 0 0 0;">{{ __('t_lifeforms_ui.discoveries.duration_hint', ['same_system' => $durations['same_system'], 'same_galaxy' => $durations['same_galaxy'], 'other_galaxy' => $durations['other_galaxy'], 'reduction' => $reduction_percent]) }}</p>
                    </div>
                    <div class="footer"></div>
                </div>

                <div class="content-box-s" id="lifeform-discoveries-running" style="margin-top: 12px;">
                    <div class="header"><h3>{{ __('t_lifeforms_ui.discoveries.running_title') }}</h3></div>
                    <div class="content" style="padding: 12px;">
                        @if (count($running) === 0)
                            <p class="smallFont" style="margin: 0;">{{ __('t_lifeforms_ui.discoveries.none_running') }}</p>
                        @else
                            <ul style="margin: 0; padding-left: 18px;">
                                @foreach ($running as $vol)
                                    <li class="lifeform-discovery-running">{{ $vol['coordinates'] }} — {{ __('t_lifeforms_ui.discoveries.returns_in', ['duration' => \OGame\Facades\AppUtil::formatTimeDuration($vol['remaining'])]) }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <div class="footer"></div>
                </div>

                <div class="content-box-s" id="lifeform-discoveries-history" style="margin-top: 12px;">
                    <div class="header"><h3>{{ __('t_lifeforms_ui.discoveries.history_title') }}</h3></div>
                    <div class="content" style="padding: 12px;">
                        @if (count($history) === 0)
                            <p class="smallFont" style="margin: 0;">{{ __('t_lifeforms_ui.discoveries.none_settled') }}</p>
                        @else
                            <ul style="margin: 0; padding-left: 18px;">
                                @foreach ($history as $vol)
                                    <li class="lifeform-discovery-settled">{{ $vol['coordinates'] }} — {{ $vol['outcome'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <div class="footer"></div>
                </div>
            </div>
        </div>
    </div>

@endsection
