@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div id="lifeformscomponent" class="maincontent">
        <div id="lifeforms">
            <header id="planet" data-anchor="technologyDetails">
                <h2>{{ __('t_lifeforms_ui.page.title') }} - {{ $planet_name }}</h2>
            </header>

            <div id="technologies">
                @if (!empty($lifeforms_error))
                    <div class="fieldwrapper"><div class="smallFont overmark" role="alert">{{ $lifeforms_error }}</div></div>
                @endif

                <div class="content-box-s">
                    <div class="header"><h3>{{ __('t_lifeforms_ui.page.title') }}</h3></div>
                    <div class="content" style="padding: 12px;">
                        @if ($choisie === null)
                            <p class="textCenter textBeefy" style="margin: 0 0 6px 0;">{{ __('t_lifeforms_ui.selection.none_yet') }}</p>
                            <p class="smallFont textCenter" style="margin: 0;">{{ __('t_lifeforms_ui.selection.once') }}</p>
                        @else
                            <p class="textCenter textBeefy" style="margin: 0 0 6px 0;">{{ __('t_lifeforms_ui.selection.chosen', ['species' => __('t_lifeforms.species.' . $choisie->machineName())]) }}</p>
                            <p class="smallFont textCenter" style="margin: 0;">{{ __('t_lifeforms_ui.selection.permanent') }}</p>
                        @endif
                    </div>
                    <div class="footer"></div>
                </div>

                @foreach ($especes as $espece)
                    @php /** @var OGame\Lifeforms\Species $s */ $s = $espece['species']; $n = $s->value; @endphp
                    <div class="content-box-s lifeform-species lifeform-species-{{ $s->machineName() }}" data-species="{{ $n }}" style="margin-top: 12px;">
                        <div class="header"><h3>{{ $espece['name'] }}@if ($espece['chosen']) — {{ __('t_lifeforms_ui.selection.chosen_badge') }}@endif</h3></div>
                        <div class="content" style="padding: 12px; display: flex; gap: 14px; align-items: flex-start; flex-wrap: wrap;">
                            <div style="flex: 0 0 auto; text-align: center;">
                                <div class="lifeform-portrait" role="img" aria-label="{{ $espece['name'] }}"
                                     style="width: 200px; height: 200px; background: url('{{ asset('img/lifeform/lifeformtype_sprite.png') }}') no-repeat -{{ ($n - 1) * 200 }}px 0; border: 1px solid #3b5164;"></div>
                                <div class="xpbar" style="margin-top: 8px;">
                                    @php $part = $espece['experience_needed'] > 0 ? min(1, $espece['experience_progress'] / $espece['experience_needed']) : 1; @endphp
                                    <svg width="84" height="84" viewBox="0 0 84 84" aria-hidden="true">
                                        <circle cx="42" cy="42" r="38" fill="none" stroke="#1d2f3d" stroke-width="6"></circle>
                                        <circle class="progress-ring__circle" cx="42" cy="42" r="38" fill="none" stroke="#7fcf93" stroke-width="6"
                                                stroke-dasharray="238.76 238.76" stroke-dashoffset="{{ 238.76 - ($part * 238.76) }}" transform="rotate(-90 42 42)"></circle>
                                        <text x="42" y="47" text-anchor="middle" fill="#dce7ef" font-size="16" font-family="Arial">{{ $espece['experience_level'] }}</text>
                                    </svg>
                                    <div class="smallFont">{{ __('t_lifeforms_ui.selection.level', ['level' => $espece['experience_level'], 'xp' => $espece['experience_progress'] . '/' . $espece['experience_needed']]) }}</div>
                                    <div class="smallFont">{{ __('t_lifeforms_ui.selection.tech_bonus', ['bonus' => rtrim(rtrim(number_format($espece['experience_bonus'], 1, '.', ''), '0'), '.')]) }}</div>
                                </div>
                            </div>
                            <div style="flex: 1 1 320px; min-width: 0;">
                                <p style="margin: 0 0 8px 0;">{{ $espece['lore'] }}</p>
                                <p class="smallFont" style="margin: 0 0 10px 0;"><strong>{{ __('t_lifeforms_ui.selection.usage') }}</strong> {{ $espece['usage'] }}</p>
                                @if ($choisie === null)
                                    <form method="post" action="{{ route('lifeforms.select') }}" class="lifeform-select-form" onsubmit="return confirm({{ json_encode(__('t_lifeforms_ui.selection.confirm', ['species' => $espece['name']])) }});">
                                        {{ csrf_field() }}
                                        <input type="hidden" name="species" value="{{ $n }}">
                                        <button type="submit" class="btn_blue">{{ __('t_lifeforms_ui.selection.select') }}</button>
                                    </form>
                                @elseif ($espece['chosen'])
                                    <a class="btn_blue" href="{{ route('lifeforms.buildings') }}">{{ __('t_lifeforms_ui.selection.go_to_buildings') }}</a>
                                    <a class="btn_blue" href="{{ route('lifeforms.research') }}">{{ __('t_lifeforms_ui.selection.go_to_research') }}</a>
                                    <a class="btn_blue" href="{{ route('lifeforms.discoveries') }}">{{ __('t_lifeforms_ui.discoveries.go_to_discoveries') }}</a>
                                    <a class="btn_blue" href="{{ route('lifeforms.bonuses') }}">{{ __('t_lifeforms_ui.bonuses.go_to_bonuses') }}</a>
                                @else
                                    <p class="smallFont" style="margin: 0;">{{ __('t_lifeforms_ui.selection.other_species') }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="content" style="padding: 0 12px 12px 12px;">
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
                        <div class="footer"></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
