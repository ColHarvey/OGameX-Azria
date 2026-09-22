{{-- L enveloppe « page » : l acces direct, Ctrl-clic et le nouvel onglet. Elle etend le gabarit du jeu — assets par
     Vite, globales et aides de dialogue en place — et rend le MEME partiel que la fenetre, dans un cadre de la
     largeur de la fenetre. L ancienne page chargeait `css/ingame.css` et `js/ingame.js`, deux chemins d avant Vite
     absents du depot : elle rendait sans style (mesure au reseau, deux 404). --}}
@extends('ingame.layouts.main')

@section('content')
    <div id="allianceProfilePage" class="ap-page">
        <h2 class="ap-page-title">{{ __('t_ingame.alliance.info_title') }}@if ($profile !== null) [{{ $profile['tag'] }}]@endif</h2>
        @include('ingame.alliance.profile.content')
    </div>
@endsection
