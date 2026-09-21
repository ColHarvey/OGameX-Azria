{{--
    La bulle d annonce de la vue generale.

    **Un seul rendu pour deux usages** : la vue generale du joueur et l apercu de l administration. Un apercu
    qui passerait par un autre gabarit ne montrerait pas ce que le joueur verra — et une faille d echappement
    ne serait fermee que d un cote.

    **Tout est echappe.** Le message conserve ses retours a la ligne par `white-space: pre-wrap`, pose dans la
    feuille : aucun `nl2br`, donc aucun `{!! !!}`, donc aucun HTML libre possible.

    Le lien est passe par `SafeAnnouncementLink` avant d arriver ici — chemin local ou `http(s)` absolu,
    jamais autre chose.
--}}
<div class="azria-announcement"
     id="azriaAnnouncement"
     data-version="{{ $announcement->version }}"
     @if(!empty($preview)) data-preview="1" @endif
     role="region"
     aria-label="{{ __('t_ingame.announcement.region_label') }}">

    <img class="azria-announcement__icon"
         src="{{ asset('img/icons/transmission.svg') }}"
         width="40" height="40" alt="">

    <div class="azria-announcement__content">
        <div class="azria-announcement__heading">
            <h3 class="azria-announcement__title">{{ $announcement->title }}</h3>
            <span class="azria-announcement__badge">{{ __('t_ingame.announcement.badge') }}</span>
        </div>

        @if(!empty($announcement->body))
            <p class="azria-announcement__body">{{ $announcement->body }}</p>
        @endif

        @if(!empty($announcement->link_url))
            <div class="azria-announcement__actions">
                <a class="azria-announcement__link" href="{{ $announcement->link_url }}">
                    {{ $announcement->link_label ?: __('t_ingame.announcement.default_link_label') }}
                </a>
            </div>
        @endif
    </div>

    @if($announcement->dismissible)
        {{--
            La croix n est offerte que si la publication l autorise. Le serveur le revérifie sur **cette
            version-la** : un bouton absent n est pas une protection.
        --}}
        <button type="button"
                class="azria-announcement__close"
                data-dismiss-url="{{ route('announcement.dismiss') }}"
                data-token="{{ csrf_token() }}"
                aria-label="{{ __('t_ingame.announcement.dismiss') }}"
                title="{{ __('t_ingame.announcement.dismiss') }}">&times;</button>
    @endif
</div>
