/*
 * Ce que le glisser d'une patrouille fait REELLEMENT a la fiche.
 *
 * ## Le defaut que ce harnais ferme
 *
 * La fiche fait 320 px, elle suit son marqueur, et `dragover` comme `drop` ignorent volontairement
 * tout evenement qui tombe sur `.gtCard` — pour qu'un relachement sur le panneau ne designe pas une
 * destination absurde. Consequence non voulue : la portion de systeme que la fiche recouvrait etait
 * **litteralement inatteignable au glisser**. Keven l'a constate au controle navigateur du
 * 11 septembre 2026 — le geste marchait, mais pas partout.
 *
 * La fiche s'efface donc le temps du geste : la feuille lui retire le pointeur et presque toute son
 * opacite sous `#galaxyTactical.gtDragging`.
 *
 * ## Ce que ce harnais prouve, et ce qu'il ne prouve pas
 *
 * Il prouve **la bascule** : la classe naît au debut du geste, et elle meurt a sa fin — quelle que
 * soit cette fin. C'est la moitie qui peut se tromper : un `dragend` pose sur chaque marqueur serait
 * perdu avec lui au premier rafraichissement, et la carte resterait effacee pour de bon.
 *
 * Il ne prouve pas l'effet visuel ni le test de survol : jsdom n'a pas de moteur de rendu, et il ne
 * choisit pas la cible d'un evenement par `pointer-events` — c'est l'essai qui la nomme. La regle de
 * style est donc epinglee a part, dans `GalaxyTacticalMapTest`, et le controle visuel de Keven reste
 * necessaire et distinct.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const SOURCE = new URL('../../resources/js/ingame/galaxy-tactical.js', import.meta.url);

/**
 * Un faux jQuery qui retient ses demandes au lieu de les envoyer.
 */
function faireJQuery() {
    const demandes = [];

    /*
     * **Les devis partent par `post`, pas par `getJSON`.** Compter `demandes` pour savoir si un devis
     * est parti mesurait la couche des flottes : l assertion « aucun devis n a demarre » etait verte
     * quoi qu il arrive. Un canal qu on ne mesure pas ne prouve rien de ce qui y passe.
     */
    const envois = [];

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
            repondre(reponse) { if (rappels.done) { rappels.done(reponse); } },
            echouer(erreur) { if (rappels.fail) { rappels.fail(erreur); } }
        });

        return api;
    };

    jq.post = function (url, donnees) {
        envois.push({ url, donnees });

        return chainable();
    };

    jq.ajax = chainable;

    return { jq, demandes, envois };
}

/**
 * Un monde complet : document, module charge, carte amorcee sur un systeme.
 */
function unMonde() {
    const dom = new JSDOM('<!doctype html><html><body><div id="galaxyTactical"></div></body></html>', {
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        url: 'https://exemple.test/galaxy'
    });

    const { window } = dom;
    const { jq, demandes, envois } = faireJQuery();

    window.jQuery = jq;
    window.$ = jq;
    window.galaxyFleetsUrl = '/ajax/galaxy/fleets';
    window.galaxyContentLink = '/ajax/galaxy';
    /* Sans cette adresse, `choisirLaDestination()` sort avant de demander quoi que ce soit. */
    window.galaxyPatrolQuoteUrl = '/ajax/galaxy/patrol/quote';
    window.playerId = 7;
    window.token = 'jeton';
    window.galaxyTacticalLoca = {};
    window.renderContentGalaxy = function () {};

    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    const amorcer = (galaxie, systeme, lignes = []) => {
        window.renderContentGalaxy({ system: { galaxy: galaxie, system: systeme, galaxyContent: lignes } });
    };

    const carte = () => window.document.getElementById('galaxyTactical');
    const marqueur = (id) => window.document.querySelector('.gtPatrolMarker[data-patrol-id="' + id + '"]');
    const fiche = () => window.document.querySelector('.gtCard');
    const efface = () => carte().classList.contains('gtDragging');

    /*
     * **Fermer le document est indispensable** : le module arme des minuteries (orbites, veille des
     * compteurs) qui gardent la boucle d'evenements de Node vivante.
     */
    const fermer = () => window.close();

    /* Un evenement de glisser tel que le module le lit : il ne touche `dataTransfer` que s'il existe. */
    const geste = (cible, nom) => {
        cible.dispatchEvent(new window.Event(nom, { bubbles: true, cancelable: true }));
    };

    const relacher = (cible, x, y) => {
        cible.dispatchEvent(new window.MouseEvent('drop', {
            bubbles: true,
            cancelable: true,
            clientX: x,
            clientY: y
        }));
    };

    return { window, demandes, envois, amorcer, carte, marqueur, fiche, efface, fermer, geste, relacher };
}

/**
 * Une patrouille telle que `PatrolProjection` la compose. Les champs sont ceux du serveur, pas ceux
 * qui rendraient l'essai commode : un montage qui invente sa charge utile ne prouve rien du jeu.
 */
