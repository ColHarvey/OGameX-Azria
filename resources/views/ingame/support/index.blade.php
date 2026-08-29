@extends('ingame.layouts.main')

@section('content')
    <div id="alliancecomponent" class="maincontent">
        <div id="netz">
            <div id="alliance">
                <div id="inhalt">
                    <div id="planet" class="planet-header">
                        <h2>Soutien</h2>
                    </div>
                    <div class="c-left"></div>
                    <div class="c-right"></div>
                    <div class="clearfloat"></div>
                    <div class="alliance_wrapper" style="height:auto; min-height:auto; padding-bottom:50px;">
                        <div class="allianceContent">
                            <div class="sectioncontent" style="display:block;">
                                <div class="contentz ui-tabs ui-corner-all ui-widget ui-widget-content">
                                    <div class="ui-tabs-panel ui-corner-bottom ui-widget-content">
                                        @if (session('status'))
                                            <p style="color:#8fce00; padding:10px;">{{ session('status') }}</p>
                                        @endif
                                        @foreach ($errors->all() as $error)
                                            <p style="color:#e74c3c; padding:10px;">{{ $error }}</p>
                                        @endforeach
                                        <p style="padding:10px;">Une question, un probleme, une suggestion ? Ecrivez a l'equipe. La reponse arrivera sur l'adresse email de votre compte.</p>
                                        <form action="{{ route('support.send') }}" method="post" autocomplete="off">
                                            {{ csrf_field() }}
                                            <div style="padding:10px;">
                                                <label class="styled textBeefy">Sujet</label><br/>
                                                <input class="textInput" type="text" name="subject" maxlength="150" style="width:400px;" value="{{ old('subject') }}" required/>
                                            </div>
                                            <div style="padding:10px;">
                                                <label class="styled textBeefy">Message</label><br/>
                                                <textarea name="body" class="alliancetexts" required>{{ old('body') }}</textarea>
                                            </div>
                                            <div style="padding:10px;">
                                                <input type="submit" class="btn_blue" value="Envoyer la demande"/>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
