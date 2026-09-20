/*
 * Ce que la barre de chat fait des FENETRES de conversation (constats de Keven, 19 septembre 2026, journal §168) :
 *
 * 1. **L en-tete n offre plus « Ouvrir la messagerie ».** Ce bouton envoyait le navigateur vers
 *    `bigChatLink + "&playerId=…"` — soit `/overview&playerId=…` sur ce serveur : une adresse sans page.
 * 2. **Les conversations ouvertes survivent au changement de page.** Le jeu ecrivait deja la liste dans le cookie
 *    `visibleChats`, mais personne ne la relisait : le gabarit repose une liste vide a chaque page.
 *
 * Le module `chat.js` est charge tel quel, avec le **vrai** jQuery du jeu ; `$.ajax` et `$.cookie` sont remplaces par des
 * faux qui retiennent ce qui part et ce qui est memorise.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const JQUERY = new URL('../../resources/js/ingame/jquery-1.12.4.min.js', import.meta.url);
const SOURCE = new URL('../../resources/js/ingame/chat.js', import.meta.url);

/**
 * Une barre de chat au theme Azria, avec le seul onglet que le gabarit pose : les contacts.
 */
function unMonde(cookie, charge) {
    const dom = new JSDOM(
        '<!doctype html><html><body><div id="chatBar" class="azria-chat">'
        + '<ul class="chat_bar_list"><li id="chatBarPlayerList" class="chat_bar_pl_list_item">'
        + '<div class="cb_playerlist_box" style="display: none;"></div></li></ul>'
        + '</div></body></html>',
        { runScripts: 'dangerously', url: 'https://exemple.test/overview' }
    );
    const { window } = dom;

    // jsdom ne met rien en page : `offsetWidth` vaut toujours zero, et le `:visible` de jQuery — dont
    // `updateVisibleState()` se sert pour savoir ce qui est ouvert — serait toujours faux. On rend donc la seule
    // chose que jQuery regarde : une boite a zero quand elle est masquee, non nulle sinon — et pour une fenetre de
    // conversation, sa vraie largeur sous le theme (350 px, comme la feuille la pose), puisque `azriaDeck()` s en
    // sert pour ranger les fenetres cote a cote.
    Object.defineProperty(window.HTMLElement.prototype, 'offsetWidth', {
        get() {
            if (this.style.display === 'none') { return 0; }
            return this.classList.contains('chat_box') ? 350 : 120;
        }
    });
    Object.defineProperty(window.HTMLElement.prototype, 'offsetHeight', {
        get() { return this.style.display === 'none' ? 0 : 24; }
    });

    const jq = window.document.createElement('script');
    jq.textContent = readFileSync(JQUERY, 'utf8');
    window.document.head.appendChild(jq);

    const requetes = [];
    window.$.ajax = function (options) {
        requetes.push({ url: options.url, donnees: options.data });
    };
    // Le greffon de barre de defilement vient du paquet du jeu, pas de chat.js : ici il ne fait rien, et ce n est pas
    // lui qu on eprouve.
    window.$.fn.mCustomScrollbar = function () { return this; };

    const memoire = { visibleChats: cookie };
    window.$.cookie = function (nom, valeur) {
        if (valeur === undefined) {
            return memoire[nom];
        }
        memoire[nom] = valeur;
        return valeur;
    };

    window.ogame = {};
    window.chatUrl = '/ajax/chat';
    window.chatHistoryUrl = '/ajax/chat/history';
    window.playerId = 1;
    window.visibleChats = { players: [], associations: [] };
    // Ce que la page pose desormais elle-meme : l historique des conversations ouvertes, sans requete.
    window.chatRestore = charge || [];
    window.chatLoca = {
        CLOSE_CONVERSATION: 'Fermer la conversation',
        MINIMIZE_CONVERSATION: 'Reduire la conversation',
        OPEN_MESSAGING: 'Ouvrir la messagerie',
        ALLIANCE_CHAT: 'Chat d alliance'
    };
    window.LocalizationStrings = { error: 'Erreur', ok: 'OK' };
    window.errorBoxNotify = function () {};

    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    return { window, requetes, memoire, chat: window.ogame.chat };
}

test('l en-tete d une conversation ferme et reduit, et n offre plus d ouvrir la messagerie', () => {
    const monde = unMonde(undefined);
    monde.chat.data[7] = { playerName: 'Cap James Kirk', playerstatus: 'on', chatItems: {}, chatItemsByDateAsc: [] };

    const fenetre = monde.chat.createChatBox(7);
    const entete = fenetre.find('.chat_box_title');

    assert.equal(entete.find('.icon_close').length, 1, 'Fermer est la.');
    assert.equal(entete.find('.icon_minimize').length, 1, 'Reduire est la.');
    assert.equal(entete.find('.icon_maximize').length, 0, 'Ouvrir la messagerie ne mene nulle part : le bouton n existe plus.');
});

