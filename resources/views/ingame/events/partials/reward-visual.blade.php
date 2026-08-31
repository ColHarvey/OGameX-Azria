@php
    // La vignette suit le modele du jeu : un titre, une illustration, et la quantite en
    // badge sur le coin. Une recompense qui contient plusieurs ressources affiche donc la
    // principale en image, les autres en ligne sous le badge.
    $principal = $detail[0] ?? null;
    $secondaires = array_slice($detail, 1);
@endphp
@if ($principal !== null)
    <div class="ogx-visual">
        @if ($principal['image'] !== null)
            <img src="{{ $principal['image'] }}" alt="{{ $principal['label'] }}" class="ogx-visual-img">
        @else
            <span class="ogx-visual-res resourceIcon {{ $principal['kind'] }}"></span>
        @endif
        <span class="ogx-badge">{{ $principal['amount'] }}</span>
    </div>

    @if (count($secondaires) > 0)
        <span class="ogx-extra">
            @foreach ($secondaires as $part)
                <span class="ogx-extra-line">{{ $part['amount'] }} {{ $part['label'] }}</span>
            @endforeach
        </span>
    @endif
@endif
