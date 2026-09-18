{{-- Le choix de la technologie d un emplacement ouvert et vide : la locale (gratuite), un tirage parmi les
     especes decouvertes (gratuit), ou une technologie d une espece decouverte contre des artefacts. --}}
{{-- `lfresearchlayer` : la classe de la couche officielle de choix, qui donne aux boutons `a.select-button` leur
     sprite vert de 142 x 54 px. Un `button.btn_blue` dans `#technologydetails` perdait fond et marges — la regle
     `#technologydetails button{background-color:#0000;padding:0}` bat une classe. --}}
<div id="technologydetails" data-technology-id="{{ 9000 + $slot }}" class="lifeform-slot-choice lfresearchlayer">
    {{-- **Le choix occupe tout le panneau, et il defile.** `#technologydetails .content` fait 200 px de haut,
         commence a 208 px de la gauche et coupe ce qui depasse : au-dela de la deuxieme espece proposee, le
         joueur ne pouvait plus rien atteindre (journal §155.23). Ici il n y a pas d image a gauche — la place
         est donc rendue au contenu, et les 300 px du cadre defilent.
         Le jeu officiel ouvre pour cela une couche a part (.lfresearchlayer) que ce fork n a pas : ce panneau
         defilant est l adaptation Azria, et elle est dite. --}}
    <div class="content" style="left: 0; right: 0; height: 300px; overflow-y: auto; padding: 30px 8px 8px 8px;">
        <button class="close">✖</button>
        <h3>{{ __('t_lifeforms_ui.research.choose_title', ['slot' => $slot, 'tier' => $tier, 'position' => $position]) }}</h3>

        <div class="lifeform-choice" style="display: flex; gap: 10px; align-items: flex-start; margin: 6px 0;">
            <span class="icon lifeformsprite sprite_small small lifeformTech{{ $local->id }}" style="flex: 0 0 auto;"></span>
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
                        <a class="select-button" href="#" onclick="this.closest('form').requestSubmit(); return false;"><span>{{ __('t_lifeforms_ui.research.choose_local') }}</span></a>
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
                    <a class="select-button" href="#" onclick="this.closest('form').requestSubmit(); return false;"><span>{{ __('t_lifeforms_ui.research.choose_random') }}</span></a>
                </form>
                <p class="smallFont" style="margin: 4px 0 0 0;">{{ __('t_lifeforms_ui.research.artifacts_owned', ['count' => $artifacts, 'cost' => $artifact_cost]) }}</p>
            </div>
            @foreach ($others as $other)
                <div class="lifeform-choice" style="display: flex; gap: 10px; align-items: flex-start; margin: 6px 0;">
                    <span class="icon lifeformsprite sprite_small small lifeformTech{{ $other['object']->id }}" style="flex: 0 0 auto;"></span>
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
                                <a class="select-button" href="#" onclick="this.closest('form').requestSubmit(); return false;"><span>{{ __('t_lifeforms_ui.research.choose_artifacts', ['cost' => $artifact_cost]) }}</span></a>
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
