{{-- Le choix de la technologie d un emplacement ouvert et vide, dans la FENETRE SUPERPOSEE du jeu officiel
     (`lfresearchlayer` : la classe est posee sur la boite de dialogue par `data-overlay-class`, et repetee ici pour
     que la feuille habille aussi le fragment seul). Une fiche par proposition — la locale (gratuite), le tirage parmi
     les especes decouvertes (gratuit), puis une technologie par espece decouverte contre des artefacts —, chacune
     avec le portrait de son espece (`.lifeform-research-item-icon`), son nom, sa description et le bouton vert que la
     feuille place (`a.select-button` dans le flux, `a.select-button-artifacts` en bas a droite, `_disabled` quand les
     artefacts manquent). Le panneau de detail de 300 px les entassait avec un ascenseur (capture de Keven, journal
     §155.26). Le cadre des fiches est celui de la page des especes, couche par couche (§155.26). --}}
@php
    $sprite = asset('img/icons/e3e67150390416129bbbc8696f7b91.png');
    // La colonne de corps du sprite va de x = 640 (bordure gauche) a 1158 (bordure droite). Le fond du wrapper la pose
    // decalee pour que SA bordure droite tombe au bord de la fiche ; l enfant `corps` la repose a gauche, clippe a 513 px,
    // donc sans sa bordure droite — sinon une ligne traversait la fiche a 515 px (capture de Keven, journal §155.26).
    $cadre = "position: relative; min-height: 120px; background: url('{$sprite}') -539px 0 repeat-y;";
    $corps = "position: absolute; top: 0; bottom: 0; left: 0; width: 513px; background: url('{$sprite}') -640px 0 repeat-y; pointer-events: none;";
    $capot = "position: absolute; top: 0; left: 0; width: 620px; height: 66px; background: url('{$sprite}') 0 0 no-repeat; pointer-events: none;";
    $barre = "width: 620px; background: url('{$sprite}') -100px -85px no-repeat, url('{$sprite}') 0 -85px no-repeat;";
