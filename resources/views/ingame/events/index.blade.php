@extends('ingame.layouts.main')

@section('content')

    @php
        // Regroupement par valeur de tritium, comme la page officielle : un bandeau
        // « Valeur en tritium : N » puis les missions qui la valent. Le service a deja trie
        // les missions par valeur croissante, un simple parcours suffit donc.
        $groupes = [];
        foreach ($missions as $mission) {
            $groupes[$mission['tritium']][] = $mission;
        }
    @endphp

    <style>
        /* Le theme porte deja toute l'interface des recompenses d'OGame, mais uniquement
           sous #rewardings : c'est la seule raison pour laquelle le conteneur ci-dessous
           porte cet identifiant. Ce bloc ne complete que ce que le theme ne fournit pas :
           les onglets et quelques etats. Aucune feuille construite n'est modifiee, elles
           ne peuvent pas etre regenerees sur ce serveur. */
        #rewardings .ogx-tab {
            cursor: pointer;
        }

        #rewardings .ogx-tab.reached {
            background: #2b5c1e;
            border-color: #3f8a2c;
        }

        #rewardings .ogx-tab.active {
            box-shadow: inset 0 0 0 1px #8fce00;
        }

        #rewardings .ogx-stage-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Bande de titre. Le theme declare .rewardheader avec le sprite mais sans
           position ni dimensions ; sans elles le titre restait du texte nu pose
           au-dessus du cadre. La decoupe a ete relevee sur le sprite : la bande occupe
           x 104-616, y 5-39, soit 512 x 35 px, biseau compris a droite. Cette largeur
           correspond a celle de .rewardlist-item-text, ce qui place le biseau pile a
           l extremite de la ligne. */
        #rewardings .rewardheader {
            background-position: -104px -5px;
            width: 512px;
            height: 35px;
            overflow: hidden;
        }

        #rewardings .rewardheader > h3 {
            margin: 0 120px 0 12px;
            font-size: 12px;
            font-weight: 600;
            line-height: 35px;
            color: #cfe3f5;
        }

        #rewardings .rewarded .rewardheader > h3 {
            color: #9c0;
        }

        #rewardings .ogx-gain {
            color: #9c0;
            font-weight: 600;
        }

        #rewardings .ogx-progress {
            color: #848484;
        }

        /* Textes du panneau de rang. Ils reprennent la mise en forme des identifiants
           #welcome, #rewarddescription et #commandingstaff du theme, en classes : la page
           affiche les cinq rangs a la fois, et un identifiant ne peut pas etre repete. */
        #rewardings .ogx-welcome {
            text-align: center;
            margin: 0 0 8px 2px;
            font-weight: 600;
        }

        #rewardings .ogx-rank-text {
            color: #848484;
            text-align: center;
            margin: 3px 0 0 4px;
            font-size: 11px;
            line-height: 130%;
        }

        #rewardings .ogx-rank-header {
            text-align: center;
            margin: 12px 0 0;
            font-size: 11px;
        }

        #rewardings .ogx-staff-note {
            color: #848484;
            text-align: center;
            margin: 14px 0 0;
            font-size: 11px;
        }

        #rewardings .ogx-staff-note.active {
            color: #9c0;
        }

        #rewardings .singleReward .rewardName {
            text-align: center;
        }

        /* Avertissement de perte : discret tant que l'echeance est lointaine, franc
           quand il ne reste que deux jours. */
        #rewardings .ogx-loss {
            color: #848484;
            text-align: center;
            margin: 4px 0 10px;
            font-size: 11px;
        }

        #rewardings .ogx-loss.urgent {
            color: #e74c3c;
            font-weight: 600;
        }

        #rewardings .ogx-empty {
            color: #848484;
            padding: 20px;
            text-align: center;
        }
    </style>

    <div id="eventscomponent" class="maincontent">
        <div id="content">
            <div id="inhalt">
                <div id="planet" style="background-image:url(/img/headers/rewards/rewards.jpg);height:250px;">
                    <div id="header_text">
                        <h2>{{ __('t_ingame.events.page_title') }}</h2>
                    </div>
                </div>

                <div id="buttonz">
                    <div class="header">
                        <h2>{{ __('t_ingame.events.page_title') }}</h2>
                    </div>
                    <div class="content">

                        @if (session('status'))
                            <div class="alert alert-success" style="margin: 4px 14px 12px;">{{ session('status') }}</div>
                        @endif

                        @if (session('error'))
                            <div class="alert alert-danger" style="margin: 4px 14px 12px;">{{ session('error') }}</div>
                        @endif

                        {{-- Identifiant impose par le theme : toutes les regles de cette interface
                             sont ecrites sous #rewardings. Le module JavaScript embarque du jeu
                             (Rewarding) vise les memes selecteurs et rechargerait la page par AJAX
                             vers une route inexistante, mais rien ne l'instancie : « new Rewarding »
                             est absent du bundle. Le verifier apres toute fusion amont touchant aux
                             assets construits. --}}
                        <div id="rewardings">
                            <div class="rewardlist">

                                <div class="titlebar">
                                    <button type="button" class="btn_blue ogx-tab active" data-panel="tasks">
                                        {{ __('t_ingame.events.tab_tasks') }}
                                    </button>
                                    <div class="tierlist">
                                        @foreach ($ranks as $rang)
                                            <button type="button"
                                                    class="btn_blue ogx-tab {{ $rang['reached'] ? 'reached' : '' }}"
                                                    data-panel="rank{{ $rang['rank'] }}">
                                                {{ __('t_ingame.events.rank_title', ['rank' => $rang['rank']]) }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="rewardlist_wrapper">

                                    {{-- Onglet des missions du jour --}}
                                    <div class="ogx-panel" data-panel="tasks">

                                        <div class="ogx-stage-row">
                                            <div class="tritiumstage">
                                                <span class="tritiumvalue">{{ __('t_ingame.events.period', [
                                                    'start' => $start !== null ? $start->format('d/m/Y') : '',
                                                    'end' => $end !== null ? $end->format('d/m/Y') : '',
                                                ]) }}</span>
                                            </div>
                                            <div class="tritiumstage playervalue">
                                                <span class="tritiumvalue">{{ __('t_ingame.events.you_have', [
                                                    'tritium' => number_format($tritium, 0, ',', ' '),
                                                ]) }}</span>
                                                <span class="tritiumicon"></span>
                                            </div>
                                        </div>

                                        <p class="ogx-loss {{ $daysLeft <= 2 ? 'urgent' : '' }}">
                                            @if ($daysLeft <= 2)
                                                {{ __('t_ingame.events.loss_warning_urgent', ['days' => $daysLeft]) }}
                                            @else
                                                {{ __('t_ingame.events.loss_warning') }}
                                            @endif
                                        </p>

                                        @forelse ($groupes as $valeur => $groupe)
                                            <div class="tritiumstage">
                                                <span class="tritiumvalue">{{ __('t_ingame.events.tritium_value', [
                                                    'tritium' => number_format($valeur, 0, ',', ' '),
                                                ]) }}</span>
                                                <span class="tritiumicon"></span>
                                            </div>

                                            @foreach ($groupe as $mission)
                                                <div class="rewardlist-item {{ $mission['done'] ? 'rewarded' : '' }}">
                                                    <div class="rewardlist-item-icon">
                                                        <img src="/img/{{ $mission['icon'] }}" width="80" height="80"
                                                             alt="{{ __('t_ingame.events.mission_' . $mission['key'] . '_name') }}">
                                                    </div>
                                                    <div class="rewardlist-item-text">
                                                        <div class="rewardheader">
                                                            <h3>{{ __('t_ingame.events.mission_' . $mission['key'] . '_name') }}</h3>
                                                        </div>

                                                        @if ($mission['done'])
                                                            <div class="reward-claimed-text">
                                                                <p class="ogx-gain">+{{ number_format($mission['tritium'], 0, ',', ' ') }}</p>
                                                                <span class="icon icon_checkmark"></span>
                                                            </div>
                                                        @endif

                                                        <div class="rewardlist-item-wrapper">
                                                            <p>{{ __('t_ingame.events.mission_' . $mission['key'], [
                                                                'target' => number_format($mission['target'], 0, ',', ' '),
                                                            ]) }}</p>

                                                            @if ($mission['target'] > 1)
                                                                <p class="ogx-progress">{{ __('t_ingame.events.progress', [
                                                                    'progress' => number_format($mission['progress'], 0, ',', ' '),
                                                                    'target' => number_format($mission['target'], 0, ',', ' '),
                                                                ]) }}</p>
                                                            @endif
                                                        </div>
                                                        <div class="rewardlist-item-bottom"></div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        @empty
                                            <p class="ogx-empty">{{ __('t_ingame.events.no_mission') }}</p>
                                        @endforelse
                                    </div>

                                    {{-- Un onglet par rang --}}
                                    @foreach ($ranks as $rang)
                                        <div class="ogx-panel" data-panel="rank{{ $rang['rank'] }}" style="display:none;">

                                            <p class="ogx-welcome">{{ __('t_ingame.events.rank_welcome') }}</p>
                                            <p class="ogx-rank-text">{{ __('t_ingame.events.rank_hint') }}</p>
                                            <p class="ogx-rank-header">{{ __('t_ingame.events.rank_progress', [
                                                'tritium' => number_format($tritium, 0, ',', ' '),
                                                'threshold' => number_format($rang['threshold'], 0, ',', ' '),
                                            ]) }}</p>
                                            <p class="ogx-rank-text">{{ __('t_ingame.events.planet_warning', ['planet' => $currentPlanetName]) }}</p>
                                            <p class="ogx-loss {{ $daysLeft <= 2 ? 'urgent' : '' }}">{{ __('t_ingame.events.loss_warning') }}</p>

                                            <div class="normalRewards">
                                                @foreach ($rang['rewards'] as $rewardKey => $reward)
                                                    <div class="singleReward">
                                                        <span class="rewardName">{{ $reward['summary'] }}</span>

                                                        @if ($rang['claimed'])
                                                            @if ($rang['chosen'] === $rewardKey)
                                                                <span class="ogx-gain">{{ __('t_ingame.events.your_choice') }}</span>
                                                            @endif
                                                        @elseif ($rang['reached'])
                                                            <form action="{{ route('events.claim-rank') }}" method="post">
                                                                {{ csrf_field() }}
                                                                <input type="hidden" name="rank" value="{{ $rang['rank'] }}"/>
                                                                <input type="hidden" name="reward" value="{{ $rewardKey }}"/>
                                                                <input type="submit" class="btn_blue ogx-choose"
                                                                       value="{{ __('t_ingame.events.choose') }}"/>
                                                            </form>
                                                        @else
                                                            <span class="ogx-progress">{{ __('t_ingame.events.rank_locked') }}</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>

                                            <p class="ogx-staff-note {{ $staffActive ? 'active' : '' }}">
                                                {{ $staffActive
                                                    ? __('t_ingame.events.bonus_active')
                                                    : __('t_ingame.events.bonus_inactive') }}
                                            </p>

                                            <div class="additionalRewards">
                                                @foreach ($rang['bonus'] as $bonus)
                                                    <div class="singleReward">
                                                        <span class="rewardName">{{ $bonus }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach

                                </div>
                            </div>
                        </div>

                        <div class="footer"></div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        $(document).ready(function () {
            // Bascule d'onglets, entierement cote client : les six panneaux sont rendus d'un
            // coup par le serveur, changer d'onglet ne recharge rien.
            $('#rewardings .ogx-tab').on('click', function () {
                var panneau = $(this).data('panel');

                $('#rewardings .ogx-tab').removeClass('active');
                $(this).addClass('active');

                $('#rewardings .ogx-panel').hide();
                $('#rewardings .ogx-panel[data-panel="' + panneau + '"]').show();
            });

            // Le choix d'une recompense de rang est definitif : on le fait confirmer.
            $('#rewardings .ogx-choose').on('click', function () {
                return confirm(@json(__('t_ingame.events.confirm_choice')));
            });
        });
    </script>

@endsection
