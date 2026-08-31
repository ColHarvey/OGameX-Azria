{{-- Chaque element porte son illustration, son nom et sa quantite. Sans le nom, une icone
     de metal et une de cristal se ressemblent assez pour qu'on ne sache pas ce qu'on
     choisit. --}}
<div class="ogx-parts">
    @foreach ($detail as $part)
        <div class="ogx-part">
            @if ($part['image'] !== null)
                <img src="{{ $part['image'] }}" alt="{{ $part['label'] }}" class="ogx-part-img">
            @else
                <span class="ogx-part-res resourceIcon {{ $part['kind'] }}"></span>
            @endif
            <span class="ogx-part-label">{{ $part['label'] }}</span>
            <span class="ogx-badge">{{ $part['amount'] }}</span>
        </div>
    @endforeach
</div>
