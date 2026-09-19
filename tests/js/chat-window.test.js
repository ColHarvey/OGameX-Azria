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
function unMonde(cookie) {
    const dom = new JSDOM(
        '<!doctype html><html><body><div id="chatBar" class="azria-chat">'
        + '<ul class="chat_bar_list"><li id="chatBarPlayerList" class="chat_bar_pl_list_item"></li></ul>'
        + '</div></body></html>',
        { runScripts: 'dangerously', url: 'https://exemple.test/overview' }
    );
    const { window } = dom;

    // jsdom ne met rien en page : `offsetWidth` vaut toujours zero, et le `:visible` de jQuery — dont
    // `updateVisibleState()` se sert pour savoir ce qui est ouvert — serait toujours faux. On rend donc la seule
    // chose que jQuery regarde : une boite a zero quand elle est masquee, non nulle sinon.
    Object.defineProperty(window.HTMLElement.prototype, 'offsetWidth', {
        get() { return this.style.display === 'none' ? 0 : 120; }
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
