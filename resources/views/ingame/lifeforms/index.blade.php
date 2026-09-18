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
      `.lifeform-item-wrapper` et `.lifeform-item-bottom` DANS `.lifeform-item-text`) est un sprite de 1166 x 98 :
      capot de 620 px (puits du portrait a gauche, bandeau, encoche en haut a droite), colonne de corps de 519 px en
      `repeat-y`, barre basse de 519 px. Le corps ne couvre PAS les 100 PREMIERS pixels de la fiche — sous le portrait
      et l anneau, c est le fond de la boite, mesure rgb(13,16,20) sur la capture officielle (audit §155.27). Les
      versions du §155.25 et du §155.26 lisaient cette bande a droite et texturaient toute la largeur : c etait faux.
--}}

@section('content')

    @include('ingame.lifeforms.partials.flash')

    <div id="lfsettingscomponent" class="maincontent">
        <div id="lfsettings">
            {{-- L illustration officielle de la page des especes (654 x 250, celle que `#netz #planet` pose dans le
                 jeu ; ce fork n a pas de `#netz`, elle est posee ici, a sa hauteur). Les pages des batiments et des
                 recherches gardent le biome de la planete, comme leurs captures officielles le montrent. --}}
            <header id="planet" data-anchor="technologyDetails"
                    style="background-image:url({{ asset('img/icons/6dafcd306b27d77508ef115722b6b0.jpg') }}); height: 250px;">
                <h2>{{ __('t_lifeforms_ui.page.title') }}</h2>
            </header>

            <div id="technologies">
                @if (!empty($lifeforms_error))
                    <div class="lfsettingsContentWrapper informationalContent">
                        <p class="overmark" role="alert" style="margin: 0;">{{ $lifeforms_error }}</p>
                    </div>
                @endif

                {{-- **Une seule boite**, comme la page officielle (capture de Keven, audit §155.27) : la barre de titre,
                     la ligne d etat, puis les quatre fiches et leurs listes repliables a la suite. La boite est un flex en
                     colonne centre (feuille) : chaque fiche prend sa largeur naturelle (puits 100 + texte 521), les barres
                     repliables leurs 625 px. --}}
                <div class="lfsettingsContentWrapper">
                    <div class="lfsettingsContent">
                        <h3>{{ __('t_lifeforms_ui.page.title') }}</h3>
                        {{-- La ligne d etat sous la barre, dans le contenu de 640 px (sa hauteur minimale de 100 px laissait un
                             vide) ; le style est celui que la feuille donne au paragraphe d une boite (`>p` : a gauche, 15 px,
                             graisse 300). --}}
                        @if ($choisie === null)
                            <p style="text-align: left; padding: 15px; font-weight: 300;"><span class="icon icon_warning"></span>{{ __('t_lifeforms_ui.selection.none_yet') }} {{ __('t_lifeforms_ui.selection.once') }}</p>
                        @else
                            <p style="text-align: left; padding: 15px; font-weight: 300;">{{ __('t_lifeforms_ui.selection.chosen', ['species' => __('t_lifeforms.species.' . $choisie->machineName())]) }} {{ __('t_lifeforms_ui.selection.permanent') }}</p>
                        @endif

                    @foreach ($especes as $espece)
                        @php
                            /** @var OGame\Lifeforms\Species $s */
                            $s = $espece['species'];
                            $n = $s->value;
                            $part = $espece['experience_needed'] > 0 ? min(1, $espece['experience_progress'] / $espece['experience_needed']) : 1;
                            $etat = $espece['chosen'] ? 'chosen' : ($choisie === null ? 'can-choose' : 'other');
                            // L anneau du jeu : rayon 38, circonference 2 pi r ; l arc se pose en STYLE, jamais en attribut —
                            // la feuille impose `.xpbar .progress-ring__circle{stroke-dasharray:10 20}`, qui bat tout attribut SVG.
                            $circonference = round(2 * M_PI * 38, 2);
                        @endphp
                        {{-- **La fiche officielle, telle que la feuille l habille, sans style en ligne** (audit §155.27, mesure au
                             pixel sur la capture officielle) : la classe d etat (`lifeformclaimed` / `lifeformcanclaim` /
                             `lifeformnotclaim`) pose le capot du sprite sur la fiche ; le portrait est absolu dans le puits ;
                             `.lifeform-item-text` (521 px, marge gauche 100) porte le titre puis `.lifeform-item-wrapper`, la
                             colonne de corps texturee de 519 px — a droite du puits seulement : sous le portrait et l anneau,
                             c est le fond de la boite, comme dans le jeu — puis `.lifeform-item-bottom` (519 x 7). Le bouton
                             vert est un enfant de la fiche, que la feuille ancre en bas a droite. Les deux versions precedentes
                             (§155.25, §155.26) posaient la texture sur toute la largeur : c etait une mauvaise lecture. --}}
                        <div class="lifeform-item lifeform-species lifeform-species-{{ $s->machineName() }} {{ ['chosen' => 'lifeformclaimed', 'can-choose' => 'lifeformcanclaim', 'other' => 'lifeformnotclaim'][$etat] }}" data-species="{{ $n }}" data-state="{{ $etat }}">
                            <div class="lifeform-item-icon lifeform{{ $n }}" role="img" aria-label="{{ $espece['name'] }}"></div>
                            {{-- L anneau d experience du jeu, sous le portrait : l arc `progress-ring`, puis le disque ombre
                                 `.outer > .inner` qui porte le niveau (balisage du rapport d espionnage officiel du depot).
                                 La place sous le portrait est posee ici : la feuille ne place pas `.xpbar` dans cette page. --}}
                            <div class="xpbar tooltip js_hideTipOnMobile" style="position: absolute; top: 84px; left: 4px;" title="{{ __('t_lifeforms_ui.selection.level', ['level' => $espece['experience_level'], 'xp' => $espece['experience_progress'] . '/' . $espece['experience_needed']]) }}" aria-label="{{ __('t_lifeforms_ui.selection.level', ['level' => $espece['experience_level'], 'xp' => $espece['experience_progress'] . '/' . $espece['experience_needed']]) }}">
                                <svg class="progress-ring" width="88" height="88" aria-hidden="true">
                                    <circle class="progress-ring__back" cx="44" cy="44" r="38" fill="transparent" stroke="#333" stroke-width="5"></circle>
                                    <circle class="progress-ring__circle" cx="44" cy="44" r="38" fill="transparent" stroke="#99cc00" stroke-width="5"
                                            style="stroke-dasharray: {{ $circonference }} {{ $circonference }}; stroke-dashoffset: {{ round($circonference - $part * $circonference, 2) }};"></circle>
                                </svg>
                                <div class="outer"><div class="inner"><span class="currentlevel">{{ $espece['experience_level'] }}</span></div></div>
                            </div>
                            <div class="lifeform-item-text">
                                <h3 style="margin: 4px 115px 6px 15px; font-weight: 600;">{{ $espece['name'] }}@if ($espece['chosen']) — {{ __('t_lifeforms_ui.selection.chosen_badge') }}@endif</h3>
                                <div class="lifeform-item-wrapper">
                                    <p>{{ $espece['lore'] }}</p>
                                    <p>&nbsp;</p>
                                    <p>{{ __('t_lifeforms_ui.selection.usage') }}<br>{{ $espece['usage'] }}</p>
                                    <p>&nbsp;</p>
                                    <p>{{ __('t_lifeforms_ui.selection.level', ['level' => $espece['experience_level'], 'xp' => $espece['experience_progress'] . '/' . $espece['experience_needed']]) }}<br>{{ __('t_lifeforms_ui.selection.tech_bonus', ['bonus' => rtrim(rtrim(number_format($espece['experience_bonus'], 1, '.', ''), '0'), '.')]) }}</p>
                                    @if ($espece['chosen'])
                                        <p>
                                            <a href="{{ route('lifeforms.buildings') }}">{{ __('t_lifeforms_ui.selection.go_to_buildings') }}</a> ·
                                            <a href="{{ route('lifeforms.research') }}">{{ __('t_lifeforms_ui.selection.go_to_research') }}</a> ·
                                            <a href="{{ route('lifeforms.discoveries') }}">{{ __('t_lifeforms_ui.discoveries.go_to_discoveries') }}</a> ·
                                            <a href="{{ route('lifeforms.bonuses') }}">{{ __('t_lifeforms_ui.bonuses.go_to_bonuses') }}</a>
                                        </p>
                                    @elseif ($choisie !== null)
                                        <p>{{ __('t_lifeforms_ui.selection.other_species') }}</p>
                                    @endif
                                </div>
                                <div class="lifeform-item-bottom"></div>
                            </div>
                            @if ($choisie === null)
                                <form method="post" action="{{ route('lifeforms.select') }}" class="lifeform-select-form" id="lifeform-select-{{ $n }}" onsubmit="return confirm({{ json_encode(__('t_lifeforms_ui.selection.confirm', ['species' => $espece['name']])) }});">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="species" value="{{ $n }}">
                                </form>
                                <a class="select-button" href="#" onclick="document.getElementById('lifeform-select-{{ $n }}').requestSubmit(); return false;">{{ __('t_lifeforms_ui.selection.select') }}</a>
                            @endif
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
                    @endforeach
                    </div>
                </div>
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
