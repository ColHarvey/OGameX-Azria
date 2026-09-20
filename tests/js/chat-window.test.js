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
function unMonde(cookie, charge, largeur, adresse) {
    const dom = new JSDOM(
        '<!doctype html><html><body><div id="chatBar" class="azria-chat">'
        + '<ul class="chat_bar_list"><li id="chatBarPlayerList" class="chat_bar_pl_list_item">'
        + '<div class="cb_playerlist_box" style="display: none;"></div></li></ul>'
        + '</div></body></html>',
        { runScripts: 'dangerously', url: adresse || 'https://exemple.test/overview' }
    );
    const { window } = dom;

    // jsdom ne met rien en page : `offsetWidth` vaut toujours zero, et le `:visible` de jQuery — dont
    // `updateVisibleState()` se sert pour savoir ce qui est ouvert — serait toujours faux. On rend donc la seule
    // chose que jQuery regarde : une boite a zero quand elle est masquee, non nulle sinon — et pour une fenetre de
    // conversation, sa vraie largeur sous le theme (350 px, comme la feuille la pose), puisque `azriaDeck()` s en
    // sert pour ranger les fenetres cote a cote.
    // **Le corps de la page a une largeur, sinon le banc joue un autre jeu** : `updateChatBar()` lit
    // `$("body").innerWidth()`, qui vaut zero sans mise en page — la barre partait alors dans la branche
    // « petit ecran », celle qui FERME les autres conversations pour n en garder qu une. Le banc mesurait donc
    // un telephone en croyant mesurer un bureau.
    Object.defineProperty(window.HTMLElement.prototype, 'offsetWidth', {
        get() {
            if (this.style.display === 'none') { return 0; }
            if (this.tagName === 'BODY' || this.tagName === 'HTML') { return largeur || 1280; }
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

    // **Le faux cookie porte la regle du vrai.** Le greffon du jeu lit `document.cookie`, decode, et s arrete
    // au PREMIER nom qui correspond : c est ce `break` qui fait qu une memoire de sous-chemin en masque une
    // autre. Une simple table nom -> valeur serait aveugle aux chemins — donc aveugle au defaut qu on eprouve.
    // jsdom tient un vrai bocal a cookies et respecte les chemins ; on s en sert.
    const memoire = {};
    const options = {};
    window.$.cookie = function (nom, valeur, reglages) {
        if (valeur === undefined) {
            const entrees = window.document.cookie.split('; ');
            for (let i = 0; i < entrees.length; i++) {
                const coupe = entrees[i].indexOf('=');
                if (coupe > 0 && decodeURIComponent(entrees[i].slice(0, coupe)) === nom) {
                    return decodeURIComponent(entrees[i].slice(coupe + 1));
                }
            }
            return undefined;
        }
        memoire[nom] = valeur;
        options[nom] = reglages;
        const morceaux = [encodeURIComponent(nom), '=', encodeURIComponent(String(valeur))];
        if (reglages && typeof reglages.expires === 'number') {
            morceaux.push('; max-age=' + (reglages.expires * 86400));
        }
        if (reglages && reglages.path) {
            morceaux.push('; path=' + reglages.path);
        }
        window.document.cookie = morceaux.join('');
        return valeur;
    };
    if (cookie !== undefined) {
        // La memoire que la page trouve en arrivant, posee a la racine comme le jeu la pose.
        window.document.cookie = 'visibleChats=' + encodeURIComponent(cookie) + '; path=/; max-age=604800';
        memoire.visibleChats = cookie;
    }

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

    return { window, requetes, memoire, options, chat: window.ogame.chat };
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
test('ouvrir une conversation la memorise, quel que soit le chemin', () => {
    // Le defaut de Keven : `updateVisibleState()` ne vivait que dans quatre gestionnaires de clic de la BARRE.
    // Ouvrir depuis le panneau des contacts n en traverse aucun — la memoire retardait donc d un clic, et la
    // derniere conversation ouverte n y entrait jamais.
    const monde = unMonde(undefined);

    monde.chat.absorbChatLog({ playerId: 7, playerName: 'Cap James Kirk', playerstatus: 'offline', chatItems: {}, chatItemsByDateAsc: [] });
    monde.chat.showChat({ playerId: 7 });

    assert.ok(monde.memoire.visibleChats, 'Ouvrir ecrit la memoire, sans attendre un clic ailleurs.');
    const apresJoueur = JSON.parse(monde.memoire.visibleChats);
    assert.deepEqual(Array.from(apresJoueur.players), [7], 'La conversation privee ouverte est retenue.');

    monde.chat.absorbChatLog({ associationId: 42, associationName: 'Les Pirates', playerstatus: 'online', chatItems: {}, chatItemsByDateAsc: [] });
    monde.chat.showChat({ associationId: 42 });

    const apresAlliance = JSON.parse(monde.memoire.visibleChats);
    assert.deepEqual(Array.from(apresAlliance.players), [7], 'La premiere conversation reste retenue.');
    assert.deepEqual(Array.from(apresAlliance.associations), [42], 'Et le canal d alliance, ouvert en dernier, l est aussi — c est lui qui disparaissait.');
});

test('fermer une conversation l oublie, sinon elle revient a la page suivante', () => {
    const monde = unMonde(undefined);
    monde.chat.absorbChatLog({ playerId: 7, playerName: 'Cap James Kirk', playerstatus: 'offline', chatItems: {}, chatItemsByDateAsc: [] });
    monde.chat.showChat({ playerId: 7 });
    assert.deepEqual(Array.from(JSON.parse(monde.memoire.visibleChats).players), [7], 'Premisse : elle est bien ouverte et retenue.');

    monde.chat.closeChatBox(7, 0);

    assert.deepEqual(Array.from(JSON.parse(monde.memoire.visibleChats).players), [], 'Fermee, elle quitte la memoire.');
});

test('la restauration ne piétine pas la memoire qu elle vient de lire', () => {
    // Pendant la restauration les fenetres ne sont pas encore posees a l ecran : lire le DOM a cet instant
    // effacerait justement ce qu on rouvre. Le cookie porte deja la verite — on n y touche pas.
    const memoireDAvant = JSON.stringify({ chatbar: false, players: [7], associations: [42] });
    const monde = unMonde(memoireDAvant, [
        { playerId: 7, playerName: 'Cap James Kirk', playerstatus: 'offline', chatItems: {}, chatItemsByDateAsc: [] },
        { associationId: 42, associationName: 'Les Pirates', playerstatus: 'online', chatItems: {}, chatItemsByDateAsc: [] }
    ]);

    monde.chat.restoreOpenChats();

    const apres = JSON.parse(monde.memoire.visibleChats);
    assert.deepEqual(Array.from(apres.players), [7], 'La conversation privee memorisee est toujours la.');
    assert.deepEqual(Array.from(apres.associations), [42], 'Le canal d alliance aussi.');
    assert.equal(monde.chat.restoringOpenChats, false, 'Et le drapeau est rabaisse : la suite memorise de nouveau.');
});
test('sur petit ecran, la barre n affiche qu une conversation mais n en oublie aucune', () => {
    // Sous le theme, un ecran de moins de 620 px ne porte qu une fenetre : `updateChatBar()` ferme les autres.
    // Si la memoire suivait cet affichage pendant la restauration, elle se reduirait a une seule conversation — et
    // les autres seraient perdues pour de bon, y compris de retour sur un grand ecran. Elle s abstient donc.
    const memoireDAvant = JSON.stringify({ chatbar: false, players: [7], associations: [42] });
    const monde = unMonde(memoireDAvant, [
        { playerId: 7, playerName: 'Cap James Kirk', playerstatus: 'offline', chatItems: {}, chatItemsByDateAsc: [] },
        { associationId: 42, associationName: 'Les Pirates', playerstatus: 'online', chatItems: {}, chatItemsByDateAsc: [] }
    ], 400);

    monde.chat.restoreOpenChats();

    const ouvertes = monde.window.$('.chat_bar_list > .chat_bar_list_item.open').length;
    assert.equal(ouvertes, 1, 'Premisse : sur un ecran etroit, la barre ne garde bien qu une fenetre ouverte.');

    const apres = JSON.parse(monde.memoire.visibleChats);
    assert.deepEqual(Array.from(apres.players), [7], 'La conversation privee reste memorisee, meme masquee.');
    assert.deepEqual(Array.from(apres.associations), [42], 'Et le canal d alliance aussi : l ecran ne decide pas de la memoire.');
});
test('sous le theme, montrer un onglet n ecrase pas son display', () => {
    // Mesure du 20 septembre 2026, contacts retenus au niveau du reseau : tant que la liste des contacts n etait
    // pas arrivee, l onglet etait une boite flex et ses icones etaient centrees sur le nom (ecart 0). Des qu elle
    // arrivait, `setVisibilityState()` posait `style="display: inline"`, l onglet passait en bloc, et les icones
    // se decalaient de 12,5 px. Un style en ligne bat n importe quelle feuille.
    const monde = unMonde(undefined);
    const $ = monde.window.$;
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7"><div class="chat_box"></div></li>');
    monde.window.visibleChats = { chatbar: false, players: [{ partnerId: 7 }], associations: [] };

    monde.chat.setVisibilityState();

    const onglet = $('.chat_bar_list_item[data-playerid="7"]')[0];
    assert.equal(onglet.style.display, '', 'Sous le theme, le style en ligne est efface : la feuille decide.');
    assert.equal(onglet.getAttribute('style') || '', '', 'Et il ne reste aucun reliquat.');
});

test('hors du theme, le comportement historique est garde', () => {
    const monde = unMonde(undefined);
    const $ = monde.window.$;
    // Sans la classe d activation, la barre n est plus la barre Azria.
    $('#chatBar').removeClass('azria-chat');
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7"><div class="chat_box"></div></li>');
    monde.window.visibleChats = { chatbar: false, players: [{ partnerId: 7 }], associations: [] };

    monde.chat.setVisibilityState();

    assert.equal(
        $('.chat_bar_list_item[data-playerid="7"]')[0].style.display,
        'inline',
        'Hors theme, l onglet reprend le display en ligne historique : on ne change pas le jeu d origine.'
    );
});

test('la memoire retient la presence, l ordre et l etat replie', () => {
    const monde = unMonde(undefined);
    const $ = monde.window.$;
    // Trois conversations : deux ouvertes, une reduite (dans la barre, fenetre repliee).
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7"><div class="chat_box" style="display: block;"></div></li>');
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-associationid="42"><div class="chat_box" style="display: block;"></div></li>');
    $('.chat_bar_list').append('<li class="chat_bar_list_item" data-playerid="9"><div class="chat_box" style="display: none;"></div></li>');
    // Et une fermee, qui ne doit rien laisser derriere elle.
    $('.chat_bar_list').append('<li class="chat_bar_list_item outOfChatbar" data-playerid="11"><div class="chat_box" style="display: none;"></div></li>');

    monde.chat.updateVisibleState();

    const memoire = JSON.parse(monde.memoire.visibleChats);
    assert.deepEqual(Array.from(memoire.players), [7, 9], 'La conversation reduite compte comme presente ; la fermee, non.');
    assert.deepEqual(Array.from(memoire.associations), [42]);
    assert.deepEqual(Array.from(memoire.ordre), ['j7', 'a42', 'j9'], 'L ordre de la barre est retenu tel quel.');
    assert.deepEqual(Array.from(memoire.reduits), ['j9'], 'Et seule la reduite est marquee repliee.');
});

test('la memoire vive dit la meme chose que le cookie', () => {
    // `setVisibilityState()` lit la variable `visibleChats`, que personne ne remettait a jour : une conversation
    // ouverte avant l arrivee des contacts n y figurait pas et se faisait fermer des que la liste arrivait.
    const monde = unMonde(undefined);
    const $ = monde.window.$;
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-playerid="7"><div class="chat_box" style="display: block;"></div></li>');
    $('.chat_bar_list').append('<li class="chat_bar_list_item open" data-associationid="42"><div class="chat_box" style="display: block;"></div></li>');

    monde.chat.updateVisibleState();

    const vive = monde.window.visibleChats;
    assert.deepEqual(Array.from(vive.players).map((p) => p.partnerId), [7], 'Les joueurs, sous la forme que le lecteur attend.');
    assert.deepEqual(Array.from(vive.associations), [42], 'Les alliances, sous la leur.');

    // Et la consequence : une seconde passe ne ferme plus rien.
    monde.chat.setVisibilityState();
    assert.equal($('.chat_bar_list_item.outOfChatbar').length, 0, 'Rien n est ferme : la memoire vive connait ces conversations.');
});

test('les conversations restaurees reprennent leur ordre et leur etat', () => {
    // `updateChatBar()` insere chaque onglet juste apres CONTACTS : restaurees dans l ordre, trois conversations
    // ressortaient a l envers. Et une conversation reduite doit revenir reduite, pas ouverte.
    const memoireDAvant = JSON.stringify({
        chatbar: false,
        players: [7, 9],
        associations: [42],
        ordre: ['j7', 'a42', 'j9'],
        reduits: ['j9']
    });
    const monde = unMonde(memoireDAvant, [
        { playerId: 7, playerName: 'Cap James Kirk', playerstatus: 'offline', chatItems: {}, chatItemsByDateAsc: [] },
        { playerId: 9, playerName: 'Legor', playerstatus: 'offline', chatItems: {}, chatItemsByDateAsc: [] },
        { associationId: 42, associationName: 'Les Pirates', playerstatus: 'online', chatItems: {}, chatItemsByDateAsc: [] }
    ]);
    const $ = monde.window.$;

    monde.chat.restoreOpenChats();

    const ordre = Array.from($('.chat_bar_list > li')).map((li) => (
        li.id === 'chatBarPlayerList' ? 'CONTACTS'
            : (li.getAttribute('data-playerid') ? 'j' + li.getAttribute('data-playerid')
                : 'a' + li.getAttribute('data-associationid'))
    ));
    assert.deepEqual(ordre, ['CONTACTS', 'j7', 'a42', 'j9'], 'L ordre memorise est rendu, pas son inverse.');

    const reduite = $('.chat_bar_list_item[data-playerid="9"]');
    assert.equal(reduite.length, 1, 'La conversation reduite est bien dans la barre.');
    assert.equal(reduite.hasClass('open'), false, 'Et elle y est repliee.');
    assert.equal(reduite.hasClass('outOfChatbar'), false, 'Repliee n est pas fermee.');
    assert.equal(reduite.children('.chat_box').is(':visible'), false, 'Sa fenetre reste fermee.');

    const ouverte = $('.chat_bar_list_item[data-playerid="7"]');
    assert.equal(ouverte.hasClass('open'), true, 'Les autres reviennent ouvertes.');
});
/*
 * **La memoire du chat vit a la racine du site** (defaut reproduit le 20 septembre 2026, journal §174).
 *
 * `$.cookie` n ecrit `path=` que si on le lui passe, et sans lui le navigateur retombe sur le REPERTOIRE du
 * document. Toutes les pages du jeu tiennent en un segment — `/resources`, `/overview`, `/lifeforms` — sauf les
 * sous-pages des formes de vie, ou le repertoire vaut `/lifeforms`. Une ecriture faite la creait un SECOND cookie
 * du meme nom, qui masquait l autre sur ces pages : la conversation ouverte disparaissait en arrivant sur
 * `/lifeforms/buildings` et revenait sur `/resources`. L etat n etait pas perdu, il etait masque.
 */
test('la memoire des conversations est ecrite a la racine du site, jamais au repertoire de la page', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');

    monde.chat.updateVisibleState();

    const reglages = monde.options.visibleChats;
    assert.ok(reglages, 'La memoire est ecrite avec des reglages, pas sans.');
    assert.equal(reglages.path, '/', 'Le chemin est nomme, et c est la racine — sinon le navigateur prendrait /lifeforms.');
    assert.equal(reglages.expires, 7, 'La duree ne change pas.');
});

test('la memoire heritee d un sous-chemin est effacee, celle de la racine survit', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    // L etat que portent deja les navigateurs des joueurs : deux cookies du meme nom, a deux chemins.
    document.cookie = 'visibleChats=racine; path=/; max-age=604800';
    document.cookie = 'visibleChats=sous-chemin; path=/lifeforms; max-age=604800';
    const avant = document.cookie.split('; ').filter((c) => c.indexOf('visibleChats=') === 0);
    assert.equal(avant.length, 2, 'Premisse : les deux memoires coexistent sur cette page.');

    monde.chat.oublierLesMemoiresDUnSousChemin();

    const apres = document.cookie.split('; ').filter((c) => c.indexOf('visibleChats=') === 0);
    assert.deepEqual(apres, ['visibleChats=racine'], 'Seule la memoire du sous-chemin part ; celle de la racine reste.');
});

test('sur une page a un seul segment, le nettoyage ne touche a rien', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/resources');
    const document = monde.window.document;

    document.cookie = 'visibleChats=racine; path=/; max-age=604800';
    document.cookie = 'maximizeId=7; path=/; max-age=604800';

    monde.chat.oublierLesMemoiresDUnSousChemin();

    const restants = document.cookie.split('; ').sort();
    assert.deepEqual(restants, ['maximizeId=7', 'visibleChats=racine'],
        'Aucun repertoire ancetre autre que la racine : le geste n efface rien.');
});

/*
 * **Le nettoyage precede la lecture, sinon il ne sert a rien.** Ce temoin ferme la mutation qui commentait
 * l appel dans `restoreOpenChats()` : le correctif etait present, mais arrivait trop tard.
 */
test('sur une sous-page, une memoire masquante n empeche plus la restauration', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    const racine = JSON.stringify({ chatbar: false, players: [7], associations: [], ordre: ['j7'], reduits: [] });
    const masquante = JSON.stringify({ chatbar: false, players: [], associations: [], ordre: [], reduits: [] });
    document.cookie = 'visibleChats=' + encodeURIComponent(racine) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(masquante) + '; path=/lifeforms; max-age=604800';

    // Premisse : sans nettoyage, c est bien la masquante que le greffon rendrait.
    assert.equal(monde.window.$.cookie('visibleChats'), masquante, 'Premisse : la memoire du sous-chemin masque l autre.');

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 1, 'La conversation memorisee a la racine repart malgre la memoire masquante.');
    assert.equal(monde.requetes[0].donnees.playerId, 7, 'Et c est la bonne.');
    assert.equal(monde.requetes[0].donnees.updateUnread, 0, 'Restaurer n est pas lire.');
});

