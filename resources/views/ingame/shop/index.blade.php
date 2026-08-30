@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

<div id="eventboxContent" style="display: none">
        <img height="16" width="16" src="/img/icons/3f9884806436537bdec305aa26fc60.gif">
    </div>

<style>
    /* Le theme fige .shop_slider et .inventory_slider a 405 x 360 px sans gerer le
       debordement : le contenu s'echappait du cadre. On le fait defiler a l'interieur. */
    #shop .shop_slider,
    #shop .inventory_slider {
        overflow-y: auto;
        overflow-x: hidden;
    }

    /* Grille auto-portante : les tuiles .item_img sont stylees par le theme, mais leur
       conteneur d'origine etait un faux carrousel aux dimensions figees.
       Largeur calculee pour tenir trois colonnes dans les 405 px disponibles :
       3 x 116 + 2 x 12 d'ecart + 2 x 10 de marge = 392 px. */
    .ogx-item-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        padding: 10px;
    }

    /* Chaque objet dans son propre cadre, pour qu'on distingue nettement les neuf.
       box-sizing indispensable : sans lui la bordure s'ajoute a la largeur et fait
       tomber la grille a deux colonnes. */
    .ogx-item {
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 116px;
        padding: 6px 3px 5px;
        background: rgba(255, 255, 255, .03);
        border: 1px solid rgba(255, 255, 255, .08);
        border-radius: 5px;
    }

    .ogx-item .item_img {
        margin: 0;
    }

    .ogx-item-name {
        margin-top: 5px;
        font-size: 11px;
        font-weight: bold;
        line-height: 1.15;
        color: #cfe3f5;
        text-align: center;
    }

    .ogx-item-action {
        margin-top: 3px;
        cursor: pointer;
    }

    /* Reserve la ligne meme quand elle est vide, pour que les cadres restent alignes. */
    .ogx-item-owned {
        font-size: 10px;
        color: #8fa7bd;
        min-height: 12px;
    }

    .ogx-empty {
        padding: 20px;
        color: #8fa7bd;
    }
