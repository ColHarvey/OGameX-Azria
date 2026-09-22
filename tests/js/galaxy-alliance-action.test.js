/*
 * L action « Alliance » de la fiche tactique ouvre la fiche publique SANS quitter la Galaxie.
 *
 * ## Le defaut que ce harnais ferme
 *
 * Le lien de la fiche publique (`a[data-alliance-profile]`) vit dans l infobulle de l ALLIANCE —
 * `#alliance<id>`, composee par `getAllianceTooltip()` du bundle herite —, pas dans celle du joueur
 * (`#player<id>`), qui porte le message, l ami et l ignorer. L action le cherchait dans `#player<id>`,
 * ne le trouvait jamais, et retombait sur une navigation `location.href` vers la page autonome : le
 * joueur quittait la Galaxie. La mesure navigateur du 22 septembre 2026 l a montre (« lien: null »
 * sur un joueur pourtant membre d une alliance).
 *
 * ## Ce que ce harnais prouve
 *
 * Un monde avec la ligne du serveur (un joueur etranger, membre de l alliance 3) et les deux
 * infobulles telles que le tableau les rend — le lien dans `#alliance3`, rien dans `#player9`. Cliquer
 * le corps ouvre la fiche tactique ; cliquer son bouton « Alliance » doit cliquer CE lien, une fois, et
 * ne provoquer aucune navigation. Le lien lui-meme est tenu par un temoin : c est le module de la fiche
 * publique qui, dans le jeu, le transforme en fenetre — il n est pas charge ici, et n a pas a l etre.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM, VirtualConsole } from 'jsdom';

const SOURCE = new URL('../../resources/js/ingame/galaxy-tactical.js', import.meta.url);

/** Un faux jQuery muet : la carte ne demande rien qui compte ici. */
function faireJQuery() {
    const chainable = () => {
        const api = {};
        api.done = () => api;
        api.fail = () => api;
        api.always = () => api;
        return api;
    };

    const jq = function () {
        return { on: () => {}, off: () => {}, trigger: () => {}, addClass: () => {}, removeClass: () => {} };
    };

    jq.getJSON = chainable;
    jq.post = chainable;
    jq.ajax = chainable;

    return jq;
}

/**
 * Les deux infobulles d une ligne du tableau, telles que le bundle herite les rend pour un joueur
 * etranger membre d une alliance : le lien de la fiche publique dans celle de l alliance, et RIEN
 * de tel dans celle du joueur.
 */
function infobullesDe({ joueur, alliance, tag }) {
    return '<div id="player' + joueur + '" style="display:none" class="htmlTooltip galaxyTooltip">'
        + '<ul class="ListLinks"><li><a href="#" class="sendMail">Message</a></li><li><a href="#" class="ignorePlayerLink">Ignorer</a></li></ul></div>'
        + '<div id="alliance' + alliance + '" style="display:none" class="htmlTooltip galaxyTooltip">'
        + '<ul class="ListLinks"><li><a href="/alliance/info/' + alliance + '" data-alliance-profile="' + alliance + '" data-alliance-tag="' + tag + '">Page de l’alliance</a></li></ul></div>';
}

/**
 * Un monde : la carte, la ligne du tableau (que la fiche deplace en elle), le module charge.
 * Les navigations que jsdom refuse sont retenues : une seule suffit a condamner l essai.
 */