@endphp
<div class="lfresearchlayer" id="lifeform-slot-choice" data-slot="{{ $slot }}">
    <div class="lifeform-item-holder">
        <p>{{ __('t_lifeforms_ui.research.choose_title', ['slot' => $slot, 'tier' => $tier, 'position' => $position]) }}<br>
            <span class="smallFont">{{ __('t_lifeforms_ui.research.artifacts_owned', ['count' => $artifacts, 'cost' => $artifact_cost]) }}</span></p>

        {{-- La technologie de l espece du compte, gratuite. --}}
        <div class="lifeform-item lifeform-choice {{ $local_taken || !$local_available ? 'lifeformnotclaim' : 'lifeformcanclaim' }}" data-choice="local" style="width: 620px;">
            <div class="lifeform-item-wrapper" style="{{ $cadre }}">
                <div aria-hidden="true" style="{{ $corps }}"></div><div aria-hidden="true" style="{{ $capot }}"></div>
                <div class="lifeform-research-item-icon lifeform-item-icon lifeform{{ $local->species->value }}" role="img" aria-label="{{ __('t_lifeforms.species.' . $local->species->machineName()) }}"></div>
                <div class="lifeform-item-text" style="width: 492px; position: relative;">
                    <h3>{{ $local_title }} <span class="smallFont">({{ __('t_lifeforms.species.' . $local->species->machineName()) }})</span></h3>
                    <p>{{ $local_description }}</p>
                    @if ($local_taken)
                        <p class="overmark">{{ __('t_lifeforms_ui.refused.slot_taken') }}</p>
                    @elseif (!$local_available)
                        <p class="overmark lifeform_unavailable">{{ __('t_lifeforms_ui.refused.not_available') }}</p>
                    @else
                        <form method="post" action="{{ route('lifeforms.research.choose') }}" id="lifeform-choice-local">
                            {{ csrf_field() }}
                            <input type="hidden" name="slot" value="{{ $slot }}">
                            <input type="hidden" name="choice" value="local">
                        </form>
                        <a class="select-button" href="#" onclick="document.getElementById('lifeform-choice-local').submit(); return false;"><span>{{ __('t_lifeforms_ui.research.choose_local') }}</span></a>
                    @endif
                </div>
            </div>
            <div class="lifeform-item-bottom" style="{{ $barre }}"></div>
        </div>

        @if (count($others) > 0)
            {{-- Le tirage au sort parmi les especes decouvertes, gratuit. --}}
            <div class="lifeform-item lifeform-choice {{ $random_available ? 'lifeformcanclaim' : 'lifeformnotclaim' }}" data-choice="random" style="width: 620px;">
                <div class="lifeform-item-wrapper" style="{{ $cadre }}">
                    <div aria-hidden="true" style="{{ $corps }}"></div><div aria-hidden="true" style="{{ $capot }}"></div>
                    <div class="lifeform-item-text" style="width: 492px; position: relative;">
                        <h3>{{ __('t_lifeforms_ui.research.choose_random') }}</h3>
                        @if (!$random_available)
                            <p class="overmark lifeform_unavailable">{{ __('t_lifeforms_ui.refused.not_available') }}</p>
                        @else
                            <form method="post" action="{{ route('lifeforms.research.choose') }}" id="lifeform-choice-random">
                                {{ csrf_field() }}
                                <input type="hidden" name="slot" value="{{ $slot }}">
                                <input type="hidden" name="choice" value="random">
                            </form>
                            <a class="select-button" href="#" onclick="document.getElementById('lifeform-choice-random').submit(); return false;"><span>{{ __('t_lifeforms_ui.research.choose_local') }}</span></a>
                        @endif
                    </div>
                </div>
                <div class="lifeform-item-bottom" style="{{ $barre }}"></div>
            </div>

            {{-- Une technologie d une espece decouverte, contre des artefacts. --}}
            @foreach ($others as $other)
                @php $payable = !$other['taken'] && $other['available'] && $artifacts >= $artifact_cost; @endphp
                <div class="lifeform-item lifeform-choice {{ $payable ? 'lifeformcanclaim' : 'lifeformnotclaim' }}" data-choice="{{ $other['object']->id }}" style="width: 620px;">
                    <div class="lifeform-item-wrapper" style="{{ $cadre }}">
                        <div aria-hidden="true" style="{{ $corps }}"></div><div aria-hidden="true" style="{{ $capot }}"></div>
                        <div class="lifeform-research-item-icon lifeform-item-icon lifeform{{ $other['species']->value }}" role="img" aria-label="{{ $other['species_name'] }}"></div>
                        <div class="lifeform-item-text" style="width: 492px; position: relative; padding-bottom: 56px;">
                            <h3>{{ $other['title'] }} <span class="smallFont">({{ $other['species_name'] }})</span></h3>
                            <p>{{ $other['description'] }}</p>
                            @if ($other['taken'])
                                <p class="overmark">{{ __('t_lifeforms_ui.refused.slot_taken') }}</p>
                            @elseif (!$other['available'])
                                <p class="overmark lifeform_unavailable">{{ __('t_lifeforms_ui.refused.not_available') }}</p>
                            @elseif ($artifacts < $artifact_cost)
                                <a class="select-button-artifacts_disabled tooltip" title="{{ __('t_lifeforms_ui.research.artifacts_short', ['cost' => $artifact_cost]) }}"><span>{{ __('t_lifeforms_ui.research.choose_artifacts', ['cost' => $artifact_cost]) }}</span></a>
                            @else
                                <form method="post" action="{{ route('lifeforms.research.choose') }}" id="lifeform-choice-{{ $other['object']->id }}">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="slot" value="{{ $slot }}">
                                    <input type="hidden" name="choice" value="{{ $other['object']->id }}">
                                </form>
                                <a class="select-button-artifacts" href="#" onclick="document.getElementById('lifeform-choice-{{ $other['object']->id }}').submit(); return false;"><span>{{ __('t_lifeforms_ui.research.choose_artifacts', ['cost' => $artifact_cost]) }}</span></a>
                            @endif
                        </div>
                    </div>
                    <div class="lifeform-item-bottom" style="{{ $barre }}"></div>
                </div>
            @endforeach
        @else
            <p><span class="smallFont">{{ __('t_lifeforms_ui.research.no_other_species') }}</span></p>
        @endif
    </div>
</div>
