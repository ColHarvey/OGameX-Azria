/*
 * Ce que le module de la vue Empire fait REELLEMENT quand les reponses n arrivent pas dans l ordre.
 *
 * Trois proprietes ne se voient pas au navigateur sans un reseau truque, et se prouvent ici :
 *  - une reponse retardee ne remplace ni un etat plus recent, ni l onglet choisi entre-temps ;
 *  - un echec conserve le tableau ET l instant affiche, et le dit ;
 *  - actualiser dix fois n ajoute aucun gestionnaire.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const JQUERY = readFileSync(new URL('../../resources/js/ingame/jquery-1.12.4.min.js', import.meta.url), 'utf8');
const MODULE = readFileSync(new URL('../../resources/js/ingame/empire.js', import.meta.url), 'utf8');

/** Une charge utile, dans la forme exacte que le serveur rend. */
function uneCharge({ instant = '10:00:00', lunes = false, colonnes = 2, lunesDuCompte = 1 } = {}) {
    const planets = [];
    for (let i = 0; i < colonnes; i++) {
        planets.push({ id: 100 + i, name: (lunes ? 'Lune ' : 'Planete ') + i, image: '', border: '', coordinates: '[1:1:' + i + ']', coordinatesLink: '#', fieldUsed: 1, fieldMax: 2, temperature: '', energy: '', energyDescr: '', energyTooltip: '', diameter: '', diameterDescr: '', diameterTooltip: '', type: lunes ? 3 : 1, production: { hourly: [], daily: [], weekly: [] } });
    }

    return {
        taken_at: 1,
        taken_at_formatted: instant,
        moons: lunes,
        moon_count: lunesDuCompte,
        planet_count: 2,
        order: [],
        translations: { header: 'Empire', groups: {}, planets: {}, production: {} },
        groups: { lifeforms: ['lf_species'] },
        planets,
        summary: {},
    };
}

function unMonde({ charge = uneCharge() } = {}) {
    const dom = new JSDOM(
        '<!doctype html><html><body>'
        + '<div id="empireComponent" data-empire-refresh="/ajax/empire" data-empire-order="/ajax/empire/order">'
        + '<div id="mainContent">'
        + '<div class="empireBar"><span id="empireTakenAt">' + charge.taken_at_formatted + '</span>'
        + '<button type="button" id="empireRefresh">Actualiser</button>'
        + '<span id="empireStale" hidden>echec</span></div>'
        + '<div id="mainWrapper"><div id="loading"></div></div>'
        + '</div></div>'
        + '</body></html>',
        { runScripts: 'dangerously', pretendToBeVisual: true, url: 'https://exemple.test/empire' },
    );
    const { window } = dom;

    window.eval(JQUERY);
    const jq = window.jQuery;

    window.empireLoca = { legend: 'legende', averageLevel: 'moyenne sur :count', noMoons: 'aucune lune', noMoonsHint: 'indice', refreshFailed: 'echec' };
    window.empireToken = 'jeton';
    window.empirePayload = charge;

    /* Le code client du jeu, reduit a ce que le module lui demande : il dessine une colonne par corps. */
    const rendus = [];
    window.createImperiumHtml = function (destination, loading, data) {
        rendus.push(data.taken_at_formatted + (data.moons ? ' lunes' : ' planetes'));
        const enveloppe = window.document.createElement('div');
        enveloppe.className = 'planetWrapper';
        data.planets.forEach(function (p) {
            enveloppe.innerHTML += '<div id="planet' + p.id + '" class="planet"><div class="planetHead"><div class="planetname">' + p.name + '</div></div><div class="values lifeforms"><div class="lf_species">x</div></div></div>';
        });
        jq(destination).append(enveloppe);
    };
    window.createHeaderHtml = function () { return '<div class="header"></div>'; };
    window.initEmpire = function () {};

    const demandes = [];
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
            echouer(xhr) { if (rappels.fail) { rappels.fail(xhr || { status: 500 }); } if (rappels.always) { rappels.always(); } },
        });

        return api;
    };

    window.eval(MODULE);
    /* `jQuery(fn)` sur un document deja pret : le module rend au prochain tour de boucle. On le force ici. */
    window.jQuery.ready.promise().then(() => {});

    return {
        window, jq, demandes, rendus,
        empire: () => window.AzriaEmpire,
        actualiser: () => window.document.getElementById('empireRefresh').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true })),
        instantAffiche: () => window.document.getElementById('empireTakenAt').textContent,
        colonnes: () => window.document.querySelectorAll('#empireComponent .planet').length,
        nomsDeColonnes: () => [...window.document.querySelectorAll('#empireComponent .planet > .planetname')].map((e) => e.textContent),
        echecVisible: () => !window.document.getElementById('empireStale').hidden,
        gestionnairesDuDocument: () => {
            const evenements = jq._data(window.document, 'events') || {};

            return Object.keys(evenements).reduce((total, type) => total + evenements[type].length, 0);
        },
    };
}

test('le premier rendu vient de la charge posee par la page, sans aucune requete', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);

    assert.deepEqual(m.rendus, ['10:00:00 planetes']);
    assert.equal(m.demandes.length, 0, 'La page porte deja sa photographie : la rouvrir ne la redemande pas.');
    assert.equal(m.colonnes(), 2);
});