test('les conversations memorisees sont redemandees au chargement de la page', () => {
    const monde = unMonde(JSON.stringify({ chatbar: false, players: [7], associations: [42] }));

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 2, 'Une demande d historique par conversation memorisee.');
    assert.equal(monde.requetes[0].donnees.playerId, 7, 'La conversation privee repart.');
    assert.equal(monde.requetes[0].donnees.mode, 2);
    assert.equal(monde.requetes[0].donnees.updateUnread, 0, 'Rouvrir une fenetre en changeant de page ne marque pas les messages comme lus.');
    assert.equal(monde.requetes[1].donnees.associationId, 42, 'Le chat d alliance aussi.');
    assert.equal(monde.requetes[1].donnees.mode, 4);
    assert.equal(monde.requetes[1].donnees.updateUnread, 0);
    assert.equal(monde.requetes[0].url, '/ajax/chat/history');
});

test('sans memoire, ou avec une memoire illisible, rien ne part', () => {
    assert.equal(unMonde(undefined).chat.restoreOpenChats() || 0, 0);
    const vide = unMonde(undefined);
    vide.chat.restoreOpenChats();
    assert.equal(vide.requetes.length, 0, 'Aucun cookie : aucune demande.');

    const casse = unMonde('{ceci n est pas du JSON');
    casse.chat.restoreOpenChats();
    assert.equal(casse.requetes.length, 0, 'Un cookie illisible est ignore, sans exception.');

    const absurde = unMonde(JSON.stringify({ players: ['abc', -3, null, { partnerId: 0 }], associations: 'pas une liste' }));
    absurde.chat.restoreOpenChats();
    assert.equal(absurde.requetes.length, 0, 'Aucun identifiant entier positif : rien n est invente.');
});

test('une conversation deja ouverte n est pas redemandee, et la memoire est bornee', () => {
    const monde = unMonde(JSON.stringify({ players: [7, 8, 9, 10, 11, 12, 13], associations: [42] }));
    monde.window.$('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7"></li>');

    monde.chat.restoreOpenChats();

    const ids = monde.requetes.map((r) => r.donnees.playerId).filter((id) => id !== undefined);
    assert.ok(!ids.includes(7), 'La conversation deja dans la barre n est pas redemandee.');
    assert.equal(monde.requetes.length, 5, 'Au plus cinq conversations repartent : une page ne lance pas dix requetes.');
});

test('le X ferme une conversation meme quand un style en ligne la tenait ouverte', () => {
    const monde = unMonde(undefined);
    const $ = monde.window.$;
    // `showChat()` et `setVisibilityState()` posent ce style en ligne ; il bat n importe quelle feuille, et sans
    // l effacer l onglet marque « ferme » restait visible — le X semblait ne rien faire (constat de Keven).
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7" style="display: inline;"><div class="chat_box"></div></li>');

    monde.chat.closeChatBox(7, undefined);

    const onglet = $('.chat_bar_list_item[data-playerid="7"]');
    assert.ok(onglet.hasClass('outOfChatbar'), 'L onglet est marque ferme.');
    assert.ok(!onglet.hasClass('open'), 'Il n est plus ouvert.');
    assert.equal(onglet[0].style.display, '', 'Le style en ligne est efface : la feuille peut enfin le cacher.');
    assert.equal(onglet.children('.chat_box')[0].style.display, 'none', 'La fenetre est cachee avec lui.');
});

test('ouvrir une conversation deja ouverte ne la referme pas', () => {
    const monde = unMonde(undefined);
    const $ = monde.window.$;
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7" style="display: inline;"><div class="chat_box" style="display: inline;"></div></li>');

    monde.chat.showChat({ playerId: 7 });

    const onglet = $('.chat_bar_list_item[data-playerid="7"]');
    assert.ok(onglet.hasClass('open'), 'La conversation reste ouverte.');
    assert.notEqual(onglet.children('.chat_box')[0].style.display, 'none', 'Cliquer le nom d un ami deja ouvert ne ferme pas sa fenetre.');
});

