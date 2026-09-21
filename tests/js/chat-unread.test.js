/*
 * Le protocole des non-lus, eprouve sans serveur : une seule lecture en vol, une actualisation due qui ne se
 * perd pas, la suspension jusqu a la fin de TOUS les marquages, un delai de reprise borne, l epoque qui ecarte
 * une photographie ancienne avant tout effet — et le total qui vient du serveur, jamais d une somme de badges.
 *
 * Le module `chat-unread.js` est charge tel quel, avec le vrai jQuery du jeu ; `$.ajax` est remplace par un faux
 * qui RETIENT chaque requete pour que l essai y reponde lui-meme, dans l ordre qu il choisit. Les minuteurs de la
 * page sont remplaces par une horloge que l essai fait avancer.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const JQUERY = new URL('../../resources/js/ingame/jquery-1.12.4.min.js', import.meta.url);
const SOURCE = new URL('../../resources/js/ingame/chat-unread.js', import.meta.url);

/**
 * Une barre de chat au theme Azria, avec Contacts, le lien de chat du haut et DEUX badges pour le meme joueur —
 * le contact ami et membre de l alliance, celui que la somme du DOM comptait deux fois.
 */
function unMonde(options) {
    options = options || {};
    const dom = new JSDOM(
        '<!doctype html><html><body>'
        + '<a class="comm_menu chat" href="/chat" title="x"><span class="new_msg_count totalChatMessages noMessage" data-new-messages="0">0</span></a>'
        + '<div id="chatBar" class="azria-chat"><ul class="chat_bar_list">'
        + '<li id="chatBarPlayerList" class="chat_bar_pl_list_item"><div class="cb_playerlist_box">'
        + '<ul class="playerlist"><li class="playerlist_item" data-playerid="1"><span class="new_msg_count noMessage" data-playerid="1" data-new-messages="0">0</span></li>'
        + '<li class="playerlist_item" data-playerid="1"><span class="new_msg_count noMessage" data-playerid="1" data-new-messages="0">0</span></li></ul>'
        + '</div><span class="onlineCount"><span class="az-count">3</span></span></li>'
        + '</ul></div></body></html>',
        { runScripts: 'dangerously', url: 'https://exemple.test/overview' }
    );
    const { window } = dom;

    // La visibilite de l onglet est un fait de l essai, pas de jsdom.
    const etat = { visibilite: 'visible' };
    Object.defineProperty(window.document, 'visibilityState', { get: () => etat.visibilite, configurable: true });

    // L horloge : les minuteurs de la page sont retenus, l essai les fait avancer.
    const horloge = { maintenant: 0, minuteurs: [], suivant: 1 };
    window.setTimeout = function (fn, delai) {
        const id = horloge.suivant++;
        horloge.minuteurs.push({ id, echeance: horloge.maintenant + (delai || 0), fn, periode: null });
        return id;
    };
    window.setInterval = function (fn, delai) {
        const id = horloge.suivant++;
        horloge.minuteurs.push({ id, echeance: horloge.maintenant + delai, fn, periode: delai });
        return id;
    };
    window.clearTimeout = window.clearInterval = function (id) {
        horloge.minuteurs = horloge.minuteurs.filter(m => m.id !== id);
    };
    function avancer(ms) {
        const cible = horloge.maintenant + ms;
        for (;;) {
            const prets = horloge.minuteurs.filter(m => m.echeance <= cible).sort((a, b) => a.echeance - b.echeance);
            if (!prets.length) { break; }
            const m = prets[0];
            horloge.maintenant = m.echeance;
            if (m.periode === null) {
                horloge.minuteurs = horloge.minuteurs.filter(x => x.id !== m.id);
            } else {
                m.echeance += m.periode;
            }
            m.fn();
        }
        horloge.maintenant = cible;
    }

    const jq = window.document.createElement('script');
    jq.textContent = readFileSync(JQUERY, 'utf8');
    window.document.head.appendChild(jq);

    // Le faux `$.ajax` retient tout ; l essai repond.
    const requetes = [];
    window.$.ajax = function (o) {
        requetes.push({ url: o.url, type: o.type, donnees: o.data, reussir: o.success, echouer: o.error });
    };

    // Ce que le navigateur n a pas dans jsdom : les observateurs, et le verrou.
    const observes = [];
    window.IntersectionObserver = class {
        constructor(cb) { this.cb = cb; }
        observe(el) { observes.push({ el, cb: this.cb }); }
    };
    window.MutationObserver = class { observe() {} };
    const verrou = { file: [], tenu: false, prises: 0 };
    window.navigator.locks = {
        request(nom, opts, cb) {
            return new Promise((resoudre) => {
                verrou.file.push(async () => { verrou.prises++; const r = await cb(); resoudre(r); });
                if (!verrou.tenu) { verrou.suivant(); }
            });
        }
    };
    verrou.suivant = async function () {
        const t = verrou.file.shift();
        if (!t) { verrou.tenu = false; return; }
        verrou.tenu = true;
        await t();
        verrou.suivant();
    };

    window.ogame = { chat: { ouvertures: [], loadChatLogWithPlayer(id) { this.ouvertures.push('p' + id); }, loadChatLogWithAssociation(id) { this.ouvertures.push('a' + id); } } };
    window.chatLoca = { UNREAD_ONE: '1 message non lu', UNREAD_MANY: '#+# messages non lus', TOAST_SENT: 'vous a envoye un message', TOAST_CLOSE: 'Fermer', SOUND_ON: 'son : on', SOUND_OFF: 'son : off' };

    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    const u = window.ogame.chatUnread;
    if (options.sonne !== undefined) { u.jouerLeSon = function () { options.sonne.push('son'); return Promise.resolve(); }; }
    u.init({ urls: { snapshot: '/ajax/chat/unread', seen: '/ajax/chat/seen' }, playerId: 9, allianceId: 7 });

    const lectures = () => requetes.filter(r => r.url === '/ajax/chat/unread');
    const marquages = () => requetes.filter(r => r.url === '/ajax/chat/seen');
    const $ = window.$;
    return { window, $, u, etat, avancer, requetes, lectures, marquages, observes, verrou,
        pastille: () => $('#chatBarPlayerList .az-unread-pip'),
        haut: () => $('a.comm_menu.chat .new_msg_count') };
}

