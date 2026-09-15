@extends('ingame.layouts.main')

@section('content')

    <div id="lfbonusescomponent" class="maincontent">
        <div id="lifeforms">
            <header id="planet" data-anchor="technologyDetails">
                <h2>{{ __('t_lifeforms_ui.bonuses.title') }}</h2>
            </header>

            <div id="technologies">
                <div class="content-box-s" id="lifeform-experience-bonuses">
                    <div class="header"><h3>{{ __('t_lifeforms_ui.bonuses.experience_title') }}</h3></div>
                    <div class="content" style="padding: 12px;">
                        <p class="smallFont" style="margin: 0 0 10px 0;">{{ __('t_lifeforms_ui.bonuses.experience_intro') }}</p>
                        <div style="display: flex; gap: 18px; flex-wrap: wrap; justify-content: center;">
                            @foreach ($experience as $espece)
                                @php $n = $espece['species']->value; $part = $espece['needed'] > 0 ? min(1, $espece['progress'] / $espece['needed']) : 1; @endphp
                                <div class="lifeform-experience-item" style="text-align: center; min-width: 120px;" data-species="{{ $n }}">
                                    <div class="currentlevel smallFont">{{ __('t_lifeforms_ui.bonuses.level', ['level' => $espece['level']]) }}</div>
                                    <div class="lifeform-item-icon lifeform{{ $n }}" role="img" aria-label="{{ $espece['name'] }}"
                                         style="width: 88px; height: 88px; margin: 4px auto; background: url('{{ asset('img/lifeform/lifeformtype_sprite.png') }}') no-repeat -{{ ($n - 1) * 88 }}px 0; background-size: 352px auto;"></div>
                                    <div class="xpbar">
                                        <svg width="72" height="72" viewBox="0 0 88 88" aria-hidden="true">
                                            <circle cx="44" cy="44" r="38" fill="none" stroke="#1d2f3d" stroke-width="5"></circle>
                                            <circle class="progress-ring__circle" cx="44" cy="44" r="38" fill="none" stroke="#99cc00" stroke-width="5"
                                                    stroke-dasharray="238.76 238.76" stroke-dashoffset="{{ 238.76 - ($part * 238.76) }}" transform="rotate(-90 44 44)"></circle>
                                        </svg>
                                    </div>
                                    <div class="smallFont">{{ $espece['name'] }}</div>
                                    <div class="smallFont">{{ $espece['progress'] }}/{{ $espece['needed'] }} XP</div>
                                    <div class="bonusValue">{{ __('t_lifeforms_ui.bonuses.bonus', ['value' => rtrim(rtrim(number_format($espece['bonus'], 1, '.', ''), '0'), '.')]) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="footer"></div>
                </div>

                <div class="content-box-s" id="lifeform-effect-bonuses" style="margin-top: 12px;">
                    <div class="header"><h3>{{ __('t_lifeforms_ui.bonuses.effects_title') }}</h3></div>
                    <div class="content" style="padding: 12px;">
                        @if (count($effets) === 0)
                            <p class="smallFont" style="margin: 0;">{{ __('t_lifeforms_ui.bonuses.none') }}</p>
                        @else
                            <p class="smallFont" style="margin: 0 0 10px 0;">{{ __('t_lifeforms_ui.bonuses.effects_intro') }}</p>
                            @foreach ($effets as $effet)
                                <div class="lifeform-bonus-item" style="border: 1px solid #3b5164; margin-bottom: 8px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 8px; background: #1d2f3d;">
                                        <span class="subCategoryTitle">{{ $effet['label'] }}</span>
                                        <span class="subCategoryBonus">{{ rtrim(rtrim(number_format($effet['total'], 2, '.', ''), '0'), '.') }} %@if ($effet['capped']) <span class="smallFont" title="{{ __('t_lifeforms_ui.bonuses.capped_hint') }}">({{ __('t_lifeforms_ui.bonuses.capped') }})</span>@endif</span>
                                    </div>
                                    @foreach ($effet['planets'] as $planete)
                                        <div class="lifeform-bonus-planet" style="padding: 4px 8px;">
                                            <div class="smallFont" style="display: flex; justify-content: space-between;">
                                                <span>{{ $planete['name'] }} [{{ $planete['coordinates'] }}]</span>
                                                <span>{{ rtrim(rtrim(number_format($planete['total'], 2, '.', ''), '0'), '.') }} %</span>
                                            </div>
                                            <table class="smallFont" style="width: 100%; border-collapse: collapse;">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 60px; text-align: center;">{{ __('t_lifeforms_ui.bonuses.slot') }}</th>
                                                        <th style="width: 60px; text-align: center;">{{ __('t_lifeforms_ui.bonuses.level_column') }}</th>
                                                        <th style="text-align: left;">{{ __('t_lifeforms_ui.bonuses.technology') }}</th>
                                                        <th style="width: 80px; text-align: center;">{{ __('t_lifeforms_ui.bonuses.total') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($planete['rows'] as $ligne)
                                                        <tr>
                                                            <td style="text-align: center;">{{ $ligne['slot'] }}</td>
                                                            <td style="text-align: center;">{{ $ligne['level'] }}</td>
                                                            <td>{{ $ligne['title'] }}</td>
                                                            <td style="text-align: center;">{{ rtrim(rtrim(number_format($ligne['percent'], 2, '.', ''), '0'), '.') }} %</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        @endif
                    </div>
                    <div class="footer"></div>
                </div>
            </div>
        </div>
    </div>

@endsection
