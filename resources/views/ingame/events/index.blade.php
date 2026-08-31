@extends('ingame.layouts.main')

@section('content')

    <style>
        /* Page autoportante : aucune regle n'est ajoutee aux feuilles construites, qui ne
           peuvent pas etre regenerees sur ce serveur (pas de Node dans le conteneur). Tout
           ce qui suit est donc porte par la page elle-meme. */
        #eventscomponent .ogx-tritium-bar {
            background: rgba(0, 0, 0, .35);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 4px;
            height: 18px;
            margin: 6px 14px 14px;
            overflow: hidden;
        }

        #eventscomponent .ogx-tritium-fill {
            background: linear-gradient(to bottom, #4aa3df, #1b6ca8);
            height: 100%;
        }

        #eventscomponent .ogx-tritium-label {
            color: #cfe3f5;
            font-weight: bold;
            padding: 0 14px;
        }

        #eventscomponent .ogx-row {
            border-bottom: 1px solid rgba(255, 255, 255, .07);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
        }

        #eventscomponent .ogx-row-name {
            flex: 1 1 auto;
        }

        #eventscomponent .ogx-row-progress {
            color: #8fa7bd;
            flex: 0 0 90px;
            text-align: right;
        }

        #eventscomponent .ogx-row-tritium {
            color: #4aa3df;
            flex: 0 0 90px;
            font-weight: bold;
            text-align: right;
        }

        #eventscomponent .ogx-row-action {
            flex: 0 0 130px;
            text-align: right;
        }

        #eventscomponent .ogx-done {
            color: #8fce00;
        }

        #eventscomponent .ogx-rank {
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 5px;
            margin: 10px 14px;
            padding: 10px;
        }

        #eventscomponent .ogx-rank.locked {
            opacity: .5;
        }

        #eventscomponent .ogx-rank h4 {
            margin: 0 0 8px;
        }

        #eventscomponent .ogx-choices {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        #eventscomponent .ogx-choice {
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 4px;
            flex: 1 1 180px;
            padding: 8px;
            text-align: center;
        }

        #eventscomponent .ogx-choice p {
            margin: 0 0 6px;
            min-height: 30px;
        }

        #eventscomponent .ogx-note {
            color: #8fa7bd;
            padding: 0 14px 10px;
        }

        #eventscomponent form.ogx-inline {
            display: inline;
        }
    </style>

    <div id="eventscomponent" class="maincontent">
        <div id="content">
            <div id="inhalt">
                <div id="planet" class="planet-header">
                    <div id="header_text">
                        <h2>{{ __('t_ingame.events.page_title') }}</h2>
                    </div>
                </div>
                <div class="c-left"></div>
                <div class="c-right"></div>

                <div id="buttonz">
                    <div class="header">
                        <h2>{{ __('t_ingame.events.page_title') }}</h2>
                    </div>
                    <div class="content">

                        @if (session('status'))
                            <div class="alert alert-success" style="margin: 4px 14px 18px;">{{ session('status') }}</div>
                        @endif

                        @if (session('error'))
                            <div class="alert alert-danger" style="margin: 4px 14px 18px;">{{ session('error') }}</div>
                        @endif

                        <p class="ogx-note">
                            {{ __('t_ingame.events.period', [
                                'start' => $start !== null ? $start->format('d/m/Y') : '',
                                'end' => $end !== null ? $end->format('d/m/Y') : '',
                            ]) }}
                        </p>

                        <p class="ogx-tritium-label">
                            {{ __('t_ingame.events.tritium_total', [
                                'tritium' => number_format($tritium, 0, ',', ' '),
                                'max' => number_format($maxTritium, 0, ',', ' '),
                            ]) }}
                        </p>

                        <div class="ogx-tritium-bar">
                            <div class="ogx-tritium-fill"
                                 style="width: {{ $maxTritium > 0 ? min(100, round($tritium * 100 / $maxTritium)) : 0 }}%;"></div>
                        </div>

                        <h3>{{ __('t_ingame.events.section_missions') }}</h3>

                        @foreach ($missions as $mission)
                            <div class="ogx-row">
                                <span class="ogx-row-name {{ $mission['done'] ? 'ogx-done' : '' }}">
                                    {{ __('t_ingame.events.mission_' . $mission['key'], ['target' => number_format($mission['target'], 0, ',', ' ')]) }}
                                </span>
                                <span class="ogx-row-progress">
                                    {{ number_format($mission['progress'], 0, ',', ' ') }} / {{ number_format($mission['target'], 0, ',', ' ') }}
                                </span>
                                <span class="ogx-row-tritium">+{{ number_format($mission['tritium'], 0, ',', ' ') }}</span>
                                <span class="ogx-row-action">
                                    @if ($mission['claimed'])
                                        <span class="ogx-done">{{ __('t_ingame.events.claimed') }}</span>
                                    @elseif ($mission['done'])
                                        <form class="ogx-inline" action="{{ route('events.claim-mission') }}" method="post">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="mission" value="{{ $mission['key'] }}"/>
                                            <input type="submit" class="btn_blue" value="{{ __('t_ingame.events.claim') }}"/>
                                        </form>
                                    @else
                                        <span style="color:#8fa7bd;">{{ __('t_ingame.events.in_progress') }}</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach

                        <h3>{{ __('t_ingame.events.section_ranks') }}</h3>

                        <p class="ogx-note">{{ __('t_ingame.events.ranks_hint') }}</p>
                        <p class="ogx-note">{{ __('t_ingame.events.planet_warning', ['planet' => $currentPlanetName]) }}</p>

                        @foreach ($ranks as $rank)
                            <div class="ogx-rank {{ $rank['reached'] ? '' : 'locked' }}">
                                <h4>
                                    {{ __('t_ingame.events.rank_title', ['rank' => $rank['rank']]) }}
                                    &mdash;
                                    {{ __('t_ingame.events.rank_threshold', ['tritium' => number_format($rank['threshold'], 0, ',', ' ')]) }}
                                </h4>

                                @if ($rank['claimed'])
                                    <p class="ogx-done">
                                        {{ __('t_ingame.events.rank_claimed', [
                                            'reward' => $rank['rewards'][$rank['chosen']]['summary'] ?? '',
                                        ]) }}
                                    </p>
                                @else
                                    <div class="ogx-choices">
                                        @foreach ($rank['rewards'] as $rewardKey => $reward)
                                            <div class="ogx-choice">
                                                <p>{{ $reward['summary'] }}</p>
                                                @if ($rank['reached'])
                                                    <form action="{{ route('events.claim-rank') }}" method="post">
                                                        {{ csrf_field() }}
                                                        <input type="hidden" name="rank" value="{{ $rank['rank'] }}"/>
                                                        <input type="hidden" name="reward" value="{{ $rewardKey }}"/>
                                                        <input type="submit" class="btn_blue"
                                                               value="{{ __('t_ingame.events.choose') }}"
                                                               onclick="return confirm('{{ __('t_ingame.events.confirm_choice') }}');"/>
                                                    </form>
                                                @else
                                                    <span style="color:#8fa7bd;">{{ __('t_ingame.events.rank_locked') }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach

                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
