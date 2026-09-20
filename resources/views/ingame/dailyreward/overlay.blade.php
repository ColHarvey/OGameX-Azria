{{--
    La fenetre de la recompense quotidienne.

    **Tout ce qui decide vient du serveur** : la journee, le montant, l eligibilite, et les secondes restantes.
    L horloge du navigateur n anime qu un compte a rebours, et n accorde jamais rien. A zero, la fenetre redemande
    son etat au serveur au lieu de conclure elle-meme — c est ce qui la remet d aplomb au passage de minuit, sans
    recharger la page.

    Le fragment porte ses donnees dans des attributs `data-*` : le script du jeu les lit sans qu aucune valeur ne
    soit interpolee dans du JavaScript.
--}}
<div id="dailyRewardWindow" class="az-daily-reward"
     data-state-url="{{ route('daily_reward.state') }}"
     data-claim-url="{{ route('daily_reward.claim') }}"
     data-claimed="{{ $state['claimed'] ? '1' : '0' }}"
     data-seconds="{{ $state['seconds_remaining'] }}"
     data-amount="{{ $state['amount'] }}"
     data-token="{{ csrf_token() }}">

    <div class="az-daily-reward__illustration">
        {{-- L illustration existante de matiere noire : la meme planche et la meme case que la boutique. --}}
        <div class="officers100 darkMatter" role="img" aria-label="{{ __('t_ingame.layout.res_dark_matter') }}"></div>
    </div>

    <p class="az-daily-reward__amount">
        <span class="az-daily-reward__figure">{{ $state['amount_formatted'] }}</span>
        <span class="az-daily-reward__unit">{{ __('t_ingame.layout.res_dark_matter') }}</span>
    </p>

    <p class="az-daily-reward__renewal">{{ __('t_ingame.daily_reward.renewal') }}</p>

    <p class="az-daily-reward__countdown">
        <span class="az-daily-reward__countdown-label" data-label-expiry="{{ __('t_ingame.daily_reward.expires_in') }}"
              data-label-next="{{ __('t_ingame.daily_reward.next_in') }}">{{ $state['claimed'] ? __('t_ingame.daily_reward.next_in') : __('t_ingame.daily_reward.expires_in') }}</span>
        <span class="az-daily-reward__timer" aria-live="polite">—</span>
    </p>

    <div class="az-daily-reward__action">
        <button type="button" class="az-daily-reward__claim"
                data-label-claim="{{ __('t_ingame.daily_reward.claim') }}"
                data-label-claimed="{{ __('t_ingame.daily_reward.already_short') }}"
                @if ($state['claimed']) disabled @endif>
            {{ $state['claimed'] ? __('t_ingame.daily_reward.already_short') : __('t_ingame.daily_reward.claim') }}
        </button>
    </div>

    {{-- La regle, en clair : elle ne depend d aucun etat et ne bouge jamais. --}}
    <p class="az-daily-reward__warning">{{ __('t_ingame.daily_reward.no_carry_over') }}</p>

    <p class="az-daily-reward__message" role="status" aria-live="polite"></p>
</div>
