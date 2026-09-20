<div id="content">
    <div>
        <script type="text/javascript">
            var currentCategory = 2;
            var currentType = {{ $highscoreCurrentType }};
            {{-- La ligne a recentrer : l alliance cherchee, sinon la mienne, sinon aucune (0 ne designe aucune ligne). --}}
            var searchPosition = {{ $highscoreFocusAllianceId }};
            var site = {{ $highscoreCurrentPage }};
            var searchSite = {{ $highscoreCurrentPage }};
            var resultsPerPage = 100;
            var searchRelId = {{ $highscoreFocusAllianceId }};
        </script>

        <div class="pagebar">
            @if ($highscoreCurrentPage > 1)
                <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => 1, 'type' => $highscoreCurrentType, 'category' => 2]) }}', '#stat_list_content'); return false;">«</a>&nbsp;
            @endif
            @for ($i = 1; $i <= ceil($highscoreAllianceAmount / 100); $i++)
                @if ($highscoreCurrentPage == $i)
                    <span class=" activePager">{{ $i }}</span>
                @else
                    <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => $i, 'type' => $highscoreCurrentType, 'category' => 2]) }}', '#stat_list_content'); return false;">
                        {{ $i }}
                    </a>
                @endif
                &nbsp;
            @endfor
            @if ($highscoreCurrentPage < ceil($highscoreAllianceAmount / 100))
                <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => ceil($highscoreAllianceAmount / 100), 'type' => $highscoreCurrentType, 'category' => 2]) }}', '#stat_list_content'); return false;">»</a>
            @endif
        </div>
        <select class="changeSite fright">
            @if($currentUserAllianceId)
                <option value="{{ $highscoreCurrentAlliancePage }}">{{ __('t_ingame.highscore.own_position') }}</option>
            @endif
            @for ($i = 1; $i <= ceil($highscoreAllianceAmount / 100); $i++)
                <option {{ $i == $highscoreCurrentPage ? 'selected="selected"' : '' }} value="{{ $i }}"> {{ ((($i-1) * 100) + 1)  }} - {{ $i * 100 }}</option>
            @endfor
        </select>
        <div class="fleft" id="highscoreHeadline">
            @if($highscoreCurrentType == 0)
                {{ __('t_ingame.highscore.points') }}
            @elseif($highscoreCurrentType == 1)
                {{ __('t_ingame.highscore.economy') }}
            @elseif($highscoreCurrentType == 2)
                {{ __('t_ingame.highscore.research') }}
            @elseif($highscoreCurrentType == 3)
                {{ __('t_ingame.highscore.military') }}
            @elseif($highscoreCurrentType == 4)
                {{ __('t_ingame.highscore.honour_points') }}
            @elseif($highscoreCurrentType == 5)
                {{ __('t_ingame.highscore.military_built') }}
            @elseif($highscoreCurrentType == 6)
                {{ __('t_ingame.highscore.military_destroyed') }}
            @elseif($highscoreCurrentType == 7)
                {{ __('t_ingame.highscore.military_lost') }}
            @endif
        </div>

        @include('ingame.highscore.partials.military-tally-note', ['isAllianceRanking' => true])

        <table id="ranks" class="allyHighscore">
            <thead>
            <tr>
                <td class="position">
                    {{ __('t_ingame.highscore.position') }}
                </td>
                <td class="movement"></td>
                <td class="name">
                    {{ __('t_ingame.highscore.alliance') }}
                </td>
                <td class="member_count" align="center">
                    {{ __('t_ingame.highscore.member') }}
                </td>
                <td align="center" class="score tooltip js_hideTipOnMobile" title="{{ __('t_ingame.highscore.average_points') }}">
                    {{ __('t_ingame.highscore.points') }}
                </td>
            </tr>
            </thead>
            <tbody>
            @forelse ($highscoreAlliances as $highscoreAlliance)
                <tr class="{{ $highscoreAlliance['id'] == $currentUserAllianceId ? 'myrank' : '' }} {{ $highscoreAlliance['rank'] % 2 == 0 ? 'alt' : '' }}" id="position{{ $highscoreAlliance['id'] }}">
                    <td class="position">
                        {{ $highscoreAlliance['rank'] }}
                    </td>

                    <td class="movement">
                        <img src="/img/icons/ea5bf2cc93e52e22e3c1b80c7f7563.gif" alt="stay">
                    </td>

                    <td class="name">
                        <div class="ally-name">
                            <span>{{ $highscoreAlliance['name'] }}</span>
                        </div>
                        <div class="ally-tag">
                            <a href="{{ route('alliance.info', ['alliance_id' => $highscoreAlliance['id']]) }}" target="_blank" class="txt_link">[{{ $highscoreAlliance['tag'] }}]</a>
                        </div>
                    </td>

                    <td class="member_count" align="center">
                        {{ $highscoreAlliance['member_count'] }}
                    </td>

                    <td class="score">
                        @if($highscoreCurrentType == 0 && ($highscoreAlliance['lifeform_points'] ?? 0) > 0)
                            {{-- Le meme detail que pour un joueur, sur le score general de l alliance. --}}
                            @php $idDetail = 'lifeformShareAlliance-' . $highscoreAlliance['id']; @endphp
                            <span class="tooltip tooltipFocusable" tabindex="0" aria-describedby="{{ $idDetail }}"
                                  title="{{ __('t_ingame.highscore.lifeform_share', ['points' => \OGame\Facades\AppUtil::formatNumber($highscoreAlliance['lifeform_points'])]) }}<br/>{{ __('t_ingame.highscore.lifeform_economy') }} : {{ \OGame\Facades\AppUtil::formatNumber($highscoreAlliance['lifeform_economy_points']) }}<br/>{{ __('t_ingame.highscore.lifeform_technology') }} : {{ \OGame\Facades\AppUtil::formatNumber($highscoreAlliance['lifeform_technology_points']) }}">
                                {{ $highscoreAlliance['points_formatted'] }}
                            </span>
                            <span id="{{ $idDetail }}" class="ui-helper-hidden-accessible">{{ __('t_ingame.highscore.lifeform_share', ['points' => \OGame\Facades\AppUtil::formatNumber($highscoreAlliance['lifeform_points'])]) }} — {{ __('t_ingame.highscore.lifeform_economy') }} : {{ \OGame\Facades\AppUtil::formatNumber($highscoreAlliance['lifeform_economy_points']) }} — {{ __('t_ingame.highscore.lifeform_technology') }} : {{ \OGame\Facades\AppUtil::formatNumber($highscoreAlliance['lifeform_technology_points']) }}</span>
                        @else
                            {{ $highscoreAlliance['points_formatted'] }}
                        @endif
                        <div class="small">ø{{ $highscoreAlliance['average_points_formatted'] }}</div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center;">{{ __('t_ingame.highscore.no_alliances_found') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <script type="text/javascript">
            $(document).ready(function(){
                // Memes regles que le classement des joueurs : un seul jeu d ecouteurs sur les boutons, une seule
                // initialisation par fragment, et le recentrage au premier chargement ou sur « Ma position » seulement.
                if (!window.highscoreInitialised) {
                    var initialiserLeContenu = initHighscoreContent;
                    initHighscoreContent = function () {
                        if (window.highscoreContentInitialised) {
                            return;
                        }
                        window.highscoreContentInitialised = true;
                        initialiserLeContenu();
                    };
                    initHighscore();
                    window.highscoreInitialised = true;
                }
                window.highscoreContentInitialised = false;
                $('.changeSite').on('change', function () {
                    userWantsFocus = this.selectedIndex === 0;
                });
                initHighscoreContent();
                userWantsFocus = false;
            });
        </script>
        <div class="pagebar">
            <a href="javascript:void(0);" class="scrollToTop">{{ __('t_ingame.layout.back_to_top') }}</a>
            &nbsp;
            @if ($highscoreCurrentPage > 1)
                <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => 1, 'type' => $highscoreCurrentType, 'category' => 2]) }}', '#stat_list_content'); return false;">«</a>&nbsp;
            @endif
            @for ($i = 1; $i <= ceil($highscoreAllianceAmount / 100); $i++)
                @if ($highscoreCurrentPage == $i)
                    <span class=" activePager">{{ $i }}</span>
                @else
                    <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => $i, 'type' => $highscoreCurrentType, 'category' => 2]) }}', '#stat_list_content'); return false;">
                        {{ $i }}
                    </a>
                @endif
                &nbsp;
            @endfor
            @if ($highscoreCurrentPage < ceil($highscoreAllianceAmount / 100))
                <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => ceil($highscoreAllianceAmount / 100), 'type' => $highscoreCurrentType, 'category' => 2]) }}', '#stat_list_content'); return false;">»</a>
            @endif
        </div>
    </div>
</div>