const photo = (total, conversations) => ({ total, conversations: conversations || [] });
// Ce qui sort de la page vit dans le royaume de jsdom : ses tableaux n ont pas le `Array.prototype` de Node, et
// `deepStrictEqual` les tient pour differents a valeurs egales. On compare des valeurs, pas des prototypes.
const nu = (x) => JSON.parse(JSON.stringify(x));

// ------------------------------------------------------------------ une seule lecture en vol

test('au demarrage, une lecture part ; une seconde demande pendant son vol est due, pas envoyee', () => {
    const m = unMonde();
    assert.equal(m.lectures().length, 1, 'Une lecture au chargement.');

    m.u.demanderLecture();
    m.u.demanderLecture();
    assert.equal(m.lectures().length, 1, 'Aucune seconde requete pendant le vol : l actualisation est due.');
    assert.equal(m.u.actualisationDue, true);

    m.lectures()[0].reussir(photo(2, [{ kind: 'direct', playerId: 1, unread: 2 }]));
    assert.equal(m.lectures().length, 2, 'La fin de la lecture honore l actualisation due : exactement une lecture de plus.');
    assert.equal(m.u.actualisationDue, false);
});

test('une photographie hors epoque est abandonnee avant tout effet, et l actualisation reste due', () => {
    const m = unMonde();
    const ancienne = m.lectures()[0];

    // Un message arrive pendant le vol : l epoque avance.
    m.u.messageRecu({ id: 50, senderId: 2, senderName: 'Kirk', text: 'x', date: 1 });
    assert.equal(m.lectures().length, 1, 'Le message pose une actualisation due ; la lecture en vol suffit pour l instant.');

    ancienne.reussir(photo(5, [{ kind: 'direct', playerId: 2, unread: 5 }]));
    assert.equal(m.haut().text(), '0', 'La photographie ancienne n a touche ni le badge...');
    assert.equal(m.pastille().attr('hidden') !== undefined, true, '...ni la pastille.');
    assert.equal(m.lectures().length, 2, 'Et une lecture fraiche est partie aussitot : rien n est perdu.');

    m.lectures()[1].reussir(photo(1, [{ kind: 'direct', playerId: 2, unread: 1 }]));
    assert.equal(m.haut().text(), '1');
    assert.equal(m.pastille().find('.az-unread-count').text(), '1');
});

