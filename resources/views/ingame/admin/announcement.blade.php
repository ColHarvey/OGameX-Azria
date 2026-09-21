@extends('ingame.layouts.main')

@php
    /*
     * **Quel onglet rouvrir.** Trois sources, dans cet ordre : un refus de validation de la bulle (son sac
     * d erreurs porte son nom), puis le parametre laisse par une redirection, puis le defaut.
     *
     * Sans cela, une saisie refusee renverrait l administrateur sur l onglet des messages, avec ses erreurs
     * affichees sur un formulaire qu il ne regardait pas.
     */
    $ongletBulle = $errors->hasBag('bulle') || request('onglet') === 'bulle' || session()->has('bulle_status');
@endphp

@section('content')
    <div id="alliancecomponent" class="maincontent">
        <div id="netz">
            <div id="alliance">
                <div id="inhalt">
                    <div id="planet" class="planet-header">
                        <h2>@lang('Announcements')</h2>
                    </div>
                    <div class="c-left"></div>
                    <div class="c-right"></div>
                    <div class="clearfloat"></div>
                    <div class="alliance_wrapper" style="height:auto; min-height:auto; padding-bottom:50px;">
                        <div class="allianceContent">
                            <div class="sectioncontent" style="display:block;">
                                <div class="contentz ui-tabs ui-corner-all ui-widget ui-widget-content" id="announcementTabs">
                                    <ul class="tabsbelow subsection_tabs ui-state-active ui-tabs-nav ui-corner-all ui-helper-reset ui-helper-clearfix ui-widget-header" role="tablist">
                                        <li role="tab" tabindex="0"
                                            class="ui-tabs-tab ui-corner-top ui-state-default ui-tab{{ $ongletBulle ? '' : ' ui-tabs-active ui-state-active' }}">
                                            <a href="#tab-message" role="presentation" tabindex="-1" class="ui-tabs-anchor">
                                                <span>{{ __('t_ingame.announcement.tab_message') }}</span>
                                            </a>
                                        </li>
                                        <li role="tab" tabindex="-1"
                                            class="ui-tabs-tab ui-corner-top ui-state-default ui-tab{{ $ongletBulle ? ' ui-tabs-active ui-state-active' : '' }}">
                                            <a href="#tab-bulle" role="presentation" tabindex="-1" class="ui-tabs-anchor">
                                                <span>{{ __('t_ingame.announcement.tab_bubble') }}</span>
                                            </a>
                                        </li>
                                    </ul>

                                    {{--
                                        ONGLET 1 — le formulaire existant, **inchange dans son fonctionnement**.
                                        Meme route, meme controleur, memes noms de champs, meme confirmation.
                                    --}}
                                    <div id="tab-message" class="ui-tabs-panel ui-corner-bottom ui-widget-content"
                                         aria-hidden="{{ $ongletBulle ? 'true' : 'false' }}"
                                         @if($ongletBulle) style="display:none;" @endif>

                                        @if (session('status'))
                                            <p style="color:#8fce00; padding:10px;">{{ session('status') }}</p>
                                        @endif

                                        {{-- Le sac par defaut, donc les erreurs du formulaire de messages seules. --}}
                                        @foreach ($errors->getBag('default')->all() as $error)
                                            <p style="color:#e74c3c; padding:10px;">{{ $error }}</p>
                                        @endforeach

                                        <p style="padding:10px;">{{ __('t_ingame.announcement.message_explanation') }}</p>

                                        <form action="{{ route('admin.announcement.send') }}" method="post" autocomplete="off">
                                            {{ csrf_field() }}

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy" for="announcementSubject">{{ __('t_ingame.announcement.subject') }}</label><br/>
                                                <input id="announcementSubject" class="textInput" type="text" name="subject" maxlength="255"
                                                       style="width:400px;" value="{{ old('subject') }}" required/>
                                            </div>

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy" for="announcementBody">{{ __('t_ingame.announcement.message') }}</label><br/>
                                                <textarea id="announcementBody" name="body" class="alliancetexts" required>{{ old('body') }}</textarea>
                                            </div>

                                            <div style="padding:10px;">
                                                <input type="submit" class="btn_blue" value="{{ __('t_ingame.announcement.send_to_all') }}"
                                                       onclick="return confirm('{{ __('t_ingame.announcement.send_confirm') }}');"/>
                                            </div>
                                        </form>
                                    </div>

                                    {{--
                                        ONGLET 2 — la bulle. Formulaire **independant** : sa propre route, son
                                        propre sac d erreurs (`bulle`), et surtout ses propres **noms de champs
                                        prefixes**.

                                        Les deux sont necessaires : le sac isole les erreurs, mais `old()` est
                                        un seul depot partage. Sans prefixe, `old('body')` serait commun aux
                                        deux formulaires — et `body` existe des deux cotes.
                                    --}}
                                    <div id="tab-bulle" class="ui-tabs-panel ui-corner-bottom ui-widget-content"
                                         aria-hidden="{{ $ongletBulle ? 'false' : 'true' }}"
                                         @if(!$ongletBulle) style="display:none;" @endif>

                                        @if (session('bulle_status'))
                                            <p style="color:#8fce00; padding:10px;">{{ session('bulle_status') }}</p>
                                        @endif

                                        @foreach ($errors->getBag('bulle')->all() as $error)
                                            <p style="color:#e74c3c; padding:10px;">{{ $error }}</p>
                                        @endforeach

                                        <p style="padding:10px;">
                                            {{ __('t_ingame.announcement.bubble_explanation') }}
                                        </p>

                                        <p style="padding:0 10px 10px;">
                                            <strong>{{ __('t_ingame.announcement.state') }}</strong>
                                            @if($bubble->enabled)
                                                <span style="color:#8fce00;">{{ __('t_ingame.announcement.state_enabled') }}</span>
                                            @else
                                                <span style="color:#9099a4;">{{ __('t_ingame.announcement.state_disabled') }}</span>
                                            @endif
                                            &mdash;
                                            @if($current)
                                                {{ __('t_ingame.announcement.state_version', ['version' => $current->version]) }}
                                            @else
                                                {{ __('t_ingame.announcement.state_never_published') }}
                                            @endif
                                        </p>

                                        <form action="{{ route('admin.announcement.bubble.save') }}" method="post" autocomplete="off" id="bubbleForm">
                                            {{ csrf_field() }}

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy" for="bubbleTitle">{{ __('t_ingame.announcement.title') }}</label><br/>
                                                <input id="bubbleTitle" class="textInput" type="text" name="bulle[title]" maxlength="120"
                                                       style="width:400px;" value="{{ old('bulle.title', $bubble->draft_title) }}" required/>
                                            </div>

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy" for="bubbleBody">{{ __('t_ingame.announcement.message') }}</label><br/>
                                                <textarea id="bubbleBody" name="bulle[body]" class="alliancetexts">{{ old('bulle.body', $bubble->draft_body) }}</textarea>
                                            </div>

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy" for="bubbleLinkUrl">{{ __('t_ingame.announcement.link_url') }}</label><br/>
                                                <input id="bubbleLinkUrl" class="textInput" type="text" name="bulle[link_url]" maxlength="500"
                                                       style="width:400px;" value="{{ old('bulle.link_url', $bubble->draft_link_url) }}"/>
                                                <br/><span class="textSmall">{{ __('t_ingame.announcement.link_hint') }}</span>
                                            </div>

                                            <div style="padding:10px;">
                                                <label class="styled textBeefy" for="bubbleLinkLabel">{{ __('t_ingame.announcement.link_label') }}</label><br/>
                                                <input id="bubbleLinkLabel" class="textInput" type="text" name="bulle[link_label]" maxlength="60"
                                                       style="width:400px;" value="{{ old('bulle.link_label', $bubble->draft_link_label) }}"/>
                                            </div>

                                            <div style="padding:10px;">
                                                <label class="styled" for="bubbleDismissible">
                                                    <input id="bubbleDismissible" type="checkbox" name="bulle[dismissible]" value="1"
                                                           {{ old('bulle.dismissible', $bubble->draft_dismissible) ? 'checked' : '' }}/>
                                                    {{ __('t_ingame.announcement.dismissible') }}
                                                </label>
                                            </div>

                                            <div style="padding:10px;">
                                                {{-- Enregistrer : **n affiche rien aux joueurs**. --}}
                                                <input type="submit" class="btn_blue" value="{{ __('t_ingame.announcement.save_draft') }}"/>

                                                {{-- L apercu emploie le meme rendu que le joueur, et n ecrit rien. --}}
                                                <input type="submit" class="btn_blue"
                                                       formaction="{{ route('admin.announcement.bubble.preview') }}"
                                                       formtarget="_blank"
                                                       value="{{ __('t_ingame.announcement.preview') }}"/>

                                                {{-- **Le seul geste qui consomme une version** et fait reapparaitre la bulle. --}}
                                                <input type="submit" class="btn_blue"
                                                       formaction="{{ route('admin.announcement.bubble.publish') }}"
                                                       value="{{ __('t_ingame.announcement.publish') }}"
                                                       onclick="return confirm('{{ __('t_ingame.announcement.publish_confirm') }}');"/>
                                            </div>
                                        </form>

                                        {{--
                                            L interrupteur vit dans son **propre** formulaire : il ne porte
                                            aucun champ de la bulle, donc il ne peut ni publier, ni enregistrer
                                            un brouillon au passage. Il ne consomme aucune version.
                                        --}}
                                        <form action="{{ route('admin.announcement.bubble.toggle') }}" method="post" autocomplete="off">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="enabled" value="{{ $bubble->enabled ? '0' : '1' }}"/>
                                            <div style="padding:10px;">
                                                <input type="submit" class="btn_blue"
                                                       value="{{ $bubble->enabled ? __('t_ingame.announcement.disable') : __('t_ingame.announcement.enable') }}"/>
                                                <span class="textSmall" style="margin-left:10px;">
                                                    {{ __('t_ingame.announcement.toggle_hint') }}
                                                </span>
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

    <script type="text/javascript">
        $(document).ready(function () {
            /*
             * Les onglets du jeu, comme la page des regles les emploie — et **dans la section du
             * contenu**, seule section que le gabarit rende (`@yield('content')`). Placee dans une
             * section `scripts`, elle n aurait jamais ete emise, et l essai l a montre.
             *
             * `active` est pose par le serveur : apres un refus, c est l onglet qui porte l erreur qui
             * se rouvre — et il se rouvre aussi sans JavaScript, les panneaux portant deja leur
             * `style="display:none"`.
             */
            $('#announcementTabs').tabs({ active: {{ $ongletBulle ? 1 : 0 }} });
        });
    </script>
@endsection
