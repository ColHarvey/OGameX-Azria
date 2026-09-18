{{-- Le choix de la technologie d un emplacement ouvert et vide, dans la FENETRE SUPERPOSEE du jeu officiel
     (`lfresearchlayer` : la classe est posee sur la boite de dialogue par `data-overlay-class`, et repetee ici pour
     que la feuille habille aussi le fragment seul). Le panneau de detail de 300 px les entassait avec un ascenseur
     (capture de Keven, journal §155.26).

     **La structure est celle que la feuille officielle attend, sans aucun style en ligne** (audit, journal §155.27) :

     - l en-tete `.lfResearchLayerContent > .holder` (titre, texte principal, texte secondaire, rangee de boutons) porte le
       tirage au sort, qui est dans le jeu UN bouton (`#selectChance`, `a.select-button` en flux : le bundle officiel
       ne lie qu un element de cet identifiant) — pas une fiche, dont le puits d icone restait un carre noir vide ;
     - chaque technologie est une fiche `.lifeform-item` : le capot du sprite est pose par la classe d etat
       (`lifeformcanclaim` / `lifeformnotclaim`), le portrait `.lifeform-research-item-icon` est absolu (10, 5) dans le
       puits, `.lifeform-item-text` (521 px, marge gauche 100) contient le titre puis `.lifeform-item-wrapper` — la colonne
       de corps de 519 px, texturee, a droite du puits — puis `.lifeform-item-bottom` (519 x 7, biseau au bord droit). Les
       cent premiers pixels ne portent aucune texture : c est le fond de la fenetre, sous le portrait, comme dans le jeu ;
     - le bouton de la fiche locale est `a.select-button-100#selectTechnology`, celui d une fiche etrangere
       `a.select-button-artifacts` (`_disabled` quand les artefacts manquent) : tous absolus en bas a droite de la fiche,
       enfants directs de `.lifeform-item` (positionnee par la feuille). --}}