test('le contre-exemple de Keven : lire 12 puis 10 — deux photographies ne volent jamais ensemble, et l ancienne ne retablit rien', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(2, [{ kind: 'direct', playerId: 2, unread: 2 }]));
    assert.equal(m.haut().text(), '2');

    // Je lis 12 : le marquage aboutit, sa photographie P2 part.
    m.u.marquer({ playerId: 2, seenIds: [12] });
    m.marquages()[0].reussir({ ok: true });
    const p2 = m.lectures()[1];
    assert.ok(p2, 'Le marquage abouti a demande sa photographie.');

    // Je lis 10 pendant que P2 vole : le marquage aboutit, mais AUCUNE seconde photographie ne part —
    // une seule lecture en vol. L actualisation est due.
    m.u.marquer({ playerId: 2, seenIds: [10] });
    m.marquages()[1].reussir({ ok: true });
    assert.equal(m.lectures().length, 2, 'Deux photographies ne volent jamais dans la meme epoque : la course n existe pas.');
    assert.equal(m.u.actualisationDue, true);

    // P2 arrive : elle repond a une epoque passee (le second marquage l a fait avancer). Ecartee avant tout
    // effet, et la lecture due part aussitot.
    p2.reussir(photo(1, [{ kind: 'direct', playerId: 2, unread: 1 }]));
    assert.equal(m.haut().text(), '2', 'La photographie ancienne n a rien ecrit.');
    const p4 = m.lectures()[2];
    assert.ok(p4, 'La lecture due est partie a la fin de l ancienne.');

    p4.reussir(photo(0, []));
    assert.equal(m.haut().text(), '0', 'La verite du serveur, apres les deux lectures : aucun non-lu retabli.');
});

// ------------------------------------------------------------------ la suspension pendant les marquages

test('deux marquages en vol : les lectures restent suspendues jusqu a la fin des DEUX', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));
    assert.equal(m.lectures().length, 1);

    m.u.marquer({ playerId: 2, seenIds: [1] });
    m.u.marquer({ associationId: 7, seenUpToId: 3 });
    m.u.demanderLecture();
    assert.equal(m.lectures().length, 1, 'Suspendu : rien ne part.');

    m.marquages()[0].reussir({ ok: true });
    assert.equal(m.lectures().length, 1, 'La premiere reponse ne leve pas seule la suspension.');

    m.marquages()[1].echouer();
    assert.equal(m.lectures().length, 2, 'A la fin du dernier — succes ou echec — exactement une lecture part.');
});

// ------------------------------------------------------------------ un echec ne boucle pas

test('des echecs repetes ne provoquent pas de boucle : le delai de reprise double et reste borne', () => {
    const m = unMonde();
    m.lectures()[0].echouer();
    assert.equal(m.lectures().length, 1, 'Rien ne repart aussitot.');
    m.avancer(1999);
    assert.equal(m.lectures().length, 1);
    m.avancer(1);
    assert.equal(m.lectures().length, 2, 'Premiere reprise a 2 s.');

    m.lectures()[1].echouer();
    m.avancer(3999);
    assert.equal(m.lectures().length, 2);
    m.avancer(1);
    assert.equal(m.lectures().length, 3, 'Deuxieme reprise a 4 s.');

    for (let i = 0; i < 8; i++) { m.lectures()[m.lectures().length - 1].echouer(); m.avancer(60000); }
    const avant = m.lectures().length;
    m.lectures()[avant - 1].echouer();
    m.avancer(59999);
    assert.equal(m.lectures().length, avant, 'Le delai est borne a 60 s : pas avant.');
    m.avancer(1);
    assert.equal(m.lectures().length, avant + 1);

    m.lectures()[avant].reussir(photo(0));
    assert.equal(m.u.echecsConsecutifs, 0, 'Un succes remet le delai a zero.');
});

// ------------------------------------------------------------------ le total vient du serveur

test('un contact present dans deux listes compte une fois : le total n est jamais somme sur les badges', () => {
    const m = unMonde();
    assert.equal(m.$('#chatBarPlayerList .new_msg_count[data-playerid="1"]').length, 2, 'Le monde a bien deux badges pour le joueur 1.');

    m.lectures()[0].reussir(photo(1, [{ kind: 'direct', playerId: 1, unread: 1 }]));

    assert.equal(m.pastille().find('.az-unread-count').text(), '1');
    assert.equal(m.haut().text(), '1');
    assert.equal(m.haut().hasClass('noMessage'), false);
    assert.equal(m.$('a.comm_menu.chat').attr('title'), '1 message non lu', 'L infobulle dit le meme nombre que le badge.');
    const badges = m.$('#chatBarPlayerList .new_msg_count[data-playerid="1"]').map(function () { return m.$(this).text(); }).get();
    assert.deepEqual(nu(badges), ['1', '1'], 'Les deux badges portent la valeur du serveur...');
    assert.equal(m.pastille().attr('title'), '1 message non lu');
    assert.equal(m.$('#chatBarPlayerList .az-count').text(), '3', '...et le nombre de contacts en ligne n a pas bouge.');
});