/*
 * **La migration de la memoire heritee** (defaut releve par Keven, 20 septembre 2026).
 *
 * Ma premiere version effacait la memoire du sous-chemin sans la lire. Or elle peut etre la SEULE a porter des
 * conversations — un joueur qui n ouvre une discussion que depuis `/lifeforms/buildings` n ecrit qu elle. Les
 * quatre cas de divergence sont eprouves ici, avec la regle qui les tranche :
 *
 *   racine porteuse           -> la racine gagne, **et ce que portait le sous-chemin est perdu** ;
 *   racine VIDE               -> la racine gagne aussi : une liste vide est une DECLARATION ;
 *   racine absente ou illisible, sous-chemin porteur -> le sous-chemin est adopte ;
 *   sous-chemin non porteur   -> rien a migrer.
 *
 * **Tranche par Keven le 20 septembre 2026** : « une racine valide fait autorite, meme lorsqu elle est vide.
 * Si j ai ferme mes conversations, elles ne doivent pas reapparaitre. » Le sous-chemin n est donc adopte que
 * lorsqu il n y a rien a contredire.
 *
 * **C est une priorite deterministe, pas une garantie de retrouver le dernier etat.** J avais ecrit « on ne
 * perd jamais, on ne ressuscite jamais » : c etait faux dans les deux sens, et Keven l a releve. Ce qui reste
 * est epingle plus bas, nomme pour ce qu il coute.
 */
