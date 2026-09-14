{{-- Un classement demande qui n est pas encore compte : le message, jamais un autre classement sous son nom
     (`HighscoreController::ajax()`). Les variables du script sont celles que posent les fragments de classement et
     que `initHighscoreContent()` lit : sans elles, une page ouverte directement sur ce type leverait une erreur. --}}
<div id="content">
    <div>
        <script type="text/javascript">
            var currentCategory = {{ $highscoreCurrentCategory }};
            var currentType = {{ $highscoreCurrentType }};
            var searchPosition = 0;
            var site = 1;
            var searchSite = 1;
            var resultsPerPage = 100;
            var searchRelId = 0;
        </script>

        <p class="textCenter" style="padding: 30px 0;">{{ __('t_ingame.highscore.statistics_not_yet_available') }}</p>
    </div>
</div>
