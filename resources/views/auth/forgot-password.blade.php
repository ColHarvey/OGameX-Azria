@extends('outgame.layouts.main')

@section('content')
<div id="menu">
    <div id="tabContentContainer">
        <div class="tabContent">
            <div class="inner-box clearfix">
                <h2>Mot de passe oublié</h2>

                @if (session('status'))
                    <p style="color:#8fce00;">{{ session('status') }}</p>
                @endif

                @foreach ($errors->all() as $error)
                    <p style="color:#e74c3c;">{{ $error }}</p>
                @endforeach

                <p>Saisissez l'adresse email de votre compte. Vous recevrez un lien permettant de définir un nouveau mot de passe.</p>

                <form method="POST" action="{{ route('password.email') }}">
                    @csrf
                    <label style="display:inline-block;vertical-align:middle;width:auto;margin-right:8px;" for="email">Adresse email :</label>
                    <div class="black-border" style="display:inline-block;vertical-align:middle;margin:4px 0;">
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus/>
                    </div>
                    <div style="margin-top:20px;">
                        <input type="submit" id="regSubmit" value="Envoyer le lien"/>
                    </div>
                </form>

                <p><a href="{{ url('/') }}">Retour à l'accueil</a></p>
            </div>
        </div>
    </div>
</div>
@endsection