function memoireDe(joueurs, alliances) {
    return JSON.stringify({
        chatbar: false,
        players: joueurs,
        associations: alliances || [],
        ordre: joueurs.map((i) => 'j' + i),
        reduits: []
    });
}

function cookiesNommes(document, nom) {
    return document.cookie.split('; ').filter((c) => c.indexOf(nom + '=') === 0)
        .map((c) => decodeURIComponent(c.slice(nom.length + 1)));
}

test('le sous-chemin est adopte quand il est la seule memoire a porter des conversations', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    // Le cas que mon premier correctif perdait : rien a la racine, tout dans le sous-chemin.
    const seule = memoireDe([7, 9]);
    document.cookie = 'visibleChats=' + encodeURIComponent(seule) + '; path=/lifeforms; max-age=604800';
    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [seule], 'Premisse : elle est bien la seule.');

    monde.chat.oublierLesMemoiresDUnSousChemin();

    // Elle a ete recopiee a la racine, et n existe plus au sous-chemin.
    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [seule],
        'La memoire du sous-chemin doit avoir ete migree, pas supprimee.');

    // Et la preuve qu elle vit desormais a la racine : elle survit a un effacement du sous-chemin.
    document.cookie = 'visibleChats=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/lifeforms';
    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [seule], 'Elle n a pas ete recopiee a la racine.');
});

