{{-- Ce que la page dit d un cumul militaire : depuis quand il couvre, quand il a ete publie pour la derniere fois,
     que la somme d une alliance suit ses membres actuels, et que ses donnees sont temporairement incompletes tant que
     des evenements attendent d etre comptes. La note est composee par le controleur
     (`HighscoreController::militaryTallyNoteFor()`) : nulle pour tout autre classement, nulle aussi tant que la
     collecte n est pas activee ou qu aucune publication n a eu lieu. --}}
@if ($militaryTallyNote !== null)
    <div class="military-tally-note" style="clear: both; padding: 4px 0 8px;">
        {{ __('t_ingame.highscore.cumulative_since', ['date' => $militaryTallyNote['since']]) }}
        <br>{{ __('t_ingame.highscore.refreshed_at', ['date' => $militaryTallyNote['refreshed']]) }}
        @if ($isAllianceRanking)
            <br>{{ __('t_ingame.highscore.alliance_sum_current_members') }}
        @endif
        @if ($militaryTallyNote['pending'] > 0)
            <br><span class="overmark">{{ __('t_ingame.highscore.data_temporarily_incomplete') }}</span>
        @endif
    </div>
@endif
