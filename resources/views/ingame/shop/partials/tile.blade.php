@php
    // Tuile d'un objet, partagee par la boutique et l'inventaire. Seul le bouton differe.
    $itemName = __('t_resources.' . $item['name_key'] . '.title') . ' ' . __('t_ingame.shop.tier_' . $item['tier_key']);
    $itemDesc = __('t_resources.' . $item['name_key'] . '.description', ['duration' => '<b>' . $item['duration'] . '</b>']);

    // Format attendu par l'infobulle du jeu : « titre|contenu HTML ».
    $tooltip = $itemName . '|' . $itemDesc . '<br /><br />'
        . __('t_ingame.shop.item_duration') . ': ' . __('t_ingame.shop.now') . '<br /><br />'
        . __('t_ingame.shop.item_price') . ': ' . number_format($item['price'], 0, '', ' ') . ' ' . __('t_ingame.shop.dark_matter') . '<br />'
        . __('t_ingame.shop.item_in_inventory') . ': ' . $owned;
@endphp
<div class="ogx-item">
    <div class="item_img r_{{ $item['rarity'] }}" style="background-image: url(/img/icons/{{ $item['image_hash'] }}-100x.png);">
        <div class="item_img_box">
            <a href="javascript:void(0);" tabindex="1" title="{{ $tooltip }}" class="detail_button tooltipHTML js_hideTipOnMobile" ref="{{ $item['ref'] }}">
                <span class="ecke"><span class="level price">{{ $item['price_label'] }} DM</span></span>
            </a>
        </div>
    </div>

    @if($action === 'buy')
        <a class="ogx-item-action ogx-buy action btn_blue" data-ref="{{ $item['ref'] }}" href="javascript:void(0);">
            {{ __('t_ingame.shop.btn_buy') }}
        </a>
        @if($owned > 0)
            <span class="ogx-item-owned">{{ __('t_ingame.shop.item_in_inventory') }} : {{ $owned }}</span>
        @endif
    @else
        <a class="ogx-item-action ogx-use action btn_blue" data-ref="{{ $item['ref'] }}" href="javascript:void(0);">
            {{ __('t_ingame.shop.loca_activate') }}
        </a>
        <span class="ogx-item-owned">&times; {{ $owned }}</span>
    @endif
</div>
