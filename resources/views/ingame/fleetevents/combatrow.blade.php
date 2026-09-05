@php /** @var array<string, mixed> $combat */ @endphp
{{--
    Une bataille en cours, dans le deroulant « Evenements » du jeu.

    Elle prend la forme des lignes qui l'entourent — meme tableau, memes colonnes, memes classes —
    et se deplie comme les details d'une union : une ligne de resume, puis une ligne de details que
    le bouton ouvre. Les missions ordinaires gardent leur place et leurs actions ; rien n'est cache.

    Ce que la ligne dit : le role du joueur, l'etat de la bataille, le corps vise et ses coordonnees.
    Ce qu'elle ne dit jamais : quand la bataille finit — ni instant, ni secondes restantes —, aucun
    numero de round, et rien des pertes des autres. Les pertes montrees sont **les siennes**, et
    seulement celles que le serveur a deja publiees.
--}}
<tr class="eventFleet combatEvent detailsClosed"
    id="combatRow-{{ $combat['id'] }}"
    data-combat-id="{{ $combat['id'] }}"
    data-combat-status="{{ $combat['status'] }}">
    <td class="countDown">
        <span class="{{ $combat['role'] === 'target' ? 'overmark' : 'middlemark' }} textBeefy combatEvent_state">{{ $combat['status_label'] }}</span>
    </td>
    <td class="arrivalTime combatEvent_role">{{ __('t_ingame.combat.role_' . $combat['role']) }}</td>
    <td class="missionFleet">
        <img src="/img/fleet/{{ $combat['role'] === 'target' ? 1 : 2 }}.gif" class="tooltipHTML combatEvent_icon"
             title="{{ __('t_ingame.combat.panel_title') }} | {{ $combat['status_label'] }}" alt=""/>
    </td>
    <td class="originFleet combatEvent_body">
        @if ($combat['target']['name'] !== '')
            <figure class="planetIcon planet js_hideTipOnMobile" title="{{ $combat['target']['name'] }}"></figure>{{ $combat['target']['name'] }}
        @endif
    </td>
    <td class="coordsOrigin">
        <a href="{{ route('galaxy.index', ['galaxy' => $combat['target']['galaxy'], 'system' => $combat['target']['system'], 'position' => $combat['target']['position']]) }}"
           target="_top">
            [{{ $combat['target']['galaxy'] }}:{{ $combat['target']['system'] }}:{{ $combat['target']['position'] }}]
        </a>
    </td>
    <td class="detailsFleet combatEvent_count">
        <span class="combatEvent_losses_total">{{ $combat['losses_total'] }}</span>
    </td>
    <td class="icon_movement">
        {{-- Le bouton de details, dans la forme du jeu : il ouvre la ligne qui suit. --}}
        <span class="tooltip toggleCombat" rel="combat{{ $combat['id'] }}"
              title="{{ __('t_ingame.combat.losses_title') }}">
            <a class="icon_link" href="javascript:void(0);" aria-expanded="false" aria-controls="combatDetails-{{ $combat['id'] }}">
                <img src="/img/icons/89624964d4b06356842188dba05b1b.gif" height="16" width="16" alt=""/>
            </a>
        </span>
    </td>
    <td class="destFleet"></td>
    <td class="destCoords"></td>
    <td class="sendMail"></td>
    <td class="sendProbe"></td>
    <td class="sendMail"></td>
</tr>
<tr class="partnerInfo combat{{ $combat['id'] }} combatEvent_details" id="combatDetails-{{ $combat['id'] }}" style="display: none;">
    <td colspan="12">
        <div class="combatEvent_panel">
            <div class="combatEvent_losses_title">{{ __('t_ingame.combat.losses_title') }}</div>
            @if ($combat['events'] === [])
                <p class="combatEvent_empty">{{ __('t_ingame.combat.no_losses_yet') }}</p>
            @else
                <ul class="combatEvent_losses">
                    @foreach ($combat['events'] as $perte)
                        <li data-key="{{ $perte['key'] }}" data-sequence="{{ $perte['sequence'] }}">
                            <span class="combatEvent_at">{{ date('H:i:s', $perte['at']) }}</span>
                            <span class="overmark">{{ trans_choice('t_ingame.combat.loss_line', $perte['amount'], ['amount' => number_format($perte['amount'], 0, ',', ' '), 'unit' => $perte['unit_label']]) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </td>
</tr>
