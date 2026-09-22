@extends('ingame.layouts.main')

{{--
    **La vue Empire n est qu une enveloppe.**

    Tout ce qui s affiche vient de `EmpireProjection`, publie ici en JSON, et c est le code client de la vue Empire
    embarque dans le bundle (`createImperiumHtml`) qui le dessine. Aucun calcul n est refait ici, et aucun second
    moteur de rendu n existe : `empire.js` pose la charge utile, appelle ce code, et applique les trois ajustements
    qui lui manquent (totaux du serveur, libelles des moyennes, nom de colonne rendu collant).

    Le JSON passe par `@json` **dans un script**, jamais par `{!! !!}` : c est la regle du depot pour tout ce qui
    voyage du serveur au navigateur.
--}}

@section('content')

    <div id="empireComponent" data-empire-refresh="{{ route('empire.refresh') }}" data-empire-order="{{ route('empire.order') }}">
        {{-- La barre vit **hors** du panneau qui defile : elle doit rester lisible quel que soit le defilement. --}}
        <div class="empireBar">
                <span class="empireTakenAt">
                    {{ __('t_ingame.empire.taken_at') }}
                    <span id="empireTakenAt">{{ $empire['taken_at_formatted'] }}</span>
                </span>
                <button type="button" id="empireRefresh" class="empireRefreshButton">{{ __('t_ingame.empire.refresh') }}</button>
            <span id="empireStale" class="empireStale" hidden>{{ __('t_ingame.empire.refresh_failed') }}</span>
        </div>

        <div id="mainContent">
            <div id="mainWrapper">
                <div id="loading">{{ __('t_ingame.layout.loading') }}</div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        window.empirePayload = @json($empire);
        window.empireToken = '{{ csrf_token() }}';
        window.empireLoca = @json($empireLoca);
    </script>

@endsection
