{{--
    Les combats durables en cours, sur la vue generale.

    Ce que le joueur voit : son role, la cible, la phase, le temps qui reste avant le prochain
    instant decide par le serveur, et ses pertes deja visibles — lues sur le fil fige, jamais
    calculees ici. Ce qu'il ne voit pas, et c'est une regle : aucune perte future, aucun numero
    de round, rien des autres participants. Le compte a rebours part de secondes restantes
    calculees par le serveur ; a zero, le panneau se redemande au serveur au lieu de deviner.

    Aucun style n'est invente : l'encadre reprend content-box-s, comme les trois boites de
    production et le panneau des PNJ juste a cote ; les couleurs sont les trois niveaux de
    gravite du jeu. Le contenu est empile, jamais mis en ligne : la boite fait 220 px.

    Le fragment entier est rendu par le serveur et remplace tel quel par le rafraichissement
    AJAX (`combat.panel`) : une seule source pour les donnees et les traductions.
--}}
<div id="combatpanelcomponent" class="combatpanel injectedComponent parent overview"
     data-refresh-url="{{ route('combat.panel') }}"
     data-server-now="{{ $combatPanel['server_now'] }}">
@if ($combatPanel['visible'])
    <div class="content-box-s">
        <div class="header">
            <h3>{{ __('t_ingame.combat.panel_title') }}</h3>
        </div>
        <div class="content">
            @foreach ($combatPanel['combats'] as $combat)
                <div class="combatpanel_combat" id="combatPanel-{{ $combat['id'] }}" data-combat-id="{{ $combat['id'] }}" data-timeline-url="{{ route('combat.timeline', ['combatId' => $combat['id']]) }}">
                    <div class="combatpanel_role {{ $combat['role'] === 'target' ? 'overmark' : ($combat['role'] === 'attacker' ? 'middlemark' : 'undermark') }}">
                        {{ __('t_ingame.combat.role_' . $combat['role']) }}
                    </div>
                    <div class="combatpanel_line">
                        {{ __('t_ingame.combat.target') }} :
                        <a href="{{ route('galaxy.index', ['galaxy' => $combat['target']['galaxy'], 'system' => $combat['target']['system'], 'position' => $combat['target']['position']]) }}">{{ $combat['target']['name'] !== '' ? $combat['target']['name'] . ' ' : '' }}[{{ $combat['target']['galaxy'] }}:{{ $combat['target']['system'] }}:{{ $combat['target']['position'] }}]</a>
                    </div>
                    <div class="combatpanel_line">
                        <span class="combatpanel_status">{{ $combat['status_label'] }}</span>
                        @if ($combat['seconds_remaining'] > 0)
                            <br>{{ $combat['countdown_label'] }}
                            <span class="combatpanel_countdown textBeefy" data-seconds="{{ $combat['seconds_remaining'] }}">{{ gmdate('H:i:s', $combat['seconds_remaining']) }}</span>
                        @endif
                    </div>
                    <div class="combatpanel_losses">
                        <div class="combatpanel_losses_title">{{ __('t_ingame.combat.losses_title') }}</div>
                        @if ($combat['events'] === [])
                            <p class="combatpanel_empty">{{ __('t_ingame.combat.no_losses_yet') }}</p>
                        @else
                            <ul>
                                @foreach ($combat['events'] as $perte)
                                    <li data-sequence="{{ $perte['sequence'] }}">
                                        <span class="combatpanel_at">{{ date('H:i:s', $perte['at']) }}</span>
                                        <span class="overmark">{{ __('t_ingame.combat.loss_line', ['amount' => number_format($perte['amount'], 0, ',', ' '), 'unit' => $perte['unit_label']]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
</div>