function unePatrouille({ id = 3, galaxie = 1, systeme = 5, deplacementPermis = true } = {}) {
    const bout = (x, y) => ({ galaxy: galaxie, system: systeme, position: 0, type: 5, x, y });

    return {
        id,
        state: 'stationed',
        state_label: 'Stationnee',
        galaxy: galaxie,
        system: systeme,
        point: { x: -660, y: 580 },
        segment: {
            id: 900 + id,
            from: bout(-660, 580),
            to: bout(-660, 580),
            time_departure: 1_700_000_000,
            time_arrival: 1_700_000_060
        },
        units: [{ id: 204, label: 'Chasseur leger', amount: 5 }],
        cargo: { metal: 0, crystal: 0, deuterium: 0 },
        fuel_reserve: 188.69,
        upkeep_per_hour: 5,
        safety_return_cost: 1,
        safety_return_at: 1_700_100_000,
        stationed_since: 1_700_000_060,
        order_version: 1,
        home: { galaxy: galaxie, system: systeme, position: 4 },
        commands: {
            move: deplacementPermis
                ? { allowed: true, reason_key: null, reason: null }
                : { allowed: false, reason_key: 'engaged', reason: 'Engagee dans un combat' },
            recall: { allowed: true, reason_key: null, reason: null }
        }
    };
}

function reponse(galaxie, systeme, patrouilles, maintenant = 1_700_000_100) {
    return {
        success: true,
        galaxy: galaxie,
        system: systeme,
        server_now: maintenant,
        movements: [],
        patrols: patrouilles,
        surveillance: [],
        counters: {}
    };
}

/** Un monde amorce, avec une patrouille posee et son marqueur saisissable. */
function unMondeAvecPatrouille(options = {}) {
    const monde = unMonde();

    monde.amorcer(1, 5);
    monde.demandes[0].repondre(reponse(1, 5, [unePatrouille(options)]));

    return monde;
}

/**
 * Une ligne de Galaxie telle que le serveur la rend : une position, un corps, un proprietaire.
 */
function uneLigne(position, { aMoi = true, bodyId = 4242, nom = 'Terra' } = {}) {
    const proprietaire = aMoi ? 7 : 9;

    return {
        position,
        playerId: proprietaire,
        planets: [{ planetType: 1, planetId: bodyId, planetName: nom, playerId: proprietaire }]
    };
}

/** Les intitules des boutons du panneau d'ordre, dans l'ordre ou ils s'affichent. */
function boutonsDuPanneau(monde) {
    return Array.from(monde.window.document.querySelectorAll('.patrol-button .gtActionLabel'))
        .map((e) => e.textContent);
}

/** Le bloc d'un corps sur la carte. */
function corps(monde, position) {
    return monde.window.document.querySelector('.gtBody[data-position="' + position + '"]');
}

/** Un monde amorce avec des lignes, une patrouille, et le geste deja commence. */
function unMondeEnTrainDeViser(lignes, options = {}) {
    const monde = unMonde();

    monde.amorcer(1, 5, lignes);
    monde.demandes[0].repondre(reponse(1, 5, [unePatrouille(options)]));
    monde.geste(monde.marqueur(3), 'dragstart');

    return monde;
}

test('la premisse : un corps de la carte existe et se depose dessus', () => {
    const monde = unMondeEnTrainDeViser([uneLigne(4)]);

    try {
        assert.ok(corps(monde, 4), 'la carte ne dessine aucun corps : les essais suivants ne prouveraient rien');
        assert.ok(monde.fiche(), 'la fiche ne s est pas ouverte au debut du geste');
    } finally {
        monde.fermer();
    }
});

/**
 * **Deposer sur un corps propose, il n'execute pas.** C'est la regle de toute la carte : le glisser
 * designe, le serveur chiffre, le joueur confirme. Un depot qui partirait tout seul serait la seule
 * action de la carte a le faire.
 */
test('deposer sur une planete a soi propose de stationner ou d atterrir', () => {
    const monde = unMondeEnTrainDeViser([uneLigne(4, { aMoi: true })]);

    try {
        monde.relacher(corps(monde, 4), 300, 200);

        const boutons = boutonsDuPanneau(monde);

        assert.ok(boutons.includes('Stationner a cote'), 'le choix « stationner a cote » n est pas propose : ' + boutons.join(' | '));
        assert.ok(boutons.includes('Atterrir'), 'le choix « atterrir » n est pas propose sur une planete a soi : ' + boutons.join(' | '));
        assert.equal(monde.envois.length, 0, 'un devis est parti tout seul : le depot a decide a la place du joueur');
    } finally {
        monde.fermer();
    }
});

/**
 * **Et sur la planete d'un autre, atterrir n'est pas offert.** Le serveur le refuserait de toute
 * facon — c'est la garde qui compte —, mais montrer un bouton qui echoue a coup sur serait mentir.
 */