test('les conversations migrees sont bien restaurees, et sans marquer de messages lus', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    monde.window.document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([7], [42]))
        + '; path=/lifeforms; max-age=604800';

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 2, 'Les deux conversations de la memoire migree repartent.');
    assert.equal(monde.requetes[0].donnees.playerId, 7);
    assert.equal(monde.requetes[0].donnees.updateUnread, 0, 'Restaurer n est pas lire.');
    assert.equal(monde.requetes[1].donnees.associationId, 42);
    assert.equal(monde.requetes[1].donnees.updateUnread, 0);
});

test('quand la racine porte des conversations, c est elle qui gagne', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    const racine = memoireDe([7]);
    const masquante = memoireDe([9]);
    document.cookie = 'visibleChats=' + encodeURIComponent(racine) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(masquante) + '; path=/lifeforms; max-age=604800';

    monde.chat.oublierLesMemoiresDUnSousChemin();

    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [racine],
        'La racine est ecrite par toutes les pages : elle prime. Ce que portait le sous-chemin est perdu — c est le compromis.');
});

test('une racine VIDE fait autorite : le sous-chemin ne la remplace pas', () => {
    const vide = memoireDe([]);

    // **Verdict inverse depuis la decision de Keven.** Une version precedente adoptait le sous-chemin ici :
    // les conversations reapparaissaient apres une fermeture volontaire. Elles ne doivent pas.
    const tenue = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    tenue.window.document.cookie = 'visibleChats=' + encodeURIComponent(vide) + '; path=/; max-age=604800';
    tenue.window.document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([7])) + '; path=/lifeforms; max-age=604800';
    tenue.chat.oublierLesMemoiresDUnSousChemin();
    assert.deepEqual(cookiesNommes(tenue.window.document, 'visibleChats'), [vide],
        'Une liste vide est une declaration : elle fait autorite, et rien ne reapparait.');

    const rien = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    rien.window.document.cookie = 'visibleChats=' + encodeURIComponent(vide) + '; path=/; max-age=604800';
    rien.window.document.cookie = 'visibleChats=' + encodeURIComponent(vide) + '; path=/lifeforms; max-age=604800';
    rien.chat.oublierLesMemoiresDUnSousChemin();
    assert.deepEqual(cookiesNommes(rien.window.document, 'visibleChats'), [vide],
        'Deux memoires vides : il en reste une, celle de la racine, et rien n a ete invente.');
});

