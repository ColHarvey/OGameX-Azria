{{-- Ce que la page dit d un cumul militaire : depuis quand il couvre, quand il a ete publie pour la derniere fois,
     que la somme d une alliance suit ses membres actuels, et que ses donnees sont temporairement incompletes tant que
     des evenements attendent d etre comptes. La note est composee par le controleur
     (`HighscoreController::militaryTallyNoteFor()`) : nulle pour tout autre classement, nulle aussi tant que la
     collecte n est pas activee ou qu aucune publication n a eu lieu.

     Une bande sobre dans les tons du jeu, une ligne quand la place le permet, qui se replie sur les petits ecrans ;
     l avertissement d incompletude prend sa propre ligne, dans la couleur d alerte du jeu. Les instants sont ecrits
     en heure du serveur, puis reecrits a l heure du navigateur, comme l horloge du bandeau : sans script, le texte du
     serveur reste. --}}
@if ($militaryTallyNote !== null)
    <div class="military-tally-note" style="clear: both; display: flex; flex-wrap: wrap; align-items: baseline; gap: 3px 14px; margin: 6px 0 10px; padding: 7px 12px; border: 1px solid #2b3945; border-left: 3px solid #6c9bc4; border-radius: 3px; background: linear-gradient(180deg, rgba(22, 32, 42, 0.9), rgba(10, 15, 21, 0.9)); color: #b7c4cf; font-size: 11px; line-height: 1.5;">
        <span style="color: #eef2f5; font-weight: bold; font-size: 10px; letter-spacing: 0.06em; text-transform: uppercase;">{{ __('t_ingame.highscore.military_tally_label') }}</span>
        <span data-military-tally-instant="{{ $militaryTallyNote['since_at'] }}" data-military-tally-shape="date" data-military-tally-text="{{ __('t_ingame.highscore.cumulative_since', ['date' => '__DATE__']) }}">{{ __('t_ingame.highscore.cumulative_since', ['date' => $militaryTallyNote['since']]) }}</span>
        <span aria-hidden="true" style="color: #55687a;">&middot;</span>
        <span data-military-tally-instant="{{ $militaryTallyNote['refreshed_at'] }}" data-military-tally-shape="datetime" data-military-tally-text="{{ __('t_ingame.highscore.refreshed_at', ['date' => '__DATE__']) }}">{{ __('t_ingame.highscore.refreshed_at', ['date' => $militaryTallyNote['refreshed']]) }}</span>
        @if ($isAllianceRanking)
            <span aria-hidden="true" style="color: #55687a;">&middot;</span>
            <span style="color: #93a4b3;">{{ __('t_ingame.highscore.alliance_sum_current_members') }}</span>
        @endif
        @if ($militaryTallyNote['pending'] > 0)
            <span class="overmark" style="flex-basis: 100%;">{{ __('t_ingame.highscore.data_temporarily_incomplete') }}</span>
        @endif
    </div>
    <script type="text/javascript">
        (function () {
            var deux = function (n) { return (n < 10 ? '0' : '') + n; };
            var elements = document.querySelectorAll('.military-tally-note [data-military-tally-instant]');
            for (var i = 0; i < elements.length; i++) {
                var element = elements[i];
                var instant = new Date(parseInt(element.getAttribute('data-military-tally-instant'), 10) * 1000);
                if (isNaN(instant.getTime())) {
                    continue;
                }
                var date = deux(instant.getDate()) + '.' + deux(instant.getMonth() + 1) + '.' + instant.getFullYear();
                if (element.getAttribute('data-military-tally-shape') === 'datetime') {
                    date += ' ' + deux(instant.getHours()) + ':' + deux(instant.getMinutes()) + ':' + deux(instant.getSeconds());
                }
                element.textContent = element.getAttribute('data-military-tally-text').replace('__DATE__', date);
            }
        })();
    </script>
@endif