test('a zero la pastille est absente ; quand le nombre monte, un bref halo', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));
    assert.notEqual(m.pastille().attr('hidden'), undefined, 'Absente a zero.');

    m.u.demanderLecture();
    m.lectures()[1].reussir(photo(3, [{ kind: 'alliance', allianceId: 7, unread: 3 }]));
    m.avancer(0);
    assert.equal(m.pastille().attr('hidden'), undefined);
    assert.equal(m.pastille().hasClass('az-unread-pip--halo'), true);
    assert.equal(m.$('a.comm_menu.chat').attr('title'), '3 messages non lus');
});

// ------------------------------------------------------------------ ne jamais notifier deux fois

test('un message deja vu ne notifie pas ; un second du meme expediteur groupe le bandeau ; le mien ne notifie pas', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));

    m.u.messageRecu({ id: 100, senderId: 2, senderName: 'Kirk', text: 'secret', date: 1 });
    m.u.messageRecu({ id: 100, senderId: 2, senderName: 'Kirk', text: 'secret', date: 1 });
    assert.equal(m.$('#chatBar .az-toast').length, 1, 'Le meme identifiant, rejoue, ne fait pas un second bandeau.');
    assert.equal(m.$('#chatBar .az-toast-count').attr('hidden') !== undefined, true);
    assert.equal(m.$('#chatBar .az-toast').text().indexOf('secret'), -1, 'Aucun contenu prive dans le bandeau.');

    m.u.messageRecu({ id: 101, senderId: 2, senderName: 'Kirk', text: 'x', date: 2 });
    assert.equal(m.$('#chatBar .az-toast').length, 1, 'Deux messages rapproches : un seul bandeau...');
    assert.equal(m.$('#chatBar .az-toast-count').text(), '(2)', '...qui porte le compte.');

    m.u.messageRecu({ id: 102, senderId: 9, senderName: 'Moi', text: 'x', date: 3 });
    assert.equal(m.$('#chatBar .az-toast').length, 1, 'Mon propre message ne notifie pas.');

    m.avancer(5000);
    assert.equal(m.$('#chatBar .az-toast').length, 0, 'Cinq secondes plus tard, le bandeau est parti — et rien n a ete marque.');
    assert.equal(m.marquages().length, 0);
});

test('le clic sur le bandeau ouvre la conversation sans marquer ; la croix ferme sans marquer ; un onglet masque n affiche rien', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));

    m.u.messageRecu({ id: 200, senderId: 2, senderName: 'Kirk', text: 'x', date: 1 });
    m.$('#chatBar .az-toast-body').trigger('click');
    assert.deepEqual(m.window.ogame.chat.ouvertures, ['p2'], 'La conversation s ouvre par le chemin du chat...');
    assert.equal(m.marquages().length, 0, '...et rien n est marque : ce sont les messages affiches qui marqueront.');
    assert.equal(m.$('#chatBar .az-toast').length, 0);

    m.u.messageRecu({ id: 201, senderId: 3, senderName: 'Spock', text: 'x', date: 2, associationId: 7 });
    m.$('#chatBar .az-toast-close').trigger('click');
    assert.equal(m.$('#chatBar .az-toast').length, 0);
    assert.equal(m.marquages().length, 0);

    m.etat.visibilite = 'hidden';
    m.u.messageRecu({ id: 202, senderId: 2, senderName: 'Kirk', text: 'x', date: 3 });
    assert.equal(m.$('#chatBar .az-toast').length, 0, 'Un onglet masque ne montre pas de bandeau.');
});

// ------------------------------------------------------------------ ce qui compte comme lu

function uneFenetre(m, attrs, messages) {
    const li = m.$('<li class="chat_bar_list_item open"></li>').attr(attrs);
    const box = m.$('<div class="chat_box"><div class="chat_box_ctn"><ul class="chat"></ul></div></div>');
    messages.forEach(x => box.find('.chat').append(m.$('<li class="chat_msg"></li>').addClass(x.odd ? 'odd' : '').attr('data-chat-id', x.id)));
    li.append(box);
    m.$('#chatBar .chat_bar_list').append(li);
    m.u.observerLesMessages();
    return li;
}
function afficher(m, id) {
    const o = m.observes.find(x => m.$(x.el).attr('data-chat-id') === String(id));
    assert.ok(o, 'Le message ' + id + ' est observe.');
    o.cb([{ target: o.el, isIntersecting: true }]);
}