test('un canal d alliance seul compte comme une conversation a migrer', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    // Rien que le canal d alliance : si la lecture ne regardait que `players`, cette memoire paraitrait vide
    // et serait effacee sans migration.
    const alliance = memoireDe([], [42]);
    document.cookie = 'visibleChats=' + encodeURIComponent(alliance) + '; path=/lifeforms; max-age=604800';

    monde.chat.oublierLesMemoiresDUnSousChemin();

    document.cookie = 'visibleChats=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/lifeforms';
    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [alliance],
        'Un canal d alliance est une conversation : il doit etre migre comme les autres.');
});

test('une memoire illisible au sous-chemin ne devient jamais la memoire de la racine', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    document.cookie = 'visibleChats=' + encodeURIComponent('{ceci n est pas du JSON') + '; path=/lifeforms; max-age=604800';

    monde.chat.oublierLesMemoiresDUnSousChemin();

    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [],
        'Une memoire illisible ne porte aucune conversation : elle part, et rien ne la remplace.');
});

/*
 * **Le compromis de la migration, cas par cas.**
 *
 * Keven, 20 septembre 2026 : « retire "on ne perd jamais, on ne ressuscite jamais" ». Il a raison — la regle est
 * une **priorite deterministe**, pas une garantie. Les trois temoins ci-dessous epinglent exactement ce qu elle
 * coute, pour que personne ne redecouvre le compromis en production et pour qu un changement de politique fasse
 * tomber un essai qui le nomme.
 */
