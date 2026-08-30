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
    /* Grille auto-portante : les tuiles .item_img sont stylees par le theme, mais leur
       conteneur d'origine etait un faux carrousel aux dimensions figees. */
    .ogx-item-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        padding: 10px;
    }

    .ogx-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 100px;
    }

    .ogx-item .item_img {
        margin: 0;
    }

    .ogx-item-action {
        margin-top: 4px;
        width: 96px;
        text-align: center;
        cursor: pointer;
    }

    .ogx-item-owned {
        margin-top: 2px;
        font-size: 11px;
        color: #8fa7bd;
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
                        <div class="btn_wrap">
                            <a href="#" tabindex="1" class="btn btn_confirm buyResourcesLink">
                                {{ __('t_ingame.shop.btn_get_more_resources') }}                    </a>
                        </div>
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
                            setTimeout(function() {
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

            $('.ogx-buy').on('click', function(e) {
                e.preventDefault();
                envoyer('{{ route('shop.buy') }}', $(this).data('ref'), @json(__('t_ingame.shop.msg_buy_error')));
            });

            $('.ogx-use').on('click', function(e) {
                e.preventDefault();
                envoyer('{{ route('shop.use') }}', $(this).data('ref'), @json(__('t_ingame.shop.msg_use_error')));
            });
        });
    </script>

@endsection
