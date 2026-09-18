@php($deuxPoints = __('t_ingame.messages.label_colon'))
{{-- Les donnees brutes du gabarit officiel (joueurs, alliances, flottes et rounds inventes) ne sont plus servies (journal §161). --}}
<div class="rawMessageData">
</div>

<div class="combatInfo">
        <div class="basicInfo">
            <span class="msg_ctn msg_ctn2 {{ $attacker_class }} tooltipLeft" data-tooltip-title="12,000">{{ __('t_ingame.messages.battle_attacker') }}{{ $deuxPoints }} ({{ $attacker_name }}){{ $deuxPoints }} {{ $attacker_losses }}</span>
            <span class="msg_ctn msg_ctn3">{{ __('t_ingame.messages.battle_resources') }}{{ $deuxPoints }} {{ $loot }}, {{ __('t_ingame.messages.battle_loot') }}{{ $deuxPoints }} {{ $loot_percentage }}%</span>
            <span class="msg_ctn msg_ctn3 tooltipLeft" data-tooltip-title="{{ $debris_sum_formatted }}">{{ __('t_ingame.messages.battle_debris_new') }}{{ $deuxPoints }} {{ $debris_sum_formatted }}</span>
        </div>
        <div class="miscInfo">
            <span class="msg_ctn msg_ctn2 {{ $defender_class }} tooltipRight" data-tooltip-title="10,210,000">{{ __('t_ingame.messages.battle_defender') }}{{ $deuxPoints }} ({{ $defender_name }}){{ $deuxPoints }} {{ $defender_losses }}</span>
            <span class="msg_ctn msg_ctn3 tooltipRight" data-tooltip-title="1,539">{{ __('t_ingame.messages.battle_repaired') }}{{ $deuxPoints }} {{ $repaired_defenses_count }}</span>
@if (!$moon_existed)
            <span class="msg_ctn msg_ctn3 tooltipRight" data-tooltip-title="1,539">{{ __('t_ingame.messages.battle_moon_chance') }}{{ $deuxPoints }} {{ $moon_chance }}%</span>
@endif
        </div>
    </div>

{{--
    Recit d'un raid de faction hostile.

    Le bloc n'apparait que si la cle npc_motive est presente dans le rapport, ce qui n'est
    le cas que pour les combats declenches par une faction hostile. Aucun affrontement
    entre joueurs ne l'affiche.

    Il arrive apres les chiffres, et volontairement : rien n'annonce un raid avant qu'il
    n'ait lieu, l'explication vient une fois que tout est joue.
--}}
@if (!empty($npc_narrative))
    <div class="combatInfo">
        <div class="basicInfo">
            <span class="msg_ctn msg_ctn3">{{ $npc_narrative }}</span>
@if (!empty($npc_crew))
            <span class="msg_ctn msg_ctn3">{{ __('t_messages.npc_raid.origin', ['crew' => $npc_crew]) }}</span>
@endif
        </div>
    </div>
@endif