test('seuls les messages recus entres dans la zone affichee sont marques, en un lot, par leur ensemble exact', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));
    uneFenetre(m, { 'data-playerid': '2' }, [{ id: 10 }, { id: 11 }, { id: 12, odd: true }, { id: 13 }]);

    afficher(m, 13);
    afficher(m, 10);
    afficher(m, 12);
    assert.equal(m.marquages().length, 0, 'Rien ne part avant le lot.');
    m.avancer(700);
    assert.equal(m.marquages().length, 1);
    assert.deepEqual(nu(m.marquages()[0].donnees), { playerId: 2, seenIds: [10, 13] }, 'L ensemble exact des messages recus affiches : ni le 11 (jamais affiche), ni le 12 (le mien).');
});

test('le canal d alliance envoie le plus grand identifiant affiche', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));
    uneFenetre(m, { 'data-associationid': '7' }, [{ id: 30 }, { id: 31 }, { id: 35 }]);

    afficher(m, 35);
    afficher(m, 30);
    m.avancer(700);
    assert.deepEqual(nu(m.marquages()[0].donnees), { associationId: 7, seenUpToId: 35 });
});

test('une fenetre reduite ou un onglet masque ne marquent rien', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));
    const fenetre = uneFenetre(m, { 'data-playerid': '2' }, [{ id: 40 }]);

    m.etat.visibilite = 'hidden';
    afficher(m, 40);
    m.avancer(700);
    assert.equal(m.marquages().length, 0, 'Onglet masque : rien.');

    m.etat.visibilite = 'visible';
    fenetre.removeClass('open').children('.chat_box').hide();
    afficher(m, 40);
    m.avancer(700);
    assert.equal(m.marquages().length, 0, 'Fenetre reduite : rien.');

    fenetre.addClass('open').children('.chat_box').show();
    afficher(m, 40);
    m.avancer(700);
    assert.equal(m.marquages().length, 1, 'Ouverte, non reduite, onglet visible : le message affiche est marque.');
});

// ------------------------------------------------------------------ le son, sous son verrou

test('le son : lecture de l anneau, controle, enregistrement et declenchement sous le meme verrou ; un onglet masque pendant l attente n agit pas', async () => {
    const sons = [];
    const m = unMonde({ sonne: sons });
    m.lectures()[0].reussir(photo(0));
    m.window.localStorage.setItem('az-chat-son:9', '1');
    m.window.localStorage.removeItem('az-chat-sons');

    m.u.sonner(500);
    m.u.sonner(500);
    await new Promise(r => setImmediate(r));
    await new Promise(r => setImmediate(r));
    assert.equal(m.verrou.prises, 2, 'Chaque message prend le verrou.');
    assert.deepEqual(sons, ['son'], 'Un seul son pour le meme identifiant : le second a trouve l anneau.');
    assert.deepEqual(JSON.parse(m.window.localStorage.getItem('az-chat-sons')), [500], 'L identifiant est ecrit dans l anneau.');

    // Masque au moment ou le verrou est obtenu : la visibilite est verifiee APRES, dans la section.
    m.etat.visibilite = 'hidden';
    m.u.sonner(501);
    await new Promise(r => setImmediate(r));
    await new Promise(r => setImmediate(r));
    assert.deepEqual(sons, ['son'], 'Un onglet masque n agit pas.');
    assert.deepEqual(JSON.parse(m.window.localStorage.getItem('az-chat-sons')), [500], 'Et il n ecrit pas l anneau : un onglet visible pourra sonner.');

    // Preference eteinte : rien, meme visible.
    m.etat.visibilite = 'visible';
    m.window.localStorage.setItem('az-chat-son:9', '0');
    m.u.sonner(502);
    await new Promise(r => setImmediate(r));
    assert.deepEqual(sons, ['son']);
});

