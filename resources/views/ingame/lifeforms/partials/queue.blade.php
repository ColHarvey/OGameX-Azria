@php
    /** @var OGame\Models\Lifeforms\LifeformQueue|null $queue_active */
    $cancelRoute = route('lifeforms.buildings.cancelbuildrequest');
    $titreDe = fn (int $id): string => __('t_lifeforms.' . OGame\Lifeforms\Catalogue\LifeformCatalogue::byId($id)->machineName . '.title');
@endphp
<div class="content-box-s">
    <div class="header"><h3>{{ $kind === 'building' ? __('t_lifeforms_ui.queue.buildings_title') : __('t_lifeforms_ui.queue.research_title') }}</h3></div>
    <div class="content">
        @if ($queue_active !== null)
            @php $reste = max(0, (int)$queue_active->time_end - (int)\Illuminate\Support\Facades\Date::now()->timestamp); @endphp
            <table cellspacing="0" cellpadding="0" class="construction active">
                <tbody>
                <tr>
                    <th colspan="2">{{ $titreDe((int)$queue_active->object_id) }}</th>
                </tr>
                <tr class="data">
                    <td class="first" rowspan="3">
                        <div>
                            <a href="javascript:void(0);" class="tooltip js_hideTipOnMobile tpd-hideOnClickOutside" style="display: block;"
                               onclick="cancelbuilding({{ $queue_active->object_id }}, {{ $queue_active->id }}, {{ json_encode(__('t_lifeforms_ui.queue.cancel_question', ['title' => $titreDe((int)$queue_active->object_id), 'level' => $queue_active->target_level])) }}); return false;" title="">
                                <span class="queuePic lifeformsprite lifeformqueue lifeformTech{{ $queue_active->object_id }}" style="display: block; width: 40px; height: 40px;"></span>
                            </a>
                            <a href="javascript:void(0);" class="tooltip js_hideTipOnMobile abortNow"
                               onclick="cancelbuilding({{ $queue_active->object_id }}, {{ $queue_active->id }}, {{ json_encode(__('t_lifeforms_ui.queue.cancel_question', ['title' => $titreDe((int)$queue_active->object_id), 'level' => $queue_active->target_level])) }}); return false;"
                               title="{{ __('t_lifeforms_ui.queue.cancel_question', ['title' => $titreDe((int)$queue_active->object_id), 'level' => $queue_active->target_level]) }}">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="15" width="15" alt="">
                            </a>
                        </div>
                    </td>
                    <td class="desc ausbau">
                        {{ __('t_lifeforms_ui.queue.improve_to') }}
                        <span class="level">{{ __('t_ingame.ajax_object.level') }} {{ $queue_active->target_level }}</span>
                    </td>
                </tr>
                <tr class="data">
                    <td class="desc">{{ __('t_lifeforms_ui.queue.duration') }}</td>
                </tr>
                <tr class="data">
                    <td class="desc timer">
                        <time class="countdown lfBuildingCountdown" data-segments="2">{{ \OGame\Facades\AppUtil::formatTimeDuration($reste) }}</time>
                    </td>
                </tr>
                </tbody>
            </table>
            <script type="text/javascript">
                var cancelBuildListEntryUrl = '{{ $cancelRoute }}';
                new CountdownTimer('lfBuildingCountdown', {{ $reste }}, '{{ url()->current() }}', null, true, 3)
                function cancelbuilding(id, listId, question) {
                    errorBoxDecision({{ json_encode(__('t_ingame.shared.caution')) }}, "" + question + "", {{ json_encode(__('t_ingame.shared.yes')) }}, {{ json_encode(__('t_ingame.shared.no')) }}, function () {
                        buildListActionCancel(id, listId)
                    });
                }
            </script>
        @else
            <table cellspacing="0" cellpadding="0" class="construction active">
                <tbody>
                <tr>
                    <td colspan="2" class="idle">
                        <a class="tooltip js_hideTipOnMobile" title="{{ __('t_lifeforms_ui.queue.idle_tooltip') }}" href="{{ $kind === 'building' ? route('lifeforms.buildings') : route('lifeforms.research') }}">
                            {{ $kind === 'building' ? __('t_lifeforms_ui.queue.idle_buildings') : __('t_lifeforms_ui.queue.idle_research') }}
                        </a>
                    </td>
                </tr>
                </tbody>
            </table>
            <script type="text/javascript">
                var cancelBuildListEntryUrl = '{{ $cancelRoute }}';
                function cancelbuilding(id, listId, question) {
                    errorBoxDecision({{ json_encode(__('t_ingame.shared.caution')) }}, "" + question + "", {{ json_encode(__('t_ingame.shared.yes')) }}, {{ json_encode(__('t_ingame.shared.no')) }}, function () {
                        buildListActionCancel(id, listId)
                    });
                }
            </script>
        @endif
        @if (count($queue_waiting) > 0)
            <table class="queue">
                <tbody>
                <tr>
                    @foreach ($queue_waiting as $item)
                        <td>
                            <a href="javascript:void(0);" class="queue_link tooltip js_hideTipOnMobile dark_highlight_tablet"
                               onclick="cancelbuilding({{ $item->object_id }},{{ $item->id }},{{ json_encode(__('t_lifeforms_ui.queue.cancel_question', ['title' => $titreDe((int)$item->object_id), 'level' => $item->target_level])) }}); return false;"
                               title="{{ $titreDe((int)$item->object_id) }}">
                                <span class="queuePic lifeformsprite lifeformqueuetiny lifeformTech{{ $item->object_id }}" style="display: inline-block; width: 28px; height: 28px;"></span>
                                <span>{{ $item->target_level }}</span>
                            </a>
                        </td>
                    @endforeach
                </tr>
                </tbody>
            </table>
        @endif
    </div>
    <div class="footer"></div>
</div>
