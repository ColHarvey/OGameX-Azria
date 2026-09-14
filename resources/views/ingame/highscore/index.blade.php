@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <script type="text/javascript">
        highscoreContentUrl = '{{ route('highscore.ajax') }}';
        var userWantsFocus = true;
        var highscoreLoca = {!! json_encode([
            'playerHighscore'  => __('t_ingame.highscore.player_highscore'),
            'allianceHighscore'=> __('t_ingame.highscore.alliance_highscore'),
        ]) !!};
    </script>

    <div id="inhalt">
        <div id="highscoreContent">
            <div class="header">
                <h2>{{ __('t_ingame.highscore.player_highscore') }}</h2>
            </div>
            <div class="content">
                <form id="send" name="send" action="#highscore">
                    <div id="scrollToTop" style="left: 760.336px;"><a href="javascript:void(0);" title="{{ __('t_ingame.layout.back_to_top') }}" class="scrollToTop tooltip js_hideTipOnMobile"></a></div>
                    <div id="row">

                        <div class="buttons leftCol" id="categoryButtons">
                            <a id="player" class="active navButton" href="javascript:void(0);" rel="1" onclick="">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.player_highscore') }}</span>
                            </a>
                            <a id="alliance" class="navButton" href="javascript:void(0);" rel="2" onclick="">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.alliance_highscore') }}</span>
                            </a>
                        </div>

                        <div class="buttons rightCol" id="typeButtons">

                            <a id="points" class="stat_filter active navButton fleft" href="javascript:void(0);" rel="0">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.points') }}</span>
                            </a>

                            <a id="economy" class="stat_filter navButton fleft" href="javascript:void(0);" rel="1">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.economy') }}</span>
                            </a>

                            <a id="research" class="stat_filter navButton fleft" href="javascript:void(0);" rel="2">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.research') }}</span>
                            </a>

                            <a id="fleet" class="stat_filter navButton fleft" href="javascript:void(0);" rel="3">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.military') }}</span>
                            </a>

                            <div id="subnav_fleet" class="fleft subnav">
                                {{-- **Trois statistiques ne sont pas encore comptees** : rien ne cumule les points militaires construits,
                                     detruits et perdus. Leurs boutons restent visibles mais desactives — ce ne sont pas des liens, le
                                     script du classement n ecoute que `a.subnavButton`, ils n envoient donc rien et ne rendent jamais un
                                     autre classement sous leur nom (decision de Keven, 13 septembre 2026). --}}
                                <span class="subnavButton subnavButton_built tooltip js_hideTipOnMobile" aria-disabled="true" style="opacity: 0.35; cursor: default;" title="{{ __('t_ingame.highscore.military_built') }} — {{ __('t_ingame.highscore.statistics_not_yet_available') }}">
                                    <span class="small-marker"></span>
                                </span>


                                <span class="subnavButton subnavButton_destroyed tooltip js_hideTipOnMobile" aria-disabled="true" style="opacity: 0.35; cursor: default;" title="{{ __('t_ingame.highscore.military_destroyed') }} — {{ __('t_ingame.highscore.statistics_not_yet_available') }}">
                                    <span class="small-marker"></span>
                                </span>

                                <span class="subnavButton subnavButton_lost tooltip js_hideTipOnMobile" aria-disabled="true" style="opacity: 0.35; cursor: default;" title="{{ __('t_ingame.highscore.military_lost') }} — {{ __('t_ingame.highscore.statistics_not_yet_available') }}">
                                    <span class="small-marker"></span>
                                </span>

                                <a href="javascript:void(0);" rel="4" class="subnavButton subnavButton_honor tooltip js_hideTipOnMobile" title="{{ __('t_ingame.highscore.honour_points') }}">
                                    <span class="small-marker"></span>
                                </a>
                            </div>
                        </div>

                        <br class="clearfloat">
                    </div>

                    <div class="" id="stat_list_content">
                        <!-- start dynamic highscore paging content -->
                        {!! $initialContent !!}
                        <!-- end dynamic highscore paging content -->
                    </div>
                </form>
            </div>
            <div class="footer"></div>
        </div>
    </div>

@endsection