test('deposer sur la planete d un autre ne propose pas d atterrir', () => {
    const monde = unMondeEnTrainDeViser([uneLigne(4, { aMoi: false })]);

    try {
        monde.relacher(corps(monde, 4), 300, 200);

        const boutons = boutonsDuPanneau(monde);

        assert.ok(boutons.includes('Stationner a cote'), 'surveiller la planete d un autre devrait rester possible');
        assert.equal(boutons.includes('Atterrir'), false, 'la carte propose de poser la flotte chez un adversaire');
    } finally {
        monde.fermer();
    }
});

/**
 * **Une orbite vide n'offre aucun choix**, et le depot reprend son chemin ordinaire vers le point de
 * l'espace. C'est la moitie que la correction pouvait casser sans bruit : tout deposer dans un choix
 * aurait supprime le stationnement en espace libre, qui est la raison d'etre des patrouilles.
 */
test('deposer sur une orbite vide chiffre directement un point de l espace', () => {
    const monde = unMondeEnTrainDeViser([uneLigne(4)]);

    try {
        // La position 9 n a pas de ligne : l orbite est libre.
        const vide = corps(monde, 9);
        assert.ok(vide, 'la premisse manque : la carte ne dessine pas l orbite libre');

        monde.relacher(vide, 300, 200);

        assert.equal(boutonsDuPanneau(monde).includes('Atterrir'), false, 'une orbite vide propose d atterrir sur rien');
        assert.equal(monde.envois.length, 1, 'le depot sur une orbite libre n a demande aucun devis');
        assert.equal(monde.envois[0].url, '/ajax/galaxy/patrol/quote', 'ce qui est parti n est pas une demande de devis');
    } finally {
        monde.fermer();
    }
});

test('la premisse : le marqueur existe et se saisit', () => {
    const monde = unMondeAvecPatrouille();

    try {
        const m = monde.marqueur(3);

        assert.ok(m, 'le marqueur de la patrouille n apparait pas : tout le reste du fichier ne prouverait rien');
        assert.equal(m.draggable, true, 'le marqueur ne se saisit pas a la souris');
        assert.equal(monde.efface(), false, 'la carte se croit deja en cours de glisser avant tout geste');
    } finally {
        monde.fermer();
    }
});

test('le debut du geste efface la fiche', () => {
    const monde = unMondeAvecPatrouille();

    try {
        monde.geste(monde.marqueur(3), 'dragstart');

        assert.ok(monde.fiche(), 'la fiche ne s est pas ouverte : le geste ne recouvrirait rien');
        assert.equal(monde.efface(), true, 'la fiche garde le pointeur pendant le geste : la zone qu elle couvre reste inatteignable');
    } finally {
        monde.fermer();
    }
});

test('la fin du geste rend la fiche, meme sans depot', () => {
    const monde = unMondeAvecPatrouille();

    try {
        monde.geste(monde.marqueur(3), 'dragstart');
        assert.equal(monde.efface(), true, 'la premisse manque : la fiche n etait pas effacee');

        monde.geste(monde.marqueur(3), 'dragend');

        assert.equal(monde.efface(), false, 'un geste abandonne laisse la fiche effacee pour toujours');
    } finally {
        monde.fermer();
    }
});

test('un depot rend la fiche', () => {
    const monde = unMondeAvecPatrouille();

    try {
        monde.geste(monde.marqueur(3), 'dragstart');
        monde.relacher(monde.carte(), 420, 260);

        assert.equal(monde.efface(), false, 'la fiche reste effacee apres un depot');
    } finally {
        monde.fermer();
    }
});

/**
 * **Le cas que l'ordre des lignes decide.** Le depot rend la fiche AVANT ses propres gardes : un
 * relachement sur le panneau est refuse comme destination — c'est voulu — mais il termine quand meme
 * le geste. Rendre la fiche apres la garde la laisserait effacee, et le joueur n'aurait plus qu'a
 * recharger la page.
 */
test('un depot refuse rend la fiche lui aussi', () => {
    const monde = unMondeAvecPatrouille();

    try {
        monde.geste(monde.marqueur(3), 'dragstart');

        const f = monde.fiche();
        assert.ok(f, 'la premisse manque : pas de fiche sur laquelle relacher');

        monde.relacher(f, 420, 260);

        assert.equal(monde.efface(), false, 'un depot tombe sur la fiche la laisse effacee');
    } finally {
        monde.fermer();
    }
});

/**
 * Un marqueur dont le deplacement est refuse n'entre jamais dans le geste : `dragstart` annule et
 * sort avant. Effacer la fiche la aurait ete doublement faux — elle porte precisement la raison du
 * refus, que le joueur doit lire.
 */
test('un deplacement refuse n efface rien', () => {
    const monde = unMondeAvecPatrouille({ deplacementPermis: false });

    try {
        monde.geste(monde.marqueur(3), 'dragstart');

        assert.ok(monde.fiche(), 'la fiche ne s ouvre pas pour dire le refus');
        assert.equal(monde.efface(), false, 'un geste refuse efface quand meme la fiche qui porte sa raison');
    } finally {
        monde.fermer();
    }
});
