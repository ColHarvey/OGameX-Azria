@extends('ingame.layouts.main')

@section('content')

    <style>
        /* Tableau de recrutement : reprend les couleurs du jeu plutot que d'introduire
           une charte etrangere. */
        #officerHire { width: 100%; margin: 18px 0 0; border-collapse: collapse; color: #6f9fc8; font-size: 11px; }
        #officerHire th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #000; color: #848484; font-weight: normal; }
        #officerHire td { padding: 6px 8px; border-bottom: 1px solid #1b2129; vertical-align: middle; }
        #officerHire tr.active td { color: #9ccd41; }
        #officerHire .officerName { color: #ff9600; }
        #officerHire .price { white-space: nowrap; }
        #officerHire button {
            background: #22303f; border: 1px solid #3b4d5f; border-radius: 3px; color: #fff;
            cursor: pointer; font-family: inherit; font-size: 11px; padding: 3px 10px; white-space: nowrap;
            transition: filter .12s ease, transform .06s ease;
        }
        #officerHire button:hover { filter: brightness(1.35); }
        #officerHire button:active { transform: translateY(1px); filter: brightness(.9); }
        #officerHire button[disabled] { opacity: .4; cursor: default; filter: none; transform: none; }
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
                    <div class="alert alert-success" style="margin: 4px 0 14px;">{{ session('status') }}</div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger" style="margin: 4px 0 14px;">{{ session('error') }}</div>
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
                                <a tabindex="12" href="javascript:void(0);" title="{{ __('t_ingame.premium.info_commanding_staff') }}" class="detail_button tooltip js_hideTipOnMobile">
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
                            <div class="remaining tooltip" title="">
                                <span class="remDate">{{ __('t_ingame.premium.remaining_officers', ['current' => $activeCount, 'max' => $totalOfficers]) }}</span>
                            </div>
                        </div>
                    </li>

                    <li class="allOfficers {{ $activeCount === $totalOfficers ? '' : 'off' }}">
                        <span title="{{ __('t_ingame.premium.benefit_fleet_slots_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_fleet_slots') }}</span><span title="{{ __('t_ingame.premium.benefit_energy_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_energy') }}</span><span title="{{ __('t_ingame.premium.benefit_mines_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_mines') }}</span><span title="{{ __('t_ingame.premium.benefit_espionage_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_espionage') }}</span>
                    </li>
                </ul>
                <br class="clearfloat">

                <table id="officerHire">
                    <tr>
                        <th>{{ __('t_ingame.premium.table_officer') }}</th>
                        <th>{{ __('t_ingame.premium.table_effects') }}</th>
                        <th>{{ __('t_ingame.premium.table_status') }}</th>
                        <th colspan="2">{{ __('t_ingame.premium.table_hire') }}</th>
                    </tr>
                    @foreach ($officers as $officer)
                        <tr class="{{ $officer['active'] ? 'active' : '' }}">
                            <td class="officerName">{{ __('t_ingame.premium.officer_' . $officer['officer']) }}</td>
                            <td>{{ __('t_ingame.premium.effects_' . $officer['officer']) }}</td>
                            <td>
                                @if ($officer['active'])
                                    {{ __('t_ingame.premium.active_until', ['date' => $officer['expires_at']->format('d/m H:i')]) }}
                                @else
                                    {{ __('t_ingame.premium.inactive') }}
                                @endif
                            </td>
                            @foreach ($officer['prices'] as $days => $price)
                                <td class="price">
                                    <form method="POST" action="{{ route('premium.hire') }}" style="display:inline">
                                        @csrf
                                        <input type="hidden" name="officer" value="{{ $officer['officer'] }}">
                                        <input type="hidden" name="days" value="{{ $days }}">
                                        <button type="submit" {{ $darkMatter < $price ? 'disabled' : '' }}>
                                            {{ __('t_ingame.premium.hire_button', ['days' => $days, 'price' => number_format($price, 0, ',', ' ')]) }}
                                        </button>
                                    </form>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>

                <div class="footer"></div>
            </div>
        </div>
    </div>

@endsection
