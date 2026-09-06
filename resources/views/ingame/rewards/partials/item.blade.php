@php
    $day = $item['day'];
    $stateClass = $item['state'] === 'claimed' ? 'rewardclaimed' : 'rewardnotclaim';

    // Le choix n'est propose que sur une recompense reclamable qui en attend un : une carte deja
    // encaissee ou encore verrouillee montrerait des portraits sur lesquels rien ne peut etre fait.
    $choixOfficiers = $item['state'] === 'claimable' ? $item['officer_choices'] : [];
@endphp

<div class="rewardlist-item">
    <div class="rewardlistimg rewardlistimg_{{ $day }} {{ $stateClass }} {{ $choixOfficiers !== [] ? 'rewardWithChoice' : '' }}">
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
                    {{-- Le formulaire ne se cache que lorsqu'il n'a rien a montrer : des qu'il porte
                         un choix, il doit etre visible et utilisable au clavier. --}}
                    <form id="claim-form-{{ $day }}" method="POST" action="{{ route('rewards.claim') }}"
                          @class(['rewardOfficerChoice' => $choixOfficiers !== []])
                          @style(['display:none' => $choixOfficiers === []])>
                        @csrf
                        <input type="hidden" name="day" value="{{ $day }}">

                        @if ($choixOfficiers !== [])
                            <span class="rewardOfficerChoice-hint">{{ __('t_ingame.rewards.officer_choice_hint') }}</span>
                            <ul class="rewardOfficerChoice-list">
                                @foreach ($choixOfficiers as $officier)
                                    <li>
                                        {{-- Le libelle enveloppe le bouton radio : tout le portrait
                                             devient cliquable, et le nom reste lisible sous lui. --}}
                                        <label class="rewardOfficerChoice-option"
                                               title="{{ __('t_ingame.premium.officer_' . $officier) }} — {{ __('t_ingame.premium.effects_' . $officier) }}">
                                            <input type="radio" name="officer" value="{{ $officier }}" @checked($loop->first)>
                                            <span class="rewardOfficerChoice-portrait {{ $officier }}" aria-hidden="true"></span>
                                            <span class="rewardOfficerChoice-name">{{ __('t_ingame.premium.officer_' . $officier) }}</span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </form>
                    <a class="reward-button" href="javascript:void(0)"
                       onclick="document.getElementById('claim-form-{{ $day }}').submit();">{{ __('t_ingame.rewards.btn_claim') }}</a>
                @elseif ($item['state'] === 'claimed')
                    <a class="reward-button disabled" href="javascript:void(0)">{{ __('t_ingame.rewards.btn_claimed') }}</a>
                @else
                    <a class="reward-button disabled" href="javascript:void(0)">{{ __('t_ingame.rewards.btn_locked', ['days' => $item['unlocks_in_days']]) }}</a>
                @endif
            </div>
            <div class="rewardlist-item-bottom"></div>
        </div>
    </div>
</div>
<br>
