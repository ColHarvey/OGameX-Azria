/*
 * Ce que la cle a molette de la liste des planetes fait REELLEMENT dans un navigateur.
 *
 * Les deux modules sont charges tels quels dans un document jsdom : `resource-bar.js`, qui demande le bandeau et
 * annonce la reponse appliquee, et `planet-list-construction.js`, qui l ecoute. La cle ne change donc qu au
 * bout du vrai chemin — une demande qui part, une reponse du serveur, l annonce du bandeau — et jamais parce
 * qu un essai aurait declenche l evenement a la main.
 *
 * ## Le temps est tenu, pas attendu
 *
 * Le monde retient chaque minuterie armee au lieu de la laisser courir, et l essai declenche celle qu il vise.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const BANDEAU = new URL('../../resources/js/ingame/resource-bar.js', import.meta.url);
const LISTE = new URL('../../resources/js/ingame/planet-list-construction.js', import.meta.url);

/** Une planete de la liste, telle que le gabarit l ecrit, avec ou sans cle. */
function unePlanete(id, { cle = null } = {}) {
    const adresse = 'https://exemple.test/overview?cp=' + id;
    const icone = cle === null
        ? ''
        : '<a class="constructionIcon tooltip js_hideTipOnMobile tpd-hideOnClickOutside" data-link="' + adresse + '" href="' + adresse + '" title="">'
            + '<span class="icon12px ' + (cle === 'rouge' ? 'icon_wrench_red' : 'icon_wrench') + '"></span></a>';

    return '<div class="smallplanet" data-planet-id="' + id + '" id="planet-' + id + '">'
        + '<a href="' + adresse + '" data-link="' + adresse + '" class="planetlink">Planete ' + id + '</a>'
        + icone
        + '<a class="moonlink" href="https://exemple.test/overview?cp=' + (id + 1000) + '">Lune</a>'
        + '</div>';
}

/** Une reponse du serveur, telle que `/ajax/resourcebox` la rend, avec l etat de la liste demande. */
function uneReponse(planetList) {
    const reponse = {
        resources: {
            metal: { amount: 1, storage: 10, baseProduction: 0, production: 0, tooltip: 'Metal', classesListItem: '' },
            crystal: { amount: 1, storage: 10, baseProduction: 0, production: 0, tooltip: 'Cristal', classesListItem: '' },
            deuterium: { amount: 1, storage: 10, baseProduction: 0, production: 0, tooltip: 'Deuterium', classesListItem: '' },
            energy: { amount: 0, tooltip: 'Energie', classesListItem: '' },
            darkmatter: { amount: 0, tooltip: 'Matiere noire', classesListItem: '' }
        },
        techs: {},
        honorScore: 11
    };

    if (planetList !== undefined) {
        reponse.planetList = planetList;
    }

    return reponse;
}

/** Un faux jQuery qui retient ses demandes. `always` tourne apres `done`, comme le vrai. */
function faireJQuery() {
    const demandes = [];
    const jq = function () {
        return { on: () => {}, off: () => {} };
    };

    jq.getJSON = function (url, donnees) {
        const rappels = {};
        const api = {
            done(cb) { rappels.done = cb; return api; },
            fail(cb) { rappels.fail = cb; return api; },
            always(cb) { rappels.always = cb; return api; }
        };

        demandes.push({
            url,
            donnees,
            repondre(reponse) { if (rappels.done) { rappels.done(reponse); } if (rappels.always) { rappels.always(); } }
        });

        return api;
    };

    return { jq, demandes };
}

/**
 * Un monde : le bandeau et la liste des planetes dans le document, les deux modules charges.
 *
 * @param {string} planetes le contenu de `#planetList`
 * @param {number|null} echeanceAuChargement la valeur que le gabarit pose sur la liste
 */
