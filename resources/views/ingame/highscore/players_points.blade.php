<div id="content">
    <div>
        <script type="text/javascript">
            var currentCategory = 1;
            var currentType = {{ $highscoreCurrentType }};
            {{-- La ligne a recentrer : le joueur cherche (lien « voir dans le classement »), sinon moi. --}}
            var searchPosition = {{ $highscoreFocusPlayerId }};
            var site = {{ $highscoreCurrentPage }};
            var searchSite = {{ $highscoreCurrentPage }};
            var resultsPerPage = 100;
            var searchRelId = {{ $highscoreFocusPlayerId }};
        </script>

        <div class="pagebar">
            @if ($highscoreCurrentPage > 1)
                <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => 1, 'type' => $highscoreCurrentType]) }}', '#stat_list_content'); return false;">«</a>&nbsp;
            @endif
            @for ($i = 1; $i <= ceil($highscorePlayerAmount / 100); $i++)
                @if ($highscoreCurrentPage == $i)
                    <span class=" activePager">{{ $i }}</span>
                @else
                    <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => $i, 'type' => $highscoreCurrentType]) }}', '#stat_list_content'); return false;">
                        {{ $i }}
                    </a>
                @endif
                &nbsp;
            @endfor
            {{-- La derniere page est la derniere : `ceil`, comme la boucle des pages. Un `floor + 1` visait une page vide
                 quand le nombre de joueurs etait un multiple de cent, et n offrait pas la derniere page sinon. --}}
            @if ($highscoreCurrentPage < ceil($highscorePlayerAmount / 100))
                <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => (int)ceil($highscorePlayerAmount / 100), 'type' => $highscoreCurrentType]) }}', '#stat_list_content'); return false;">»</a>
            @endif
        </div>
        <select class="changeSite fright">
            @if ($currentPlayerIsAdmin ?? false)
                @if ($highscoreAdminVisible ?? false)
                    <option value="{{ $highscoreCurrentPlayerPage }}">{{ __('t_ingame.highscore.own_position') }}</option>
                @else
                    <option value="1">{{ __('t_ingame.highscore.own_position_hidden') }}</option>
                @endif
            @else
                <option value="{{ $highscoreCurrentPlayerPage }}">{{ __('t_ingame.highscore.own_position') }}</option>
            @endif
            @for ($i = 1; $i <= ceil($highscorePlayerAmount / 100); $i++)
                <option {{ $i == $highscoreCurrentPage ? 'selected="selected"' : '' }} value="{{ $i }}"> {{ ((($i-1) * 100) + 1)  }} - {{ $i * 100 }}</option>
            @endfor
        </select>
        <div class="fleft" id="highscoreHeadline">
            {{-- Le titre dit le classement affiche, comme celui des alliances : il disait toujours « Points ». --}}
            @if($highscoreCurrentType == 1)
                {{ __('t_ingame.highscore.economy') }}
            @elseif($highscoreCurrentType == 2)
                {{ __('t_ingame.highscore.research') }}
            @elseif($highscoreCurrentType == 3)
                {{ __('t_ingame.highscore.military') }}
            @elseif($highscoreCurrentType == 4)
                {{ __('t_ingame.highscore.honour_points') }}
            @elseif($highscoreCurrentType == 5)
                {{ __('t_ingame.highscore.military_built') }}
            @elseif($highscoreCurrentType == 6)
                {{ __('t_ingame.highscore.military_destroyed') }}
            @elseif($highscoreCurrentType == 7)
                {{ __('t_ingame.highscore.military_lost') }}
            @else
                {{ __('t_ingame.highscore.points') }}
            @endif
        </div>
        @include('ingame.highscore.partials.military-tally-note', ['isAllianceRanking' => false])

        <table id="ranks" class="userHighscore">
            <thead>
            <tr>
                <td class="position">
                    {{ __('t_ingame.highscore.position') }}
                </td>
                <td class="movement"></td>
                <td class="name">
                    {{ __('t_ingame.highscore.player_name_honour') }}
                </td>
                <td class="sendmsg" align="center">
                    {{ __('t_ingame.highscore.action') }}
                </td>
                <td class="score" align="center">
                    {{ __('t_ingame.highscore.points') }}
                </td>
            </tr>
            </thead>
            <tbody>
            @foreach ($highscorePlayers as $highscorePlayer)
                {{-- Une ligne de faction n'a ni identifiant de compte ni rang : son
                     identifiant DOM reprend donc son type, pour rester unique. --}}
                <tr class="{{ ($highscorePlayer['is_faction'] ?? false) ? '' : ($highscorePlayer['id'] == $player->getId() ? 'myrank' : ($highscorePlayer['rank'] % 2 == 0 ? 'alt' : '')) }}" id="position{{ ($highscorePlayer['is_faction'] ?? false) ? $highscorePlayer['faction_type'] : $highscorePlayer['id'] }}">
                    <td class="position">
                        {{ $highscorePlayer['rank'] }}
                    </td>

                    <td class="movement">
                        @include('ingame.highscore.partials.rank-movement', ['movement' => $highscorePlayer['movement'] ?? null])
                    </td>
                    <td class="name">
                        <div class="highscoreNameFieldWrapper" style="height: unset;">
                            <div class="highscoreNameAndTitleHolder" style="width: calc(100% - 0px); flex-direction: row;">
                                <div class="highscoreNameHolder">
                                    @if(!empty($highscorePlayer['alliance_tag']))
                                        <span class="ally-tag">
                                            <a href="{{ route('alliance.info', ['alliance_id' => $highscorePlayer['alliance_id']]) }}" target="_ally">
                                                [{{ $highscorePlayer['alliance_tag'] }}]
                                            </a>
                                        </span>
                                    @endif

                                    @if ($highscorePlayer['is_faction'] ?? false)
                                        {{-- Une faction n'est pas un compte : ni lien de galaxie, ni fiche joueur.
                                             Sa couleur est celle qui la designe deja en galaxie, pour qu'on la
                                             reconnaisse d'un coup d'oeil comme une intelligence artificielle. --}}
                                        <span class="playername {{ $highscorePlayer['colour_class'] }}">
                                            {{ $highscorePlayer['name'] }}
                                        </span>
                                        <span class="ally-tag">({{ trans_choice('t_ingame.highscore.faction_bases', $highscorePlayer['faction_bases'], ['count' => $highscorePlayer['faction_bases']]) }})</span>
                                    @else
                                    <a href="{{ route('galaxy.index', ['galaxy' => $highscorePlayer['planet_coords']->galaxy, 'system' => $highscorePlayer['planet_coords']->system, 'position' => $highscorePlayer['planet_coords']->position]) }}" class="dark_highlight_tablet">
                                        <span class="playername{{ ($highscorePlayer['is_admin'] ?? false) ? ' status_abbr_admin' : '' }}">
                                            {{ $highscorePlayer['name'] }}
                                        </span>
                                    </a>
                                    @if ($highscorePlayer['is_admin'] ?? false)
                                        {{-- Le badge vit **hors du lien** : cliquer une decoration ne doit pas
                                             emmener le joueur sur des coordonnees qu'il n'a pas demandees. Les
                                             dimensions sont ecrites dans la balise pour que la ligne ne saute pas
                                             pendant le chargement de l'image. --}}
                                        <img src="/img/icons/badge-admin.png" width="55" height="14" class="badgeAdmin"
                                             alt="{{ __('t_ingame.highscore.badge_admin') }}"
                                             title="{{ __('t_ingame.highscore.badge_admin') }}">
                                    @endif
                                    @endif
                                </div>
                                @if (($highscorePlayer['honor_points'] ?? null) !== null)
                                    @php
                                        // **La couleur suit le signe**, comme partout ailleurs dans le jeu : un
                                        // total negatif se lit en rouge, un positif en vert, et zero reste neutre.
                                        $honneur = (int)$highscorePlayer['honor_points'];
                                        $classeHonneur = $honneur < 0 ? 'undermark' : ($honneur > 0 ? 'middlemark' : '');
                                    @endphp
                                    <div class="honorScore">
                                        (<span class="{{ $classeHonneur }} tooltip js_hideTipOnMobile" title="{{ __('t_ingame.highscore.honour_points') }}">{{ \OGame\Facades\AppUtil::formatNumber($honneur) }}</span>)
                                    </div>
                                @endif
                            </div>
                        </div>
                    </td>

                    <td class="sendmsg">
                        <div class="sendmsg_content">
                            @unless ($highscorePlayer['is_faction'] ?? false)
                            <a href="javascript:void(0)" class="sendMail js_openChat tooltip" data-playerid="{{ $highscorePlayer['id'] }}" title="{{ __('t_ingame.highscore.write_message') }}"><span class="icon icon_chat"></span></a>
                            @endunless
                            @if(!($highscorePlayer['is_faction'] ?? false) && $highscorePlayer['id'] != $player->getId() && !($highscorePlayer['is_admin'] ?? false))
                                <a class="tooltip js_hideTipOnMobile icon sendBuddyRequest" title="{{ __('t_ingame.highscore.buddy_request') }}" data-playerid="{{ $highscorePlayer['id'] }}" data-playername="{{ $highscorePlayer['name'] }}" href="javascript:void(0);">
                                    <span class="icon icon_user"></span>
                                </a>
                            @endif
                        </div>
                    </td>

                    <td class="score">
                        {{-- **Le detail des formes de vie se lit sur le score General**, et nulle part ailleurs
                             (decision de Keven, 20 septembre 2026, qui remplace les trois onglets). Les trois
                             nombres viennent du meme enregistrement que le total affiche : le detail ne peut donc
                             pas le contredire. Rien ne s affiche pour un compte sans formes de vie. --}}
                        @if($highscoreCurrentType == 0 && ($highscorePlayer['lifeform_points'] ?? 0) > 0)
                            @php $idDetail = 'lifeformShare-' . ($highscorePlayer['is_faction'] ?? false ? $highscorePlayer['faction_type'] : $highscorePlayer['id']); @endphp
                            <span class="tooltip tooltipFocusable" tabindex="0" aria-describedby="{{ $idDetail }}"
                                  title="{{ __('t_ingame.highscore.lifeform_share', ['points' => \OGame\Facades\AppUtil::formatNumber($highscorePlayer['lifeform_points'])]) }}<br/>{{ __('t_ingame.highscore.lifeform_economy') }} : {{ \OGame\Facades\AppUtil::formatNumber($highscorePlayer['lifeform_economy_points']) }}<br/>{{ __('t_ingame.highscore.lifeform_technology') }} : {{ \OGame\Facades\AppUtil::formatNumber($highscorePlayer['lifeform_technology_points']) }}">
                                {{ $highscorePlayer['points_formatted'] }}
                            </span>
                            {{-- Le meme texte, dans la page : il ne depend d aucune bibliotheque et se lit sans survol. --}}
                            <span id="{{ $idDetail }}" class="ui-helper-hidden-accessible">{{ __('t_ingame.highscore.lifeform_share', ['points' => \OGame\Facades\AppUtil::formatNumber($highscorePlayer['lifeform_points'])]) }} — {{ __('t_ingame.highscore.lifeform_economy') }} : {{ \OGame\Facades\AppUtil::formatNumber($highscorePlayer['lifeform_economy_points']) }} — {{ __('t_ingame.highscore.lifeform_technology') }} : {{ \OGame\Facades\AppUtil::formatNumber($highscorePlayer['lifeform_technology_points']) }}</span>
                        @elseif($highscoreCurrentType == 3 && isset($highscorePlayer['total_ships']))
                            <span class="tooltip" title="{{ __('t_ingame.highscore.total_ships') }}: {{ number_format($highscorePlayer['total_ships']) }}">
                                {{ $highscorePlayer['points_formatted'] }}
                            </span>
                        @else
                            {{ $highscorePlayer['points_formatted'] }}
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

@include('ingame.shared.buddy.bbcode-parser')

        <script type="text/javascript">
            // Initialize buddy dialog after it loads
            window.initBuddyDialog = function() {
                var locaKeys = {!! json_encode([
                    'bold'               => __('t_ingame.messages.bbcode_bold'),
                    'italic'             => __('t_ingame.messages.bbcode_italic'),
                    'underline'          => __('t_ingame.messages.bbcode_underline'),
                    'stroke'             => __('t_ingame.messages.bbcode_stroke'),
                    'sub'                => __('t_ingame.messages.bbcode_sub'),
                    'sup'                => __('t_ingame.messages.bbcode_sup'),
                    'fontColor'          => __('t_ingame.messages.bbcode_font_color'),
                    'fontSize'           => __('t_ingame.messages.bbcode_font_size'),
                    'backgroundColor'    => __('t_ingame.messages.bbcode_bg_color'),
                    'backgroundImage'    => __('t_ingame.messages.bbcode_bg_image'),
                    'tooltip'            => __('t_ingame.messages.bbcode_tooltip'),
                    'alignLeft'          => __('t_ingame.messages.bbcode_align_left'),
                    'alignCenter'        => __('t_ingame.messages.bbcode_align_center'),
                    'alignRight'         => __('t_ingame.messages.bbcode_align_right'),
                    'alignJustify'       => __('t_ingame.messages.bbcode_align_justify'),
                    'block'              => __('t_ingame.messages.bbcode_block'),
                    'code'               => __('t_ingame.messages.bbcode_code'),
                    'spoiler'            => __('t_ingame.messages.bbcode_spoiler'),
                    'moreopts'           => __('t_ingame.messages.bbcode_moreopts'),
                    'list'               => __('t_ingame.messages.bbcode_list'),
                    'hr'                 => __('t_ingame.messages.bbcode_hr'),
                    'picture'            => __('t_ingame.messages.bbcode_picture'),
                    'link'               => __('t_ingame.messages.bbcode_link'),
                    'email'              => __('t_ingame.messages.bbcode_email'),
                    'player'             => __('t_ingame.messages.bbcode_player'),
                    'item'               => __('t_ingame.messages.bbcode_item'),
                    'coordinates'        => __('t_ingame.messages.bbcode_coordinates'),
                    'preview'            => __('t_ingame.messages.bbcode_preview'),
                    'textPlaceHolder'    => __('t_ingame.messages.bbcode_text_ph'),
                    'playerPlaceHolder'  => __('t_ingame.messages.bbcode_player_ph'),
                    'itemPlaceHolder'    => __('t_ingame.messages.bbcode_item_ph'),
                    'coordinatePlaceHolder' => __('t_ingame.messages.bbcode_coord_ph'),
                    'charsLeft'          => __('t_ingame.messages.bbcode_chars_left'),
                    'colorPicker'        => ['ok' => __('t_ingame.messages.bbcode_ok'), 'cancel' => __('t_ingame.messages.bbcode_cancel'), 'rgbR' => 'R', 'rgbG' => 'G', 'rgbB' => 'B'],
                    'backgroundImagePicker' => ['ok' => __('t_ingame.messages.bbcode_ok'), 'repeatX' => __('t_ingame.messages.bbcode_repeat_x'), 'repeatY' => __('t_ingame.messages.bbcode_repeat_y')],
                ]) !!};

                // Block BBCode preview AJAX calls temporarily to prevent 405 errors
                var blockPreviewCalls = true;
                $.ajaxPrefilter(function(options, originalOptions, jqXHR) {
                    // Block POST requests to preview URLs (empty, /overview, or invalid URLs)
                    if (blockPreviewCalls && options.type === 'POST' &&
                        (!options.url || options.url === '' || options.url.indexOf('/overview') > -1 ||
                         options.url.indexOf('&imgAllowed=') === 0)) {
                        jqXHR.abort();
                        return false;
                    }
                });

                initBuddyRequestForm();

                // TODO: The BBCode editor includes an "Item" dropdown for linking game items.
                // This feature is not yet implemented as the item system is not available.
                // When items are implemented, update the BBCode parser and preview to support [item]ItemID[/item] tags.
                initBBCodeEditor(locaKeys, {}, false, '.buddy_request_textarea', 5000, true);

                // Re-enable AJAX calls after initialization
                setTimeout(function() {
                    blockPreviewCalls = false;
                }, 500);

                setTimeout(function() {
                    var $textarea = $('.buddy_request_textarea');
                    var $container = $textarea.closest('.markItUpContainer');
                    var $preview = $container.find('.miu_preview_container');

                    $container.find('.preview_link').off('click').on('click', function(e) {
                        e.preventDefault();
                        if ($preview.is(':visible')) {
                            $preview.hide();
                            $(this).removeClass('active');
                        } else {
                            $preview.html(window.buddyBBCodeParser($textarea.val())).show();
                            $(this).addClass('active');
                        }
                    });
                }, 150);

                $('#buddyRequestForm').off('submit').on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    $.ajax({
                        url: form.attr('action'),
                        type: 'POST',
                        data: form.serialize(),
                        success: function(response) {
                            if (response.success) {
                                fadeBox(@json(__('t_ingame.highscore.buddy_request_sent')), false);
                                form.closest('.ui-dialog-content').dialog('close');
                                setTimeout(function() {
                                    form.closest('.overlayDiv').remove();
                                    form.closest('.ui-dialog').remove();
                                }, 100);
                            } else {
                                fadeBox(response.message || @json(__('t_ingame.highscore.buddy_request_failed')), true);
                            }
                        },
                        error: function(xhr) {
                            var errorMessage = @json(__('t_ingame.highscore.buddy_request_failed'));
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                errorMessage = xhr.responseJSON.message;
                            }
                            fadeBox(errorMessage, true);
                        }
                    });
                });
            };

            // Global function for sending buddy requests (must be global for AJAX-loaded content)
            window.sendBuddyRequestDialog = function(playerId, playerName) {
                // Close any existing buddy request dialogs
                $('.buddyRequestDialog').each(function() {
                    try {
                        $(this).dialog('destroy');
                    } catch(e) {}
                    $(this).remove();
                });
                $('.ui-dialog:has(.buddyRequestDialog)').remove();

                // Create dialog container
                var $dialog = $('<div class="overlayDiv buddyRequestDialog"></div>').css('display', 'none');
                $('body').append($dialog);

                // Initialize the dialog first
                $dialog.dialog({
                    title: @json(__('t_ingame.highscore.buddy_request_to')) + ' ' + playerName,
                    width: 'auto',
                    height: 'auto',
                    modal: false,
                    closeText: '',
                    position: { my: "center", at: "center" },
                    close: function() {
                        $(this).dialog('destroy');
                        $(this).remove();
                    }
                });

                // Load content via AJAX
                var dialogUrl = '{{ route('buddies.requestdialog') }}?id=' + playerId + '&name=' + encodeURIComponent(playerName) + '&_=' + Date.now();

                $.get(dialogUrl).done(function(data) {
                    $dialog.empty().append(data);

                    // Initialize buddy dialog BBCode editor
                    if (typeof window.initBuddyDialog === 'function') {
                        window.initBuddyDialog();
                    }

                    // Reposition after content loads - check if dialog is still initialized
                    try {
                        if ($dialog.hasClass('ui-dialog-content')) {
                            $dialog.dialog('option', 'position', $dialog.dialog('option', 'position'));
                        }
                    } catch(e) {
                        // Silently ignore repositioning errors
                    }
                }).fail(function() {
                    try {
                        $dialog.dialog('close');
                    } catch(e) {}
                });
            };

            $(document).ready(function(){
                // **Un seul jeu d ecouteurs, une seule initialisation par fragment.** `initHighscore()` pose ses ecouteurs
                // de clic sur les boutons de type et de categorie, qui vivent hors du fragment, sans retirer les
                // precedents : rappele a chaque chargement, il les doublait, et un clic finissait par declencher une
                // cascade de chargements. Le chargeur (`ajaxSubmit`, `ajaxCall`) rappelle en outre `initHighscoreContent()`
                // apres avoir insere un fragment dont le script l a deja appelee : la seconde entree ne fait rien.
                if (!window.highscoreInitialised) {
                    var initialiserLeContenu = initHighscoreContent;
                    initHighscoreContent = function () {
                        if (window.highscoreContentInitialised) {
                            return;
                        }
                        window.highscoreContentInitialised = true;
                        initialiserLeContenu();
                    };
                    initHighscore();
                    window.highscoreInitialised = true;
                }
                window.highscoreContentInitialised = false;

                // **Le recentrage sur ma ligne anime le defilement pendant une seconde** et ramene la fenetre a chaque image :
                // le premier chargement seul le fait, et le choix « Ma position » dans le selecteur ; tout autre
                // chargement — un type, une page — respecte mon defilement.
                $('.changeSite').on('change', function () {
                    userWantsFocus = this.selectedIndex === 0;
                });
                initHighscoreContent();
                userWantsFocus = false;

                // Handle buddy request button clicks
                $(document).on('click', '.sendBuddyRequest, .sendBuddyRequestLink', function(e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    var playerId = $(this).data('playerid');
                    var playerName = $(this).data('playername');
                    if (playerId && playerName) {
                        window.sendBuddyRequestDialog(playerId, playerName);
                    }
                    return false;
                });

                // Handle ignore player button clicks
                $(document).on('click', '.ignorePlayerLink', function(e) {
                    e.preventDefault();
                    var playerId = $(this).data('playerid');
                    var playerName = $(this).data('playername');

                    if (playerId && playerName) {
                        // Confirm before ignoring
                        if (confirm(@json(__('t_ingame.highscore.are_you_sure_ignore')) + ' ' + playerName + '?')) {
                            $.ajax({
                                url: '{{ route('buddies.ignore') }}',
                                type: 'POST',
                                data: {
                                    ignored_user_id: playerId,
                                    _token: '{{ csrf_token() }}'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        fadeBox(@json(__('t_ingame.highscore.player_ignored')), false);
                                    } else {
                                        fadeBox(response.message || @json(__('t_ingame.highscore.player_ignored_failed')), true);
                                    }
                                },
                                error: function(xhr) {
                                    var errorMessage = @json(__('t_ingame.highscore.player_ignored_failed'));
                                    if (xhr.responseJSON && xhr.responseJSON.message) {
                                        errorMessage = xhr.responseJSON.message;
                                    }
                                    fadeBox(errorMessage, true);
                                }
                            });
                        }
                    }
                    return false;
                });
            });
        </script>
        <div class="pagebar">
            <a href="javascript:void(0);" class="scrollToTop">{{ __('t_ingame.layout.back_to_top') }}</a>
            &nbsp;
            @if ($highscoreCurrentPage > 1)
                <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => 1, 'type' => $highscoreCurrentType]) }}', '#stat_list_content'); return false;">«</a>&nbsp;
            @endif
            @for ($i = 1; $i <= ceil($highscorePlayerAmount / 100); $i++)
                @if ($highscoreCurrentPage == $i)
                    <span class=" activePager">{{ $i }}</span>
                @else
                    <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => $i, 'type' => $highscoreCurrentType]) }}', '#stat_list_content'); return false;">
                        {{ $i }}
                    </a>
                @endif
                &nbsp;
            @endfor
            {{-- La derniere page est la derniere : `ceil`, comme la boucle des pages. Un `floor + 1` visait une page vide
                 quand le nombre de joueurs etait un multiple de cent, et n offrait pas la derniere page sinon. --}}
            @if ($highscoreCurrentPage < ceil($highscorePlayerAmount / 100))
                <a href="javascript:void(0);" class="" onclick="ajaxCall('{{ route('highscore.ajax', ['page' => (int)ceil($highscorePlayerAmount / 100), 'type' => $highscoreCurrentType]) }}', '#stat_list_content'); return false;">»</a>
            @endif
        </div>
    </div>
</div>
