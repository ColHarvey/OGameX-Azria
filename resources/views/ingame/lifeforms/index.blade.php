@extends('ingame.layouts.main')

{{--
    La page des formes de vie du compte : l espece choisie, ou le choix.

    ## Ce que la feuille du jeu habille, et ce qu elle n habille pas

    Batie sur la page officielle `lfsettings` : `#lfsettingscomponent` (664 px), `.lfsettingsContentWrapper` (le
    cadre), `.lfsettingsContent` (640 px), `.lifeform-item` avec son icone de 77 px tiree du sprite officiel et son
    bouton vert `a.select-button` place en bas a droite par la feuille.

    **Deux pieges mesures a l ecran** (journal §155.23, §155.25) :

    - l en-tete officiel tient son image de `#netz #planet`, et ce fork n a pas de `#netz` : seule la regle
      generique `#planet{width:654px;height:300px}` s appliquait, donc un trou de 300 px sans image. L image
      officielle (654 x 250) est donc posee ici, a sa hauteur ;
    - le cadre officiel d une fiche (`lifeformclaimed` / `lifeformcanclaim` / `lifeformnotclaim` sur la fiche,
      `.lifeform-item-wrapper` et `.lifeform-item-bottom` dedans) est un sprite de 1166 x 98 : capot de 620 px
      avec son encoche en haut a droite, colonne de corps de 520 px en `repeat-y`, barre basse de 519 px. Le corps
      ne couvre PAS les 100 derniers pixels de la fiche : c est ainsi dans le jeu (mesure sur une capture officielle,
      rgb(33,42,52) puis rgb(13,16,20) a droite du texte). Sans le wrapper et la barre, le capot seul paraissait
      une languette orpheline (§155.23) ; avec eux, la fiche est celle du jeu.
--}}

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div id="lfsettingscomponent" class="maincontent">
        <div id="lfsettings">
            {{-- L illustration officielle de la page des especes (654 x 250, celle que `#netz #planet` pose dans le
                 jeu ; ce fork n a pas de `#netz`, elle est posee ici, a sa hauteur). Les pages des batiments et des
                 recherches gardent le biome de la planete, comme leurs captures officielles le montrent. --}}
            <header id="planet" data-anchor="technologyDetails"
                    style="background-image:url({{ asset('img/icons/6dafcd306b27d77508ef115722b6b0.jpg') }}); height: 250px;">
                <h2>{{ __('t_lifeforms_ui.page.title') }} - {{ $planet_name }}</h2>
            </header>

            <div id="technologies">
                @if (!empty($lifeforms_error))
                    <div class="lfsettingsContentWrapper informationalContent">
                        <p class="overmark" role="alert" style="margin: 0;">{{ $lifeforms_error }}</p>
                    </div>
                @endif

                <div class="lfsettingsContentWrapper">
                    <div class="lfsettingsContent" style="text-align: center;">
                        <h3>{{ __('t_lifeforms_ui.page.title') }}</h3>
                        @if ($choisie === null)
                            <p class="textBeefy" style="margin: 0 0 4px 0;">{{ __('t_lifeforms_ui.selection.none_yet') }}</p>
                            <p class="smallFont" style="margin: 0 0 6px 0;">{{ __('t_lifeforms_ui.selection.once') }}</p>
                        @else
                            <p class="textBeefy" style="margin: 0 0 4px 0;">{{ __('t_lifeforms_ui.selection.chosen', ['species' => __('t_lifeforms.species.' . $choisie->machineName())]) }}</p>
                            <p class="smallFont" style="margin: 0 0 6px 0;">{{ __('t_lifeforms_ui.selection.permanent') }}</p>
                        @endif
                    </div>
                </div>

                @foreach ($especes as $espece)
                    @php
                        /** @var OGame\Lifeforms\Species $s */
                        $s = $espece['species'];
                        $n = $s->value;
                        $part = $espece['experience_needed'] > 0 ? min(1, $espece['experience_progress'] / $espece['experience_needed']) : 1;
                        $etat = $espece['chosen'] ? 'chosen' : ($choisie === null ? 'can-choose' : 'other');
                    @endphp
                    <div class="lfsettingsContentWrapper">
                        <div class="lfsettingsContent">
                            <div class="lifeform-item lifeform-species lifeform-species-{{ $s->machineName() }} {{ ['chosen' => 'lifeformclaimed', 'can-choose' => 'lifeformcanclaim', 'other' => 'lifeformnotclaim'][$etat] }}" data-species="{{ $n }}" data-state="{{ $etat }}">
                            <div class="lifeform-item-wrapper" style="position: relative; min-height: 190px;">
                                {{-- Aucun style en ligne : `.lifeform-item-icon` porte deja le sprite du jeu, et
                                     `.lifeformN` la position de l espece. --}}
                                <div class="lifeform-item-icon lifeform{{ $n }}" role="img" aria-label="{{ $espece['name'] }}"></div>
                                {{-- L anneau du jeu : l arc se pose en STYLE, jamais en attribut — la feuille impose
                                     `.xpbar .progress-ring__circle{stroke-dasharray:10 20}`, qui bat tout attribut SVG
                                     et rendait un anneau pointille sans progression. --}}
                                <div class="xpbar" style="position: absolute; top: 88px; left: 4px;" aria-label="{{ __('t_lifeforms_ui.selection.level', ['level' => $espece['experience_level'], 'xp' => $espece['experience_progress'] . '/' . $espece['experience_needed']]) }}">
                                    <svg class="progress-ring" width="88" height="88" aria-hidden="true" style="position: absolute; top: 0; left: 0;">
                                        <circle cx="44" cy="44" r="40" fill="none" stroke="#1d2f3d" stroke-width="4"></circle>
                                        <circle class="progress-ring__circle" cx="44" cy="44" r="40" fill="none" stroke="#7fcf93" stroke-width="4"
                                                style="stroke-dasharray: {{ round($part * 2 * M_PI * 40, 2) }} {{ round((1 - $part) * 2 * M_PI * 40, 2) }};"></circle>
                                    </svg>
                                    <span class="currentlevel">{{ $espece['experience_level'] }}</span>
                                </div>
                                <div class="lifeform-item-text">
                                    <h3>{{ $espece['name'] }}@if ($espece['chosen']) — {{ __('t_lifeforms_ui.selection.chosen_badge') }}@endif</h3>
                                    <p style="margin: 0 0 6px 0;">{{ $espece['lore'] }}</p>
                                    <p class="smallFont" style="margin: 0 0 6px 0;"><strong>{{ __('t_lifeforms_ui.selection.usage') }}</strong> {{ $espece['usage'] }}</p>
                                    <p class="smallFont" style="margin: 0 0 6px 0;">
                                        {{ __('t_lifeforms_ui.selection.level', ['level' => $espece['experience_level'], 'xp' => $espece['experience_progress'] . '/' . $espece['experience_needed']]) }}
                                        · {{ __('t_lifeforms_ui.selection.tech_bonus', ['bonus' => rtrim(rtrim(number_format($espece['experience_bonus'], 1, '.', ''), '0'), '.')]) }}
                                    </p>
                                    @if ($choisie === null)
                                        <form method="post" action="{{ route('lifeforms.select') }}" class="lifeform-select-form" id="lifeform-select-{{ $n }}" onsubmit="return confirm({{ json_encode(__('t_lifeforms_ui.selection.confirm', ['species' => $espece['name']])) }});">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="species" value="{{ $n }}">
                                        </form>
                                        <a class="select-button" href="#" onclick="document.getElementById('lifeform-select-{{ $n }}').requestSubmit(); return false;">{{ __('t_lifeforms_ui.selection.select') }}</a>
                                    @elseif ($espece['chosen'])
                                        <p class="smallFont" style="margin: 0 0 6px 0;">
                                            <a href="{{ route('lifeforms.buildings') }}">{{ __('t_lifeforms_ui.selection.go_to_buildings') }}</a> ·
                                            <a href="{{ route('lifeforms.research') }}">{{ __('t_lifeforms_ui.selection.go_to_research') }}</a> ·
                                            <a href="{{ route('lifeforms.discoveries') }}">{{ __('t_lifeforms_ui.discoveries.go_to_discoveries') }}</a> ·
                                            <a href="{{ route('lifeforms.bonuses') }}">{{ __('t_lifeforms_ui.bonuses.go_to_bonuses') }}</a>
                                        </p>
                                    @else
                                        <p class="smallFont" style="margin: 0 0 6px 0;">{{ __('t_lifeforms_ui.selection.other_species') }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="lifeform-item-bottom"></div>
                            </div>

                            {{-- **Les deux listes de l espece prennent les barres du jeu** (`.lifeformTechnology > h1`,
                                 `.technologyRow`, `.technologyInfo`), au lieu de deux `<details>` nus : c est ce que la
                                 feuille officielle habille sous `#lfsettings #technologies`, triangle vert compris. --}}
                            @foreach ([['buildings_list', $espece['buildings'], false], ['technologies_list', $espece['technologies'], true]] as [$cle, $objets, $avecPalier])
                                <div class="lifeformTechnology">
                                    <h1 role="button" tabindex="0" aria-expanded="false"><span>{{ __('t_lifeforms_ui.selection.' . $cle) }}</span></h1>
                                    @foreach (array_chunk($objets, 3) as $rangee)
                                        {{-- Repliee au depart, comme le triangle du jeu l annonce : quatre especes, trente
                                             objets chacune, la page s ouvrirait sur douze ecrans de vignettes. --}}
                                        <div class="technologyRow" style="display: none;">
                                            @foreach ($rangee as $objet)
                                                <div class="technologyInfo tooltip" title="{{ __('t_lifeforms.' . $objet->machineName . '.description') }}">
                                                    <span class="queuePic lifeformqueuetiny lifeformTech{{ $objet->id }}"></span>
                                                    <span class="technologyName">@if ($avecPalier){{ __('t_lifeforms_ui.selection.tier', ['tier' => $objet->tier()]) }} · @endif{{ __('t_lifeforms.' . $objet->machineName . '.title') }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Le repli d une liste, comme le jeu : la barre de titre est cliquable (`cursor:pointer` dans la feuille) et
         cache les rangees qui la suivent. Rien de plus ; l etat n est pas persiste. --}}
    <script type="text/javascript">
        (function () {
            'use strict';

            document.querySelectorAll('#lfsettings .lifeformTechnology > h1').forEach(function (barre) {
                function basculer() {
                    var ouvert = barre.getAttribute('aria-expanded') !== 'false';
                    var rangee = barre.nextElementSibling;
                    while (rangee !== null) {
                        rangee.style.display = ouvert ? 'none' : 'flex';
                        rangee = rangee.nextElementSibling;
                    }
                    barre.setAttribute('aria-expanded', ouvert ? 'false' : 'true');
                }

                barre.addEventListener('click', basculer);
                barre.addEventListener('keydown', function (evenement) {
                    if (evenement.key === 'Enter' || evenement.key === ' ') {
                        evenement.preventDefault();
                        basculer();
                    }
                });
            });
        })();
    </script>
@endsection