function unMonde({ planetes = unePlanete(11, { cle: 'normale' }) + unePlanete(12), echeanceAuChargement = null } = {}) {
    const attribut = echeanceAuChargement === null ? '' : ' data-construction-next-change-in="' + echeanceAuChargement + '"';
    const corps = '<div id="resourcesbarcomponent" data-resourcebox-url="/ajax/resourcebox"></div>'
        + '<div id="planetList"' + attribut + '>' + planetes + '</div>';

    const dom = new JSDOM('<!doctype html><html><head><meta name="ogame-player-id" content="7"><meta name="ogame-planet-id" content="11"></head><body>' + corps + '</body></html>', {
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        url: 'https://exemple.test/overview'
    });

    const { window } = dom;
    const { jq, demandes } = faireJQuery();

    window.jQuery = jq;
    window.$ = jq;
    window.reloadResources = function (donnees, rappel) {
        if (typeof rappel === 'function') {
            rappel(donnees.resources);
        }
    };

    /* Les minuteries sont retenues, jamais laissees courir. */
    const minuteries = [];
    let prochainNumero = 1;

    window.setTimeout = (fonction, delai) => {
        const minuterie = { numero: prochainNumero++, fonction, delai, annulee: false, tiree: false };
        minuteries.push(minuterie);

        return minuterie.numero;
    };
    window.clearTimeout = (numero) => {
        const minuterie = minuteries.find((m) => m.numero === numero);

        if (minuterie) {
            minuterie.annulee = true;
        }
    };
    window.setInterval = () => prochainNumero++;

    /* Ce que les modules signalent a la console, retenu pour qu un bruit se voie. */
    const erreurs = [];
    window.console.error = (...morceaux) => { erreurs.push(morceaux); };

    let cache = false;
    Object.defineProperty(window.document, 'hidden', { get: () => cache, configurable: true });

    for (const source of [BANDEAU, LISTE]) {
        const script = window.document.createElement('script');
        script.textContent = readFileSync(source, 'utf8');
        window.document.body.appendChild(script);
    }

    window.document.dispatchEvent(new window.Event('DOMContentLoaded'));

    /** Le vrai chemin : le bandeau demande, le serveur repond, le bandeau annonce. */
    const leServeurRepond = (planetList) => {
        window.getAjaxResourcebox();
        const demande = demandes[demandes.length - 1];

        assert.ok(demande, 'le bandeau n a rien demande');
        demande.repondre(uneReponse(planetList));
    };

    const laCle = (id) => window.document.querySelector('.smallplanet[data-planet-id="' + id + '"] a.constructionIcon');
    // **Le faux porte la regle du vrai** : une minuterie declenchee n est plus active, comme un vrai `setTimeout`.
    const actives = () => minuteries.filter((m) => !m.annulee && !m.tiree);
    const declencher = (minuterie) => {
        minuterie.tiree = true;
        minuterie.fonction();
    };

    return {
        window,
        demandes,
        minuteries,
        actives,
        declencher,
        erreurs,
        laCle,
        leServeurRepond,
        cacher: () => { cache = true; },
        fermer: () => window.close()
    };
}

test('une construction terminee eteint la cle sans recharger la page', () => {
    const monde = unMonde();

    assert.ok(monde.laCle(11), 'le monde ne part pas d une cle allumee : l essai ne prouverait rien');

    monde.leServeurRepond({ constructions: [], nextChangeIn: null });

    assert.equal(monde.laCle(11), null, 'La cle reste allumee apres une reponse qui dit que plus rien ne se construit.');

    monde.fermer();
});

test('une construction lancee ailleurs allume la cle, au meme endroit et vers la meme planete que le gabarit', () => {
    const monde = unMonde();

    monde.leServeurRepond({ constructions: [{ planetId: 11, downgrade: false }, { planetId: 12, downgrade: false }], nextChangeIn: null });

    const cle = monde.laCle(12);
    const planete = monde.window.document.querySelector('.smallplanet[data-planet-id="12"]');
    const lien = planete.querySelector('a.planetlink');

    assert.ok(cle, 'Une construction annoncee sur la planete 12 n allume aucune cle.');
    assert.equal(lien.nextElementSibling, cle, 'La cle n est pas posee juste apres le lien de la planete, comme le gabarit la pose.');
    assert.equal(cle.getAttribute('href'), lien.getAttribute('href'), 'La cle ne mene pas a sa planete.');
    assert.equal(cle.getAttribute('data-link'), lien.getAttribute('href'));
    assert.equal(cle.querySelector('span').className, 'icon12px icon_wrench');
    assert.equal(planete.querySelectorAll('a.constructionIcon').length, 1);
    assert.ok(monde.laCle(11), 'La cle de la planete 11, toujours en construction, a ete retiree.');
    assert.equal(monde.window.document.querySelectorAll('a.constructionIcon').length, 2);

    monde.fermer();
});