test('les fenetres se rangent cote a cote, et non sur leur onglet', () => {
    const monde = unMonde(undefined);
    const $ = monde.window.$;
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7"><div class="chat_box"></div></li>');
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-associationid="42"><div class="chat_box"></div></li>');

    monde.chat.azriaDeck();

    const places = $('.chat_bar_list .chat_box').map(function () { return this.style.getPropertyValue('--az-window-right'); }).get();
    assert.equal(places.length, 2);
    assert.notEqual(places[0], places[1], 'Deux fenetres ouvertes ne se superposent pas.');
    assert.equal(places[0], '8px', 'La premiere se colle au bord droit de la barre.');
    assert.equal(places[1], '365px', 'La seconde se range a sa gauche, d une largeur de fenetre plus l ecart.');
});

test('la page porte l historique : les conversations reviennent sans aucune requete', () => {
    const monde = unMonde(
        JSON.stringify({ players: [7], associations: [] }),
        [{ playerId: 7, playerName: 'Cap James Kirk', playerstatus: 'offline', chatItems: {}, chatItemsByDateAsc: [] }]
    );

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 0, 'Aucune demande d historique : la page l a deja apportee.');
    const onglet = monde.window.$('.chat_bar_list .chat_bar_list_item[data-playerid="7"]');
    assert.equal(onglet.length, 1, 'La conversation est la des le chargement.');
    assert.equal(onglet.find('.chat_box_title .icon_close').length, 1, 'Avec sa fenetre et son bouton de fermeture.');
    assert.equal(monde.chat.data[7].playerName, 'Cap James Kirk', 'Et son historique est en memoire.');
});

test('ce que le jeu memorise est bien ce qu il relit', () => {
    const monde = unMonde(undefined);
    const $ = monde.window.$;
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7"><div class="chat_box" style="display: inline;"></div></li>');
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-associationid="42"><div class="chat_box" style="display: inline;"></div></li>');

    monde.chat.updateVisibleState();

    const releve = JSON.parse(monde.memoire.visibleChats);
    assert.deepEqual(Array.from(releve.players), [7], 'La conversation privee ouverte est memorisee.');
    assert.deepEqual(Array.from(releve.associations), [42], 'Le chat d alliance ouvert aussi.');

    // Et ce relevé, relu par une page neuve, redemande exactement ces deux conversations.
    const page = unMonde(monde.memoire.visibleChats);
    page.chat.restoreOpenChats();
    assert.equal(page.requetes.length, 2, 'La page suivante rouvre ce que la precedente avait ouvert.');
});

test('la barre initialisee deux fois ne dedouble pas ses gestionnaires', () => {
    // Le gabarit appelle `initChatBar` une premiere fois pour poser les conversations des le chargement, puis une
    // seconde quand la liste des contacts est arrivee. Les gestionnaires etant delegues sur `.chat_bar_list`, un
    // `off` vise sur `html` n en retirait aucun : chaque clic partait deux fois, le panneau des contacts s ouvrait
    // puis se refermait, et le bouton paraissait mort.
    const monde = unMonde(undefined);
    const $ = monde.window.$;

    monde.chat.initChatBar(1);
    const apresUn = $._data($('.chat_bar_list')[0], 'events').click.length;
    monde.chat.initChatBar(1);
    const apresDeux = $._data($('.chat_bar_list')[0], 'events').click.length;

    assert.equal(apresDeux, apresUn, 'Le second appel remplace les gestionnaires, il ne les ajoute pas.');
    assert.equal(
        $._data(monde.window, 'events').resize.length,
        1,
        'Le redimensionnement aussi : un seul gestionnaire, quel que soit le nombre d appels.'
    );

    // Le retrait reste chez lui : `initMaximize()` pose son propre gestionnaire sur la meme liste, dans l espace
    // `.chatBar`. Une reinitialisation de la barre ne doit pas l emporter.
    let ailleurs = 0;
    $('.chat_bar_list').on('click.chatBar', '.temoin-ailleurs', function () { ailleurs += 1; });
    monde.chat.initChatBar(1);
    $('.chat_bar_list').append('<li class="temoin-ailleurs"></li>');
    $('.temoin-ailleurs').trigger('click');
    assert.equal(ailleurs, 1, 'Le gestionnaire d un autre module survit a une reinitialisation de la barre.');

    const panneau = $('.cb_playerlist_box');
    assert.equal(panneau.is(':visible'), false, 'Le panneau part masque, comme le gabarit le pose.');
    $('#chatBarPlayerList').trigger('click');
    assert.equal(panneau.is(':visible'), true, 'Un clic ouvre les contacts.');
    $('#chatBarPlayerList').trigger('click');
    assert.equal(panneau.is(':visible'), false, 'Le clic suivant les referme.');
});