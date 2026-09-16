{{-- La planete est tenue par une bataille non reglee : son developpement de formes de vie est suspendu jusqu a la
     resolution (journal §155.16). Le joueur le lit ici plutot que de voir une page qui semble defectueuse. Le style
     est en ligne comme celui de l attente de suppression : aucun asset a reconstruire. --}}
@if (!empty($held))
    <style>
        .lifeformHeldNotice {
            margin: 0 0 8px;
            padding: 6px 12px;
            border: 1px solid #a45c00;
            border-radius: 4px;
            background: linear-gradient(180deg, #3a2a10 0%, #2a1c08 100%);
            color: #f1c67a;
            font-size: 11px;
            line-height: 15px;
            text-align: center;
        }
        .lifeformHeldNotice strong { color: #ffd98a; }
    </style>
    <div class="lifeformHeldNotice" role="status" data-held-since="{{ $held['since'] }}">
        <strong>{{ __('t_lifeforms_ui.held.title') }}</strong>
        {{ __('t_lifeforms_ui.held.body') }}
        <span class="smallFont">{{ __('t_lifeforms_ui.held.since') }} {{ $held['since_formatted'] }}</span>
    </div>
@endif