</style>

    <div id="inhalt">
        <div id="planet">
            <div id="header_text">
                <h2>
                    {{ __('t_ingame.shop.page_title') }}            </h2>
            </div>

            <div id="detail" class="detail_screen small">
                <div id="techDetailLoading"></div>
            </div>

        </div>
        <div class="c-left"></div>
        <div class="c-right"></div>

        <div id="buttonz">
            <div class="header">
                <h2>{{ __('t_ingame.shop.page_title') }}</h2>
            </div>
            <div class="content">
                <button class="to_shop active tooltip js_hideTipOnMobile" title="{{ __('t_ingame.shop.tooltip_shop') }}">
                    <span class="to_shop_icon">{{ __('t_ingame.shop.btn_shop') }}</span>
                </button>
                <button class="to_inventory tooltip js_hideTipOnMobile" title="{{ __('t_ingame.shop.tooltip_inventory') }}">
        <span class="to_inventory_icon">
            {{ __('t_ingame.shop.btn_inventory') }}            </span>
                </button>

                <div id="itemBox" class="border5px">
                    <div class="aside">
                        {{-- Les categories sont calculees a partir du catalogue : pas de compteur
                             en dur, et aucun onglet vide promettant un contenu inexistant. --}}
                        <ul class="listfilter border5px categoryFilter">
                            @php
                                $libellesCategories = [
                                    'all' => 'category_all',
                                    'construction' => 'category_construction',
                                    'shipyard' => 'category_shipyard',
                                    'research' => 'category_research',
                                ];
                            @endphp
                            @foreach($categories as $cle => $nombre)
                                <li class="border5px inShop {{ $cle === $activeCategory ? 'active' : '' }}">
                                    <a href="{{ route('shop.index', ['category' => $cle]) }}"
                                       class="{{ $cle === $activeCategory ? 'active' : '' }}">
                                        <span>
                                            {{ __('t_ingame.shop.' . ($libellesCategories[$cle] ?? 'category_all')) }} (<span class="amount">{{ $nombre }}</span>)
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div id="js_shopSliderBox" class="shop_slider">
                        <div class="ogx-item-grid">
                            @foreach($shopItems as $item)
                                @include('ingame.shop.partials.tile', [
                                    'item' => $item,
                                    'owned' => $inventory[$item['ref']] ?? 0,
                                    'action' => 'buy',
                                ])
                            @endforeach
                        </div>
                    </div>

                    <div id="js_inventorySliderBox" class="inventory_slider" style="display:none;">
                        @if(count($inventoryItems) > 0)
                            <div class="ogx-item-grid">
                                @foreach($inventoryItems as $item)
                                    @include('ingame.shop.partials.tile', [
                                        'item' => $item,
                                        'owned' => $inventory[$item['ref']] ?? 0,
                                        'action' => 'use',
                                    ])
                                @endforeach
                            </div>
                        @else
                            <p class="ogx-empty">{{ __('t_ingame.shop.empty_inventory') }}</p>
                        @endif
                    </div>
                </div>        <div class="footer"></div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        $(document).ready(function() {
            // Bascule entre la boutique et l'inventaire. Les deux panneaux sont rendus par le
            // serveur : aucun aller-retour n'est necessaire pour passer de l'un a l'autre.
            //
            // Le JavaScript embarque du jeu contient bien un module de boutique (initShop,
            // vers la ligne 27094 de e7c74974...js) qui gere cette bascule, mais rien ne
            // l'appelle : il reconstruit ses carrousels depuis un catalogue cote client que
            // nous ne fournissons pas. Ses gestionnaires ne se lient donc jamais et les
            // notres sont les seuls actifs. Ne pas appeler initShop() sans reprendre tout
            // ce catalogue.
            function afficherPanneau(inventaire) {
                $('#js_shopSliderBox').toggle(!inventaire);
                $('#js_inventorySliderBox').toggle(inventaire);
                $('.to_shop').toggleClass('active', !inventaire);
                $('.to_inventory').toggleClass('active', inventaire);
            }

            $('.to_shop').on('click', function() { afficherPanneau(false); });
            $('.to_inventory').on('click', function() { afficherPanneau(true); });

            // L'icone du menu Directives pointe vers /shop#page=inventory : on ouvre donc
            // directement l'inventaire quand ce fragment est present.
            if (window.location.hash.indexOf('page=inventory') !== -1) {
                afficherPanneau(true);
            }

            function envoyer(url, ref, messageErreurParDefaut) {
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: {
                        ref: ref,
                        _token: $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            fadeBox(response.message, false);
                            // La page est rechargee : le solde de matiere noire, les quantites
                            // en inventaire et le compte a rebours de la file changent tous.
                            // On reste sur le panneau ouvert : apres avoir active un objet
                            // depuis l'inventaire, on veut y rester.
                            setTimeout(function() {
                                if ($('#js_inventorySliderBox').is(':visible')) {
                                    window.location.hash = 'page=inventory';
                                }
                                window.location.reload();
                            }, 1200);
                        } else {
                            fadeBox(response.message || messageErreurParDefaut, true);
                        }
                    },
                    error: function(xhr) {
                        var message = messageErreurParDefaut;
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        fadeBox(message, true);
                    }
                });
            }

            // Confirmation avant tout achat : la matiere noire est rare et un clic
            // malencontreux ne doit pas la depenser.
            $('.ogx-buy').on('click', function(e) {
                e.preventDefault();

                var bouton = $(this);
                var question = @json(__('t_ingame.shop.confirm_buy'))
                    .replace(':item', bouton.data('name'))
                    .replace(':price', bouton.data('price'));

                errorBoxDecision(
                    @json(__('t_ingame.shared.caution')),
                    question,
                    @json(__('t_ingame.shared.yes')),
                    @json(__('t_ingame.shared.no')),
                    function() {
                        envoyer('{{ route('shop.buy') }}', bouton.data('ref'), @json(__('t_ingame.shop.msg_buy_error')));
                    }
                );
            });

            $('.ogx-use').on('click', function(e) {
                e.preventDefault();
                envoyer('{{ route('shop.use') }}', $(this).data('ref'), @json(__('t_ingame.shop.msg_use_error')));
            });
        });
    </script>

@endsection
