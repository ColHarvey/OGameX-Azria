{{-- La fiche publique d une alliance : le SEUL partiel de contenu, rendu par la fenetre superposee comme par la page
     autonome. Tout vient de `PublicProfile` — un tableau nomme, jamais le modele — et rien ici ne peut atteindre une
     colonne reservee aux membres.

     Ce qui n existe pas se dit : « Non classee » sans rang publie, jamais un zero ; « Aucune classe » avec l embleme
     neutre, qui signifie l absence de classe et n est pas un logo de remplacement ; le logo collectif vectoriel quand
     l alliance n en a pas de valide. Une image qui ne charge pas bascule UNE fois vers le logo de remplacement, par
     un drapeau : pas de boucle.

     La presentation publique est du BBCode rendu par le parseur serveur, qui echappe tout avant de poser ses balises
     et ne rend un lien que vers http ou https. C est l unique `{!! !!}` de cette fiche, et il ne porte que ce que ce
     parseur a produit. Les emblemes reprennent `.sprite.allianceclass.medium.<machine>` : 60 px depuis les images
     `_100.png`, sans nouveau CSS. --}}
@php
    use OGame\Facades\AppUtil;
@endphp
@if ($profile === null)
    <div class="azria-alliance-profile ap-missing" role="alert">
        <p>{{ __('t_ingame.alliance.profile_missing') }}</p>
    </div>
@else
    <div class="azria-alliance-profile" data-alliance-id="{{ $profile['id'] }}">
        <div class="ap-header">
            <img class="ap-logo"
                 src="{{ $profile['logo'] ?? asset('img/alliance/alliance-placeholder.svg') }}"
                 alt="{{ __('t_ingame.alliance.profile_logo_alt', ['tag' => $profile['tag']]) }}"
                 width="80" height="80"
                 data-fallback="{{ asset('img/alliance/alliance-placeholder.svg') }}"
                 onerror="if (!this.dataset.fell) { this.dataset.fell = '1'; this.src = this.dataset.fallback; }">
            <div class="ap-identity">
                <div class="ap-tag">[{{ $profile['tag'] }}]</div>
                <h2>{{ $profile['name'] }}</h2>
            </div>
        </div>

        <dl class="ap-stats">
            <div class="ap-stat">
                <dt>{{ __('t_ingame.alliance.profile_members') }}</dt>
                <dd>{{ $profile['members'] }}</dd>
            </div>
            <div class="ap-stat">
                <dt>{{ __('t_ingame.alliance.profile_rank') }}</dt>
                <dd>{{ $profile['rank'] === null ? __('t_ingame.alliance.profile_unranked') : $profile['rank'] }}</dd>
            </div>
            <div class="ap-stat">
                <dt>{{ __('t_ingame.alliance.profile_points') }}</dt>
                <dd>{{ $profile['points'] === null ? '&ndash;' : AppUtil::formatNumber($profile['points']) }}</dd>
            </div>
        </dl>

        <div class="ap-class">
            <span class="sprite allianceclass medium {{ $profile['class']?->getMachineName() ?? 'none' }}"
                  role="img"
                  aria-label="{{ $profile['class']?->getName() ?? __('t_ingame.alliance.class_none_selected') }}"></span>
            <div class="ap-class-text">
                <h3>{{ __('t_ingame.alliance.class_label') }}</h3>
                <p class="ap-class-name">{{ $profile['class']?->getName() ?? __('t_ingame.alliance.class_none_selected') }}</p>
                @if ($profile['class'] !== null)
                    <ul class="ap-bonus">
                        @foreach ($profile['class']->bonusLabels() as $bonus)
                            <li>{{ $bonus }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        @if ($profile['description'] !== '')
            <div class="ap-description">{!! $profile['description'] !!}</div>
        @else
            <p class="ap-description ap-empty">{{ __('t_ingame.alliance.profile_description_none') }}</p>
        @endif

        @if ($profile['homepage'] !== null)
            <p class="ap-homepage">
                <span class="ap-homepage-label">{{ __('t_ingame.alliance.homepage') }}</span>
                <a href="{{ $profile['homepage'] }}" target="_blank" rel="noopener noreferrer">{{ $profile['homepage'] }}</a>
            </p>
        @endif

        @if ($canApply)
            <div class="ap-actions">
                <button type="button"
                        class="btn_blue js_allianceApply"
                        data-alliance-id="{{ $profile['id'] }}"
                        data-apply-url="{{ route('alliance.apply') }}"
                        data-token="{{ csrf_token() }}"
                        data-confirm="{{ __('t_ingame.alliance.apply_confirm') }}"
                        data-success="{{ __('t_ingame.alliance.msg_apply_success') }}"
                        data-error="{{ __('t_ingame.alliance.msg_apply_error') }}"
                        data-applied="{{ __('t_ingame.alliance.profile_applied') }}">{{ __('t_ingame.alliance.apply_title') }}</button>
            </div>
        @endif
    </div>
@endif
