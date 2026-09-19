ogame.chat = {
    connected: false,
    connecting: false,
    playerId: null,
    associationId: null,
    data: {association: {}},
    playernames: {},
    playerList: null,
    isLoadingPlayerList: false,
    playerListSelector: new Array,
    /**
     * Les icones du chat Azria : douze SVG Lucide Static 0.468.0 (licence ISC, `public/img/chat-azria/LICENSE.txt`),
     * figees dans le bundle et rendues en ligne pour heriter de la couleur CSS — jamais chargees depuis le reseau,
     * jamais depuis un contenu fourni par un joueur (kit de Codex, 15 septembre 2026).
     */
    azriaIcons: {
        'radio-tower': '<path d="M4.9 16.1C1 12.2 1 5.8 4.9 1.9"/> <path d="M7.8 4.7a6.14 6.14 0 0 0-.8 7.5"/> <circle cx="12" cy="9" r="2"/> <path d="M16.2 4.8c2 2 2.26 5.11.8 7.47"/> <path d="M19.1 1.9a9.96 9.96 0 0 1 0 14.1"/> <path d="M9.5 18h5"/> <path d="m8 22 4-11 4 11"/>',
        'message-square': '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'shield': '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'orbit': '<circle cx="12" cy="12" r="3"/> <circle cx="19" cy="5" r="2"/> <circle cx="5" cy="19" r="2"/> <path d="M10.4 21.9a10 10 0 0 0 9.941-15.416"/> <path d="M13.5 2.1a10 10 0 0 0-9.841 15.416"/>',
        'crosshair': '<circle cx="12" cy="12" r="10"/> <line x1="22" x2="18" y1="12" y2="12"/> <line x1="6" x2="2" y1="12" y2="12"/> <line x1="12" x2="12" y1="6" y2="2"/> <line x1="12" x2="12" y1="22" y2="18"/>',
        'sparkles': '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/> <path d="M20 3v4"/> <path d="M22 5h-4"/> <path d="M4 17v2"/> <path d="M5 18H3"/>',
        'moon': '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'send': '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/> <path d="m21.854 2.147-10.94 10.939"/>',
        'minus': '<path d="M5 12h14"/>',
        'x': '<path d="M18 6 6 18"/> <path d="m6 6 12 12"/>',
        'chevron-up': '<path d="m18 15-6-6-6 6"/>',
        'chevron-down': '<path d="m6 9 6 6 6-6"/>',
    },
    /**
     * Une icone du dictionnaire, en SVG en ligne (decoratif : aria-hidden ; le nom accessible est porte par le bouton).
     */
    azriaIcon: function (name, size) {
        var d = ogame.chat.azriaIcons[name];
        if (!d) {
            return '';
        }
        var s = size || 16;
        return '<svg class="az-svg az-svg-' + name + '" xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + d + '</svg>';
    },
    /**
     * Le theme Azria du chat est-il pose sur la barre ? (classe opt-in `azria-chat` sur #chatBar : sans elle, les
     * fabriques rendent exactement ce qu elles rendaient.)
     */
    azriaActive: function () {
        return $('#chatBar').hasClass('azria-chat');
    },
    /**
     * Un libelle du chat : la clef de `chatLoca` posee par le gabarit, sinon la clef elle-meme — visible, donc reperable.
     */
    loca: function (key) {
        return (typeof chatLoca !== 'undefined' && chatLoca[key] !== undefined) ? chatLoca[key] : key;
    },
    /**
     * L initiale sure d un nom, pour un avatar CSS (texte, jamais du HTML).
     */
    azriaInitial: function (name) {
        var t = $.trim(String(name || ''));
        return t.length ? t.charAt(0).toUpperCase() : '?';
    },
    initConnection: function () {
        var c = ogame.chat;
        if (c.connecting || c.connected || c.isMobile) {
            return;
        }
        c.connecting = true;
        try {
            if (typeof window.Echo === 'undefined' || typeof window.Echo.private !== 'function') {
                c.connecting = false;
                return;
            }

            // Subscribe to user's private channel for direct messages
            // **Le point initial n'est pas cosmetique.** Sans lui, la bibliotheque prefixe le nom
            // par son espace de noms par defaut, `App.Events`, et attend un evenement que ce jeu
            // n'emet pas : ses classes vivent sous `OGame\Events`.
            window.Echo.private('chat.user.' + c.playerId)
                .listen('.ChatMessageSent', function (e) {
                    c.messageReceived(e);
                });

            // If user has alliance, subscribe to alliance channel
            if (c.associationId) {
                window.Echo.private('chat.alliance.' + c.associationId)
                    .listen('.ChatMessageSent', function (e) {
                        c.messageReceived(e);
                    });
            }

            c.connecting = false;
            c.connected = true;
        } catch (d) {
            c.connecting = false;
        }
    },
    initialize: function () {
        var b = ogame.chat;
        b.initConnection();
        $(".new_msg_count[data-playerid]").each(function () {
            b.saveMessageCounter($(this).data("new-messages"), $(this).data("playerid"))
        });
        this.updateTotalNewChatCounter();
        $(".js_playerlist").on("click", ".playerlist_item", function () {
            var a = $(this).hasClass("nothingThere");
            if (!a) {
                var d = $(this).data("msgid");
                if (d) {
                    b.loadChatLogWithPlayer(this, d)
                } else {
                    b.loadChatLogWithPlayer(this)
                }
            }
        });
        $(".js_playerlist").on("click", ".openAssociationChat", function () {
            var a = $(this).data("msgid");
            if (a) {
                b.loadChatLogWithAssociation(this, a)
            } else {
                b.loadChatLogWithAssociation(this)
            }
        });
        $("#chatMsgList").on("click", ".msg", function () {
            var e = $(this).data("playerid");
            var a = $(this).data("associationid");
            if (e !== undefined && e > 0) {
                b.saveMessageCounter(0, e);
                ogame.messagemarker.setPartnerId(e);
                ogame.messagemarker.updateNewMarker();
                ogame.chat.updateTotalNewChatCounter();
                var f = $(".playerlist .playerlist_item[data-playerId=" + e + "]").data("msgid");
                if (f) {
                    b.loadChatLogWithPlayer(this, f)
                } else {
                    b.loadChatLogWithPlayer(this)
                }
            } else {
                var f = $(".playerlist .playerlist_item[data-associationId=" + a + "]").data("msgid");
                b.saveMessageCounterAssociation(0, a);
                if (f) {
                    b.loadChatLogWithAssociation(this, f)
                } else {
                    b.loadChatLogWithAssociation(this)
                }
            }
        });
        $(".chat").on("click", ".sys_msg", function (f) {
            var h = $(this).data("foreign-player-id");
            var g = $(this).data("foreign-association-id");
            var a = {playerId: h, associationId: g, ajax: 1};
            console.log('chatLoadMoreMessages()');
            $.ajax({
                url: chatUrlLoadMoreMessages,
                type: "POST",
                dataType: "html",
                data: a,
                success: function (c) {
                    $(".chat").each(function (e, d) {
                        if (h !== undefined && h == $(d).data("foreign-player-id")) {
                            $(d).html(c)
                        } else {
                            if (g !== undefined && g == $(d).data("foreign-association-id")) {
                                $(d).html(c)
                            }
                        }
                    })
                },
                error: function (e, c, d) {
                }
            })
        });
        $("body").on("click", ".js_openChat", function () {
            b.loadChatLogWithPlayer(this)
        });
        if (typeof $.cookie("maximizeId") == "string" || typeof $.cookie("maximizeId") == "number") {
            $('#chatMsgList .msg[data-playerid="' + $.cookie("maximizeId") + '"]').trigger("click");
            $.cookie("maximizeId", null)
        }
    },
    getTotalNewChatCounter: function () {
        return ogame.messagecounter.sumNewChatMessages
    },
    updateTotalNewChatCounter: function () {
        var b = 0;
        if ($(".msg .new_msg_count").length > 0) {
            $(".msg .new_msg_count").each(function () {
                b += Number($(this).data("new-messages"))
            })
        } else {
            if ($("#chatBarPlayerList .new_msg_count").length > 0) {
                $("#chatBarPlayerList .new_msg_count").each(function () {
                    b += Number($(this).data("new-messages"))
                })
            }
        }
        ogame.messagecounter.initialize(ogame.messagecounter.type_chat, ogame.chat.playerId);
        if (ogame.messagecounter.sumNewChatMessages !== b) {
            ogame.messagecounter.initChatCounter(b);
            ogame.messagecounter.sumNewChatMessages = b;
            ogame.messagecounter.update()
        }
        return b
    },
    retryConnection: function () {
        var b = ogame.chat;
        setTimeout(function () {
            b.initConnection()
        }, 5000)
    },
    /*
     * **Un message n'est jamais perdu en silence, et un refus ne se rejoue pas** (constats de Keven, 19 septembre 2026,
     * journal §167).
     *
     * `issue`, facultatif, recoit l'issue de l'envoi : `envoye()` quand le serveur l'a accepte, `echoue(raison)` quand il
     * n'est pas parti — le texte doit alors etre rendu au joueur —, `refuse()` quand la conversation ne lui est plus
     * accessible. Un appelant qui ne le passe pas garde le comportement d'avant, sans la boucle.
     *
     * `NOT_AUTHORIZED` est un **vrai refus** du serveur (`ChatController` : le joueur n'est plus dans cette alliance).
     * Le rejouer, comme le faisait ce script, envoyait la meme requete sans fin : rien ne peut le lever avant que ses
     * droits changent.
     */
    sendMessage: function (u, r, n, m, issue) {
        var o = ogame.chat;
        var fin = issue || {};
        if ($.trim(n).length == 0) {
            s("TEXT_EMPTY");
            if (fin.echoue) {
                fin.echoue("TEXT_EMPTY")
            }
            return
        }
        if (u > 0) {
            var w = {playerId: u, text: n, mode: 1, ajax: 1}
        } else {
            var w = {associationId: r, text: n, mode: 3, ajax: 1}
        }
        if (typeof m !== "undefined" && typeof m.id !== "undefined") {
            w.msg2reply = m.id
        }
        function v() {
            console.log('v()');
            $.ajax({
                url: chatUrl,
                type: "POST",
                dataType: "json",
                data: w,
                success: function (a) {
                    p(a)
                },
                error: function () {
                    // Aucune reponse : le message n'est pas parti. On le dit, et l'appelant rend le texte.
                    s('NETWORK_FAILED');
                    if (fin.echoue) {
                        fin.echoue('NETWORK_FAILED')
                    }
                }
            })
        }

        function q(a) {
            if (typeof a.refAuthor !== "undefined" && typeof a.refContent !== "undefined") {
                $refData = {author: a.refAuthor, text: a.refContent}
            } else {
                $refData = 0
            }
            if (a.targetId !== undefined) {
                o.addChatItem(a.targetId, 0, a.text, a.id, false, $refData, a.date)
            } else {
                o.addChatItem(u, a.targetAssociationId, a.text, a.id, false, $refData, a.date)
            }
        }

        function s(a) {
            if (chatLoca[a] !== undefined) {
                errorBoxNotify(LocalizationStrings.error, chatLoca[a], LocalizationStrings.ok)
            } else {
                errorBoxNotify(LocalizationStrings.error, a, LocalizationStrings.ok)
            }
        }

        function p(a) {
            switch (a.status) {
                case"NOT_AUTHORIZED":
                    // Un vrai refus : on le dit une fois, et la conversation se ferme. Plus jamais `v()` ici.
                    s('NOT_AUTHORIZED');
                    if (fin.refuse) {
                        fin.refuse()
                    }
                    break;
                case"OK":
                    q(a);
                    ogame.chat.cleanupUrl();
                    if (fin.envoye) {
                        fin.envoye()
                    }
                    break;
                default:
                    s(a.status);
                    if (fin.echoue) {
                        fin.echoue(a.status)
                    }
            }
        }

        v()
    },
    messageReceived: function (h) {
        var g = ogame.chat;
        if (typeof h.refAuthor !== "undefined" && typeof h.refText !== "undefined") {
            $refData = {author: h.refAuthor, text: h.refText}
        } else {
            $refData = 0
        }
        if (h.senderName !== undefined && h.senderId !== undefined) {
            g.playernames[h.senderId] = h.senderName
        }
        if ($(".chat_bar_list").length) {
            if (h.associationId !== undefined && h.associationId > 0) {
                if (g.data.association[h.associationId] === undefined) {
                    g.loadChatLogWithAssociation(h.associationId, null, function () {
                        g.addChatItem(h.senderId, h.associationId, h.text, h.id, true, $refData, h.date)
                    }, false)
                } else {
                    g.addChatItem(h.senderId, h.associationId, h.text, h.id, true, $refData, h.date)
                }
            } else {
                if (g.data[h.senderId] === undefined) {
                    g.loadChatLogWithPlayer(h.senderId, null, function () {
                        g.addChatItem(h.senderId, 0, h.text, h.id, true, $refData, h.date)
                    }, false)
                } else {
                    g.addChatItem(h.senderId, 0, h.text, h.id, true, $refData, h.date)
                }
            }
        }
        if (h.associationId !== undefined && h.associationId > 0) {
            if ($('.chat_bar_list_item.open[data-associationid="' + h.associationId + '"]').length <= 0) {
                var e = $('.new_msg_count[data-associationid="' + h.associationId + '"]').data("new-messages");
                if (isNaN(e)) {
                    e = 0
                }
                e++;
                g.saveMessageCounterAssociation(e, h.associationId);
                g.updateTotalNewChatCounter()
            } else {
                var f = {
                    associationId: h.associationId,
                    mode: 4,
                    ajax: 1,
                    updateUnread: 1
                };
                $.ajax({
                    url: chatHistoryUrl,
                    type: "POST",
                    data: f,
                    success: function (a) {
                    },
                    error: function (c, a, b) {
                    }
                })
            }
        } else {
            if (h.senderId !== undefined && h.senderId > 0) {
                ogame.messagemarker.setPartnerId(h.senderId);
                if (!g.isOpen(h.senderId)) {
                    ogame.messagecounter.initialize(ogame.messagecounter.type_chat, h.senderId);
                    var e = parseInt(ogame.messagecounter.newChats[h.senderId]);
                    if (isNaN(e)) {
                        e = 0
                    }
                    e++;
                    g.saveMessageCounter(e, h.senderId);
                    ogame.messagemarker.updateNewMarker()
                } else {
                    g.saveMessageCounter(0, $(this).data("playerid"));
                    ogame.messagemarker.updateNewMarker()
                }
            }
        }
    },
    cleanupUrl: function () {
        var k = window.location.href;
        var h = k.indexOf("&");
        if (h > 0) {
            var g = k.indexOf("?");
            var l = k.substring(0, g);
            var f = l + "?page=chat";
            window.history.pushState({}, "", f)
        }
    },
    saveMessageCounter: function (c, d) {
        if (isNaN(d) || d === 0) {
            return false
        }
        $('.new_msg_count[data-playerid="' + d + '"]').data("new-messages", c);
        ogame.messagecounter.newChats[d] = c
    },
    saveMessageCounterAssociation: function (c, d) {
        if (isNaN(d) || d === 0) {
            return false
        }
        $('.new_msg_count[data-associationid="' + d + '"]').data("new-messages", c);
        $('.new_msg_count[data-associationid="' + d + '"]').text(c);
        ogame.messagemarker.updateNewMarker()
    },
    isOpen: function (e) {
        var f = false;
        var d = $(".chatContent").data("chatplayerid");
        if (d != "undefined" && d == e) {
            f = true
        } else {
            $(".chat_box").each(function () {
                if ($(this).attr("data-playerid") == e) {
                    if ($(this).css("display") == "block") {
                        f = true
                    }
                }
            })
        }
        return f
    },
    loadChatLogWithPlayer: function (p, n, l, h) {
        var m = ogame.chat;
        var o;
        if (typeof h == "undefined") {
            h = true
        }
        if (typeof p == "number") {
            o = p
        } else {
            o = $(p).attr("data-playerId")
        }
        var k = {playerId: o, mode: 2, ajax: 1, updateUnread: (h ? 1 : 0)};
        if (typeof n == "number") {
            k.msg2reply = n
        }
        $.ajax({
            url: chatHistoryUrl, type: "POST", dataType: "json", data: k, success: function (a) {
                m.absorbChatLog(a);
                if (typeof l == "function") {
                    l()
                } else {
                    if ($(p).parents("#chatBarPlayerList").length || $("body")[0].id != "chat") {
                        m.showChat(a)
                    } else {
                        m.showChatHistory(a)
                    }
                }
                var b = $(".chat_bar_list").find(".chat_bar_list_item[data-playerid='" + a.playerId + "']");
                m.updateCustomScrollbar(b.find(".chat_box_ctn"))
            }, error: function (c, a, b) {
            }
        })
    },
    loadChatLogWithAssociation: function (o, n, l, h) {
        var m = ogame.chat;
        var p;
        if (typeof h == "undefined") {
            h = true
        }
        if (typeof o == "number") {
            p = o
        } else {
            p = $(o).attr("data-associationid")
        }
        var k = {associationId: p, mode: 4, ajax: 1, updateUnread: (h ? 1 : 0)};
        if (typeof n == "number") {
            k.msg2reply = n
        }
        $.ajax({
            url: chatHistoryUrl, type: "POST", dataType: "json", data: k, success: function (a) {
                m.absorbChatLog(a);
                if (typeof l == "function") {
                    l()
                } else {
                    if ($(o).parents("#chatBarPlayerList").length || $("body")[0].id != "chat") {
                        m.showChat(a)
                    } else {
                        m.showChatHistory(a)
                    }
                }
                var b = $(".chat_bar_list").find(".chat_bar_list_item[data-associationid='" + a.associationId + "']");
                m.updateCustomScrollbar(b.find(".chat_box_ctn"))
            }, error: function (c, a, b) {
            }
        })
    },
    /**
     * Ranger l historique d une conversation dans la memoire du script : le meme geste pour la reponse de la route
     * et pour la charge que la page pose (`chatRestore`).
     */
    absorbChatLog: function (a) {
        var m = ogame.chat;
        if (a && a.associationId !== undefined) {
            m.data.association[a.associationId] = {
                playerstatus: a.playerstatus,
                associationName: a.associationName,
                associationId: a.associationId,
                chatItems: a.chatItems,
                chatItemsByDateAsc: a.chatItemsByDateAsc
            };
            return
        }
        if (a && a.playerId !== undefined) {
            m.data[a.playerId] = {
                playerstatus: a.playerstatus,
                playerName: a.playerName,
                playerId: a.playerId,
                chatItems: a.chatItems,
                chatItemsByDateAsc: a.chatItemsByDateAsc
            }
        }
    },
    /**
     * **Les conversations ouvertes survivent au changement de page** (constat de Keven, 19 septembre 2026).
     *
     * Le navigateur memorise les fenetres ouvertes dans le cookie `visibleChats` (`updateVisibleState`) ; personne
     * ne le relisait, et le gabarit reposait une liste vide a chaque page — tout se refermait. Desormais la PAGE
     * porte leur historique (`chatRestore`, construit par `OpenConversations`) : les fenetres sont la d emblee,
     * sans requete et sans battement. A defaut — page ancienne, cookie seul — on redemande l historique par le
     * chemin ordinaire, cinq conversations au plus.
     *
     * Rien n est invente : un identifiant qui n est pas un entier positif est ignore, un cookie illisible est
     * ignore, et une conversation deja dans la barre n est pas redemandee. Rouvrir une fenetre ne marque pas les
     * messages comme lus (`updateUnread` a faux) : ce n est pas la lire.
     */
    restoreOpenChats: function () {
        var c = ogame.chat;
        // **La page porte deja l historique des conversations ouvertes** : on les pose sans une requete, donc sans
        // le battement pendant lequel la fenetre manquait a l ecran (constat de Keven, 19 septembre 2026).
        if (typeof chatRestore !== 'undefined' && $.isArray(chatRestore) && chatRestore.length) {
            $.each(chatRestore, function (i, charge) {
                c.absorbChatLog(charge);
                c.showChat(charge)
            });
            return
        }
        if (typeof $.cookie !== 'function') {
            return
        }
        var brut = $.cookie('visibleChats');
        if (!brut) {
            return
        }
        var memoire = null;
        try {
            memoire = JSON.parse(brut)
        } catch (e) {
            return
        }
        if (!memoire || typeof memoire !== 'object') {
            return
        }
        var entiers = function (liste) {
            var vus = [];
            if (!$.isArray(liste)) {
                return vus
            }
            for (var i = 0; i < liste.length; i++) {
                var v = liste[i];
                if (v !== null && typeof v === 'object') {
                    v = v.partnerId
                }
                v = parseInt(v, 10);
                if (!isNaN(v) && v > 0 && $.inArray(v, vus) === -1) {
                    vus.push(v)
                }
            }
            return vus
        };
        var reste = 5;
        $.each(entiers(memoire.players), function (i, id) {
            if (reste <= 0 || $(".chat_bar_list .chat_bar_list_item[data-playerid='" + id + "']").length) {
                return
            }
            reste--;
            c.loadChatLogWithPlayer(id, undefined, undefined, false)
        });
        $.each(entiers(memoire.associations), function (i, id) {
            if (reste <= 0 || $(".chat_bar_list .chat_bar_list_item[data-associationid='" + id + "']").length) {
                return
            }
            reste--;
            c.loadChatLogWithAssociation(id, undefined, undefined, false)
        })
    },
    initChat: function (c, d, associationId) {
        ogame.chat.playerId = c;
        ogame.chat.isMobile = d;
        ogame.chat.associationId = associationId || null;
        ogame.chat.initPlayerlist();
        ogame.chat.initialize();
        ogame.chat.toggleVisibility();
        ogame.chat.setVisibilityState();
        ogame.chat.restoreOpenChats();
        ogame.chat.initMaximize();
        ogame.chat.getInMaxChat()
    },
    getLastChatItemData: function () {
        var l = ogame.chat;
        var h = null;
        $(".chat_box_ctn .mCustomScrollBox .mCSB_container").each(function () {
            var a = $(this).children("ul.chat").children("li:last");
            if (h === null || a.attr("data-chat-id") > h.attr("data-chat-id")) {
                h = a
            }
        });
        if (h === null) {
            $("ul.largeChat").each(function () {
                var a = $(this).children("li:last");
                if (h === null || a.attr("data-chat-id") > h.attr("data-chat-id")) {
                    h = a
                }
            })
        }
        if (h === null) {
            return null
        }
        var k = h.children(".msg_head").find(".msg_date").html();
        var f = h.find(".msg_content").html();
        var g = {date: k, text: f};
        return g
    },
    addChatItem: function (F, x, B, D, v, A, r) {
        var u = ogame.chat;
        var q;
        // **L onglet, pas n importe quel element qui porte l identifiant** : le panneau des contacts vit dans la meme liste et ses
        // lignes portent data-playerid ; le message d un contact sans fenetre ouverte s ajoutait a sa ligne, donc nulle part, et
        // la fenetre ne s ouvrait jamais en direct (defaut herite, ferme avec le design Azria, journal §158).
        if (x > 0) {
            q = $(".chat_bar_list").find(".chat_bar_list_item[data-associationid='" + x + "']")
        } else {
            q = $(".chat_bar_list").find(".chat_bar_list_item[data-playerid='" + F + "']")
        }
        var y = {};
        y.date = r;
        y.newClass = "new";
        if (v) {
            if (u.data[F] !== undefined) {
                y.playerName = u.data[F].playerName
            } else {
                y.playerName = u.playernames[F]
            }
            y.altClass = ""
        } else {
            y.playerName = playerName;
            y.altClass = "odd"
        }
        y.chatID = D;
        y.chatContent = B;
        if (typeof A == "object") {
            y.refData = A
        }
        if (!q.length) {
            var E = u.createChatBarContainer(F);
            u.updateChatBar(E);
            q = $(".chat_bar_list").find(".chat_bar_list_item[data-playerid='" + F + "']")
        }
        var w = u.createChatItem(y);
        var s = u.getLastChatItemData();
        // **Un chat vide n'a pas de dernier element** : `s` vaut alors `null`, et la condition
        // d'origine faisait disparaitre le tout premier message jusqu'au rechargement. Rien a
        // comparer ne veut pas dire rien a afficher.
        if (s === null || y.date != s.date || y.chatContent != s.text) {
            q.find(".chat").append(w);
            u.updateCustomScrollbar(q.find(".chat_box_ctn"));
            var C = $(".js_chatHistory");
            if (C.length && (C.data("chatplayerid") == F || C.data("associationid") == x)) {
                C.find(".chat.clearfix").append(w.clone());
                u.updateCustomScrollbar($(".largeChatContainer"))
            }
        }
    },
    addToMoreBox: function (m) {
        var l = ogame.chat;
        var k = m.length;
        if (k && $(".more_chat_bar_items").length < 1) {
            $(".chat_bar_list").append(l.createMoreBox("more_chat_bar_items"))
        }
        var g = $(".more_chat_bar_items .more_items");
        var h = $(".more_chat_bar_items .chat_box");
        for (var n = 0; n <= k; n++) {
            g.append(m.pop())
        }
        l.updateCustomScrollbar(h)
    },
    createChatBarContainer: function (f) {
        var g = ogame.chat;
        if (!f) {
            return
        }
        var h = g.data[f];
        g.data.playerId = f;
        var e = $('<li class="chat_bar_list_item open" data-playerid="' + f + '"></li>');
        e.append('<span class="playerstatus ' + h.playerstatus + '"></span>');
        if (g.azriaActive()) {
            e.append('<span class="az-tab-icon">' + g.azriaIcon('message-square') + '</span>');
            e.append($('<span class="cb_playername"></span>').text(h.playerName));
        } else {
            e.append('<span class="cb_playername">' + h.playerName + "</span>");
        }
        e.append(g.azriaActive() ? $('<span class="icon icon_close fright"></span>').attr('title', g.loca('CLOSE_CONVERSATION')).html(g.azriaIcon('x')) : '<span class="icon icon_close fright"></span>');
        e.prepend(g.createChatBox(f));
        return e
    },
    createChatBarContainerForAssociations: function (f) {
        var g = ogame.chat;
        if (!f) {
            return
        }
        var h = g.data.association[f];
        g.data.associationId = f;
        var e = $('<li class="chat_bar_list_item open" data-associationid="' + f + '"></li>');
        e.append('<span class="playerstatus ' + h.playerstatus + '"></span>');
        e.append('<span class="chatstatus cs_new fleft"></span>');
        if (g.azriaActive()) {
            e.append('<span class="az-tab-icon">' + g.azriaIcon('shield') + '</span>');
        }
        e.append($('<span class="cb_playername" data-associationid="' + f + '"></span>').text(g.loca('ALLIANCE_CHAT')));
        e.append('<span class="new_msg_count noMessage" data-associationid="' + f + '" data-new-messages="0">0</span>');
        e.append(g.azriaActive() ? $('<span class="icon icon_close fright"></span>').attr('title', g.loca('CLOSE_CONVERSATION')).html(g.azriaIcon('x')) : '<span class="icon icon_close fright"></span>');
        e.prepend(g.createChatBoxForAssociations(f));
        return e
    },
    /**
     * Fermer une conversation : l onglet quitte la barre, et la fenetre avec lui — elle vit dedans.
     *
     * **Le style en ligne doit partir** (constat de Keven, 19 septembre 2026) : `showChat()` et
     * `setVisibilityState()` posent `style="display: inline"` sur l onglet, et un style en ligne bat n importe
     * quelle feuille. Sans l effacer, un onglet marque `outOfChatbar` restait visible — le X semblait ne rien
     * faire sur une conversation rouverte depuis les contacts.
     */
    closeChatBox: function (f, d) {
        var e = $(".chat_bar_list_item");
        var fermer = function (a) {
            $(a).addClass("outOfChatbar").removeClass("open").children(".chat_box").hide();
            a.style.display = ""
        };
        $.each(e, function (b, a) {
            if (f !== undefined && $(a).data("playerid") == f) {
                fermer(a)
            } else {
                if (d !== undefined && $(a).data("associationid") == d) {
                    fermer(a)
                }
            }
        })
    },
    getVisibleChats: function () {
        if (typeof visibleChats == "undefined") {
            visibleChats = {chatbar: false, players: [], associations: []}
        }
        return visibleChats
    },
    getVisibleChatPlayerIds: function () {
        var k = ogame.chat;
        var l = k.getVisibleChats();
        var h = {};
        var g = 0;
        for (var f = 0; f < l.players.length; f++) {
            if ($.inArray(l.players[f]["partnerId"], h) == -1) {
                h[g] = l.players[f]["partnerId"];
                g++
            }
        }
        return h
    },
    getVisibleChatAssociationIds: function () {
        var h = ogame.chat;
        var k = h.getVisibleChats();
        var l = {};
        var g = 0;
        for (var f = 0; f < k.associations.length; f++) {
            if ($.inArray(k.associations[f]["partnerId"], l) == -1) {
                l[g] = k.associations[f];
                g++
            }
        }
        return l
    },
    /**
     * La place de chaque fenetre de conversation le long de la barre (theme Azria).
     *
     * Les fenetres ne sont plus posees sur leur onglet — c est ce qui obligeait l onglet a faire leur largeur
     * (constat de Keven, 19 septembre 2026). Elles se rangent cote a cote depuis le bord droit, a la suite du
     * panneau des contacts quand il est ouvert. La barre etant en `flex-direction: row-reverse`, le premier onglet
     * du DOM est le plus a droite : l ordre des fenetres suit celui des onglets.
     */
    azriaDeck: function () {
        var bar = $('#chatBar');
        if (!bar.hasClass('azria-chat')) {
            return;
        }
        var panel = $('#chatBarPlayerList > .cb_playerlist_box');
        var shift = 0;
        if (panel.length && panel.is(':visible') && $('body').innerWidth() >= 620) {
            shift = panel.outerWidth() + 14;
        }
        var droite = 8 + shift;
        $('.chat_bar_list > .chat_bar_list_item.open').each(function () {
            var box = $(this).children('.chat_box');
            if (!box.length) {
                return;
            }
            box[0].style.setProperty('--az-window-right', droite + 'px');
            droite += (box.outerWidth() || 350) + 7;
        });
    },
    setVisibilityState: function () {
        var n = ogame.chat;
        var s = n.getVisibleChatPlayerIds();
        var m = n.getVisibleChatAssociationIds();
        var o = $("#chatBar .chat_bar_list .chat_bar_list_item");
        for (var q = 0; q < o.length; q++) {
            var l = o.get(q);
            var u = $(l).data("playerid");
            var r = $(l).data("associationid");
            if (u !== undefined && !n.isInJson(u, s)) {
                n.closeChatBox(u, 0)
            } else {
                if (r !== undefined && !n.isInJson(r, m)) {
                    n.closeChatBox(0, r)
                } else {
                    l.style.display = "inline";
                    if ($(l).hasClass("open")) {
                        var p = $(l).find("div.chat_box")[0];
                        p.style.display = "inline";
                        n.updateCustomScrollbar($(l).find(".chat_box_ctn"), 1)
                    }
                }
            }
        }
        n.azriaDeck()
    },
    isInJson: function (f, d) {
        var e = null;
        if ($.isEmptyObject(d)) {
            e = false
        }
        if (e !== false) {
            $.each(d, function (a, b) {
                if (b == f) {
                    e = true
                }
            });
            if (e !== true) {
                false
            }
        }
        return e
    },
    toggleVisibility: function () {
        $(".chat_bar_list_item .icon_close").on("click", function (f) {
            var e = $(this).parent().data("playerid");
            var d = $(this).closest(".chat_box");
            if (!d.length) {
                d = $(this).parent()[0];
                d.style.display = "none"
            }
            if (e > 0) {
                console.log('toggleVisibilityChat()');
                $.ajax({
                    type: "POST",
                    url: "/chat/visibility",
                    data: {from: playerId, to: e, showState: 0},
                    success: function (a) {
                    },
                    error: function (c, a, b) {
                    }
                })
            }
        });
        $(".cb_playerlist_box .playerlist_item").on("click", function () {
            var b = $(this).data("playerid");
            if (b) {
                console.log('cb_playerlist_box()');
                $.ajax({
                    type: "POST",
                    url: "/chat/visibility",
                    data: {from: playerId, to: b, showState: 1},
                    success: function (a) {
                    },
                    error: function (a, e, f) {
                    }
                })
            }
        })
    },
    initMaximize: function () {
        $(".chat_bar_list").on("click.chatBar", ".chat_box .chat_box_title .icon_maximize", function () {
            var c = $(this).parent();
            var d = $(c).parent().data("playerid");
            $.cookie("maximizeId", d);
            $(".chat_bar_list_item.open .chat_box_title .icon_close").trigger("click");
            window.location = bigChatLink + "&playerId=" + d
        })
    },
    getInMaxChat: function () {
        var b = location.href;
        if (typeof bigChatLink == "undefined") {
            bigChatLink = ""
        }
        if (bigChatLink == b) {
            if ($.cookie("maximizeId") !== null) {
                $("#chatMsgList .msg[data-playerId=" + $.cookie("maximizeId") + "]").trigger("click")
            }
        }
        $.cookie("maximizeId", null)
    },
    createChatBox: function (l) {
        var n = ogame.chat;
        if (!l) {
            return
        }
        var p = n.data[l];
        var r = $('<div class="chat_box_title"></div>');
        r.append(n.azriaTitleButtons());
        n.azriaTitleIdentity(r, p.playerName, p.playerstatus, n.azriaInitial(p.playerName));
        var m = $('<div class="chat_box_ctn"><ul class="chat clearfix"></ul></div>');
        var k = {};
        for (var q = 0; q < p.chatItemsByDateAsc.length; q++) {
            k = p.chatItems[p.chatItemsByDateAsc[q]];
            m.find(".chat").append(n.createChatItem(k))
        }
        var o = $('<div class="chat_box" data-playerid="' + l + '"></div>');
        o.append(r);
        o.append(m);
        o.append('<textarea name="text" class="chat_box_textarea"></textarea>');
        n.azriaSendButton(o);
        return o
    },
    createChatBoxForAssociations: function (r) {
        var n = ogame.chat;
        if (!r) {
            return
        }
        var p = n.data.association[r];
        var k = $('<div class="chat_box_title"></div>');
        k.append(n.azriaTitleButtons());
        n.azriaTitleIdentity(k, p.associationName || n.loca('ALLIANCE_CHAT'), 'alliance', null);
        var m = $('<div class="chat_box_ctn"><ul class="chat clearfix" data-foreign-association-id="' + r + '"></ul></div>');
        var l = {};
        for (var q = 0; q < p.chatItemsByDateAsc.length; q++) {
            l = p.chatItems[p.chatItemsByDateAsc[q]];
            m.find(".chat").append(n.createChatItem(l))
        }
        var o = $('<div class="chat_box" data-associationid="' + r + '"></div>');
        o.append(k);
        o.append(m);
        o.append('<textarea name="text" class="chat_box_textarea"></textarea>');
        n.azriaSendButton(o);
        return o
    },
    /**
     * Les boutons de l en-tete d une fenetre.
     *
     * Sans le theme, les deux accroches historiques : fermer et ouvrir la messagerie. **Sous le theme, « Ouvrir la
     * messagerie » n existe plus** (constat de Keven, 19 septembre 2026) : ce bouton envoie le navigateur vers
     * `bigChatLink + "&playerId=" + id`, et `bigChatLink` n est pose nulle part sur ce serveur — le clic menait donc
     * a une adresse sans page. Restent « fermer » et « reduire ».
     */
    azriaTitleButtons: function () {
        var n = ogame.chat;
        if (!n.azriaActive()) {
            return [$('<span class="icon icon_close fright"></span>'), $('<span class="icon icon_maximize fright"></span>')];
        }
        var close = $('<span class="icon icon_close fright az-icon" role="button" tabindex="0"></span>').attr('title', n.loca('CLOSE_CONVERSATION')).attr('aria-label', n.loca('CLOSE_CONVERSATION')).html(n.azriaIcon('x'));
        var minimize = $('<span class="icon icon_minimize fright az-icon" role="button" tabindex="0"></span>').attr('title', n.loca('MINIMIZE_CONVERSATION')).attr('aria-label', n.loca('MINIMIZE_CONVERSATION')).html(n.azriaIcon('minus'));
        return [close, minimize];
    },
    /**
     * L identite de l en-tete sous le theme Azria : un avatar (initiale sure, ou le bouclier pour l alliance), le nom en
     * texte, et la presence telle que le serveur la connait — jamais inventee.
     */
    azriaTitleIdentity: function (title, name, status, initial) {
        var n = ogame.chat;
        if (!n.azriaActive()) {
            return;
        }
        var avatar = $('<span class="az-avatar" aria-hidden="true"></span>');
        if (initial === null) {
            avatar.addClass('az-avatar-alliance').html(n.azriaIcon('shield', 19));
        } else {
            avatar.text(initial);
        }
        var label = $('<span class="az-title"></span>').text(name);
        var small = $('<small></small>');
        if (status === 'alliance') {
            small.text(n.loca('ALLIANCE_CHANNEL'));
        } else if (status === 'online' || status === 'offline') {
            // La pastille verte seulement pour une presence en ligne ; hors ligne se dit en toutes lettres, sans pastille.
            if (status === 'online') {
                small.append('<span class="az-status-dot online"></span>');
            }
            small.append(document.createTextNode(n.loca(status === 'online' ? 'STATUS_ONLINE' : 'STATUS_OFFLINE')));
        } else {
            small.text(n.loca('PRIVATE_CONVERSATION'));
        }
        label.append(small);
        title.prepend(label).prepend(avatar);
    },
    /**
     * Le bouton Envoyer d une fenetre (theme Azria) : il prend exactement le chemin de la touche Entree —
     * `submitChatBarMsg` avec le code 13 —, jamais une requete a part.
     */
    azriaSendButton: function (box) {
        var n = ogame.chat;
        if (!n.azriaActive()) {
            return;
        }
        box.append($('<button type="button" class="az-send"></button>').attr('title', n.loca('SEND')).attr('aria-label', n.loca('SEND')).html(n.azriaIcon('send')));
    },
    createChatItem: function (m) {
        if (!m) {
            console.warn("no chatItem given");
            return
        }
        var g = $('<div class="msg_head"></div>');
        // Le serveur date chaque message en SECONDES (created_at->timestamp, envoi, historique et diffusion) ; getFormatedDate
        // attend des millisecondes : chaque message s affichait en janvier 1970. Une valeur deja en millisecondes passe telle quelle.
        var horodatage = Number(m.date);
        if (!isNaN(horodatage) && horodatage > 0 && horodatage < 100000000000) {
            horodatage = horodatage * 1000
        }
        g.append('<span class="msg_date fright">' + getFormatedDate(horodatage, "[d].[m].[Y] <span>[H]:[i]:[s]</span>") + "</span>");
        g.append('<span class="msg_title blue_txt ' + m.newClass + '">' + m.playerName + "</span>");
        var h = $('<li class="chat_msg ' + m.altClass + '" data-chat-id="' + m.chatID + '"></li>');
        h.append(g);
        if (typeof m.refData !== "undefined") {
            var k = $('<div class="referenceMsg"></div>');
            var n = '<div class="refAuthor">' + m.refData.author + "</div>";
            var l = '<div class="refText new">' + m.refData.text + "</div>";
            k.append(n);
            k.append(l);
            h.append(k)
        }
        h.append('<span class="msg_content">' + m.chatContent + "</span>");
        h.append('<div class="speechbubble_arrow"></div>');
        return h
    },
    createMoreBox: function (d) {
        var c = $('<li class="chat_bar_list_item ' + d + '">' + chatLoca.MORE_USERS + '<span class="icon icon_close fright"></span></li>');
        c.prepend($('<div class="chat_box"><ul class="more_items clearfix"></ul></div>'));
        return c
    },
    filterPlayerlist: function () {
        var l = [];
        var h;
        var k = $("#playerlistFilters").find('input[type="checkbox"]');
        k.each(function () {
            l.push($(this).attr("id"))
        });
        $(".playerlist_item").show();
        h = false;
        k.each(function () {
            if ($(this).prop("checked")) {
                h = true
            }
        });
        if (!h) {
            return
        }
        var f;
        var g;
        $(".playerlist_item").filter(function () {
            f = false;
            g = $(this);
            $.each(l, function (b, a) {
                if (g.data(a) === "off" && $("#" + a).prop("checked")) {
                    f = true
                }
            });
            (f === true) ? g.hide() : g.show()
        })
    },
    initChatBar: function (d) {
        var c = ogame.chat;
        ogame.chat.playerId = d;
        $("html").off(".chatBar");
        $(window).resize(function () {
            c.updateChatBar()
        });
        $(".chat_bar_list").on("click.chatBar", "#chatBarPlayerList", function (a) {
            // L onglet porte une icone et un compteur sous le theme Azria : le clic sur l un d eux est un clic sur l onglet.
            if ($(a.target).attr("id") !== "chatBarPlayerList" && !$(a.target).closest(".onlineCount").length) {
                return
            }
            $(".cb_playerlist_box").toggle();
            c.updateCustomScrollbar($(".scrollContainer"), true);
            c.azriaDeck();
            c.updateVisibleState()
        }).on("click.chatBar", ".cb_playerlist_box .az-collapse", function (a) {
            // Replier le panneau des contacts : la meme chose que fermer l onglet.
            a.stopPropagation();
            $(".cb_playerlist_box").hide();
            c.azriaDeck();
            c.updateVisibleState()
        }).on("click.chatBar", ".cb_playerlist_box .az-filter button", function (a) {
            // Les trois filtres du panneau posent les deux cases que `filterPlayerlist` lit deja : en ligne, tous, discussions.
            a.stopPropagation();
            var mode = $(this).data("filter");
            $("[id=playerlistFilters] [id=filteronline]").prop("checked", mode === "online");
            $("[id=playerlistFilters] [id=filterchatactive]").prop("checked", mode === "active");
            $(this).closest(".az-filter").find("button").attr("aria-pressed", "false");
            $(this).attr("aria-pressed", "true");
            c.filterPlayerlist();
            c.updateCustomScrollbar($(".scrollContainer"), true)
        }).on("click.chatBar", ".chat_box .chat_box_title .icon_minimize", function (a) {
            // Reduire : la fenetre se cache, l onglet reste, le brouillon aussi (le DOM n est pas detruit).
            a.stopPropagation();
            var item = $(this).closest(".chat_bar_list_item");
            item.children(".chat_box").hide();
            item.removeClass("open");
            c.updateChatBar();
            c.updateVisibleState()
        }).on("click.chatBar", ".chat_box .az-send", function (a) {
            // Envoyer : le chemin de la touche Entree, et rien d autre. Un texte vide ne part pas ; un texte deja parti a
            // vide la zone, donc un second clic n envoie rien.
            a.stopPropagation();
            var t = $(this).siblings(".chat_box_textarea");
            if ($.trim(t.val()).length > 0) {
                c.submitChatBarMsg(t, 13, false, t[0].scrollHeight)
            }
            t.focus()
        }).on("click.chatBar", ".chat_bar_list_item", function (a) {
            a.stopPropagation();
            if (!isNaN($(this).data("playerid"))) {
                ogame.messagemarker.toggle(ogame.messagemarker.action_remove, ogame.messagemarker.type_chattab, $(this).data("playerid"));
                ogame.messagemarker.toggle(ogame.messagemarker.action_remove, ogame.messagemarker.type_chatbar, $(this).data("playerid"));
                c.saveMessageCounter(0, $(this).data("playerid"));
                ogame.messagemarker.setPartnerId($(this).data("playerid"));
                ogame.messagemarker.updateNewMarker();
                ogame.chat.updateTotalNewChatCounter()
            } else {
                if (!isNaN($(this).data("associationid") > 0)) {
                    c.saveMessageCounterAssociation(0, $(this).data("associationid"))
                }
            }
            $.ajax({
                url: "/chat/read",
                type: "POST",
                dataType: "json",
                data: {
                    playerId: $(this).data("playerid")
                },
                success: function (b) {
                },
                error: function (h, b, g) {
                }
            });
            if ($(this).closest(".more_items").length) {
                c.swapChatBarItem($(this))
            } else {
                c.toggleChatBox($(a.target), $(this))
            }
            c.updateVisibleState()
        }).on("click.chatBar", ".chat_bar_list_item > .icon_close", function (a) {
            a.stopPropagation();
            var b = $(this).closest(".chat_bar_list_item");
            ogame.chat.closeChatBox(b.attr("data-playerid"), b.attr("data-associationid"));
            b.remove("open");
            c.updateChatBar()
        }).on("keyup.chatBar", ".chat_box_textarea", function (a) {
            if ((a.ctrlKey || a.keyCode == 10) && a.keyCode == 13) {
                a.preventDefault();
                var b = $(this).val();
                $(this).val(b + "\n")
            } else {
                if ($.trim($(this).val().length > 0)) {
                    a.preventDefault();
                    c.submitChatBarMsg($(a.currentTarget), a.which, a.shiftKey, a.delegateTarget.scrollHeight)
                }
            }
        }).on("click.chatBar", ".chat_box_textarea", function (a) {
            ogame.messagemarker.toggle(ogame.messagemarker.action_remove, ogame.messagemarker.type_chattab, $(this).parent().parent().parent().data("playerid"));
            ogame.messagemarker.toggle(ogame.messagemarker.action_remove, ogame.messagemarker.type_chatbar, $(this).parent().parent().parent().data("playerid"));
            if ($(this).data("playerid") > 0) {
                c.saveMessageCounter(0, $(this).data("playerid"))
            } else {
                if ($(this).data("associationid") > 0) {
                    c.saveMessageCounterAssociation(0, $(this).data("associationid"))
                }
            }
        })
    },
    initPlayerlist: function () {
        var c = ogame.chat;
        var d = ogame.tools;
        $(".js_accordion").accordion({
            collapsible: true,
            heightStyle: "content"
        });
        $(".playerlist_item:odd").addClass("odd");
        d.addHover(".playerlist_item, .msg, .playerlist_top_box .playerlist");
        $(".js_playerlist").on("click.playerList", ".pl_filter_set", function () {
            c.filterPlayerlist()
        });
        c.filterPlayerlist()
    },
    showChat: function (h) {
        var e = false;
        var g = ogame.chat;
        $(".chat_bar_list_item").each(function () {
            var a = $(this);
            if ((h.playerId !== undefined && a.data("playerid") === h.playerId) || (h.associationId !== undefined && a.data("associationid") === h.associationId)) {
                e = true;
                if (a.hasClass("outOfChatbar")) {
                    a.removeClass("outOfChatbar")
                }
                // **Ouvrir ce qui est deja ouvert ne le referme pas** (constat de Keven, 19 septembre 2026) :
                // `a.click()` BASCULE la fenetre, donc cliquer le nom d un ami dont la conversation etait ouverte
                // la fermait. On ne clique que si la fenetre n est pas visible.
                if (!a.hasClass("open") || !a.children(".chat_box").is(":visible")) {
                    a.click();
                    a[0].style.display = "inline"
                } else {
                    a.fadeTo("400", 0.3).fadeTo("400", 1)
                }
                a.find("textarea").focus()
            }
        });
        if (!e) {
            var f;
            if (h.playerId !== undefined) {
                f = g.createChatBarContainer(h.playerId)
            } else {
                f = g.createChatBarContainerForAssociations(h.associationId)
            }
            g.updateChatBar(f)
        }
    },
    showChatHistory: function (e) {
        var d = $(".js_chatHistory");
        var f = e.data;
        if (d.length) {
            d.remove()
        }
        $("#chatList").remove();
        $(f).insertAfter("#planet");
        $("li.playerlist_item").removeClass("active");
        $("li.playerlist_item[data-playerid='" + e.playerId + "']").addClass("active");
        initBBCodeEditor(locaKeys, itemNames, false, ".new_msg_textarea", 2000, true)
    },
    submitChatBarMsg: function (p, l, h, m) {
        var o = ogame.chat;
        var n = parseInt($(".chat_box_textarea").css("max-height"));
        var k = parseInt($(".chat_box_textarea").css("padding-top")) + parseInt($(".chat_box_textarea").css("padding-bottom"));
        if (l === 13 && h) {
            if (m <= (n + k)) {
                p.css("height", m - k)
            }
            return
        }
        if (l === 13) {
            // **Le texte reste dans le champ jusqu'a la confirmation du serveur** (constat de Keven, journal §167). Il etait
            // vide des l'envoi : une connexion coupee faisait disparaitre le message, sans avertissement ni brouillon.
            // Pendant l'envoi, le champ est en lecture seule et une seconde Entree ne part pas : jamais de double envoi.
            if (p.data("azriaEnvoi") === true) {
                return
            }
            var boite = p.parent(".chat_box");
            var joueur = boite.data("playerid");
            var alliance = boite.data("associationid");
            if (joueur === undefined && alliance === undefined) {
                return
            }
            var texte = p.val();
            p.data("azriaEnvoi", true).prop("readonly", true).addClass("azria-chat-sending");
            var liberer = function () {
                p.data("azriaEnvoi", false).prop("readonly", false).removeClass("azria-chat-sending")
            };
            o.sendMessage(joueur !== undefined ? joueur : 0, joueur !== undefined ? 0 : alliance, texte, undefined, {
                envoye: function () {
                    liberer();
                    p.val("")
                },
                echoue: function () {
                    // Le brouillon est rendu tel que le joueur l'a tape : sans le saut de ligne que la touche Entree
                    // vient d'ajouter, sinon chaque nouvel essai en accumulerait un.
                    liberer();
                    p.val(texte.replace(/\r?\n$/, ""))
                },
                refuse: function () {
                    // Desactivee jusqu'a l'actualisation des droits (un rechargement de la page) : le refus est dit dans
                    // le champ lui-meme, et plus rien ne part d'ici.
                    liberer();
                    p.val(texte.replace(/\r?\n$/, "")).prop("disabled", true).addClass("azria-chat-refused").attr("placeholder", o.loca('NOT_AUTHORIZED'))
                }
            })
        }
    },
    swapChatBarItem: function (d) {
        var f = ogame.chat;
        var e = $(".more_chat_bar_items").prev();
        e.removeClass("open").find(".icon_close").hide().end().find(".chat_box").hide();
        e.remove();
        d.addClass("open").find(".icon_close").show().end().find(".chat_box").show().end().insertBefore(".more_chat_bar_items");
        f.addToMoreBox([e]);
        f.updateChatBar();
        f.updateCustomScrollbar(d.find(".chat_box_ctn"))
    },
    toggleChatBox: function (f, l) {
        var h = ogame.chat;
        // Le clic peut tomber sur le SVG d une icone : on regarde l icone, pas le noeud touche.
        if (f.parents(".chat_box").length && !f.closest(".icon_close").length) {
            return
        }
        if (h.azriaActive() && f.closest(".chat_box_title .icon_close").length) {
            // Sous le theme, « Fermer » dans l en-tete ferme la conversation (l onglet part) ; « Reduire » la cache.
            l.children(".icon_close").trigger("click");
            return
        }
        var k = l.children(".chat_box");
        if (k.is(":visible")) {
            k.hide();
            l.removeClass("open")
        } else {
            if (!l.hasClass("more_chat_bar_items")) {
                l.addClass("open");
                h.updateChatBar()
            }
            if (h.azriaActive() && $("body").innerWidth() < 620) {
                // Petit ecran : une seule fenetre ouverte a la fois, les autres se reduisent (leur brouillon reste).
                $(".chat_bar_list > .chat_bar_list_item.open").not(l).each(function () {
                    $(this).children(".chat_box").hide();
                    $(this).removeClass("open")
                })
            }
            k.show();
            var g = k.find(".chat_box_ctn");
            if (l.hasClass("more_chat_bar_items")) {
                g = k
            }
            h.updateCustomScrollbar(g);
            k.find("textarea").focus()
        }
        ogame.messagecounter.resetCounterByType(ogame.messagecounter.type_chat)
    },
    handleTooMuchWindows: function (o, l, u, s, p, r) {
        var q = ogame.chat;
        var n = true;
        var m = [];
        $($(".chat_bar_list > .chat_bar_list_item").get().reverse()).each(function () {
            var a = $(this);
            if (n) {
                if (a.hasClass("more_chat_bar_items") || a.attr("id") === "chatBarPlayerList") {
                    return
                }
                if (a.hasClass("open")) {
                    o--
                } else {
                    l--
                }
                a.removeClass("open").find(".icon_close").hide().end().find(".chat_box").hide();
                m.push(a);
                a.remove();
                widthTotal = s * l + u * o + p;
                n = (widthTotal >= r) ? true : false
            }
        });
        q.addToMoreBox(m)
    },
    getItemFromMorelist2Chatbar: function () {
        var c = $(".more_items .chat_bar_list_item").first().remove();
        var d = ogame.chat;
        c.addClass("open").find(".icon_close").show().end().find(".chat_box").show().end().insertBefore(".more_chat_bar_items");
        if ($(".more_items .chat_bar_list_item").length <= 0) {
            $(".more_chat_bar_items").remove()
        }
        d.updateCustomScrollbar($(".more_chat_bar_items>.chat_box"));
        d.updateCustomScrollbar(c.find(".chat_box_ctn"))
    },
    updateChatBar: function (n) {
        var r = ogame.chat;
        var o = $(".chat_bar_list > .chat_bar_list_item.open").length;
        var v = $(".more_chat_bar_items").length;
        var m = $(".chat_bar_list").children().length - o - v;
        var u = 190;
        var w = r.azriaActive() ? 350 : 270;
        var q = 190;
        var s = $("body").innerWidth();
        if (n) {
            o++
        }
        var p = u * m + w * o + q * v;
        if (r.azriaActive() && s < 620) {
            // Petit ecran sous le theme : pas de boite « plus » (les onglets se rangent en flex), une seule fenetre ouverte,
            // bornee par l ecran par la feuille.
            if (n) {
                $(".chat_bar_list > .chat_bar_list_item.open").each(function () {
                    $(this).children(".chat_box").hide();
                    $(this).removeClass("open")
                });
                n.insertAfter("#chatBarPlayerList");
                r.updateCustomScrollbar(n.find(".chat_box_ctn"))
            }
            r.azriaDeck();
            return
        }
        if (p >= s) {
            r.handleTooMuchWindows(o, m, w, u, q, s)
        } else {
            if ((p + w) <= s && $(".more_chat_bar_items").length > 0) {
                r.getItemFromMorelist2Chatbar()
            }
        }
        if (n) {
            n.insertAfter("#chatBarPlayerList");
            r.updateCustomScrollbar(n.find(".chat_box_ctn"))
        }
        r.azriaDeck()
    },
    updateCustomScrollbar: function (c, d) {
        if (!c || c.length == 0) {
            return
        }
        if (c.hasClass("mCustomScrollbar")) {
            c.mCustomScrollbar("update")
        } else {
            c.mCustomScrollbar({theme: "ogame"})
        }
        if (d !== true) {
            c.mCustomScrollbar("scrollTo", "bottom", {scrollInertia: 0})
        }
        c.each(function () {
            if ($(this).height() + "px" == $(this).css("max-height")) {
                $(this).addClass("scrollbarPresent")
            }
        })
    },
    updateVisibleState: function () {
        var b = {chatbar: false, players: [], associations: []};
        $(".chat_bar_list>.chat_bar_list_item").each(function () {
            var a = $(this);
            if (a.attr("id") === "chatBarPlayerList" && a.children(".cb_playerlist_box").is(":visible")) {
                b.chatbar = true
            } else {
                if (a.data("playerid") && a.children(".chat_box").is(":visible")) {
                    b.players.push(a.data("playerid"))
                } else {
                    if (a.data("associationid") && a.children(".chat_box").is(":visible")) {
                        b.associations.push(a.data("associationid"))
                    }
                }
            }
        });
        $.cookie("visibleChats", JSON.stringify(b), {expires: 7})
    },
    showPlayerList: function (d) {
        var c = ogame.chat;
        if ($.inArray(d, c.playerListSelector) === -1) {
            c.playerListSelector.push(d)
        }
        if (c.isLoadingPlayerList === false && c.playerList === null) {
            c.isLoadingPlayerList = true;
            $.ajax({
                url: '/buddies/online',
                type: "GET",
                dataType: "json",
                success: function (response) {
                    function playerItem(player, index, filterChatActive, filterOnline, isStranger) {
                        var statusClass, statusTitle;
                        if (isStranger) {
                            statusClass = 'disallowed';
                            statusTitle = c.loca('STATUS_HIDDEN');
                        } else {
                            statusClass = player.isOnline ? 'online' : 'offline';
                            statusTitle = c.loca(player.isOnline ? 'STATUS_ONLINE' : 'STATUS_OFFLINE');
                        }
                        var li = '<li class="playerlist_item ' + (index % 2 !== 0 ? 'odd' : '') + '" data-playerid="' + player.id + '" data-filterchatactive="' + filterChatActive + '" data-filteronline="' + filterOnline + '">';
                        li += '<p class="playername">';
                        li += '<span class="playerstatus tooltip ' + statusClass + '" data-tooltip-title="' + statusTitle + '"></span>';
                        li += player.username + '</p>';
                        li += '<span class="new_msg_count noMessage" data-playerid="' + player.id + '" data-new-messages="0">0</span>';
                        li += '<span class="chatstatus cs_active fright"></span>';
                        li += '</li>';
                        return li;
                    }

                    // Build the HTML for the player list matching original game structure
                    var html = '<div class="js_playerlist pl_container contentbox fleft">';
                    html += '<h2 class="header"><span class="c-right"></span><span class="c-left"></span>' + c.loca('PLAYER_LIST') + '</h2>';
                    html += '<div class="content">';

                    // Filter checkboxes
                    html += '<form id="playerlistFilters">';
                    html += '<p class="overlay pl_filter_title">' + c.loca('FILTER_BY') + '</p>';
                    html += '<fieldset class="pl_filter_set">';
                    html += '<input id="filteronline" class="fleft" type="checkbox"><label for="filteronline" class="pl_filter">' + c.loca('FILTER_ONLINE') + ' </label>';
                    html += '</fieldset>';
                    html += '<fieldset class="pl_filter_set">';
                    html += '<input id="filterchatactive" class="fleft" type="checkbox"><label for="filterchatactive" class="pl_filter">' + c.loca('FILTER_ACTIVE') + ' </label>';
                    html += '</fieldset>';
                    html += '</form>';

                    // Buddies section
                    html += '<div class="playerlist_box js_accordion" style="overflow: hidden;">';
                    html += '<h3>' + c.loca('BUDDIES') + '</h3>';
                    html += '<div>';
                    html += '<div class="playerlist_top_box"></div>';
                    html += '<div class="scrollContainer"><ul class="playerlist">';

                    if (response.success && response.buddies && response.buddies.length > 0) {
                        response.buddies.forEach(function(buddy, index) {
                            html += playerItem(buddy, index, buddy.hasActiveChat ? 'on' : 'off', buddy.isOnline ? 'on' : 'off');
                        });
                    } else {
                        html += '<li class="no_buddies">' + c.loca('NO_BUDDIES') + '</li>';
                    }

                    html += '</ul></div></div></div>';

                    // Alliance section
                    if (response.alliance) {
                        html += '<div class="playerlist_box js_accordion" style="overflow: hidden;">';
                        html += '<h3>' + c.loca('ALLIANCE') + '</h3>';
                        html += '<div>';
                        html += '<div class="playerlist_top_box">';
                        html += '<div class="playerlist openAssociationChat" data-associationid="' + response.alliance.id + '">';
                        html += '<span title="" class="playerstatus tooltip blank"></span>';
                        html += '<span style="color: orange">' + c.loca('ALLIANCE_CHAT') + '</span>';
                        html += '<span class="new_msg_count noMessage" data-new-messages="0" data-associationid="' + response.alliance.id + '">0</span>';
                        html += '<span class="chatstatus cs_active fright"></span>';
                        html += '</div>';
                        html += '</div>';
                        html += '<div class="scrollContainer"><ul class="playerlist">';

                        if (response.allianceMembers && response.allianceMembers.length > 0) {
                            response.allianceMembers.forEach(function(member, index) {
                                html += playerItem(member, index, member.hasActiveChat ? 'on' : 'off', member.isOnline ? 'on' : 'off');
                            });
                        }

                        html += '</ul></div></div></div>';
                    }

                    // Strangers section
                    if (response.recentPartners && response.recentPartners.length > 0) {
                        html += '<div class="playerlist_box js_accordion" style="overflow: hidden;">';
                        html += '<h3>' + c.loca('STRANGERS') + '</h3>';
                        html += '<div>';
                        html += '<div class="playerlist_top_box"></div>';
                        html += '<div class="scrollContainer"><ul class="playerlist">';

                        response.recentPartners.forEach(function(partner, index) {
                            html += playerItem(partner, index, 'on', 'off', true);
                        });

                        html += '</ul></div></div></div>';
                    }

                    html += '</div>';
                    html += '<div class="footer"><div class="c-right"></div><div class="c-left"></div></div>';
                    html += '</div>';

                    // IMPORTANT: Always set playerList so initChatBar() can be called
                    c.playerList = html;
                    c.playerListAzria = c.azriaRoster(response);
                    c.isLoadingPlayerList = false;
                    c._showPlayerList()
                },
                error: function (f, a, b) {
                    console.error('showPlayerList() - Error loading buddies:', a, b);
                    c.isLoadingPlayerList = false;
                    c.playerList = '<div class="content"><p>' + c.loca('LOAD_ERROR') + '</p></div>';
                    c.playerListAzria = null;
                    c._showPlayerList()
                }
            })
        } else {
            c._showPlayerList()
        }
    },
    _showPlayerList: function () {
        var b = ogame.chat;
        $.each(b.playerListSelector, function (a, d) {
            // La barre prend le panneau Azria quand le theme est pose ; le panneau lateral de la page de chat garde le sien.
            if (b.azriaActive() && b.playerListAzria && $(d).closest('#chatBar').length) {
                $(d).empty().append(b.playerListAzria.clone(true))
            } else {
                $(d).html(b.playerList)
            }
        })
    },
    /**
     * Le panneau des contacts sous le theme Azria, construit en DOM (les noms sont du texte, jamais du HTML), sur les
     * memes accroches que le panneau historique : `.js_playerlist`, `#playerlistFilters` et ses deux cases (lues par
     * `filterPlayerlist`), `.playerlist_item[data-playerid]` avec ses filtres, `.openAssociationChat`, `.new_msg_count`,
     * `.playerstatus`, `.scrollContainer`. Les donnees viennent de `/buddies/online` ; aucun statut n est invente : une
     * pastille verte seulement quand le serveur dit « en ligne », rien pour un inconnu dont le statut n est pas visible.
     */
    azriaRoster: function (response) {
        var c = ogame.chat;
        var buddies = (response.success && response.buddies) ? response.buddies : [];
        var members = response.allianceMembers || [];
        var strangers = response.recentPartners || [];
        var known = buddies.concat(members);
        var online = 0;
        for (var i = 0; i < known.length; i++) {
            if (known[i].isOnline) {
                online++;
            }
        }
        var root = $('<div class="js_playerlist pl_container az-roster"></div>');
        var header = $('<div class="az-header"></div>');
        header.append('<span class="az-symbol" aria-hidden="true">' + c.azriaIcon('radio-tower') + '</span>');
        var title = $('<div class="az-title"></div>').text(c.loca('COMMUNICATIONS'));
        var sub = $('<small></small>');
        if (online > 0) {
            sub.append('<span class="az-status-dot online"></span>');
        }
        sub.append(document.createTextNode(c.loca('CONTACTS_ONLINE_SHORT').replace('#+#', String(online))));
        title.append(sub);
        header.append(title);
        header.append($('<button type="button" class="az-icon az-collapse"></button>').attr('title', c.loca('COLLAPSE_CONTACTS')).attr('aria-label', c.loca('COLLAPSE_CONTACTS')).html(c.azriaIcon('chevron-down')));
        root.append(header);
        // Les filtres : trois boutons qui posent les deux cases historiques (cachees) ; « tous » les decoche.
        var filters = $('<form id="playerlistFilters" class="az-filter"></form>').attr('aria-label', c.loca('FILTER_BY'));
        filters.append('<input id="filteronline" type="checkbox" class="az-hidden" tabindex="-1" aria-hidden="true">');
        filters.append('<input id="filterchatactive" type="checkbox" class="az-hidden" tabindex="-1" aria-hidden="true">');
        $.each([['online', 'FILTER_ONLINE'], ['all', 'FILTER_ALL'], ['active', 'FILTER_ACTIVE']], function (i, def) {
            filters.append($('<button type="button"></button>').attr('data-filter', def[0]).attr('aria-pressed', def[0] === 'all' ? 'true' : 'false').text(c.loca(def[1])));
        });
        root.append(filters);
        var scroll = $('<div class="scrollContainer"></div>');
        function row(player, index, filterChatActive, filterOnline, isStranger) {
            var li = $('<li class="playerlist_item"></li>').addClass(index % 2 !== 0 ? 'odd' : '');
            li.attr('data-playerid', player.id).attr('data-filterchatactive', filterChatActive).attr('data-filteronline', filterOnline);
            li.append($('<span class="az-avatar" aria-hidden="true"></span>').text(c.azriaInitial(player.username)));
            var label = $('<span class="az-label"></span>');
            var name = $('<p class="playername"></p>');
            var statusClass = isStranger ? 'disallowed' : (player.isOnline ? 'online' : 'offline');
            var statusTitle = c.loca(isStranger ? 'STATUS_HIDDEN' : (player.isOnline ? 'STATUS_ONLINE' : 'STATUS_OFFLINE'));
            name.append($('<span class="playerstatus tooltip"></span>').addClass(statusClass).attr('data-tooltip-title', statusTitle));
            name.append(document.createTextNode(player.username));
            label.append(name);
            var small = $('<small></small>');
            if (!isStranger && player.isOnline) {
                small.append('<span class="az-status-dot online"></span>');
            }
            small.append(document.createTextNode(statusTitle));
            label.append(small);
            li.append(label);
            li.append($('<span class="new_msg_count noMessage" data-new-messages="0">0</span>').attr('data-playerid', player.id));
            if (player.hasActiveChat) {
                li.append('<span class="az-active-mark" aria-hidden="true">' + c.azriaIcon('message-square') + '</span>');
            }
            li.append('<span class="chatstatus cs_active fright"></span>');
            return li;
        }
        function category(key) {
            return $('<div class="az-category"></div>').text(c.loca(key));
        }
        if (response.alliance) {
            scroll.append(category('ALLIANCE'));
            var top = $('<div class="playerlist_top_box"></div>');
            var channel = $('<div class="playerlist openAssociationChat az-channel"></div>').attr('data-associationid', response.alliance.id);
            channel.append('<span class="az-avatar az-avatar-alliance" aria-hidden="true">' + c.azriaIcon('shield', 19) + '</span>');
            var clabel = $('<span class="az-label"></span>');
            if (response.alliance.tag) {
                clabel.append($('<span class="az-tag"></span>').text(response.alliance.tag));
            }
            clabel.append($('<span class="az-name"></span>').text(response.alliance.name || c.loca('ALLIANCE_CHAT')));
            clabel.append($('<small></small>').text(c.loca('ALLIANCE_CHANNEL')));
            channel.append(clabel);
            channel.append($('<span class="new_msg_count noMessage" data-new-messages="0">0</span>').attr('data-associationid', response.alliance.id));
            channel.append('<span class="chatstatus cs_active fright"></span>');
            top.append(channel);
            scroll.append(top);
            var mlist = $('<ul class="playerlist"></ul>');
            $.each(members, function (i, m) {
                mlist.append(row(m, i, m.hasActiveChat ? 'on' : 'off', m.isOnline ? 'on' : 'off', false));
            });
            scroll.append(mlist);
        }
        scroll.append(category('BUDDIES'));
        var blist = $('<ul class="playerlist"></ul>');
        if (buddies.length) {
            $.each(buddies, function (i, b) {
                blist.append(row(b, i, b.hasActiveChat ? 'on' : 'off', b.isOnline ? 'on' : 'off', false));
            });
        } else {
            blist.append($('<li class="no_buddies"></li>').text(c.loca('NO_BUDDIES')));
        }
        scroll.append(blist);
        if (strangers.length) {
            scroll.append(category('STRANGERS'));
            var slist = $('<ul class="playerlist"></ul>');
            $.each(strangers, function (i, p) {
                slist.append(row(p, i, 'on', 'off', true));
            });
            scroll.append(slist);
        }
        root.append(scroll);
        var foot = $('<div class="az-roster-foot"></div>');
        foot.append($('<span></span>').text(c.loca('CONTACTS_NETWORK')));
        foot.append($('<span></span>').text(c.loca('ONLINE_RATIO').replace('#online#', String(online)).replace('#total#', String(known.length))));
        root.append(foot);
        return root;
    }
};