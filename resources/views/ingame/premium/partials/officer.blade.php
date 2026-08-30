@php
    /** @var array{officer: string, active: bool, expires_at: \Illuminate\Support\Carbon|null, prices: array<int,int>} $officer */
    $name = $officer['officer'];
    // Le numero de tuile reprend celui d'OGame : la matiere noire occupe le 1.
    $tabIndex = ['commander' => 2, 'admiral' => 3, 'engineer' => 4, 'geologist' => 5, 'technocrat' => 6][$name];
    $statusTitle = $officer['active']
        ? __('t_ingame.premium.active_until', ['date' => $officer['expires_at']->format('d/m H:i')])
        : __('t_ingame.premium.inactive');
@endphp

<li class="button {{ $officer['active'] ? 'on' : '' }}" id="button{{ $tabIndex }}">
    <div class="premium">
        <div class="officers100 {{ $name }}">
            <a tabindex="{{ $tabIndex }}" href="javascript:void(0);"
               title="{{ __('t_ingame.premium.officer_' . $name) }} — {{ $statusTitle }}"
               class="detail_button tooltip js_hideTipOnMobile">
                <span class="ecke">
                    <span class="level">
                        @if ($officer['active'])
                            <img src="/img/icons/b1c7ef5b1164eba44e55b7f6d25d35.gif" width="12" height="11" alt="">
                        @else
                            <img src="/img/icons/aa2ad16d1e00956f7dc8af8be3ca52.gif" width="12" height="11" alt="">
                        @endif
                    </span>
                </span>
            </a>
        </div>
    </div>
</li>
