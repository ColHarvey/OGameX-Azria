{{--
    Indicateur de provocation, affiche sur la vue generale.

    Un systeme de PNJ hostiles qui avance masque produit de la frustration : le joueur subit
    sans comprendre, et conclut que le jeu est injuste. Tout ce que le serveur sait de sa
    situation est donc affiche ici — sa jauge, son plafond reel, la base la plus proche, et
    quand la menace redescendra.

    Aucun style n'est invente. L'encadre reprend content-box-s, l'encadre des trois boites de
    production juste au-dessus ; la jauge reprend bar_container et filllevel_bar ; les
    couleurs sont les trois niveaux de gravite du jeu, undermark, middlemark et overmark. Le
    panneau suit donc le theme du joueur, y compris s'il en change.

    La boite du theme fait 220 px de large et coupe ce qui depasse. Le contenu est donc
    empile, jamais mis en ligne : c'est le melange d'une jauge large et d'un encadre etroit
    qui faisait deborder la premiere version.
--}}
@if ($npcThreat['visible'])
    <div id="npcthreatcomponent" class="npcthreat injectedComponent parent overview">
        <div class="content-box-s">
            <div class="header">
                <h3>{{ __('t_ingame.npc.threat_title') }}</h3>
            </div>
            <div class="content">

                <div id="npcThreatGauge">
                    <div class="npcthreat_line">
                        {{ __('t_ingame.npc.threat_level') }} :
                        <span class="{{ $npcThreat['mark'] }}">{{ $npcThreat['value'] }}</span> / {{ $npcThreat['ceiling'] }}
                    </div>
                    <div class="bar_container">
                        <div class="filllevel_bar filllevel_{{ $npcThreat['mark'] }}" style="width: {{ $npcThreat['percent'] }}%;"></div>
                    </div>
                    <div class="npcthreat_band {{ $npcThreat['mark'] }}">{{ $npcThreat['band_label'] }}</div>
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
                        <a href="{{ route('galaxy.index', ['galaxy' => $npcThreat['nearest_galaxy'], 'system' => $npcThreat['nearest_system'], 'position' => $npcThreat['nearest_position']]) }}">{{ $npcThreat['nearest'] }}</a>
                        <br>{{ __('t_ingame.npc.threat_multiplier', ['factor' => $npcThreat['proximity']]) }}
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
