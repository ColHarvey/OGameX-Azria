{{-- Chaque element d'une recompense porte sa propre illustration et son propre montant,
     poses cote a cote. Une seule quantite en badge pour toute la vignette laissait croire
     qu'elle valait pour la ligne du dessous. --}}
<div class="ogx-parts">
    @foreach ($detail as $part)
        <div class="ogx-part">
            @if ($part['image'] !== null)
                <img src="{{ $part['image'] }}" alt="{{ $part['label'] }}" class="ogx-part-img">
            @else
                <span class="ogx-part-res resourceIcon {{ $part['kind'] }}"></span>
            @endif
            <span class="ogx-badge">{{ $part['amount'] }}</span>
        </div>
    @endforeach
</div>
