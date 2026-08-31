@extends('ingame.layouts.main')

@section('content')
    <div id="alliancecomponent" class="maincontent">
        <div id="netz">
            <div id="alliance">
                <div id="inhalt">
                    <div id="planet" class="planet-header">
                        <h2>Evenement de missions</h2>
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

                                        <p style="padding:10px;">
                                            Pendant un evenement, chaque joueur recoit chaque jour un tirage de missions
                                            et gagne du tritium, qui debloque cinq rangs de recompenses.
                                            @if ($running)
                                                <br><strong style="color:#8fce00;">Evenement en cours.</strong>
                                            @elseif ($enabled)
                                                <br><strong style="color:#f48406;">Ouvert, mais hors des dates : les joueurs ne voient rien.</strong>
                                            @else
                                                <br><strong style="color:#9a9a9a;">Aucun evenement en cours.</strong>
                                            @endif
                                        </p>

                                        <form action="{{ route('admin.event.update') }}" method="post" autocomplete="off">
                                            {{ csrf_field() }}

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy">
                                                    <input type="checkbox" name="enabled" value="1" {{ $enabled ? 'checked' : '' }}/>
                                                    Evenement ouvert
                                                </label>
                                            </div>

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy">Date de debut</label><br/>
                                                <input class="textInput" type="date" name="start" style="width:200px;"
                                                       value="{{ old('start', $start) }}" required/>
                                            </div>

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy">Date de fin</label><br/>
                                                <input class="textInput" type="date" name="end" style="width:200px;"
                                                       value="{{ old('end', $end) }}" required/>
                                                <br/><span style="color:#9a9a9a;">Le dernier jour est inclus.</span>
                                            </div>

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy">Missions par jour et par joueur</label><br/>
                                                <input class="textInput" type="number" name="missions_per_day" min="1" max="15"
                                                       style="width:80px;" value="{{ old('missions_per_day', $missionsPerDay) }}" required/>
                                                <br/><span style="color:#9a9a9a;">15 missions au catalogue. Au-dela, tout le monde recoit les memes.</span>
                                            </div>

                                            <div style="padding:10px;">
                                                <input type="submit" class="btn_blue" value="Enregistrer"
                                                       onclick="return confirm('Enregistrer ? Si l evenement passe de ferme a ouvert, une annonce part a tous les joueurs.');"/>
                                            </div>
                                        </form>

                                        <p style="padding:10px; color:#9a9a9a;">
                                            L'annonce n'est envoyee qu'au passage de ferme a ouvert. Reenregistrer un
                                            evenement deja ouvert ne renvoie rien : un message parti ne se rattrape pas.
                                        </p>

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
