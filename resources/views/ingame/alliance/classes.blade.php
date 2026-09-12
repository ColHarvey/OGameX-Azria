{{-- Alliance Classes Tab --}}
@php
    use OGame\Enums\AllianceClass;

    /*
     * **La page ne decide de rien, elle montre.** Le droit, la monnaie et le doublon sont juges par
     * `AllianceClassService` ; la page ne fait qu'en refleter l'etat, pour qu'un bouton n'invite
     * jamais a un geste que le serveur refusera. Le serveur refuse quand meme : un bouton grise
     * n'est pas une protection.
     */
    $classeActive = $allianceClass ?? null;
    $peutChoisir = $mayChooseClass ?? false;
    $matiereNoire = (int)($darkMatter ?? 0);
    $prix = (int)($classPrice ?? AllianceClass::PRICE_IN_DARK_MATTER);
@endphp
<div id="allianceclassselection">
    <div class="content">
        <h2>{{ __('t_ingame.alliance.select_class_title') }}</h2>
        <p>{{ __('t_ingame.alliance.select_class_note') }}</p>

        <p class="allianceclass current">
            <strong>{{ __('t_ingame.alliance.class_current') }} :</strong>
            {{ $classeActive === null ? __('t_ingame.alliance.class_none_selected') : $classeActive->getName() }}
        </p>

        @unless ($peutChoisir)
            <p class="allianceclass hint">{{ __('t_ingame.alliance.class_no_permission_hint') }}</p>
        @endunless

        <div class="allianceclass boxes">
            @foreach (AllianceClass::cases() as $classe)
                @php
                    $estActive = $classeActive === $classe;
                    $abordable = $matiereNoire >= $prix;
                    // Trois raisons de ne pas offrir le geste, et chacune a son titre.
                    $offert = $peutChoisir && !$estActive && $abordable;
                    $titre = match (true) {
                        $estActive => __('t_ingame.alliance.class_already_selected'),
                        !$peutChoisir => __('t_ingame.alliance.class_not_allowed'),
                        !$abordable => __('t_ingame.alliance.no_dark_matter'),
                        default => '',
                    };
                @endphp
                <div class="allianceclass box {{ $estActive ? 'active' : '' }}"
                     data-alliance-class-id="{{ $classe->value }}"
                     data-alliance-class-name="{{ $classe->getName() }}"
                     data-alliance-class-price="{{ $prix }}">
                    <div class="buttons">
                        @if ($offert)
                            <a class="build-it js_hideTipOnMobile allianceclass-choose" href="#"
                               data-alliance-class-id="{{ $classe->value }}">
                                <span>{{ __('t_ingame.alliance.class_change_for') }}<br>{{ number_format($prix, 0, ',', '.') }} DM</span>
                            </a>
                        @else
                            <a class="build-it_disabled tooltip js_hideTipOnMobile {{ $abordable ? '' : 'nodarkmatter' }}"
                               rel="{{ route('premium.index') }}"
                               data-tooltip-title="{{ $titre }}">
                                <span>{{ __('t_ingame.alliance.buy_for') }}<br>{{ number_format($prix, 0, ',', '.') }} DM</span>
                            </a>
                        @endif
                    </div>
                    <div class="sprite allianceclass large {{ $classe->getMachineName() }}"></div>
                    <div class="boxClassBoni">
                        <h2>{{ $classe->getName() }}</h2>
                        <ul>
                            @foreach ($classe->bonusLabels() as $bonus)
                                <li class="allianceclass bonus">{{ $bonus }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>

        <br>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('#allianceclassselection').off('click', '.allianceclass-choose').on('click', '.allianceclass-choose', function(event) {
            event.preventDefault();

            var bouton = $(this);

            // Une seule demande a la fois : un double clic ne debite pas deux fois.
            if (bouton.hasClass('is-sending')) {
                return;
            }

            bouton.addClass('is-sending');

            $.post(@json(route('alliance.classes.choose')), {
                _token: window.token,
                alliance_class_id: bouton.data('alliance-class-id')
            }).done(function(reponse) {
                fadeBox(reponse.message, reponse.status !== 'success');

                if (reponse.status === 'success') {
                    // La page entiere est refaite par le serveur : l'etat affiche vient de lui.
                    window.location.reload();
                }
            }).fail(function(xhr) {
                var reponse = xhr.responseJSON || {};
                fadeBox(reponse.message || @json(__('t_ingame.shared.error')), true);
            }).always(function() {
                bouton.removeClass('is-sending');
            });
        });
    });
</script>