function unMonde() {
    const navigations = [];
    const console = new VirtualConsole();

    console.on('jsdomError', (e) => { navigations.push(String(e && e.message)); });

    const dom = new JSDOM(
        '<!doctype html><html><body>'
        + '<div id="galaxyTactical"></div>'
        + '<div id="galaxyContent"><div id="galaxyRow4" class="galaxyRow">'
        + '<span class="playerName" rel="player9">Rival2</span>'
        + infobullesDe({ joueur: 9, alliance: 3, tag: 'MESUR2' })
        + '</div></div>'
        + '</body></html>',
        { runScripts: 'dangerously', pretendToBeVisual: true, url: 'https://exemple.test/galaxy', virtualConsole: console },
    );
    const { window } = dom;

    window.jQuery = faireJQuery();
    window.$ = window.jQuery;
    window.galaxyFleetsUrl = '/ajax/galaxy/fleets';
    window.galaxyContentLink = '/ajax/galaxy';
    window.playerId = 7;
    window.token = 'jeton';
    window.galaxyTacticalLoca = {};
    window.galaxyCurrentPlanetId = 101;
    window.galaxyPatrolsEnabled = false;
    window.getAjaxEventbox = function () {};
    window.refreshFleetEvents = function () {};
    window.renderContentGalaxy = function () {};
    window.Echo = { private: () => ({ listen: () => {} }), leave: () => {} };

    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    /*
     * Le lien de la fiche publique est tenu par un temoin pose LA OU LE MODULE DE LA FICHE ECOUTE : delegue
     * sur le document, en bouillonnement. Un temoin sur le lien lui-meme verrait un clic que la carte
     * aurait ensuite arrete en chemin ; celui-ci ne compte que les clics qui atteignent le document.
     */
    const clicsSurLeLien = [];
    /*
     * Et TOUT clic qui atteint le document est note, avec sa cible. C'est la que vit le fermeur du jeu
     * (`initHideElements()`, delegue sur le document avec le selecteur `html`) : un clic qui y arrive
     * avec une cible hors d'un `.ui-dialog` ferme toutes les fenetres ouvertes — celle que le bouton
     * vient d'ouvrir comprise. Le clic du BOUTON ne doit donc jamais y parvenir ; celui du lien, si,
     * c'est lui qui ouvre la fenetre (et le module de la fiche l'arrete lui-meme ensuite).
     */
    const clicsAuDocument = [];

    window.document.addEventListener('click', (e) => {
        const a = e.target.closest ? e.target.closest('a[data-alliance-profile]') : null;

        clicsAuDocument.push(a ? 'lien' : e.target.tagName + (e.target.dataset && e.target.dataset.action ? '[' + e.target.dataset.action + ']' : ''));

        if (a) {
            e.preventDefault();
            clicsSurLeLien.push(a.getAttribute('data-alliance-profile'));
        }
    });

    const amorcer = (lignes) => {
        window.renderContentGalaxy({ system: { galaxy: 1, system: 3, galaxyContent: lignes } });
    };
    const cliquer = (cible) => {
        cible.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    };
    const corps = (position) => window.document.querySelector('.gtBody[data-position="' + position + '"]');
    const fiche = () => window.document.querySelector('.gtCard');
    const bouton = (clef) => window.document.querySelector('.gtCard button[data-action="' + clef + '"]');

    return { window, navigations, clicsSurLeLien, clicsAuDocument, amorcer, cliquer, corps, fiche, bouton, fermer: () => window.close() };
}

/** La ligne du serveur : une planete etrangere, son proprietaire membre de l alliance 3. */
function laLigneDeRival2({ allianceId = 3 } = {}) {
    return {
        position: 4,
        playerId: 9,
        player: { playerId: 9, playerName: 'Rival2', allianceId, allianceTag: allianceId ? 'MESUR2' : null, isAllianceMember: false, actions: {} },
        planets: [{ planetType: 1, planetId: 5004, planetName: 'Terra', playerId: 9 }],
    };
}

test('le bouton Alliance clique le lien de l infobulle de l ALLIANCE, une fois, sans quitter la Galaxie', () => {
    const m = unMonde();

    try {
        m.amorcer([laLigneDeRival2()]);
        m.cliquer(m.corps(4));

        assert.ok(m.fiche() && !m.fiche().hidden, 'la fiche tactique s ouvre sur le corps');

        const b = m.bouton('alliance');

        assert.ok(b, 'la fiche porte le bouton Alliance');
        assert.equal(b.disabled, false, 'membre d une alliance : le bouton est actif');

        m.clicsAuDocument.length = 0;
        m.cliquer(b);

        assert.deepEqual(m.clicsSurLeLien, ['3'], 'le clic sur le lien de #alliance3 atteint le document, exactement une fois');
        assert.deepEqual(m.clicsAuDocument, ['lien'], 'seul le clic du lien atteint le document : celui du bouton s arrete avant le fermeur du jeu');
        assert.deepEqual(m.navigations, [], 'aucune navigation : le joueur reste dans la Galaxie');
    } finally {
        m.fermer();
    }
});

test('sans alliance, le bouton est inactif et ne clique rien', () => {
    const m = unMonde();

    try {
        m.amorcer([laLigneDeRival2({ allianceId: null })]);
        m.cliquer(m.corps(4));

        const b = m.bouton('alliance');

        assert.ok(b);
        assert.equal(b.disabled, true);

        m.cliquer(b);

        assert.deepEqual(m.clicsSurLeLien, []);
        assert.deepEqual(m.navigations, []);
    } finally {
        m.fermer();
    }
});
