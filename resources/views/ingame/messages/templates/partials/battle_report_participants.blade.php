{{-- L en-tete d un camp du rapport de combat (journal §161) : le choix du membre que le script officiel lit
     (`#<side>_select_combatreport`, `.participant_select` — une liste des qu un camp compte plusieurs flottes, la
     garnison comprise), puis, quand le rapport porte le bloc gele, une ligne par participant avec ses propres niveaux,
     sa classe et la part des formes de vie par type d unite. Un rapport ancien n a qu un membre par camp et aucune
     ligne : rien n est invente. --}}
<div class="common_info">
@if (count($members) > 1)
    <select id="{{ $side }}_select_combatreport" class="participant_select" data-member-name="all">
        <option value="all">{{ __('t_ingame.messages.battle_all_participants') }}</option>
        @foreach ($members as $member)
            <option value="{{ $member['name'] }}" data-coords="{{ $member['coords'] }}" data-planettype="{{ $member['planet_type'] }}">{{ $member['label'] }}@if ($member['kind'] === 'garrison') ({{ __('t_ingame.messages.battle_garrison') }})@endif</option>
        @endforeach
    </select>
@else
    <span id="{{ $side }}_select_combatreport" data-member-name="{{ $members[0]['name'] ?? '' }}">
        @if ($headlineTooltip !== null)
            <span class="tooltip js_hideTipOnMobile" data-tooltip-title="{{ $headlineTooltip }}">{{ $headline }}</span>
        @else
            <span>{{ $headline }}</span>
        @endif
    </span>
@endif
    <span class="participant_label {{ $labelClass }}">{{ $label }}:</span>
</div>
<br class="clearfloat">
@if ($frozen)
    <ul class="common_info fleft az-participants">
        <li class="az-participants-title">{{ __('t_ingame.messages.battle_participants') }}:</li>
        @foreach ($members as $member)
            <li class="az-participant">
                <span class="az-participant-name">{{ $member['label'] }}@if ($member['kind'] === 'garrison') ({{ __('t_ingame.messages.battle_garrison') }})@endif</span>
                — {{ __('t_ingame.messages.battle_class') }}: {{ $member['character_class'] ?? '-' }}
                — {{ __('t_ingame.messages.battle_weapons') }}: {{ $member['weapons'] }}%, {{ __('t_ingame.messages.battle_shields') }}: {{ $member['shields'] }}%, {{ __('t_ingame.messages.battle_armour') }}: {{ $member['armor'] }}%
                @if ($member['class_levels'] > 0)
                    — {{ __('t_ingame.messages.battle_class_levels', ['levels' => $member['class_levels']]) }}
                @endif
                @if ($member['lifeform_lines'] !== [])
                    <ul class="az-participant-lifeforms">
                        <li>{{ __('t_ingame.messages.battle_lifeform_bonus') }}:</li>
                        @foreach ($member['lifeform_lines'] as $line)
                            <li>{{ __('t_ingame.messages.battle_lifeform_line', ['unit' => $line['unit'], 'percent' => rtrim(rtrim(number_format($line['percent'], 2, ',', ' '), '0'), ','), 'weapon' => $line['weapon'], 'shield' => $line['shield'], 'armor' => $line['armor']]) }}</li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
    <br class="clearfloat">
@endif