test('le nom de colonne devient enfant direct de la colonne, sinon il ne pourrait pas coller', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);

    assert.equal(m.nomsDeColonnes().length, 2, 'Un nom reste dans son en-tete : colle, il disparaitrait des qu on descend.');
    assert.deepEqual(m.nomsDeColonnes(), ['Planete 0', 'Planete 1']);
});

test('une actualisation demande l onglet courant et remplace la photographie', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);
    m.actualiser();

    assert.equal(m.demandes.length, 1);
    assert.equal(m.demandes[0].options.url, '/ajax/empire');
    // `deepEqual` sur un objet venu de jsdom compare aussi son prototype, d un autre royaume : champ par champ.
    assert.equal(m.demandes[0].options.data.planetType, 0);

    m.demandes[0].repondre(uneCharge({ instant: '10:00:30' }));
    assert.equal(m.instantAffiche(), '10:00:30');
    assert.equal(m.echecVisible(), false);
});

test('une reponse retardee ne remplace pas un etat plus recent', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);

    m.actualiser();
    m.actualiser();
    assert.equal(m.demandes.length, 2);

    // La seconde repond d abord, puis la premiere arrive en retard.
    m.demandes[1].repondre(uneCharge({ instant: '10:00:20' }));
    assert.equal(m.instantAffiche(), '10:00:20');

    m.demandes[0].repondre(uneCharge({ instant: '10:00:10' }));
    assert.equal(m.instantAffiche(), '10:00:20', 'La reponse en retard a ecrase un etat plus recent.');
    assert.deepEqual(m.rendus, ['10:00:00 planetes', '10:00:20 planetes']);
});

test('une reponse retardee ne remplace pas l onglet choisi entre-temps', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);

    m.actualiser();                       // onglet Planetes
    m.empire().request(true);             // le joueur passe aux Lunes
    assert.equal(m.demandes.length, 2);
    assert.equal(m.demandes[1].options.data.planetType, 1);

    m.demandes[1].repondre(uneCharge({ instant: '10:01:00', lunes: true, colonnes: 1 }));
    assert.equal(m.colonnes(), 1);
    assert.deepEqual(m.nomsDeColonnes(), ['Lune 0']);

    // La reponse des planetes arrive maintenant : elle ne doit pas ramener l onglet precedent.
    m.demandes[0].repondre(uneCharge({ instant: '10:00:55' }));
    assert.deepEqual(m.nomsDeColonnes(), ['Lune 0'], 'L onglet choisi entre-temps a ete remplace par une reponse en retard.');
    assert.equal(m.instantAffiche(), '10:01:00');
});

test('un echec conserve le tableau, son instant, et le dit', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);

    const colonnesAvant = m.colonnes();
    m.actualiser();
    m.demandes[0].echouer({ status: 500 });

    assert.equal(m.colonnes(), colonnesAvant, 'Le tableau a ete efface par un echec.');
    assert.equal(m.instantAffiche(), '10:00:00', 'L instant affiche a saute alors que rien de neuf n est arrive.');
    assert.equal(m.echecVisible(), true);
    assert.deepEqual(m.rendus, ['10:00:00 planetes'], 'Un echec ne doit rien redessiner.');
});

test('un echec en retard ne parle pas par-dessus une reponse plus recente', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);

    m.actualiser();
    m.actualiser();
    m.demandes[1].repondre(uneCharge({ instant: '10:02:00' }));
    m.demandes[0].echouer({ status: 500 });

    assert.equal(m.echecVisible(), false, 'Un echec perime a annonce une panne alors que la page est a jour.');
    assert.equal(m.instantAffiche(), '10:02:00');
});

test('actualiser dix fois n ajoute aucun gestionnaire', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);

    const avant = m.gestionnairesDuDocument();

    for (let i = 0; i < 10; i++) {
        m.actualiser();
        m.demandes[m.demandes.length - 1].repondre(uneCharge({ instant: '10:0' + (i % 10) + ':00' }));
    }

    assert.equal(m.gestionnairesDuDocument(), avant, 'Les gestionnaires s accumulent a chaque actualisation.');
    assert.equal(m.colonnes(), 2, 'Les colonnes s empilent : le conteneur n est pas vide avant le rendu.');
    assert.equal(m.nomsDeColonnes().length, 2);
});

test('sans lune, l onglet est inactif et la page le dit au lieu de dessiner une grille vide', () => {
    const m = unMonde();
    m.empire().render(uneCharge({ lunes: true, colonnes: 0, lunesDuCompte: 0 }));

    assert.equal(m.colonnes(), 0);
    assert.match(m.window.document.querySelector('.empireEmpty').textContent, /aucune lune/);
    assert.deepEqual(m.rendus, [], 'Le code client ne doit pas etre appele pour dessiner rien.');
});

test('reinitialiser l ordre nomme l onglet et redessine sans quitter la page', () => {
    const m = unMonde();
    m.empire().render(m.window.empirePayload);

    m.window.clearImperiumOrder();

    assert.equal(m.demandes.length, 1);
    assert.equal(m.demandes[0].options.url, '/ajax/empire/order');
    assert.equal(m.demandes[0].options.type, 'POST');
    assert.equal(m.demandes[0].options.data.type, 'reset');
    assert.equal(m.demandes[0].options.data.moons, 0);
    assert.equal(m.demandes[0].options.data._token, 'jeton');

    m.demandes[0].repondre({ success: true });
    assert.equal(m.demandes.length, 2, 'Apres la remise a zero, la page se redemande.');
    assert.equal(m.demandes[1].options.url, '/ajax/empire');
});