test('une demolition rend la cle rouge, et la couleur suit le travail sans dupliquer la cle', () => {
    const monde = unMonde();

    monde.leServeurRepond({ constructions: [{ planetId: 11, downgrade: true }], nextChangeIn: null });
    assert.equal(monde.laCle(11).querySelector('span').className, 'icon12px icon_wrench_red', 'Une demolition ne rend pas la cle rouge.');

    monde.leServeurRepond({ constructions: [{ planetId: 11, downgrade: false }], nextChangeIn: null });
    assert.equal(monde.laCle(11).querySelector('span').className, 'icon12px icon_wrench', 'La cle reste rouge apres la fin de la demolition.');

    const planete = monde.window.document.querySelector('.smallplanet[data-planet-id="11"]');
    assert.equal(planete.querySelectorAll('a.constructionIcon').length, 1, 'La cle a ete dupliquee.');
    assert.equal(planete.querySelectorAll('a.constructionIcon span').length, 1);

    monde.fermer();
});

test('le module redemande le bandeau une seconde apres l echeance annoncee, et une echeance remplace la precedente', () => {
    const monde = unMonde();

    monde.leServeurRepond({ constructions: [{ planetId: 11, downgrade: false }], nextChangeIn: 42 });

    assert.equal(monde.actives().length, 1, 'Aucune relecture n est programmee a l echeance annoncee.');
    assert.equal(monde.actives()[0].delai, 43000, 'La relecture n est pas programmee une seconde apres l echeance.');

    const premiere = monde.actives()[0];
    const demandesAvant = monde.demandes.length;

    monde.declencher(premiere);

    assert.equal(monde.demandes.length, demandesAvant + 1, 'A l echeance, le bandeau n est pas redemande : la cle attendrait la veille.');

    monde.demandes[monde.demandes.length - 1].repondre(uneReponse({ constructions: [], nextChangeIn: 7 }));
    assert.equal(monde.laCle(11), null);
    assert.deepEqual(monde.actives().map((m) => m.delai), [8000]);

    monde.leServeurRepond({ constructions: [], nextChangeIn: null });
    assert.equal(monde.actives().length, 0, 'Une echeance perimee reste programmee apres un etat qui n en annonce plus.');

    monde.fermer();
});

test('au chargement, l echeance vient de la liste des planetes, sans aucune requete', () => {
    const monde = unMonde({ echeanceAuChargement: 5 });

    assert.equal(monde.demandes.length, 0, 'Le chargement de la page a demande le bandeau : une requete de plus par clic.');
    assert.deepEqual(monde.actives().map((m) => m.delai), [6000], 'L echeance posee par le gabarit n est pas programmee.');

    monde.fermer();
});

test('une echeance longue est plafonnee a une heure', () => {
    const monde = unMonde();

    monde.leServeurRepond({ constructions: [{ planetId: 11, downgrade: false }], nextChangeIn: 90000 });

    assert.deepEqual(monde.actives().map((m) => m.delai), [3601000]);

    monde.fermer();
});

test('un onglet cache ne demande rien a l echeance', () => {
    const monde = unMonde({ echeanceAuChargement: 5 });

    monde.cacher();
    monde.declencher(monde.actives()[0]);

    assert.equal(monde.demandes.length, 0, 'Un onglet cache a redemande le bandeau.');

    monde.fermer();
});

/*
 * **Et elle ne fait aucun bruit.** Pendant un deploiement, une page servie par le nouveau bundle peut interroger un
 * serveur qui ne porte pas encore l etat : une trace dans la console toutes les trente secondes n apprendrait rien.
 */
test('une reponse sans etat lisible ne touche a aucune cle', () => {
    for (const illisible of [undefined, null, {}, { constructions: 'aucune' }]) {
        const monde = unMonde();

        monde.leServeurRepond(illisible);

        assert.ok(monde.laCle(11), 'Une reponse sans etat lisible a eteint la cle : ' + JSON.stringify(illisible));
        assert.equal(monde.erreurs.length, 0, 'Une reponse sans etat lisible fait du bruit dans la console : ' + JSON.stringify(illisible));

        monde.fermer();
    }
});

test('une planete que la page ne montre pas est ignoree', () => {
    const monde = unMonde();

    monde.leServeurRepond({ constructions: [{ planetId: 99, downgrade: false }], nextChangeIn: null });

    assert.equal(monde.laCle(11), null);
    assert.equal(monde.window.document.querySelectorAll('a.constructionIcon').length, 0, 'Une cle a ete posee pour une planete absente de la liste.');

    monde.fermer();
});