test('COMPROMIS, cas 1 : deux conversations differentes — celle du sous-chemin est PERDUE', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    const racine = memoireDe([7]);
    const ailleurs = memoireDe([9]);
    document.cookie = 'visibleChats=' + encodeURIComponent(racine) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(ailleurs) + '; path=/lifeforms; max-age=604800';

    monde.chat.oublierLesMemoiresDUnSousChemin();

    // Ce n est PAS une fusion : la conversation 9 disparait, et c est assume.
    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [racine],
        'La racine prime ; la conversation qui n existait que dans le sous-chemin est perdue.');
    assert.equal(monde.chat.etatDeLaMemoire(racine), 'porteuse');
});

test('COMPROMIS, cas 2 : fermeture sur le sous-chemin, racine encore porteuse — la fermeture est OUBLIEE', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    // Le joueur avait j7 et j9 ouvertes, puis a ferme j9 depuis une sous-page : le sous-chemin ne porte plus que j7.
    const racine = memoireDe([7, 9]);
    const apresFermeture = memoireDe([7]);
    document.cookie = 'visibleChats=' + encodeURIComponent(racine) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(apresFermeture) + '; path=/lifeforms; max-age=604800';

    monde.chat.oublierLesMemoiresDUnSousChemin();

    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [racine],
        'La racine prime : la fermeture faite sur la sous-page est oubliee, et j9 reapparaitra une fois.');
});

