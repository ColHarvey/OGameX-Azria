@extends('ingame.layouts.main')

@section('content')

    <style>
        /* La rangee d'officiers est figee a 130px de haut par la CSS du jeu, alors que
           sept tuiles de 106px passent a la ligne dans 640px. La seconde rangee debordait
           donc par-dessus le panneau, et son z-index:2 interceptait les clics.
           overflow:hidden fait contenir les flottants, height:auto laisse grandir. */
        #buttonz ul#building { height: auto !important; overflow: hidden; }

        /* Panneau de detail, affiche au clic sur un portrait. Cale sur ul#building (640px). */
        #officerDetail {
            position: relative; z-index: 3;
            width: 606px; margin: 10px auto 0 17px; padding: 12px 16px;
            background: #10181f; border: 1px solid #1b2129;
            color: #6f9fc8; font-size: 11px; line-height: 17px; text-align: left;
        }
        #officerDetail .odName { color: #ff9600; font-size: 12px; font-weight: bold; display: block; margin-bottom: 4px; }
        #officerDetail .odEffects { display: block; }
        #officerDetail .odOwned {
            display: block; margin-top: 10px; color: #9ccd41;
            background: url("/img/icons/b1c7ef5b1164eba44e55b7f6d25d35.gif") no-repeat 0 3px; padding-left: 18px;
        }
        #officerDetail .odBuy { margin-top: 12px; }
        #officerDetail form { display: inline; }

        /* Bouton de la page Recompenses : 141x25, sans motif grave, et c'est la paire
           du sprite dont le survol contraste le plus (vert 189 -> 255). */
        #officerDetail .hireBtn {
            display: inline-block; width: 141px; height: 15px; margin: 0 12px 0 0; padding: 5px 0;
            background: transparent url("/img/icons/18e4684df27114667e11541e5b2ef8.png") 0 -214px no-repeat;
            border: 0; color: #fff; cursor: pointer; font-family: inherit; font-size: 10px;
            font-weight: 600; line-height: 15px; text-align: center; white-space: nowrap;
            text-shadow: -1px 1px 3px #123f02;
            transition: filter .12s ease, transform .06s ease;
        }
        #officerDetail .hireBtn:hover:not([disabled]) {
            filter: brightness(1.22);
            text-shadow: 0 0 7px #7dff2e, -1px 1px 3px #123f02;
        }
        #officerDetail .hireBtn:active:not([disabled]) { transform: translateY(1px); filter: brightness(.9); }
        #officerDetail .hireBtn[disabled] { opacity: .35; cursor: default; filter: none; transform: none; }
        #officerDetail .odTooPoor { display: block; margin-top: 8px; color: #a94442; }

        /* Ligne de bonus sans marqueur tant qu'aucun officier n'est choisi. */
        li.allOfficers.noIcon span { background-image: none !important; padding-right: 0; }

        #buttonz ul#building li a.detail_button { cursor: pointer; }
    </style>

    <div id="eventboxContent" style="display: none">
        <img height="16" width="16" src="/img/icons/3f9884806436537bdec305aa26fc60.gif" alt="">
    </div>

    <div id="inhalt" class="officers">
        <div id="planet">
            <div id="header_text">
                <h2>{{ __('t_ingame.premium.recruit_officers') }}</h2>
            </div>

            <div id="detail" class="detail_screen small">
                <div id="techDetailLoading"></div>
            </div>

        </div>
        <div class="c-left"></div>
        <div class="c-right"></div>
        <div id="buttonz">
            <div class="header">
                <h2>{{ __('t_ingame.premium.your_officers') }}</h2>
            </div>
            <div class="content">
                @if (session('status'))
                    <div class="alert alert-success" style="margin: 8px 17px 0;">{{ session('status') }}</div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger" style="margin: 8px 17px 0;">{{ session('error') }}</div>
                @endif

                <p class="stimulus">{{ __('t_ingame.premium.intro_text') }}</p>

                <ul id="building">
                    <li class="on button" id="button1">
                        <div class="premium1">
                            <div class="officers100  darkMatter">
                                <a tabindex="1" href="javascript:void(0);" title="{{ __('t_ingame.premium.info_dark_matter') }}" class="detail_button tooltip js_hideTipOnMobile">
                                    <span class="ecke">
                                        <span class="level">
                                            {{ number_format($darkMatter, 0, ',', ' ') }}
                                        </span>
                                    </span>
                                </a>
                            </div>
                        </div>
                    </li>

                    @foreach ($officers as $officer)
                        @include('ingame.premium.partials.officer', ['officer' => $officer])
                    @endforeach

                    <li class="button {{ $activeCount === $totalOfficers ? 'on' : '' }}" id="button12">
                        <div class="premium">
                            <div class="officers100  allOfficers">
                                <a tabindex="12" href="javascript:void(0);"
                                   title="{{ __('t_ingame.premium.info_commanding_staff') }}"
                                   class="detail_button tooltip js_hideTipOnMobile"
                                   data-effects="{{ __('t_ingame.premium.benefit_resources') }}"
                                   data-panel="staff"
                                   data-active="{{ $activeCount === $totalOfficers ? '1' : '0' }}"
                                   onclick="showOfficerEffects(this);">
                                    <span class="ecke">
                                        <span class="level">
                                            @if ($activeCount === $totalOfficers)
                                                <img src="/img/icons/b1c7ef5b1164eba44e55b7f6d25d35.gif" width="12" height="11" alt="">
                                            @else
                                                <img src="/img/icons/aa2ad16d1e00956f7dc8af8be3ca52.gif" width="12" height="11" alt="">
                                            @endif
                                        </span>
                                    </span>
                                </a>
                            </div>
                            <div class="remaining tooltip" title="{{ __('t_ingame.premium.info_commanding_staff') }}">
                                <span class="remDate">{{ __('t_ingame.premium.remaining_officers', ['current' => $activeCount, 'max' => $totalOfficers]) }}</span>
                            </div>
                        </div>
                    </li>

                    <li class="allOfficers noIcon" id="officerBenefits">
                        <span id="officerBenefitsText" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.choose_officer') }}</span>
                    </li>
                </ul>
                <br class="clearfloat">
                <div id="officerDetail">
                    <div class="odPanel" id="odPanel-none">
                        <span class="odEffects">{{ __('t_ingame.premium.choose_officer_hint') }}</span>
                    </div>

                    @foreach ($officers as $officer)
                        <div class="odPanel" id="odPanel-{{ $officer['officer'] }}" style="display: none;">
                            <span class="odName">{{ __('t_ingame.premium.officer_' . $officer['officer']) }}</span>

                            @if ($officer['active'])
                                <span class="odOwned">{{ __('t_ingame.premium.already_owned', ['date' => $officer['expires_at']->format('d/m/Y H:i')]) }}</span>
                            @endif

                            <div class="odBuy">
                                @foreach ($officer['prices'] as $days => $price)
                                    <form method="POST" action="{{ route('premium.hire') }}">
                                        @csrf
                                        <input type="hidden" name="officer" value="{{ $officer['officer'] }}">
                                        <input type="hidden" name="days" value="{{ $days }}">
                                        <button type="submit" class="hireBtn" {{ $darkMatter < $price ? 'disabled' : '' }}>{{ __('t_ingame.premium.officer_buy_for', ['days' => $days, 'price' => number_format($price, 0, ',', ' ')]) }}</button>
                                    </form>
                                @endforeach
                            </div>

                            @if ($darkMatter < min($officer['prices']))
                                <span class="odTooPoor">{{ __('t_ingame.premium.not_enough') }}</span>
                            @endif
                        </div>
                    @endforeach

                    <div class="odPanel" id="odPanel-staff" style="display: none;">
                        <span class="odName">{{ __('t_ingame.premium.info_commanding_staff') }}</span>
                        <span class="odEffects">{{ __('t_ingame.premium.staff_explained', ['current' => $activeCount, 'max' => $totalOfficers]) }}</span>
                        @if ($activeCount === $totalOfficers)
                            <span class="odOwned">{{ __('t_ingame.premium.benefit_resources') }}</span>
                        @endif
                    </div>
                </div>

                <br class="clearfloat">
                <div class="footer"></div>
            </div>
        </div>
    </div>

    <div style="clear: both; height: 1px;"></div>

    <script>
        // Un clic sur un portrait affiche le panneau correspondant sous la rangee,
        // et bascule la ligne de bonus entre coche verte et croix rouge.
        function showOfficerEffects(link) {
            var cible = link.getAttribute('data-panel');
            var panneaux = document.querySelectorAll('#officerDetail .odPanel');
            for (var i = 0; i < panneaux.length; i++) {
                panneaux[i].style.display = (panneaux[i].id === 'odPanel-' + cible) ? 'block' : 'none';
            }

            var box = document.getElementById('officerBenefits');
            var text = document.getElementById('officerBenefitsText');
            if (box && text) {
                box.classList.remove('noIcon');
                text.textContent = link.getAttribute('data-effects') || '';
                if (link.getAttribute('data-active') === '1') {
                    box.classList.remove('off');
                } else {
                    box.classList.add('off');
                }
            }
        }
    </script>

@endsection
