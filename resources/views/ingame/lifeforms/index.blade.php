@extends('ingame.layouts.main')

{{--
    La page des formes de vie du compte : l espece choisie, ou le choix.

    Batie sur la structure de la page officielle `lfsettings` — `#lfsettingscomponent`, `.lfsettingsContentWrapper`,
    `.lifeform-item` avec son icone, son texte et son bouton — parce que c est elle que la feuille de style du jeu
    connait : 664 px de large, cadre et fiches d espece. Le premier gabarit posait ces fiches dans `.content-box-s`,
    la boite laterale de 220 px : la page se lisait dans une colonne etroite, ici comme en production (revue de la
    previsualisation locale, journal §155.22).
--}}

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div id="lfsettingscomponent" class="maincontent">
        <div id="lfsettings">
            <header id="planet" data-anchor="technologyDetails">
                <h2>{{ __('t_lifeforms_ui.page.title') }} - {{ $planet_name }}</h2>
            </header>

            <div id="technologies">
                @if (!empty($lifeforms_error))
                    <div class="lfsettingsContentWrapper informationalContent">
                        <p class="overmark" role="alert" style="margin: 0;">{{ $lifeforms_error }}</p>
                    </div>
                @endif

                <div class="lfsettingsContentWrapper informationalContent">
                    @if ($choisie === null)
                        <p class="textBeefy" style="margin: 0 0 4px 0;">{{ __('t_lifeforms_ui.selection.none_yet') }}</p>
                        <p class="smallFont" style="margin: 0;">{{ __('t_lifeforms_ui.selection.once') }}</p>
                    @else
                        <p class="textBeefy" style="margin: 0 0 4px 0;">{{ __('t_lifeforms_ui.selection.chosen', ['species' => __('t_lifeforms.species.' . $choisie->machineName())]) }}</p>
                        <p class="smallFont" style="margin: 0;">{{ __('t_lifeforms_ui.selection.permanent') }}</p>
                    @endif
                </div>

                <div class="lfsettingsContentWrapper">
                    <div class="lfsettingsContent">
                        @foreach ($especes as $espece)
                            @php /** @var OGame\Lifeforms\Species $s */ $s = $espece['species']; $n = $s->value; $part = $espece['experience_needed'] > 0 ? min(1, $espece['experience_progress'] / $espece['experience_needed']) : 1; @endphp
                            <div class="lifeform-item lifeform-species lifeform-species-{{ $s->machineName() }} {{ $espece['chosen'] ? 'lifeformclaimed' : ($choisie === null ? 'lifeformcanclaim' : 'lifeformnotclaim') }}" data-species="{{ $n }}">
                                <div class="lifeform-item-icon lifeform{{ $n }}" role="img" aria-label="{{ $espece['name'] }}"
                                     style="background-image: url('{{ asset('img/lifeform/lifeformtype_sprite.png') }}'); background-repeat: no-repeat;"></div>
                                <div class="lifeform-item-wrapper">
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
                                            <p style="margin: 0 0 6px 0;">
                                                <a class="btn_blue" href="{{ route('lifeforms.buildings') }}">{{ __('t_lifeforms_ui.selection.go_to_buildings') }}</a>
                                                <a class="btn_blue" href="{{ route('lifeforms.research') }}">{{ __('t_lifeforms_ui.selection.go_to_research') }}</a>
                                                <a class="btn_blue" href="{{ route('lifeforms.discoveries') }}">{{ __('t_lifeforms_ui.discoveries.go_to_discoveries') }}</a>
                                                <a class="btn_blue" href="{{ route('lifeforms.bonuses') }}">{{ __('t_lifeforms_ui.bonuses.go_to_bonuses') }}</a>
                                            </p>
                                        @else
                                            <p class="smallFont" style="margin: 0 0 6px 0;">{{ __('t_lifeforms_ui.selection.other_species') }}</p>
                                        @endif
                                        <details>
                                            <summary class="textBeefy" style="cursor: pointer;">{{ __('t_lifeforms_ui.selection.buildings_list') }}</summary>
                                            <ul class="icons" style="display: flex; flex-wrap: wrap; gap: 6px; list-style: none; padding: 6px 0 0 0; margin: 0;">
                                                @foreach ($espece['buildings'] as $batiment)
                                                    <li class="tooltip" title="{{ __('t_lifeforms.' . $batiment->machineName . '.description') }}" style="width: 100px; text-align: center;">
                                                        <span class="icon lifeformsprite sprite_medium medium lifeformTech{{ $batiment->id }}" style="display: block; margin: auto;"></span>
                                                        <span class="smallFont">{{ __('t_lifeforms.' . $batiment->machineName . '.title') }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                        <details>
                                            <summary class="textBeefy" style="cursor: pointer;">{{ __('t_lifeforms_ui.selection.technologies_list') }}</summary>
                                            <ul class="icons" style="display: flex; flex-wrap: wrap; gap: 6px; list-style: none; padding: 6px 0 0 0; margin: 0;">
                                                @foreach ($espece['technologies'] as $technologie)
                                                    <li class="tooltip" title="{{ __('t_lifeforms.' . $technologie->machineName . '.description') }}" style="width: 100px; text-align: center;">
                                                        <span class="icon lifeformsprite sprite_medium medium lifeformTech{{ $technologie->id }}" style="display: block; margin: auto;"></span>
                                                        <span class="smallFont">{{ __('t_lifeforms_ui.selection.tier', ['tier' => $technologie->tier()]) }} · {{ __('t_lifeforms.' . $technologie->machineName . '.title') }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    </div>
                                    <div class="xpbar" style="flex: 0 0 auto; text-align: center; padding: 8px 0 0 8px;">
                                        <svg width="72" height="72" viewBox="0 0 84 84" aria-hidden="true">
                                            <circle cx="42" cy="42" r="38" fill="none" stroke="#1d2f3d" stroke-width="6"></circle>
                                            <circle class="progress-ring__circle" cx="42" cy="42" r="38" fill="none" stroke="#7fcf93" stroke-width="6"
                                                    stroke-dasharray="238.76 238.76" stroke-dashoffset="{{ 238.76 - ($part * 238.76) }}" transform="rotate(-90 42 42)"></circle>
                                            <text x="42" y="47" text-anchor="middle" fill="#dce7ef" font-size="16" font-family="Arial">{{ $espece['experience_level'] }}</text>
                                        </svg>
                                    </div>
                                </div>
                                <div class="lifeform-item-bottom"></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
