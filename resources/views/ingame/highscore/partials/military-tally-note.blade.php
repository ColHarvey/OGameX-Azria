{{-- Ce que la page dit d un cumul militaire : depuis quand il couvre, que la somme d une alliance suit ses membres
     actuels, et que ses donnees sont temporairement incompletes tant que des evenements attendent d etre comptes.
     La note est composee par le controleur (`HighscoreController::militaryTallyNoteFor()`) : nulle pour tout autre
     classement, nulle aussi tant que la collecte n est pas activee. --}}
@if ($militaryTallyNote !== null)
    <div class="military-tally-note" style="clear: both; padding: 4px 0 8px;">
        {{ __('t_ingame.highscore.cumulative_since', ['date' => $militaryTallyNote['since']]) }}
        @if ($isAllianceRanking)
            <br>{{ __('t_ingame.highscore.alliance_sum_current_members') }}
        @endif
        @if ($militaryTallyNote['pending'] > 0)
            <br><span class="overmark">{{ __('t_ingame.highscore.data_temporarily_incomplete') }}</span>
        @endif
    </div>
@endif
