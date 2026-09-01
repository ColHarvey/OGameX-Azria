{{--
    Indicateur de provocation.

    Un systeme de PNJ hostiles qui avance masque produit de la frustration : le joueur subit
    sans comprendre, et conclut que le jeu est injuste. Tout ce que le serveur sait de sa
    situation est donc affiche ici — sa jauge, son plafond reel, la base la plus proche, et
    quand la menace redescendra.

    Aucun style n'est invente. L'encadre reprend la structure content-box-s employee par les
    pages Chantier et Installations ; la jauge reprend bar_container et filllevel_bar, celles
    de la soute sur cette meme page ; les couleurs sont les trois niveaux de gravite du jeu,
    undermark, middlemark et overmark. Le panneau suit donc le theme du joueur, y compris
    s'il en change.
--}}
@if ($npcThreat['visible'])
    <div id="npcthreatcomponent" class="npcthreat injectedComponent parent">
        <div class="content-box-s">
            <div class="header">
                <h3>{{ __('t_ingame.npc.threat_title') }}</h3>
            </div>
            <div class="content">

                <div id="npcThreatGauge">
                    {{ __('t_ingame.npc.threat_level') }} :
                    <div class="fleft bar_container">
                        <div class="filllevel_bar filllevel_{{ $npcThreat['mark'] }}" style="width: {{ $npcThreat['percent'] }}%;"></div>
                    </div>
                    <span class="{{ $npcThreat['mark'] }}">{{ $npcThreat['value'] }}</span> / {{ $npcThreat['ceiling'] }}
                    &mdash; <span class="{{ $npcThreat['mark'] }}">{{ $npcThreat['band_label'] }}</span>
                </div>

                @if ($npcThreat['accumulated'] > $npcThreat['value'])
                    {{-- Le joueur a accumule plus de rancune que son exposition ne lui en fait
                         risquer. Le taire donnerait une fausse impression de securite le jour
                         ou il se rapprochera d'une base ou grandira. --}}
                    <p>{{ __('t_ingame.npc.threat_accumulated', ['accumulated' => $npcThreat['accumulated'], 'effective' => $npcThreat['value']]) }}</p>
                @endif

                <p>{{ $npcThreat['band_description'] }}</p>

                @if ($npcThreat['ceiling'] < $npcThreat['max'])
                    <p class="undermark">{{ __('t_ingame.npc.threat_capped', ['ceiling' => $npcThreat['ceiling']]) }}</p>
                @endif

                @if ($npcThreat['nearest'] !== null)
                    <p>
                        {{ __('t_ingame.npc.threat_nearest') }} :
                        <a href="{{ route('galaxy.index', ['galaxy' => $npcThreat['nearest_galaxy'], 'system' => $npcThreat['nearest_system'], 'position' => $npcThreat['nearest_position']]) }}">
                            {{ $npcThreat['nearest'] }}
                        </a>
                        &mdash; {{ __('t_ingame.npc.threat_multiplier', ['factor' => $npcThreat['proximity']]) }}
                    </p>
                @endif

                @if ($npcThreat['next_decay'] !== null)
                    <p>{{ __('t_ingame.npc.threat_next_decay') }} : {{ $npcThreat['next_decay'] }}</p>
                @endif

            </div>
            <div class="footer"></div>
        </div>
    </div>
@endif
