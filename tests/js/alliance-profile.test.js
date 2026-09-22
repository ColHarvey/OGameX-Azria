/*
 * Ce que le module de la fiche publique d alliance fait REELLEMENT dans un document.
 *
 * Le VRAI jQuery du jeu (`jquery-1.12.4.min.js` — `jquery.js` n est qu un lot de greffons) est charge dans jsdom, puis le module tel quel. Ce qui est remplace, et porte la regle
 * du vrai : `openOverlay` (retient ses appels, transforme l element en dialogue avec `dialog()` qui sait fermer,
 * detruire et remonter), `jQuery.ajax` (retient les demandes au lieu de les envoyer, et laisse l essai repondre ou
 * echouer). Ce qui est observe : les ouvertures, les requetes qui partent et ce qu elles portent, ce que la fenetre
 * affiche, ou revient le focus.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const JQUERY = readFileSync(new URL('../../resources/js/ingame/jquery-1.12.4.min.js', import.meta.url), 'utf8');
const MODULE = readFileSync(new URL('../../resources/js/ingame/alliance-profile.js', import.meta.url), 'utf8');

function unMonde({ avecOverlay = true, avecDialogue = true } = {}) {
    const dom = new JSDOM(
        '<!doctype html><html><body>'
        + '<a id="lienA" href="/alliance/info/7" data-alliance-profile="7" data-alliance-tag="AAA">[AAA]</a>'
        + '<a id="lienB" href="/alliance/info/9" data-alliance-profile="9" data-alliance-tag="BBB">[BBB]</a>'
        + '<a id="lienA2" href="/alliance/info/7" data-alliance-profile="7" data-alliance-tag="AAA">[AAA] encore</a>'
        + '</body></html>',
        { runScripts: 'dangerously', pretendToBeVisual: true, url: 'https://exemple.test/highscore' },
    );
    const { window } = dom;

    window.eval(JQUERY);
    const jq = window.jQuery;

    window.LocalizationStrings = {
        loading: 'Chargement',
        attention: 'Attention',
        yes: 'Oui',
        no: 'Non',
        allianceProfile: { title: "Informations sur l'alliance", loading: 'Chargement', loadFailed: 'Echec' },
    };

    const ouvertures = [];
    const demandes = [];
    const fermetures = [];

    if (avecDialogue) {
        jq.fn.dialog = function (options) {
            const el = this;
            if (typeof options === 'object') {
                el.data('dialogOptions', options);
                el.addClass('ui-dialog-content');
                el.css('display', 'block');
                const titre = window.document.createElement('span');
                titre.className = 'ui-dialog-title';
                titre.textContent = options.title || '';
                const boite = window.document.createElement('div');
                boite.className = 'ui-dialog';
                boite.appendChild(titre);
                el[0].parentNode.insertBefore(boite, el[0]);
                boite.appendChild(el[0]);
                return el;
            }
            if (options === 'close') {
                fermetures.push(el.attr('data-alliance-id'));
                const o = el.data('dialogOptions') || {};
                if (typeof o.close === 'function') { o.close(); }
                return el;
            }
            if (options === 'destroy') {
                const boite = el[0].parentNode;
                if (boite && boite.className === 'ui-dialog') { boite.parentNode.insertBefore(el[0], boite); boite.remove(); }
                return el;
            }
            if (options === 'moveToTop') { el.data('remontee', (el.data('remontee') || 0) + 1); return el; }
            if (options === 'option') { return { my: 'center', at: 'center' }; }
            return el;
        };
    }

    if (avecOverlay) {
        window.openOverlay = function (element, params) {
            ouvertures.push({ titre: params.title, largeur: params.width, type: params.type });
            jq(element).dialog(params);
        };
    }

    jq.ajax = function (options) {
        const rappels = {};
        const api = {
            done(cb) { rappels.done = cb; return api; },
            fail(cb) { rappels.fail = cb; return api; },
            always(cb) { rappels.always = cb; return api; },
        };
        demandes.push({
            options,
            repondre(reponse) { if (rappels.done) { rappels.done(reponse); } if (rappels.always) { rappels.always(); } },
            echouer(xhr) { if (rappels.fail) { rappels.fail(xhr); } if (rappels.always) { rappels.always(); } },
        });
        return api;
    };

    window.eval(MODULE);

    const cliquer = (id, init = {}) => {
        const lien = window.document.getElementById(id);
        const e = new window.MouseEvent('click', Object.assign({ bubbles: true, cancelable: true, button: 0 }, init));
        lien.dispatchEvent(e);
        return e;
    };

    return { window, jq, ouvertures, demandes, fermetures, cliquer, fenetres: () => jq('.overlayDiv.allianceProfile') };
}

test('un clic simple ouvre une fenetre par le mecanisme du jeu, et demande le seul fragment, une fois', () => {
    const m = unMonde();
    const e = m.cliquer('lienA');

    assert.equal(e.defaultPrevented, true, 'le navigateur ne doit pas naviguer');
    assert.equal(m.ouvertures.length, 1);
    assert.equal(m.ouvertures[0].type, 'inline');
    assert.equal(m.ouvertures[0].titre, 'Informations sur l’alliance [AAA]', 'le titre porte le tag, et une apostrophe typographique');
    assert.equal(m.demandes.length, 1);
    assert.equal(m.demandes[0].options.url, '/alliance/info/7?overlay=1');
    assert.equal(m.demandes[0].options.type, 'GET');
    assert.equal(m.fenetres().length, 1);
    assert.match(m.fenetres().text(), /Chargement/);
});

// Le fermeur du jeu, tel que `initHideElements()` le pose : delegue sur le document avec le selecteur `html`,
// il ferme tout `.overlayDiv` des qu un clic tombe hors d un `.ui-dialog`. jQuery le met dans la file APRES le
// gestionnaire du lien (la cible est plus profonde que `html`) et l ignore une fois la remontee arretee : c est
// cette arrestation que le module doit faire, comme le gestionnaire historique des overlays par `return false`.
// Un ecouteur natif sur `html` ne serait pas le bon temoin : il tire AVANT le repartiteur jQuery du document.
function poserLeFermeurDuJeu(m) {
    const passages = [];

    m.jq(m.window.document).delegate('html', 'click.hideElem', function (e) {
        passages.push(e.target.id);

        if (m.jq(e.target).parents('.ui-dialog').length) {
            return;
        }

        m.jq('.overlayDiv').each(function () { m.jq(this).dialog('close'); });
    });

    return passages;
}

test('le clic qui ouvre la fiche n atteint pas le fermeur du jeu : la fenetre survit a son propre clic', () => {
    const m = unMonde();
    const passages = poserLeFermeurDuJeu(m);
    m.cliquer('lienA');

    assert.deepEqual(passages, [], 'la remontee est arretee avant le gestionnaire delegue sur html');
    assert.equal(m.fenetres().length, 1, 'la fenetre ouverte par ce clic est encore la');
    assert.deepEqual(m.fermetures, []);

    // Un clic hors de la fenetre, ensuite, la ferme bien par le fermeur du jeu : le module ne le prive de rien.
    m.window.document.body.dispatchEvent(new m.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    assert.deepEqual(passages, ['']);
    assert.equal(m.fenetres().length, 0);
});

test('un clic modifie sur le lien remonte jusqu au fermeur du jeu : le module ne l avale pas', () => {
    const m = unMonde();
    const passages = poserLeFermeurDuJeu(m);
    m.cliquer('lienA', { ctrlKey: true });

    assert.deepEqual(passages, ['lienA']);
    assert.equal(m.fenetres().length, 0);
});

test('la reponse remplace le chargement par le fragment', () => {
    const m = unMonde();
    m.cliquer('lienA');
    m.demandes[0].repondre('<div class="azria-alliance-profile" data-alliance-id="7"><h2>Alpha</h2></div>');

    assert.equal(m.fenetres().find('h2').text(), 'Alpha');
    assert.doesNotMatch(m.fenetres().text(), /Chargement/);
});

test('une 404 affiche le fragment d erreur du serveur, sans fenetre vide ni chargement bloque', () => {
    const m = unMonde();
    m.cliquer('lienA');
    m.demandes[0].echouer({ status: 404, responseText: '<div class="azria-alliance-profile ap-missing" role="alert"><p>Introuvable</p></div>' });

    assert.equal(m.fenetres().length, 1);
    assert.equal(m.fenetres().find('[role="alert"]').text(), 'Introuvable');
    assert.doesNotMatch(m.fenetres().text(), /Chargement/);
});

test('une autre panne affiche un message, jamais un chargement eternel', () => {
    const m = unMonde();
    m.cliquer('lienA');
    m.demandes[0].echouer({ status: 500, responseText: '' });

    assert.equal(m.fenetres().find('[role="alert"]').text(), 'Echec');
});

test('un clic modifie ou du bouton du milieu laisse le navigateur faire : rien ne s ouvre, rien ne part', () => {
    for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1, which: 2 }]) {
        const m = unMonde();
        const e = m.cliquer('lienA', init);

        assert.equal(e.defaultPrevented, false, JSON.stringify(init));
        assert.equal(m.ouvertures.length, 0, JSON.stringify(init));
        assert.equal(m.demandes.length, 0, JSON.stringify(init));
    }
});

test('sans le mecanisme d overlay, le lien navigue normalement', () => {
    const m = unMonde({ avecOverlay: false });
    const e = m.cliquer('lienA');

    assert.equal(e.defaultPrevented, false);
    assert.equal(m.demandes.length, 0);
});

test('une fenetre par alliance : un second clic sur la meme alliance la remonte au lieu d en ouvrir une autre', () => {
    const m = unMonde();
    m.cliquer('lienA');
    m.cliquer('lienA2');

    assert.equal(m.ouvertures.length, 1);
    assert.equal(m.demandes.length, 1, 'aucune seconde requete');
    assert.equal(m.fenetres().length, 1);
    assert.equal(m.fenetres().data('remontee'), 1);
});

test('deux alliances differentes ont deux fenetres, identifiees par leur identifiant et non par le seul titre', () => {
    const m = unMonde();
    m.cliquer('lienA');
    m.cliquer('lienB');

    assert.equal(m.ouvertures.length, 2);
    assert.deepEqual(m.ouvertures.map(o => o.titre), ['Informations sur l’alliance [AAA]', 'Informations sur l’alliance [BBB]']);
    assert.equal(m.fenetres().length, 2);
    assert.equal(m.window.AzriaAllianceProfile.windowOf(7).length, 1);
    assert.equal(m.window.AzriaAllianceProfile.windowOf(9).length, 1);
});

test('fermer retire la fenetre et rend le focus au lien qui l a ouverte', () => {
    const m = unMonde();
    m.cliquer('lienB');
    const fenetre = m.fenetres();
    fenetre.dialog('close');

    assert.equal(m.fenetres().length, 0);
    assert.equal(m.window.document.activeElement, m.window.document.getElementById('lienB'));
});

test('la candidature : confirmer envoie UN POST ; le succes ferme la fiche ; un refus la laisse utilisable sans succes', () => {
    const m = unMonde();
    m.window.confirm = () => true;
    const messages = [];
    m.window.fadeBox = (texte, echec) => messages.push({ texte, echec: !!echec });

    m.cliquer('lienA');
    m.demandes[0].repondre('<div class="azria-alliance-profile" data-alliance-id="7">'
        + '<button type="button" class="btn_blue js_allianceApply" data-alliance-id="7" data-apply-url="/alliance/apply" data-token="jeton" data-confirm="Sur ?" data-success="Envoyee" data-error="Echec" data-applied="Envoyee">Postuler</button>'
        + '</div>');

    const bouton = m.fenetres().find('.js_allianceApply');
    bouton[0].dispatchEvent(new m.window.MouseEvent('click', { bubbles: true, cancelable: true }));

    assert.equal(m.demandes.length, 2, 'un seul POST est parti');
    assert.equal(m.demandes[1].options.type, 'POST');
    assert.equal(m.demandes[1].options.url, '/alliance/apply');
    // Champ par champ : l objet vient de la fenetre jsdom, et une comparaison en bloc butait sur son prototype.
    assert.equal(m.demandes[1].options.data.alliance_id, '7');
    assert.equal(m.demandes[1].options.data.message, '');
    assert.equal(m.demandes[1].options.data._token, 'jeton');
    assert.equal(bouton.prop('disabled'), true, 'le bouton est tenu pendant l envoi');

    // Refus : la fiche reste, le bouton est rendu, aucun succes annonce.
    m.demandes[1].echouer({ status: 400, responseJSON: { success: false, message: 'Alliance fermee' } });
    assert.equal(m.fenetres().length, 1);
    assert.equal(bouton.prop('disabled'), false);
    assert.deepEqual(messages, [{ texte: 'Alliance fermee', echec: true }]);

    // Succes : la fiche se ferme, l onglet reste.
    bouton[0].dispatchEvent(new m.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    m.demandes[2].repondre({ success: true, message: 'Candidature envoyee' });
    assert.equal(m.fenetres().length, 0);
    assert.deepEqual(m.fermetures, ['7']);
    assert.equal(messages[1].echec, false);
});

test('un fragment reinjecte ne double aucun gestionnaire : trois fiches ouvertes, un clic, un POST', () => {
    const m = unMonde();
    m.window.confirm = () => true;
    m.window.fadeBox = () => {};

    m.cliquer('lienA');
    m.demandes[0].repondre('<div class="azria-alliance-profile" data-alliance-id="7"><button class="js_allianceApply" data-alliance-id="7" data-apply-url="/alliance/apply" data-token="t" data-confirm="?" data-success="ok" data-error="ko" data-applied="ok">Postuler</button></div>');
    m.fenetres().dialog('close');
    m.cliquer('lienB');
    m.demandes[1].repondre('<div class="azria-alliance-profile" data-alliance-id="9"><button class="js_allianceApply" data-alliance-id="9" data-apply-url="/alliance/apply" data-token="t" data-confirm="?" data-success="ok" data-error="ko" data-applied="ok">Postuler</button></div>');

    m.fenetres().find('.js_allianceApply')[0].dispatchEvent(new m.window.MouseEvent('click', { bubbles: true, cancelable: true }));

    assert.equal(m.demandes.filter(d => d.options.type === 'POST').length, 1);
});