<div class="lfresearchlayer" id="lifeform-slot-choice" data-slot="{{ $slot }}">
    <div class="lfResearchLayerContent">
        <div class="holder">
            <h2>{{ __('t_lifeforms_ui.research.choose_title', ['slot' => $slot, 'tier' => $tier, 'position' => $position]) }}</h2>
            <div class="main-text">
                <p>{{ __('t_lifeforms_ui.research.choose_intro') }}</p>
                <p>{{ __('t_lifeforms_ui.research.artifacts_owned', ['count' => $artifacts, 'cost' => $artifact_cost]) }}</p>
            </div>
            @if (count($others) > 0)
                <div class="sub-text">{{ __('t_lifeforms_ui.research.choose_random_intro') }}</div>
                <div class="button-holder">
                    @if ($random_available)
                        <form method="post" action="{{ route('lifeforms.research.choose') }}" id="lifeform-choice-random">
                            {{ csrf_field() }}
                            <input type="hidden" name="slot" value="{{ $slot }}">
                            <input type="hidden" name="choice" value="random">
                        </form>
                        {{-- Dans la rangee de boutons la feuille rend l ancre en bloc (plus en flex) : le libelle se centre comme celui des
                             boutons `a.build-it` du jeu (cellule de tableau, milieu). --}}
                        <a class="select-button" id="selectChance" href="#" onclick="document.getElementById('lifeform-choice-random').submit(); return false;"><span style="display: table-cell; vertical-align: middle; width: 142px; height: 54px;">{{ __('t_lifeforms_ui.research.choose_random') }}</span></a>
                    @else
                        <p class="overmark lifeform_unavailable">{{ __('t_lifeforms_ui.research.choose_random') }} : {{ __('t_lifeforms_ui.refused.not_available') }}</p>
                    @endif
                </div>
            @else
                <div class="sub-text">{{ __('t_lifeforms_ui.research.no_other_species') }}</div>
            @endif
        </div>
    </div>

    <div class="lifeform-item-holder">
        {{-- La technologie de l espece du compte, gratuite. --}}
        <div class="lifeform-item lifeform-choice {{ $local_taken || !$local_available ? 'lifeformnotclaim' : 'lifeformcanclaim' }}" data-choice="local">
            <div class="lifeform-research-item-icon lifeform-item-icon lifeform{{ $local->species->value }}" role="img" aria-label="{{ __('t_lifeforms.species.' . $local->species->machineName()) }}"></div>
            <div class="lifeform-item-text">
                <h3>{{ $local_title }}</h3>
                {{-- Le bouton absolu (54 px, a 15 px du bas) recouvrirait une description courte : le bas du corps lui est reserve.
                     C est le seul style en ligne de la fenetre ; la feuille ne donne au corps que 30 px de hauteur minimale. --}}
                <div class="lifeform-item-wrapper"@if (!$local_taken && $local_available) style="padding-bottom: 56px;"@endif>
                    <p class="smallFont">{{ __('t_lifeforms.species.' . $local->species->machineName()) }} — {{ __('t_lifeforms_ui.research.choose_local_intro') }}</p>
                    <p>{{ $local_description }}</p>
                    @if ($local_taken)
                        <p class="overmark">{{ __('t_lifeforms_ui.refused.slot_taken') }}</p>
                    @elseif (!$local_available)
                        <p class="overmark lifeform_unavailable">{{ __('t_lifeforms_ui.refused.not_available') }}</p>
                    @endif
                </div>
                <div class="lifeform-item-bottom"></div>
            </div>
            @if (!$local_taken && $local_available)
                <form method="post" action="{{ route('lifeforms.research.choose') }}" id="lifeform-choice-local">
                    {{ csrf_field() }}
                    <input type="hidden" name="slot" value="{{ $slot }}">
                    <input type="hidden" name="choice" value="local">
                </form>
                <a class="select-button-100" id="selectTechnology" href="#" onclick="document.getElementById('lifeform-choice-local').submit(); return false;"><span>{{ __('t_lifeforms_ui.research.choose_local') }}</span></a>
            @endif
        </div>

        {{-- Une technologie d une espece decouverte, contre des artefacts. --}}
        @foreach ($others as $other)
            @php $payable = !$other['taken'] && $other['available'] && $artifacts >= $artifact_cost; @endphp
            <div class="lifeform-item lifeform-choice {{ $payable ? 'lifeformcanclaim' : 'lifeformnotclaim' }}" data-choice="{{ $other['object']->id }}">
                <div class="lifeform-research-item-icon lifeform-item-icon lifeform{{ $other['species']->value }}" role="img" aria-label="{{ $other['species_name'] }}"></div>
                <div class="lifeform-item-text">
                    <h3>{{ $other['title'] }}</h3>
                    <div class="lifeform-item-wrapper"@if (!$other['taken'] && $other['available']) style="padding-bottom: 56px;"@endif>
                        <p class="smallFont">{{ $other['species_name'] }}</p>
                        <p>{{ $other['description'] }}</p>
                        @if ($other['taken'])
                            <p class="overmark">{{ __('t_lifeforms_ui.refused.slot_taken') }}</p>
                        @elseif (!$other['available'])
                            <p class="overmark lifeform_unavailable">{{ __('t_lifeforms_ui.refused.not_available') }}</p>
                        @endif
                    </div>
                    <div class="lifeform-item-bottom"></div>
                </div>
                @if (!$other['taken'] && $other['available'])
                    @if ($artifacts < $artifact_cost)
                        <a class="select-button-artifacts_disabled tooltip" title="{{ __('t_lifeforms_ui.research.artifacts_short', ['cost' => $artifact_cost]) }}"><span>{{ __('t_lifeforms_ui.research.choose_artifacts', ['cost' => $artifact_cost]) }}</span></a>
                    @else
                        <form method="post" action="{{ route('lifeforms.research.choose') }}" id="lifeform-choice-{{ $other['object']->id }}">
                            {{ csrf_field() }}
                            <input type="hidden" name="slot" value="{{ $slot }}">
                            <input type="hidden" name="choice" value="{{ $other['object']->id }}">
                        </form>
                        <a class="select-button-artifacts selectArtifacts" href="#" onclick="document.getElementById('lifeform-choice-{{ $other['object']->id }}').submit(); return false;"><span>{{ __('t_lifeforms_ui.research.choose_artifacts', ['cost' => $artifact_cost]) }}</span></a>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
</div>
