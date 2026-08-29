<div class="searchLayer">
    <div class="messagebox">
        <div id="netz">
            <div id="message">
                <div id="inhalt">
                    <div class="sectioncontent" id="section41" style="display:block;">
                        <div class="contentz">
                            <table cellpadding="0" cellspacing="0" class="searchall">
                                <tbody><tr>
                                    <td class="textCenter" style="padding-bottom:10px;">
                                        {{ __('t_ingame.search.input_hint') }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="ptb10 textCenter">
                                        <form id="searchForm" name="search" action="javascript:void(0);" onsubmit="return false;" method="POST">
                                            <input type="search" id="searchText" name="searchtext" class="textInput" value="">
                                            <input type="submit" value="{{ __('t_ingame.search.search_btn') }}" name="search" class="btn_blue buttonSave"><p>
                                            </p></form>
                                    </td>
                                </tr>
                                </tbody></table>
                            <div class="searchTabs">
                                <ul class="tabsbelow subsection_tabs" id="tabs_example_one">
                                    <li class="{{ request('category') == 2 || !request('category') ? 'ui-tabs-active' : '' }}">
                                        <a href="#one" class="tab" data-category="2">
                                            <span>
                                               {{ __('t_ingame.search.tab_players') }}
                                            </span>
                                        </a>
                                    </li>
                                    <li class="{{ request('category') == 4 ? 'ui-tabs-active' : '' }}">
                                        <a href="#two" class="tab" data-category="4">
                                            <span>
                                               {{ __('t_ingame.search.tab_alliances') }}
                                            </span>
                                        </a>
                                    </li>
                                    <li class="{{ request('category') == 3 ? 'ui-tabs-active' : '' }}">
                                        <a href="#three" class="tab" data-category="3">
                                            <span>
                                               {{ __('t_ingame.search.tab_planets') }}
                                            </span>
                                        </a>
                                    </li>
                                </ul>

                                <div class="ajaxContent">
                                    <br>
                                    <p class="textCenter">{{ __('t_ingame.search.no_search_term') }}</p>
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
(function($) {
    var locaSearch = {
        noSearchTerm: {!! json_encode(__('t_ingame.search.no_search_term')) !!},
        searching: {!! json_encode(__('t_ingame.search.searching')) !!},
        searchFailed: {!! json_encode(__('t_ingame.search.search_failed')) !!},
        noResults: {!! json_encode(__('t_ingame.search.no_results')) !!},
        playerName: {!! json_encode(__('t_ingame.search.player_name')) !!},
        planetName: {!! json_encode(__('t_ingame.search.planet_name')) !!},
        coordinates: {!! json_encode(__('t_ingame.search.coordinates')) !!},
        tag: {!! json_encode(__('t_ingame.search.tag')) !!},
        allianceName: {!! json_encode(__('t_ingame.search.alliance_name')) !!},
        member: {!! json_encode(__('t_ingame.search.member')) !!},
        points: {!! json_encode(__('t_ingame.search.points')) !!},
        action: {!! json_encode(__('t_ingame.search.action')) !!},
        applyForAlliance: {!! json_encode(__('t_ingame.search.apply_for_alliance')) !!}
    };

    let currentCategory = {{ request('category', 2) }};

    // Tab switching
    $('.searchTabs .tab').on('click', function(e) {
        e.preventDefault();
        currentCategory = $(this).data('category');

        // Update active tab
        $('.searchTabs li').removeClass('ui-tabs-active');
        $(this).parent().addClass('ui-tabs-active');

        // Clear results if search text is empty
        if ($('#searchText').val().trim() === '') {
            $('.ajaxContent').html('<br><p class="textCenter">' + locaSearch.noSearchTerm + '</p>');
        } else {
            // Re-run search with new category
            performSearch();
        }
    });

    // Search form submission
    $('#searchForm').on('submit', function(e) {
        e.preventDefault();
        performSearch();
    });

    function performSearch() {
        const searchText = $('#searchText').val().trim();

        if (searchText === '') {
            $('.ajaxContent').html('<br><p class="textCenter">' + locaSearch.noSearchTerm + '</p>');
            return;
        }

        // Show loading indicator
        $('.ajaxContent').html('<br><p class="textCenter">' + locaSearch.searching + '</p>');

        $.ajax({
            url: '{{ route('search.ajax') }}',
            type: 'POST',
            data: {
                searchtext: searchText,
                category: currentCategory,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.status === 'error') {
                    $('.ajaxContent').html('<br><p class="textCenter">' + response.message + '</p>');
                    return;
                }

                displayResults(response.results, response.category);
            },
            error: function() {
                $('.ajaxContent').html('<br><p class="textCenter">' + locaSearch.searchFailed + '</p>');
            }
        });
    }

    function displayResults(results, category) {
        if (results.length === 0) {
            $('.ajaxContent').html('<br><p class="textCenter">' + locaSearch.noResults + '</p>');
            return;
        }

        let html = '';

        if (category === 2) {
            // Player results
            html += '<table class="searchresults" cellpadding="0" cellspacing="0">';
            html += '<tbody><tr><th>' + locaSearch.playerName + '</th><th class="action">' + locaSearch.action + '</th></tr>';
            results.forEach(function(result) {
                html += '<tr><td>' + escapeHtml(result.name) + '</td>';
                html += '<td class="action" style="white-space:nowrap;">';
                @if(auth()->check())
                if (result.id != {{ auth()->id() }}) {
                    html += '<a href="javascript:void(0);" class="sendMail js_openChat tooltip" style="display:inline-block;vertical-align:middle;margin-right:4px;" data-playerid="' + result.id + '" title="Ecrire un message"><span class="icon icon_chat"></span></a>';
                    html += '<a href="javascript:void(0);" class="tooltip js_hideTipOnMobile icon sendBuddyRequest" style="display:inline-block;vertical-align:middle;" data-playerid="' + result.id + '" data-playername="' + escapeHtml(result.name) + '" title="Demande d\'ami"><span class="icon icon_user"></span></a>';
                }
                @endif
                html += '</td></tr>';
            });
            html += '</tbody></table>';
        } else if (category === 3) {
            // Planet results
            html += '<table class="searchresults" cellpadding="0" cellspacing="0">';
            html += '<tbody><tr><th>' + locaSearch.planetName + '</th><th>' + locaSearch.coordinates + '</th></tr>';
            results.forEach(function(result) {
                html += '<tr><td>' + escapeHtml(result.name) + '</td><td>' + escapeHtml(result.coordinates) + '</td></tr>';
            });
            html += '</tbody></table>';
        } else if (category === 4) {
            // Alliance results
            html += '<table cellpadding="0" cellspacing="0" class="searchresults">';
            html += '<tbody><tr>';
            html += '<th class="allyTag">' + locaSearch.tag + '</th>';
            html += '<th class="allyName">' + locaSearch.allianceName + '</th>';
            html += '<th class="allyMembers">' + locaSearch.member + '</th>';
            html += '<th class="allyMembers">#</th>';
            html += '<th class="allyPoints">' + locaSearch.points + '</th>';
            html += '<th class="action">' + locaSearch.action + '</th>';
            html += '</tr>';

            let rowClass = 'alt';
            results.forEach(function(result) {
                const infoUrl = '{{ route('alliance.info', ['alliance_id' => '__ID__']) }}'.replace('__ID__', result.id);
                const highscoreUrl = '{{ route('highscore.index') }}?searchRelId=' + result.id + '&category=2&type=0';
                const applyUrl = '{{ route('alliance.index') }}?alliance_id=' + result.id;

                // Format points with thousands separators
                const formattedPoints = new Intl.NumberFormat('en-US').format(result.points);

                html += '<tr class="' + rowClass + '">';
                html += '<td class="allyTag">';
                html += '<span class="dark_highlight_tablet">' + escapeHtml(result.tag) + '</span>';
                html += '</td>';
                html += '<td class="allyName">';
                html += '<span class="dark_highlight_tablet alliance_class small none">' + escapeHtml(result.name) + '</span>';
                html += '</td>';
                html += '<td class="allyMembers">' + result.member_count + '</td>';
                html += '<td class="allyMembers">' + (result.rank || '?') + '</td>';
                html += '<td class="allyPoints">';
                html += '<a class="dark_highlight_tablet" target="_parent" href="' + highscoreUrl + '">' + formattedPoints + '</a>';
                html += '</td>';
                html += '<td class="action">';

                // Only show apply link if user is not in an alliance AND alliance is open
                @if(!auth()->user()->alliance_id)
                if (result.is_open) {
                    html += '<a title="' + locaSearch.applyForAlliance + '" class="tooltip js_hideTipOnMobile icon js-apply-alliance" href="javascript:void(0);" data-alliance-id="' + result.id + '" data-alliance-name="' + escapeHtml(result.name) + '">';
                    html += '<span class="icon icon_mail"></span>';
                    html += '</a>';
                }
                @endif

                html += '</td>';
                html += '</tr>';

                // Alternate row class
                rowClass = (rowClass === 'alt') ? '' : 'alt';
            });

            html += '<tr><th colspan="6"></th></tr>';
            html += '<tr><td colspan="6" class="pagebar" align="right"><p></p></td></tr>';
            html += '</tbody></table>';
        }

        $('.ajaxContent').html(html);
    }

    // Candidature a une alliance directement depuis la fenetre de recherche
    $(document).off('click.applyAlliance').on('click.applyAlliance', '.js-apply-alliance', function (e) {
        e.preventDefault();

        var $lien = $(this);
        var allianceId = $lien.data('alliance-id');
        var allianceName = $lien.data('alliance-name');

        if (!confirm('Envoyer une candidature a l\'alliance ' + allianceName + ' ?')) {
            return;
        }

        var $cellule = $lien.closest('td');
        $lien.hide();
        $cellule.append('<span class="js-apply-status" style="color:#848484;">...</span>');

        $.ajax({
            url: '{{ route('alliance.apply') }}',
            type: 'POST',
            data: {
                alliance_id: allianceId,
                message: '',
                _token: '{{ csrf_token() }}'
            },
            success: function (response) {
                $cellule.find('.js-apply-status').remove();
                if (response && response.success) {
                    $lien.attr('title', 'Candidature envoyee').show();
                    $lien.find('span').css('opacity', '0.4');
                    $lien.off('click.applyAlliance').on('click', function (ev) { ev.preventDefault(); });
                } else {
                    $lien.attr('title', (response && response.message) || 'Echec').show();
                }
            },
            error: function (xhr) {
                $cellule.find('.js-apply-status').remove();
                var msg = 'Echec de l\'envoi';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $lien.attr('title', msg).show();
                alert(msg);
            }
        });
    });

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
})(jQuery);
</script>