test('le bouton du son porte la preference, et une panne d ornement ne casse ni la pastille ni le compteur', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));
    const bouton = m.u.boutonDuSon();
    assert.equal(bouton.attr('aria-pressed'), 'false');
    bouton.trigger('click');
    assert.equal(bouton.attr('aria-pressed'), 'true');
    assert.equal(m.window.localStorage.getItem('az-chat-son:9'), '1');

    // Le bandeau se casse : la pastille et le badge continuent d etre poses.
    m.u.afficherLeBandeau = function () { throw new Error('panne'); };
    m.u.messageRecu({ id: 900, senderId: 2, senderName: 'Kirk', text: 'x', date: 1 });
    m.lectures()[m.lectures().length - 1].reussir(photo(4, [{ kind: 'direct', playerId: 2, unread: 4 }]));
    assert.equal(m.haut().text(), '4');
    assert.equal(m.pastille().find('.az-unread-count').text(), '4');
});

// ------------------------------------------------------------------ la veille

test('la veille ne sonde qu un onglet visible, et relit aussitot au retour', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));

    m.avancer(20000);
    assert.equal(m.lectures().length, 2, 'Direct non connecte dans ce monde : veille a 20 s.');
    m.lectures()[1].reussir(photo(0));

    m.etat.visibilite = 'hidden';
    m.window.document.dispatchEvent(new m.window.Event('visibilitychange'));
    m.avancer(120000);
    assert.equal(m.lectures().length, 2, 'Masque : aucune requete.');

    m.etat.visibilite = 'visible';
    m.window.document.dispatchEvent(new m.window.Event('visibilitychange'));
    assert.equal(m.lectures().length, 3, 'Visible a nouveau : une lecture immediate.');
});

// ------------------------------------------------------------------ le bandeau ne recouvre aucun controle, ou il s efface

test('le bandeau se pose dans la bande ; si elle recouvre un controle, au-dessus des onglets ; sinon nulle part — la pastille reste', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));

    // 1. La bande est libre : le bandeau y reste, sans la classe « au-dessus ».
    m.u.couvreUnControle = () => false;
    m.u.messageRecu({ id: 300, senderId: 2, senderName: 'Kirk', text: 'x', date: 1 });
    assert.equal(m.$('#chatBar .az-toast').length, 1);
    assert.equal(m.$('#chatBar .az-toast-stack').hasClass('az-toast-stack--above'), false, 'Dans la bande.');
    assert.equal(m.$('#chatBar > .az-toast-stack:first-child').length, 1, 'Premier enfant de la barre : a gauche des onglets, hors de la liste.');
    m.avancer(5000);

    // 2. La bande recouvre un controle, la place au-dessus non : le bandeau monte.
    let appels = 0;
    m.u.couvreUnControle = () => (++appels === 1);
    m.u.messageRecu({ id: 301, senderId: 3, senderName: 'Spock', text: 'x', date: 2 });
    assert.equal(m.$('#chatBar .az-toast').length, 1);
    assert.equal(m.$('#chatBar .az-toast-stack').hasClass('az-toast-stack--above'), true, 'Au-dessus des onglets.');
    m.avancer(5000);

    // 3. Les deux places recouvrent un controle : aucun bandeau, mais la pastille et le badge suivent quand meme.
    m.u.couvreUnControle = () => true;
    m.u.messageRecu({ id: 302, senderId: 4, senderName: 'Uhura', text: 'x', date: 3 });
    assert.equal(m.$('#chatBar .az-toast').length, 0, 'Plutot aucun bandeau qu un controle recouvert.');
    assert.equal(m.$('#chatBar .az-toast-stack').hasClass('az-toast-stack--above'), false, 'La pile est rendue a sa place.');
    // Chaque message recu a fait avancer l epoque : la lecture en vol est perimee, sa reponse est ecartee et une
    // lecture fraiche part — le protocole du §4. On repond aux deux ; seule la fraiche compte.
    const perimee = m.lectures().length;
    m.lectures()[perimee - 1].reussir(photo(9, [{ kind: 'direct', playerId: 4, unread: 9 }]));
    assert.equal(m.haut().text(), '0', 'La photographie perimee n a rien ecrit.');
    assert.equal(m.lectures().length, perimee + 1, 'Une lecture fraiche est partie.');
    m.lectures()[perimee].reussir(photo(3, [{ kind: 'direct', playerId: 4, unread: 3 }]));
    assert.equal(m.pastille().find('.az-unread-count').text(), '3');
    assert.equal(m.haut().text(), '3');
});

test('sans mise en page, rien n est recouvert : couvreUnControle rend faux sur des rectangles nuls', () => {
    const m = unMonde();
    m.lectures()[0].reussir(photo(0));
    m.u.messageRecu({ id: 310, senderId: 2, senderName: 'Kirk', text: 'x', date: 1 });
    assert.equal(m.u.couvreUnControle(m.$('#chatBar .az-toast')), false);
});
