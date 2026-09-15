{{-- Le choix de la technologie d un emplacement ouvert et vide : la locale (gratuite), un tirage parmi les
     especes decouvertes (gratuit), ou une technologie d une espece decouverte contre des artefacts. --}}
<div id="technologydetails" data-technology-id="{{ 9000 + $slot }}" class="lifeform-slot-choice">
    <div class="content">
        <button class="close">✖</button>
        <h3>{{ __('t_lifeforms_ui.research.choose_title', ['slot' => $slot, 'tier' => $tier, 'position' => $position]) }}</h3>

        <div class="lifeform-choice" style="display: flex; gap: 12px; align-items: flex-start; margin: 8px 0;">
            <span class="icon lifeformsprite sprite_medium medium lifeformTech{{ $local->id }}" style="flex: 0 0 auto;"></span>
            <div style="flex: 1 1 auto;">
                <p class="textBeefy" style="margin: 0;">{{ $local_title }}</p>
                <p class="smallFont" style="margin: 2px 0 6px 0;">{{ $local_description }}</p>
                @if ($local_taken)
                    <span class="smallFont overmark">{{ __('t_lifeforms_ui.refused.slot_taken') }}</span>
                @else
                    <form method="post" action="{{ route('lifeforms.research.choose') }}" style="display: inline;">
                        {{ csrf_field() }}
                        <input type="hidden" name="slot" value="{{ $slot }}">
                        <input type="hidden" name="choice" value="local">
                        <button type="submit" class="btn_blue">{{ __('t_lifeforms_ui.research.choose_local') }}</button>
                    </form>
                @endif
            </div>
        </div>

        @if (count($others) > 0)
            <div class="lifeform-choice" style="margin: 8px 0;">
                <form method="post" action="{{ route('lifeforms.research.choose') }}" style="display: inline;">
                    {{ csrf_field() }}
                    <input type="hidden" name="slot" value="{{ $slot }}">
                    <input type="hidden" name="choice" value="random">
                    <button type="submit" class="btn_blue">{{ __('t_lifeforms_ui.research.choose_random') }}</button>
                </form>
                <p class="smallFont" style="margin: 4px 0 0 0;">{{ __('t_lifeforms_ui.research.artifacts_owned', ['count' => $artifacts, 'cost' => $artifact_cost]) }}</p>
            </div>
            @foreach ($others as $other)
                <div class="lifeform-choice" style="display: flex; gap: 12px; align-items: flex-start; margin: 8px 0;">
                    <span class="icon lifeformsprite sprite_medium medium lifeformTech{{ $other['object']->id }}" style="flex: 0 0 auto;"></span>
                    <div style="flex: 1 1 auto;">
                        <p class="textBeefy" style="margin: 0;">{{ $other['title'] }} <span class="smallFont">({{ $other['species_name'] }})</span></p>
                        <p class="smallFont" style="margin: 2px 0 6px 0;">{{ $other['description'] }}</p>
                        @if ($other['taken'])
                            <span class="smallFont overmark">{{ __('t_lifeforms_ui.refused.slot_taken') }}</span>
                        @elseif ($artifacts < $artifact_cost)
                            <span class="smallFont overmark">{{ __('t_lifeforms_ui.research.artifacts_short', ['cost' => $artifact_cost]) }}</span>
                        @else
                            <form method="post" action="{{ route('lifeforms.research.choose') }}" style="display: inline;">
                                {{ csrf_field() }}
                                <input type="hidden" name="slot" value="{{ $slot }}">
                                <input type="hidden" name="choice" value="{{ $other['object']->id }}">
                                <button type="submit" class="btn_blue">{{ __('t_lifeforms_ui.research.choose_artifacts', ['cost' => $artifact_cost]) }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        @else
            <p class="smallFont" style="margin: 8px 0 0 0;">{{ __('t_lifeforms_ui.research.no_other_species') }}</p>
        @endif
    </div>
</div>
