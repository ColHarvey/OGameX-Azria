@extends('ingame.layouts.main')

@section('content')

    <style>
        /* Le sprite du bouton contient deja une seconde nuance de vert a -214px.
           On l'accentue pour que le survol soit franchement visible, et on ajoute
           un leger enfoncement au clic. translateY plutot que top : le bouton est
           positionne en absolu par sa propriete bottom, que top perturberait. */
        /* :not(.disabled) exclut les boutons inactifs a la source. Les neutraliser
           apres coup ne suffisait pas : le changement de sprite au survol serait
           quand meme passe, et un bouton gris aurait vire au vert. */
        #rewardscomponent a.reward-button:not(.disabled) {
            transition: filter .12s ease, transform .06s ease;
        }
        #rewardscomponent a.reward-button:not(.disabled):hover {
            background-position: 0 -214px;
            filter: brightness(1.18);
            text-shadow: 0 0 6px #2e8b0a, -1px 1px 3px #123f02;
        }
        #rewardscomponent a.reward-button:not(.disabled):active {
            transform: translateY(1px);
            filter: brightness(.92);
        }
        /* Bouton inactif : aucun effet, et un curseur qui le dit. */
        #rewardscomponent a.reward-button.disabled {
            cursor: default;
        }
    </style>

    <div id="rewardscomponent" class="maincontent">
        <div id="content">
            <div id="inhalt">
                <div id="planet" style="background-image:url(/img/headers/rewards/rewards.jpg);height:250px;">
                    <div id="header_text">
                        <h2>{{ __('t_ingame.rewards.page_title') }}</h2>
                    </div>
                </div>
                <div id="buttonz">
                    <div class="header">
                        <h2>{{ __('t_ingame.rewards.page_title') }}</h2>
                    </div>
                    <div class="content">
                        <div class="rewardlist">
                            <a class="tooltipLeft fright questionIcons" style="display: inline-block"
                               title="{{ __('t_ingame.rewards.hint') }}">
                                <span class="rewardDetail"></span>
                            </a>
                            <br>

                            @if (session('status'))
                                <div class="alert alert-success" style="margin: 4px 14px 18px;">{{ session('status') }}</div>
                            @endif

                            @if (session('error'))
                                <div class="alert alert-danger" style="margin: 4px 14px 18px;">{{ session('error') }}</div>
                            @endif

                            <h3>{{ __('t_ingame.rewards.section_available') }}</h3>
                            @forelse ($available as $item)
                                @include('ingame.rewards.partials.item', ['item' => $item, 'playerName' => $playerName])
                            @empty
                                <p style="margin: 10px 14px 26px; color: #848484;">{{ __('t_ingame.rewards.none_available') }}</p>
                            @endforelse

                            <h3 style="margin-top: 22px;">{{ __('t_ingame.rewards.section_locked') }}</h3>
                            @foreach ($upcoming as $item)
                                @include('ingame.rewards.partials.item', ['item' => $item, 'playerName' => $playerName])
                            @endforeach

                            <h3 style="margin-top: 22px;">{{ __('t_ingame.rewards.section_collected') }}</h3>
                            @forelse ($collected as $item)
                                @include('ingame.rewards.partials.item', ['item' => $item, 'playerName' => $playerName])
                            @empty
                                <p style="margin: 10px 14px 26px; color: #848484;">{{ __('t_ingame.rewards.none_collected') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

@endsection
