@php /** @var OGame\Models\Resources $price */ /** @var OGame\Lifeforms\Catalogue\LifeformObject $object */ @endphp

<div id="technologydetails" data-technology-id="{{ $object->id }}">
    <div class="lifeformsprite sprite_large lifeformTech{{ $object->id }}">
        @if ($active_item !== null)
            <a role="button" href="javascript:void(0);" class="tooltip abort_link js_hideTipOnMobile" title=""
               onclick="cancelbuilding({{ $object->id }},{{ $active_item->id }},{{ json_encode(__('t_ingame.ajax_object.cancel_expansion_confirm', ['name' => $title, 'level' => $active_item->target_level])) }}); return false;"></a>
        @endif
    </div>

    <div class="content">
        <button class="close">✖</button>
        <h3>{{ $title }}</h3>

        <div class="information">
            <span class="level" data-value="{{ $next_level }}">
                {{ __('t_ingame.ajax_object.level') }} {{ $current_level }}
            </span>
            <ul class="narrow">
                <li class="build_duration"><strong>{{ __('t_ingame.ajax_object.production_duration') }}</strong>
                    <time class="value tooltip" title="">{{ $production_time }}</time>
                </li>
                @if ($energy > 0)
                    <li class="additional_energy_consumption"><strong>{{ __('t_ingame.ajax_object.energy_needed') }}</strong>
                        <span class="value tooltip" data-value="{{ $energy }}" title="">{{ $energy }}</span>
                    </li>
                @endif
                @if ($population_required !== null)
                    <li class="lifeform_population"><strong>{{ __('t_lifeforms_ui.buildings.population_required') }}</strong>
                        <span class="value tooltip" title="{{ __('t_lifeforms_ui.buildings.population_current', ['current' => $population_current]) }}">{{ $population_required }}</span>
                    </li>
                @endif
            </ul>

            <div class="costs">
                <p>{{ __('t_ingame.ajax_object.required_to_improve') }} {{ $next_level }}:</p>
                <ul class="ipiHintable" data-ipi-hint="">
                    @if (!empty($price->metal->get()))
                        <li class="resource metal icon tooltip js_hideTipOnMobile {{ $planet->metal()->get() < $price->metal->get() ? 'insufficient' : 'sufficient' }}" data-value="{{ $price->metal->get() }}"
                            title="{{ $price->metal->getFormattedLong() }} {{ __('t_ingame.ajax_object.metal') }}">{{ $price->metal->getFormatted() }}</li>
                    @endif
                    @if (!empty($price->crystal->get()))
                        <li class="resource crystal icon tooltip js_hideTipOnMobile {{ $planet->crystal()->get() < $price->crystal->get() ? 'insufficient' : 'sufficient' }}" data-value="{{ $price->crystal->get() }}"
                            title="{{ $price->crystal->getFormattedLong() }} {{ __('t_ingame.ajax_object.crystal') }}">{{ $price->crystal->getFormatted() }}</li>
                    @endif
                    @if (!empty($price->deuterium->get()))
                        <li class="resource deuterium icon tooltip js_hideTipOnMobile {{ $planet->deuterium()->get() < $price->deuterium->get() ? 'insufficient' : 'sufficient' }}" data-value="{{ $price->deuterium->get() }}"
                            title="{{ $price->deuterium->getFormattedLong() }} {{ __('t_ingame.ajax_object.deuterium') }}">{{ $price->deuterium->getFormatted() }}</li>
                    @endif
                </ul>
            </div>

            @if (count($requirements) > 0)
                <div class="lifeform_requirements smallFont" style="margin-top: 6px;">
                    <strong>{{ __('t_lifeforms_ui.buildings.requirements') }}</strong>
                    @foreach ($requirements as $requirement)
                        <span class="{{ $requirement['met'] ? 'undermark' : 'overmark' }}">{{ $requirement['title'] }} {{ $requirement['level'] }}</span>@if (!$loop->last), @endif
                    @endforeach
                </div>
            @endif

            @if ($available)
            <div class="build-it_wrap">
                <div class="ipiHintable" data-ipi-hint="ipiTechnologyUpgradeLifeform{{ $object->id }}">
                    <button class="upgrade" data-technology="{{ $object->id }}" @if (!$can_build) disabled @endif>
                        <span class="label tooltip" title="{{ $reason ?? '' }}">{{ !empty($queue_busy) ? __('t_ingame.ajax_object.in_queue') : __('t_ingame.ajax_object.improve') }}</span>
                        <span class="label_bg"></span>
                    </button>
                </div>
            </div>
            @endif
        </div>

    </div>

    {{-- La bande de description est un frere de `.content` (journal §155.23). --}}
    <div class="description">
        @if (!$available)
            {{-- L effet n est pas applique : aucun bonus promis, l indisponibilite en clair (journal §155.26). --}}
            <p class="overmark lifeform_unavailable" style="margin: 0 0 6px 0;">{{ __('t_lifeforms_ui.refused.not_available') }}</p>
        @else
        <div class="lifeform_effects">
            <table class="lifeform_effects_table smallFont" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align: left;">{{ __('t_lifeforms_ui.buildings.effect') }}</th>
                        <th style="text-align: right;">{{ __('t_lifeforms_ui.buildings.now') }}</th>
                        <th style="text-align: right;">{{ __('t_lifeforms_ui.buildings.next') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($effects as $effect)
                        <tr data-effect="{{ $effect['code'] }}">
                            <td>{{ $effect['label'] }}</td>
                            <td style="text-align: right;">{{ $effect['now'] }}</td>
                            <td style="text-align: right;" class="undermark">{{ $effect['next'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="txt_box">
            <span class="text smallFont">{{ $description }}</span>
        </div>
    </div>
</div>

{{-- **Une variable que le bundle lit au clic du bouton du panneau** (`TechnologyDetails`, garde du plafond de bonus) : la
     fiche officielle la pose en fin de fragment, que jQuery execute a l insertion. Sans elle : ReferenceError, et la
     recherche ne partait pas (demo pilotee, journal §155.24). Le plafond n est pas mesure ici : aucun avertissement. --}}
<script type="text/javascript">
    var showLifeformBonusCapReached = false;
</script>
