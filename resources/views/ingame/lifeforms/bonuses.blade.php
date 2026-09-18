@extends('ingame.layouts.main')

{{--
    Les bonus des formes de vie du compte, sur les boites « RS » de la page officielle des bonus (`#lfbonusescomponent`,
    `.headerRS` / `.mainRS` / `.footerRS`, 670 px) : les boites `.content-box-s` du premier gabarit sont les boites
    laterales de 220 px, et la page se lisait dans une colonne etroite (journal §155.22).
--}}

@section('content')

    <div id="lfbonusescomponent" class="maincontent">
        <div id="lifeforms">
            <header id="planet" class="shortHeader" data-anchor="technologyDetails">
                <h2>{{ __('t_lifeforms_ui.bonuses.title') }}</h2>
            </header>

            <div id="technologies">
                <div class="headerRS"></div>
                <div class="mainRS" id="lifeform-experience-bonuses">
                    {{-- La barre de titre du composant officiel : `bonus-item-heading` porte le fond et ses deux
                         embouts, le triangle vert vient de `arrow-icon`. Un `<h3>` nu n etait habille par rien ici —
                         la regle `.lfsettingsContent > h3` appartient a la page des especes (journal §155.23). --}}
                    <bonus-item-heading class="active">
                        <arrow-icon></arrow-icon>
                        <span>{{ __('t_lifeforms_ui.bonuses.experience_title') }}</span>
                        <span class="info"></span>
                    </bonus-item-heading>
                    <bonus-item-content-holder>
                        <p class="smallFont" style="margin: 0 0 10px 0;">{{ __('t_lifeforms_ui.bonuses.experience_intro') }}</p>
                        <div style="display: flex; gap: 18px; flex-wrap: wrap; justify-content: center;">
                            @foreach ($experience as $espece)
                                @php
                                    $n = $espece['species']->value;
                                    $part = $espece['needed'] > 0 ? min(1, $espece['progress'] / $espece['needed']) : 1;
                                    // Rayon 40 dans une fenetre de 88 : circonference 2 pi r.
                                    $tour = 2 * M_PI * 40;
                                @endphp
                                <lifeform-avatar class="lifeform-experience-item" data-species="{{ $n }}">
                                    <lifeform-avatar-xp-holder>
                                        <div class="lifeform-item-icon lifeform{{ $n }}" role="img" aria-label="{{ $espece['name'] }}"></div>
                                        {{-- **L arc se pose en style, pas en attribut** : la feuille du jeu impose
                                             `.xpbar .progress-ring__circle{stroke-dasharray:10 20}`, qui bat tout
                                             attribut SVG — l anneau etait pointille et ne montrait aucune progression. --}}
                                        <div class="xpHolder">
                                            <div class="xpbar" aria-label="{{ $espece['progress'] }}/{{ $espece['needed'] }} XP">
                                                <svg class="progress-ring" width="88" height="88" aria-hidden="true" style="position: absolute; top: 0; left: 0;">
                                                    <circle cx="44" cy="44" r="40" fill="none" stroke="#1d2f3d" stroke-width="4"></circle>
                                                    <circle class="progress-ring__circle" cx="44" cy="44" r="40" fill="none" stroke="#7fcf93" stroke-width="4"
                                                            style="stroke-dasharray: {{ round($part * $tour, 2) }} {{ round((1 - $part) * $tour, 2) }};"></circle>
                                                </svg>
                                            </div>
                                        </div>
                                    </lifeform-avatar-xp-holder>
                                    <span class="currentlevel">{{ __('t_lifeforms_ui.bonuses.level', ['level' => $espece['level']]) }}</span>
                                    <span class="smallFont">{{ $espece['name'] }}</span>
                                    <span class="smallFont">{{ $espece['progress'] }}/{{ $espece['needed'] }} XP</span>
                                    <span class="bonusValue">{{ __('t_lifeforms_ui.bonuses.bonus', ['value' => rtrim(rtrim(number_format($espece['bonus'], 1, '.', ''), '0'), '.')]) }}</span>
                                </lifeform-avatar>
                            @endforeach
                        </div>
                    </bonus-item-content-holder>
                </div>
                <div class="footerRS"></div>

                <div class="headerRS" style="margin-top: 12px;"></div>
                <div class="mainRS" id="lifeform-effect-bonuses">
                    <bonus-item-heading class="active">
                        <arrow-icon></arrow-icon>
                        <span>{{ __('t_lifeforms_ui.bonuses.effects_title') }}</span>
                        <span class="info"></span>
                    </bonus-item-heading>
                    <bonus-item-content-holder>
                        @if (count($effets) === 0)
                            <p class="smallFont" style="margin: 0;">{{ __('t_lifeforms_ui.bonuses.none') }}</p>
                        @else
                            <p class="smallFont" style="margin: 0 0 10px 0;">{{ __('t_lifeforms_ui.bonuses.effects_intro') }}</p>
                            {{-- **Les lignes sont celles du jeu** : `inner-bonus-item-heading` (22 px, une ligne sur
                                 deux teintee, valeur poussee a droite par `.subCategoryBonus`). Le tableau a quatre
                                 colonnes du premier gabarit n etait habille par aucune classe et se lisait tasse. --}}
                            @foreach ($effets as $effet)
                                <div class="lifeform-bonus-item" data-effect="{{ $effet['key'] }}" style="margin-bottom: 10px;">
                                    <inner-bonus-item-heading class="textBeefy">
                                        <span>{{ $effet['label'] }}</span>
                                        <span class="subCategoryBonus">{{ rtrim(rtrim(number_format($effet['total'], 2, '.', ''), '0'), '.') }} %@if ($effet['capped']) <span class="smallFont overmark" title="{{ __('t_lifeforms_ui.bonuses.capped_hint') }}">({{ __('t_lifeforms_ui.bonuses.capped') }})</span>@endif</span>
                                    </inner-bonus-item-heading>
                                    @foreach ($effet['planets'] as $planete)
                                        <div class="lifeform-bonus-planet">
                                            <inner-bonus-item-heading class="smallFont">
                                                <span>{{ $planete['name'] }} [{{ $planete['coordinates'] }}]</span>
                                                <span class="subCategoryBonus">{{ rtrim(rtrim(number_format($planete['total'], 2, '.', ''), '0'), '.') }} %</span>
                                            </inner-bonus-item-heading>
                                            @foreach ($planete['rows'] as $ligne)
                                                <inner-bonus-item-heading class="smallFont" style="padding-left: 16px;">
                                                    <span class="queuePic lifeformqueuetiny lifeformTech{{ $ligne['object'] }}"></span>
                                                    <span>{{ $ligne['title'] }}</span>
                                                    <span class="smallFont">{{ __('t_lifeforms_ui.bonuses.slot') }} {{ $ligne['slot'] }} · {{ __('t_lifeforms_ui.bonuses.level_column') }} {{ $ligne['level'] }}</span>
                                                    <span class="subCategoryBonus">{{ rtrim(rtrim(number_format($ligne['percent'], 2, '.', ''), '0'), '.') }} %</span>
                                                </inner-bonus-item-heading>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        @endif
                    </bonus-item-content-holder>
                </div>
                <div class="footerRS"></div>
            </div>
        </div>
    </div>

@endsection
