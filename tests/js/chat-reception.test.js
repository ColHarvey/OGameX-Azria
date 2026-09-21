/*
 * Ce que la barre de chat fait d un message qui ARRIVE (plan des notifications valide par Keven, 21 septembre 2026) :
 *
 * 1. **Rien ne s ouvre.** Une conversation fermee reste fermee — la pastille et le bandeau signalent, le clic ouvre.
 *    Le code herite chargeait l historique et `addChatItem` creait la fenetre ; un comportement historique ne
 *    remplace pas cette decision.
 * 2. **Une conversation reduite reste reduite**, et le message rejoint sa liste, cachee ; **une conversation
 *    ouverte reste ouverte** et recoit le message.
 * 3. **Le dedoublonnage porte sur l identifiant reel du message.** L ancienne condition comparait la date brute de
 *    l evenement au HTML formate de la derniere date : jamais egaux, donc un message deja rendu par l historique
 *    etait ajoute une seconde fois — vu a la capture. Et elle effacait un second message distinct de meme texte et
 *    meme date. Les deux ordres d arrivee sont eprouves.
 *
 * `chat.js` est charge tel quel avec le vrai jQuery ; `$.ajax` est un faux qui retient tout, et l essai verifie
 * qu aucun historique n est demande a la reception.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const JQUERY = new URL('../../resources/js/ingame/jquery-1.12.4.min.js', import.meta.url);
const SOURCE = new URL('../../resources/js/ingame/chat.js', import.meta.url);

function unMonde() {
    const dom = new JSDOM(
        '<!doctype html><html><body><div id="chatBar" class="azria-chat">'
        + '<ul class="chat_bar_list"><li id="chatBarPlayerList" class="chat_bar_pl_list_item"><div class="cb_playerlist_box" style="display: none;"></div></li></ul>'
        + '</div></body></html>',
        { runScripts: 'dangerously', url: 'https://exemple.test/overview' }
    );
    const { window } = dom;
    Object.defineProperty(window.HTMLElement.prototype, 'offsetWidth', {
        get() { if (this.style.display === 'none') { return 0; } return this.tagName === 'BODY' || this.tagName === 'HTML' ? 1280 : (this.classList.contains('chat_box') ? 350 : 120); }
    });
    Object.defineProperty(window.HTMLElement.prototype, 'offsetHeight', { get() { return this.style.display === 'none' ? 0 : 24; } });

    const jq = window.document.createElement('script');
    jq.textContent = readFileSync(JQUERY, 'utf8');
    window.document.head.appendChild(jq);

    const requetes = [];
    window.$.ajax = function (o) { requetes.push({ url: o.url, donnees: o.data }); };
    window.$.fn.mCustomScrollbar = function () { return this; };
    window.$.cookie = function () { return undefined; };

    window.ogame = {};
    window.chatUrl = '/ajax/chat';
    window.chatHistoryUrl = '/ajax/chat/history';
    window.playerId = 1;
    window.playerName = 'Moi';
    window.visibleChats = { players: [], associations: [] };
    window.chatRestore = [];
    window.chatLoca = { CLOSE_CONVERSATION: 'Fermer', MINIMIZE_CONVERSATION: 'Reduire', ALLIANCE_CHAT: 'Chat d alliance', SEND: 'Envoyer' };
    window.LocalizationStrings = { error: 'Erreur', ok: 'OK' };
    window.errorBoxNotify = function () {};
    window.getFormatedDate = function (ms, format) { return String(ms); };

    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    const chat = window.ogame.chat;
    chat.playerId = 1;
    chat.data = { association: {} };
    chat.playernames = {};

    // Une fenetre pour le joueur 7, avec un historique deja rendu (les identifiants donnes).
    function uneFenetre(ids, ouverte) {
        chat.data[7] = { playerName: 'Kirk', playerstatus: 'on', chatItems: {}, chatItemsByDateAsc: [] };
        ids.forEach((id, i) => {
            chat.data[7].chatItems[id] = { chatID: id, playerName: 'Kirk', altClass: '', newClass: '', chatContent: 'salut ' + i, date: 1000 + i };
            chat.data[7].chatItemsByDateAsc.push(id);
        });
        const li = chat.createChatBarContainer(7);
        window.$('.chat_bar_list').append(li);
        if (!ouverte) {
            li.removeClass('open');
            li.children('.chat_box').hide();
        }
        return li;
    }
    const message = (id, texte, date) => ({ id, senderId: 7, senderName: 'Kirk', text: texte, date: date || 5 });
    const rendus = (li) => li.find('.chat_msg').map(function () { return window.$(this).attr('data-chat-id'); }).get();

    return { window, $: window.$, chat, requetes, uneFenetre, message, rendus };
}

test('a la reception, une conversation fermee reste fermee : aucune fenetre creee, aucun historique demande', () => {
    const m = unMonde();
    m.chat.messageReceived(m.message(41, 'coucou'));

    assert.equal(m.$('.chat_bar_list_item[data-playerid="7"]').length, 0, 'Aucun onglet n est apparu.');
    assert.equal(m.requetes.length, 0, 'Aucune requete d historique n est partie : le message vit au serveur.');
    assert.equal(m.chat.playernames[7], 'Kirk', 'Le nom de l expediteur est retenu pour le bandeau.');
});

test('une conversation reduite reste reduite et recoit le message dans sa liste cachee', () => {
    const m = unMonde();
    const li = m.uneFenetre([10, 11], false);
    assert.equal(li.hasClass('open'), false);

    m.chat.messageReceived(m.message(12, 'nouveau'));

    assert.equal(li.hasClass('open'), false, 'Toujours reduite.');
    assert.equal(li.children('.chat_box').css('display'), 'none', 'Toujours cachee.');
    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(li))), ['10', '11', '12'], 'Le message est dans la liste, pret pour la reouverture.');
});

test('une conversation ouverte reste ouverte et recoit le message', () => {
    const m = unMonde();
    const li = m.uneFenetre([10], true);

    m.chat.messageReceived(m.message(11, 'nouveau'));

    assert.equal(li.hasClass('open'), true);
    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(li))), ['10', '11']);
});

test('historique puis direct : un message deja rendu par l historique n est pas ajoute une seconde fois', () => {
    const m = unMonde();
    const li = m.uneFenetre([10, 11], true);

    m.chat.messageReceived(m.message(11, 'salut 1', 1001));
    m.chat.messageReceived(m.message(11, 'salut 1', 1001));

    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(li))), ['10', '11'], 'L identifiant 11 est rendu une fois, quel que soit le nombre de rejeux.');
});

test('direct puis historique : le message recu en direct n est pas re-rendu par l historique qui le porte', () => {
    const m = unMonde();
    const li = m.uneFenetre([10], true);
    m.chat.messageReceived(m.message(11, 'en direct'));
    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(li))), ['10', '11']);

    // L historique arrive ensuite avec 11 dedans, et la fenetre est (re)montree : elle existe deja, `showChat` ne
    // la reconstruit pas — et une nouvelle fenetre montee depuis l historique porte 11 une seule fois.
    m.chat.absorbChatLog({ playerId: 7, playerName: 'Kirk', playerstatus: 'on',
        chatItems: { 10: { chatID: 10, playerName: 'Kirk', altClass: '', newClass: '', chatContent: 'salut 0', date: 1000 },
            11: { chatID: 11, playerName: 'Kirk', altClass: '', newClass: '', chatContent: 'en direct', date: 5 } },
        chatItemsByDateAsc: [10, 11] });
    m.chat.showChat({ playerId: 7 });
    assert.equal(m.$('.chat_bar_list_item[data-playerid="7"]').length, 1, 'Une seule fenetre.');
    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(m.$('.chat_bar_list_item[data-playerid="7"]')))), ['10', '11']);

    // Et si la fenetre est montee a neuf depuis cet historique : 11 une fois.
    m.$('.chat_bar_list_item[data-playerid="7"]').remove();
    const neuve = m.chat.createChatBarContainer(7);
    m.$('.chat_bar_list').append(neuve);
    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(neuve))), ['10', '11']);
});

test('le premier message d une conversation vide s affiche : rien a comparer ne veut pas dire rien a rendre', () => {
    const m = unMonde();
    const li = m.uneFenetre([], true);
    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(li))), [], 'La fenetre est vide avant la reception.');

    m.chat.messageReceived(m.message(41, 'premier'));

    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(li))), ['41'], 'La garde d origine (`s !== null && ...`) effacait ce message ; celle-ci ne compare qu un identifiant.');
    assert.equal(li.find('.chat_msg[data-chat-id="41"] .msg_content').text(), 'premier');
});

test('deux messages distincts de meme texte et meme date sont tous les deux rendus', () => {
    const m = unMonde();
    const li = m.uneFenetre([10], true);

    m.chat.messageReceived(m.message(11, 'ok', 7));
    m.chat.messageReceived(m.message(12, 'ok', 7));

    assert.deepEqual(JSON.parse(JSON.stringify(m.rendus(li))), ['10', '11', '12'], 'Deux identifiants, deux messages — l ancienne condition effacait le second.');
});