test('DECISION : racine VOLONTAIREMENT vide — rien ne reapparait', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;

    // Une racine vide n est pas une memoire manquante : c est une **declaration**, « rien n est ouvert ».
    // Keven a tranche le 20 septembre 2026 : elle fait autorite. Une fermeture volontaire ne se defait pas.
    const videVolontaire = memoireDe([]);
    const sousChemin = memoireDe([7]);
    document.cookie = 'visibleChats=' + encodeURIComponent(videVolontaire) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(sousChemin) + '; path=/lifeforms; max-age=604800';

    assert.equal(monde.chat.etatDeLaMemoire(videVolontaire), 'vide',
        'Une liste vide se distingue d une absence : elle est lisible et dit explicitement « rien d ouvert ».');

    monde.chat.oublierLesMemoiresDUnSousChemin();

    assert.deepEqual(cookiesNommes(document, 'visibleChats'), [videVolontaire],
        'La racine vide fait autorite : les conversations fermees ne reapparaissent pas.');
});

test('les quatre etats d une memoire sont distingues, et deux seulement cedent la place', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const etat = (brut) => monde.chat.etatDeLaMemoire(brut);

    assert.equal(etat(undefined), 'absente', 'Cookie absent.');
    assert.equal(etat(null), 'absente', 'Cookie nul.');
    assert.equal(etat(''), 'absente', 'Cookie vide de contenu.');
    assert.equal(etat('{ceci n est pas du JSON'), 'illisible', 'Cookie abime.');
    assert.equal(etat('"une chaine"'), 'illisible', 'JSON valide mais pas un objet.');
    assert.equal(etat(memoireDe([])), 'vide', 'Listes vides : une declaration, pas une absence.');
    assert.equal(etat(JSON.stringify({chatbar: false})), 'vide', 'Listes manquantes : rien d ouvert.');
    assert.equal(etat(memoireDe([7])), 'porteuse', 'Une conversation privee.');
    assert.equal(etat(memoireDe([], [42])), 'porteuse', 'Un canal d alliance compte autant.');
});

test('DECISION : seules une racine absente ou illisible cedent la place', () => {
    const sousChemin = memoireDe([7]);

    // 1. Racine absente : il n y a rien a contredire, le sous-chemin est adopte.
    const absente = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    absente.window.document.cookie = 'visibleChats=' + encodeURIComponent(sousChemin) + '; path=/lifeforms; max-age=604800';
    absente.chat.oublierLesMemoiresDUnSousChemin();
    absente.window.document.cookie = 'visibleChats=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/lifeforms';
    assert.deepEqual(cookiesNommes(absente.window.document, 'visibleChats'), [sousChemin],
        'Racine absente : le sous-chemin est adopte.');

    // 2. Racine illisible : une memoire abimee ne contredit rien non plus.
    const abimee = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    abimee.window.document.cookie = 'visibleChats=' + encodeURIComponent('{abime') + '; path=/; max-age=604800';
    abimee.window.document.cookie = 'visibleChats=' + encodeURIComponent(sousChemin) + '; path=/lifeforms; max-age=604800';
    assert.equal(abimee.chat.etatDeLaMemoire('{abime'), 'illisible', 'Premisse : la racine est bien illisible.');
    abimee.chat.oublierLesMemoiresDUnSousChemin();
    abimee.window.document.cookie = 'visibleChats=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/lifeforms';
    assert.deepEqual(cookiesNommes(abimee.window.document, 'visibleChats'), [sousChemin],
        'Racine illisible : le sous-chemin est adopte, et il remplace la memoire abimee.');
});

function aLog(joueur) {
    return {
        playerId: joueur,
        playerName: 'Joueur ' + joueur,
        playerstatus: 'on',
        chatItems: {},
        chatItemsByDateAsc: []
    };
}

