@extends('ingame.layouts.main')

@section('content')

    <style>
        /* Le gabarit du jeu impose #inhalt a 670px et ul#building a 640px. */
        #officerHire {
            width: 640px; margin: 4px auto 0 17px; border-collapse: collapse;
            color: #6f9fc8; font-size: 11px; line-height: 15px;
        }
        #officerHire th {
            text-align: left; padding: 5px 8px; color: #848484; font-weight: normal;
            border-bottom: 1px solid #000; background: #10181f;
        }
        #officerHire td { padding: 5px 8px; border-bottom: 1px solid #1b2129; vertical-align: middle; }
        #officerHire tr:last-child td { border-bottom: 0; }
        #officerHire .colOfficer { width: 120px; color: #ff9600; white-space: nowrap; }
        #officerHire .colStatus { width: 150px; white-space: nowrap; }
        #officerHire .colHire { width: 214px; text-align: left; white-space: nowrap; }
        #officerHire tr.isActive .colStatus { color: #9ccd41; }

        /* Bouton vert du sprite du jeu : 102x38 px a 0 -241px, survol a -104px. */
        #officerHire .hireBtn {
            display: inline-block; width: 102px; height: 38px; margin-right: 8px; padding: 0;
            background: transparent url("/img/icons/18e4684df27114667e11541e5b2ef8.png") 0 -241px no-repeat;
            border: 0; color: #fff; cursor: pointer; font-family: inherit; font-size: 11px;
            font-weight: 700; line-height: 36px; text-align: center;
            text-shadow: -1px 1px 3px #123f02;
            transition: filter .12s ease, transform .06s ease;
        }
        #officerHire .hireBtn:hover:not([disabled]) { background-position: -104px -241px; filter: brightness(1.1); }
        #officerHire .hireBtn:active:not([disabled]) { transform: translateY(1px); filter: brightness(.9); }
        #officerHire .hireBtn[disabled] { opacity: .35; cursor: default; filter: none; transform: none; }

        /* La ligne de bonus sert aussi a afficher les effets d'un officier au clic. */
        #officerBenefits { cursor: default; }
        #officerBenefits span { cursor: help; }
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

                    <li class="allOfficers {{ $activeCount === $totalOfficers ? '' : 'off' }}" id="officerBenefits">
                        <span id="officerBenefitsText" title="{{ __('t_ingame.premium.benefit_resources_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_resources') }}</span>
                    </li>
                </ul>
                <br class="clearfloat">
                <table id="officerHire">
                    <tr>
                        <th class="colOfficer">{{ __('t_ingame.premium.table_officer') }}</th>
                        <th class="colStatus">{{ __('t_ingame.premium.table_status') }}</th>
                        <th class="colHire">{{ __('t_ingame.premium.table_hire') }}</th>
                    </tr>
                    @foreach ($officers as $officer)
                        <tr class="{{ $officer['active'] ? 'isActive' : '' }}">
                            <td class="colOfficer">{{ __('t_ingame.premium.officer_' . $officer['officer']) }}</td>
                            <td class="colStatus">
                                @if ($officer['active'])
                                    {{ __('t_ingame.premium.active_until', ['date' => $officer['expires_at']->format('d/m H:i')]) }}
                                @else
                                    {{ __('t_ingame.premium.inactive') }}
                                @endif
                            </td>
                            <td class="colHire">
                                @foreach ($officer['prices'] as $days => $price)
                                    <form method="POST" action="{{ route('premium.hire') }}" style="display:inline">
                                        @csrf
                                        <input type="hidden" name="officer" value="{{ $officer['officer'] }}">
                                        <input type="hidden" name="days" value="{{ $days }}">
                                        <button type="submit" class="hireBtn" {{ $darkMatter < $price ? 'disabled' : '' }}
                                                title="{{ __('t_ingame.premium.hire_title', ['officer' => __('t_ingame.premium.officer_' . $officer['officer']), 'days' => $days, 'price' => number_format($price, 0, ',', ' ')]) }}">{{ __('t_ingame.premium.hire_button', ['days' => $days, 'price' => number_format($price, 0, ',', ' ')]) }}</button>
                                    </form>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </table>

                <br class="clearfloat">
                <div class="footer"></div>
            </div>
        </div>
    </div>

    <script>
        // Un clic sur une grosse tete affiche les effets de cet officier dans la boite
        // situee a cote du compteur. La classe "off" bascule la coche verte en croix rouge.
        function showOfficerEffects(link) {
            var box = document.getElementById('officerBenefits');
            var text = document.getElementById('officerBenefitsText');
            if (!box || !text) { return; }
            text.textContent = link.getAttribute('data-effects') || '';
            if (link.getAttribute('data-active') === '1') {
                box.classList.remove('off');
            } else {
                box.classList.add('off');
            }
        }
    </script>

@endsection
