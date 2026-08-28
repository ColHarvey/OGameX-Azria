@extends('outgame.layouts.main')

@section('content')
<div id="menu">
    <div id="tabContentContainer">
        <div class="tabContent">
            <div class="inner-box clearfix">
                <h2>Nouveau mot de passe</h2>

                @foreach ($errors->all() as $error)
                    <p style="color:#e74c3c;">{{ $error }}</p>
                @endforeach

                <form method="POST" action="{{ route('password.update') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $request->route('token') }}"/>

                    <label style="display:inline-block;vertical-align:middle;width:auto;margin-right:8px;" for="email">Adresse email :</label>
                    <div class="black-border" style="display:inline-block;vertical-align:middle;margin:4px 0;">
                        <input type="email" id="email" name="email" value="{{ old('email', $request->email) }}" required autofocus/>
                    </div>

                    <label style="display:inline-block;vertical-align:middle;width:auto;margin-right:8px;" for="password">Nouveau mot de passe :</label>
                    <div class="black-border" style="display:inline-block;vertical-align:middle;margin:4px 0;">
                        <input type="password" id="password" name="password" required/>
                    </div>

                    <label style="display:inline-block;vertical-align:middle;width:auto;margin-right:8px;" for="password_confirmation">Confirmer :</label>
                    <div class="black-border" style="display:inline-block;vertical-align:middle;margin:4px 0;">
                        <input type="password" id="password_confirmation" name="password_confirmation" required/>
                    </div>

                    <div style="margin-top:20px;">
                        <input type="submit" id="regSubmit" value="Réinitialiser"/>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