/*
 * **Le serveur lit le cookie AVANT le script : sa charge utile peut etre perimee.**
 *
 * Mesure au navigateur, 20 septembre 2026 : dans les cas ou la racine gagne, les cookies etaient corrects mais la
 * PREMIERE page affichait encore les conversations du sous-chemin — le serveur avait construit `chatRestore` a
 * partir de la memoire masquante, avant tout nettoyage. Une conversation fermee reapparaissait donc pour une page,
 * ce que la decision de Keven interdit.
 *
 * `oublierLesMemoiresDUnSousChemin()` rend desormais si la page est encore digne de foi, et `restoreOpenChats()`
 * ignore la charge utile quand elle ne l est pas.
 */
test('la charge utile de la page est PERIMEE quand la racine gagne : elle est ignoree', () => {
    // La page porte j1 — ce que le serveur a lu du cookie masquant — alors que la racine dit j3.
    const monde = unMonde(undefined, [aLog(1)], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([3])) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([1])) + '; path=/lifeforms; max-age=604800';

    monde.chat.restoreOpenChats();

    // La charge utile (j1) est ignoree, et c est j3 — la racine — qui est redemande.
    assert.equal(monde.requetes.length, 1, 'La reprise doit passer par le cookie de la racine, pas par la page.');
    assert.equal(monde.requetes[0].donnees.playerId, 3, 'C est la conversation de la racine qui repart.');
    assert.equal(monde.requetes[0].donnees.updateUnread, 0, 'Restaurer n est pas lire.');
    assert.equal(monde.chat.data[1], undefined, 'La conversation perimee n a pas ete absorbee.');
});

test('une racine VIDE ignore aussi la charge utile : rien ne reapparait, pas meme pour une page', () => {
    const monde = unMonde(undefined, [aLog(1)], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([])) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([1])) + '; path=/lifeforms; max-age=604800';

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 0, 'Une racine vide fait autorite : rien n est redemande.');
    assert.equal(monde.window.$('.chat_bar_list > li[data-playerid]').length, 0, 'Et rien n est pose a l ecran.');
});

test('sans memoire masquante, la charge utile de la page reste employee', () => {
    // Premisse du couple precedent : sans cookie de sous-chemin, la page sert toujours, et sans aucune requete.
    const monde = unMonde(undefined, [aLog(1)], 1280, 'https://exemple.test/resources');
    monde.window.document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([1])) + '; path=/; max-age=604800';

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 0, 'La page porte deja l historique : aucune requete.');
    assert.equal(monde.window.$('.chat_bar_list > li[data-playerid="1"]').length, 1, 'Et la conversation est posee.');
});

test('sous-chemin VIDE et racine porteuse : la page arrive vide, mais la racine est restauree', () => {
    // Le serveur a lu le cookie du sous-chemin — vide — donc `chatRestore` est vide. Sans le drapeau de
    // peremption, la restauration s arreterait la et la racine serait ignoree pour cette page.
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([3])) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([])) + '; path=/lifeforms; max-age=604800';

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 1, 'La conversation de la racine doit etre redemandee.');
    assert.equal(monde.requetes[0].donnees.playerId, 3);
    assert.equal(monde.requetes[0].donnees.updateUnread, 0, 'Restaurer n est pas lire.');
});

test('sous-chemin ILLISIBLE et racine porteuse : la racine est restauree elle aussi', () => {
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([3])) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent('{abime') + '; path=/lifeforms; max-age=604800';

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 1, 'Une memoire de sous-chemin abimee ne doit pas effacer la racine.');
    assert.equal(monde.requetes[0].donnees.playerId, 3);
});

test('sous-chemin vide ET racine vide : rien a restaurer, et aucune requete inutile', () => {
    // Premisse du couple precedent : quand les deux disent « rien d ouvert », la page vide est la bonne reponse.
    const monde = unMonde(undefined, [], 1280, 'https://exemple.test/lifeforms/buildings');
    const document = monde.window.document;
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([])) + '; path=/; max-age=604800';
    document.cookie = 'visibleChats=' + encodeURIComponent(memoireDe([])) + '; path=/lifeforms; max-age=604800';

    monde.chat.restoreOpenChats();

    assert.equal(monde.requetes.length, 0, 'Deux memoires vides : rien ne doit partir sur le reseau.');
});
