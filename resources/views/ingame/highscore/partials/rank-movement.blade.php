{{-- La colonne de variation d une ligne de classement : ce que le rang a fait depuis la reference publiee.

     Le serveur a deja tout decide (`HighscoreService::movementRow()`, depuis `RankMovement`) : cet inclus ne fait
     que choisir une forme. Cinq cas, et un sixieme silencieux.

     - `null` : la ligne n est pas classee — ligne de faction, compte exclu. La cellule reste vide, comme Keven
       l a demande : pas de variation pour ce qui n a pas de rang.
     - `unavailable` : aucune reference n a encore ete publiee pour cette categorie. Un tiret discret, et
       l explication dans l infobulle : la colonne fait quarante pixels, « Reference indisponible » n y tiendrait
       pas. Surtout, ce n est PAS « Nouv. » — le premier jour, personne n entre, on ignore simplement d ou l on
       vient.
     - `new` : la reference existe, le sujet n y figurait pas. Une vraie entree neuve.
     - `stable`, `up`, `down` : les icones du jeu, deja presentes, avec le nombre de places entre parentheses
       comme le gabarit d origine le posait. La couleur vient de `undermark` (vert) et `overmark` (rouge), tous
       deux definis en `!important` dans la feuille du jeu.

     Les icones ne portent pas de texte de remplacement : l information est dans le libelle accessible du
     conteneur, et la repeter la ferait lire deux fois. --}}
@if (!empty($movement))
    @php
        $mouvementLibelle = match ($movement['state']) {
            'up' => trans_choice('t_ingame.highscore.rank_movement_up', $movement['places'], ['places' => $movement['places'], 'date' => $movement['reference_formatted']]),
            'down' => trans_choice('t_ingame.highscore.rank_movement_down', $movement['places'], ['places' => $movement['places'], 'date' => $movement['reference_formatted']]),
            'stable' => __('t_ingame.highscore.rank_movement_stable', ['date' => $movement['reference_formatted']]),
            'new' => __('t_ingame.highscore.rank_movement_new_tooltip', ['date' => $movement['reference_formatted']]),
            default => __('t_ingame.highscore.rank_movement_unavailable'),
        };
    @endphp
    @if ($movement['state'] === 'unavailable')
        <span class="tooltip js_hideTipOnMobile" title="{{ $mouvementLibelle }}" aria-label="{{ $mouvementLibelle }}">&ndash;</span>
    @elseif ($movement['state'] === 'new')
        <span class="middlemark tooltip js_hideTipOnMobile" title="{{ $mouvementLibelle }}" aria-label="{{ $mouvementLibelle }}">{{ __('t_ingame.highscore.rank_movement_new') }}</span>
    @elseif ($movement['state'] === 'stable')
        <span class="tooltip js_hideTipOnMobile" title="{{ $mouvementLibelle }}" aria-label="{{ $mouvementLibelle }}">
            <img src="/img/icons/ea5bf2cc93e52e22e3c1b80c7f7563.gif" alt="">
        </span>
    @elseif ($movement['state'] === 'up')
        <span class="undermark tooltip js_hideTipOnMobile" title="{{ $mouvementLibelle }}" aria-label="{{ $mouvementLibelle }}">
            <img src="/img/icons/1c7545144452ec3e38c9fba216c4f9.gif" alt="">
            <span class="stats_counter">({{ $movement['places'] }})</span>
        </span>
    @else
        <span class="overmark tooltip js_hideTipOnMobile" title="{{ $mouvementLibelle }}" aria-label="{{ $mouvementLibelle }}">
            <img src="/img/icons/7e6b4e65bec62ac2f10ea24ba76c51.gif" alt="">
            <span class="stats_counter">({{ $movement['places'] }})</span>
        </span>
    @endif
@endif
