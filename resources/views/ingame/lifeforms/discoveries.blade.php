@extends('ingame.layouts.main')

{{--
    Les vols de decouverte, page propre a Azria (l icone de la Galaxie attend un build du front).

    Le cadre est celui de la page officielle `lfsettings` (`#lfsettingscomponent`, `.lfsettingsContentWrapper`), le
    seul cadre large que la feuille de style du jeu connaisse pour les formes de vie ; les boites `.content-box-s` du
    premier gabarit sont les boites laterales de 220 px, et la page se lisait dans une colonne etroite (journal §155.22).
--}}

@section('content')

    @include('ingame.lifeforms.partials.flash')

    <div id="lfsettingscomponent" class="maincontent">
        <div id="lfsettings">
            <header id="planet" data-anchor="technologyDetails"
                    style="background-image:url({{ asset('img/headers/resources/' . $header_filename) }}.jpg);">
                <h2>{{ __('t_lifeforms_ui.discoveries.title') }} - {{ $planet_name }}</h2>
            </header>

            {{-- L avis de suspension se pose avant le cadre, comme sur les pages des batiments et des recherches. --}}
            @include('ingame.lifeforms.partials.held', ['held' => $held ?? null])
            <div id="technologies">
                @if (!empty($lifeforms_error))
                    <div class="lfsettingsContentWrapper informationalContent">
                        <p class="overmark" role="alert" style="margin: 0;">{{ $lifeforms_error }}</p>
                    </div>
                @endif

                {{-- **Chaque section prend la barre de titre du jeu** : la feuille la pose sur `.lfsettingsContent > h3`,
                     donc le titre doit etre l enfant direct de la boite interieure, pas du cadre. Sans cette boite, les
                     quatre titres restaient du texte bleu nu (journal §155.23). --}}
                <div class="lfsettingsContentWrapper" id="lifeform-discoveries-summary">
                    <div class="lfsettingsContent">
                        <h3>{{ __('t_lifeforms_ui.discoveries.title') }} — {{ $species_name }}</h3>
                        <p class="smallFont" style="margin: 0 0 8px 0;">{{ __('t_lifeforms_ui.discoveries.intro', ['metal' => number_format($cost->metal->get(), 0, ',', ' '), 'crystal' => number_format($cost->crystal->get(), 0, ',', ' '), 'deuterium' => number_format($cost->deuterium->get(), 0, ',', ' ')]) }}</p>
                        <ul style="margin: 0 0 8px 0; padding-left: 18px; list-style: outside;">
                            <li id="lifeform-discoveries-quota">{{ __('t_lifeforms_ui.discoveries.quota', ['available' => $available, 'per_day' => $per_day]) }}</li>
                            <li>{{ __('t_lifeforms_ui.discoveries.next_quota', ['when' => $next_refill]) }}</li>
                            <li id="lifeform-discoveries-artifacts">{{ __('t_lifeforms_ui.discoveries.artifacts', ['count' => $artifacts, 'cap' => $artifact_cap]) }}</li>
                        </ul>
                        @if (!$centre_open)
                            <p class="smallFont overmark" style="margin: 0 0 8px 0;">{{ __('t_lifeforms_ui.discoveries.centre_needed') }}</p>
                        @endif
                    </div>
                </div>

                <div class="lfsettingsContentWrapper" id="lifeform-discoveries-launch">
                    <div class="lfsettingsContent">
                        <h3>{{ __('t_lifeforms_ui.discoveries.launch_title') }}</h3>
                        {{-- Les champs sont en `type="text"` : la peau des champs du jeu ne couvre que
                             `input[type=text]`, et un `type="number"` restait un rectangle blanc de navigateur.
                             La bordure du serveur reste la seule qui decide (le controleur valide). --}}
                        <form method="post" action="{{ route('lifeforms.discoveries.launch') }}" id="lifeform-discovery-form" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                            @csrf
                            <label style="display: flex; flex-direction: column; gap: 4px;">
                                <span class="smallFont">{{ __('t_lifeforms_ui.discoveries.galaxy') }}</span>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" class="hideNumberSpin" name="galaxy" value="{{ old('galaxy', $current->galaxy) }}" required style="width: 40px;">
                            </label>
                            <label style="display: flex; flex-direction: column; gap: 4px;">
                                <span class="smallFont">{{ __('t_lifeforms_ui.discoveries.system') }}</span>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" class="hideNumberSpin" name="system" value="{{ old('system', $current->system) }}" required style="width: 40px;">
                            </label>
                            <label style="display: flex; flex-direction: column; gap: 4px;">
                                <span class="smallFont">{{ __('t_lifeforms_ui.discoveries.position') }}</span>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" class="hideNumberSpin" name="position" value="{{ old('position', $current->position) }}" required style="width: 40px;">
                            </label>
                            <button type="submit" class="btn_blue" @if (!$centre_open || $available < 1 || $vacation) disabled @endif>{{ __('t_lifeforms_ui.discoveries.launch') }}</button>
                        </form>
                        <p class="smallFont" style="margin: 8px 0;">{{ __('t_lifeforms_ui.discoveries.duration_hint', ['same_system' => $durations['same_system'], 'same_galaxy' => $durations['same_galaxy'], 'other_galaxy' => $durations['other_galaxy'], 'reduction' => $reduction_percent]) }}</p>
                    </div>
                </div>

                <div class="lfsettingsContentWrapper" id="lifeform-discoveries-running">
                    <div class="lfsettingsContent">
                        <h3>{{ __('t_lifeforms_ui.discoveries.running_title') }}</h3>
                        @if (count($running) === 0)
                            <p class="smallFont" style="margin: 0 0 8px 0;">{{ __('t_lifeforms_ui.discoveries.none_running') }}</p>
                        @else
                            <ul style="margin: 0 0 8px 0; padding-left: 18px; list-style: outside;">
                                @foreach ($running as $vol)
                                    <li class="lifeform-discovery-running">{{ $vol['coordinates'] }} — {{ __('t_lifeforms_ui.discoveries.returns_in', ['duration' => \OGame\Facades\AppUtil::formatTimeDuration($vol['remaining'])]) }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="lfsettingsContentWrapper" id="lifeform-discoveries-history">
                    <div class="lfsettingsContent">
                        <h3>{{ __('t_lifeforms_ui.discoveries.history_title') }}</h3>
                        @if (count($history) === 0)
                            <p class="smallFont" style="margin: 0 0 8px 0;">{{ __('t_lifeforms_ui.discoveries.none_settled') }}</p>
                        @else
                            <ul style="margin: 0 0 8px 0; padding-left: 18px; list-style: outside;">
                                @foreach ($history as $vol)
                                    <li class="lifeform-discovery-settled">{{ $vol['coordinates'] }} — {{ $vol['outcome'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection
