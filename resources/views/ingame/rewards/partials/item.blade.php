@php
    $day = $item['day'];
    $stateClass = $item['state'] === 'claimed' ? 'rewardclaimed' : 'rewardnotclaim';
@endphp

<div class="rewardlist-item">
    <div class="rewardlistimg rewardlistimg_{{ $day }} {{ $stateClass }}">
        <div class="rewardlist-item-icon">
            <img src="/img/icons/2251eaefdfdf075833e5247781a4ac.png" alt="">
        </div>
        <div class="rewardlist-item-text">
            <h3>{{ __('t_ingame.rewards.day_' . $day . '_title') }}</h3>
            <div class="rewardlist-item-wrapper">
                <p>
                    {{ __('t_ingame.rewards.greeting', ['name' => $playerName]) }}<br><br>
                    {{ __('t_ingame.rewards.day_' . $day . '_text') }}<br><br>
                    @if ($item['summary'] !== '')
                        <strong>{{ $item['summary'] }}</strong><br><br>
                    @endif
                    {{ __('t_ingame.rewards.good_luck') }}<br>
                    {{ __('t_ingame.rewards.signature') }}
                </p>

                @if ($item['state'] === 'claimable')
                    <form id="claim-form-{{ $day }}" method="POST" action="{{ route('rewards.claim') }}" style="display:none">
                        @csrf
                        <input type="hidden" name="day" value="{{ $day }}">
                    </form>
                    <a class="reward-button" href="javascript:void(0)"
                       onclick="document.getElementById('claim-form-{{ $day }}').submit();">{{ __('t_ingame.rewards.btn_claim') }}</a>
                @elseif ($item['state'] === 'claimed')
                    <a class="reward-button disabled" href="javascript:void(0)">{{ __('t_ingame.rewards.btn_claimed') }}</a>
                @elseif ($item['state'] === 'unavailable')
                    <a class="reward-button disabled" href="javascript:void(0)">{{ __('t_ingame.rewards.btn_unavailable') }}</a>
                @else
                    <a class="reward-button disabled" href="javascript:void(0)">{{ __('t_ingame.rewards.btn_locked', ['days' => $item['unlocks_in_days']]) }}</a>
                @endif
            </div>
            <div class="rewardlist-item-bottom"></div>
        </div>
    </div>
</div>
<br>
