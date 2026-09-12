/*
 * Ce que la couche de surveillance fait REELLEMENT dans un navigateur.
 *
 * ## Le module reel, jamais des fonctions extraites
 *
 * Eprouver des fonctions pures validerait une logique debranchee de l interface : elles pourraient
 * etre justes et n etre appelees par personne. Ce harnais charge donc `galaxy-tactical.js` tel quel
 * dans un document jsdom, l amorce par le vrai point d entree (`renderContentGalaxy`, que le module
 * enveloppe), et ne touche ensuite qu aux entrees du monde exterieur : les reponses reseau et les
 * evenements de connexion. Ce qui est observe est le DOM.
 *
 * ## Une mutation sans effet observable, et pourquoi elle le reste
 *
 * Remplacer la liste (`=`) ou la fusionner (`concat`) donne le MEME resultat, mais **sous deux
 * conditions, pas une** : que l invalidation vide la liste au depart de chaque requete, ET que le
 * rejet des reponses obsoletes fonctionne. Sans le second, une ancienne reponse s appliquerait
 * apres une recente et la fusion accumulerait ce que le remplacement aurait ecarte. Les deux
 * mecanismes sont eprouves ici — leurs mutations tombent — donc la mutation `concat` est
 * **equivalente dans le parcours teste**, et ne se compte pas parmi les tuees. Ce n est pas un
 * temoin qui manque.
 *
 * **Cela ne rend aucun des deux inutile** : ils agissent a des instants differents — l invalidation
 * protege pendant le vol de la requete, l affectation rend la reponse faisant autorite. Retirer
 * l invalidation se voit (cette mutation-la tombe). La forme `concat` reste interdite par un temoin
 * de forme sur le bundle servi (`SurveillanceBrowserLayerTest`), dont c est exactement le role.
 *
 * ## Ce qu il ne prouve pas
 *
 * Ni le rendu graphique, ni la mise en page, ni l ergonomie : jsdom n a pas de moteur de rendu. Le
 * controle visuel de Keven reste necessaire et distinct.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const SOURCE = new URL('../../resources/js/ingame/galaxy-tactical.js', import.meta.url);

/**
 * Un faux jQuery qui retient ses demandes au lieu de les envoyer.
 *
 * C est la seule facon d obtenir l ordre qui nous interesse : une requete partie, puis une seconde,
 * puis la reponse de la PREMIERE. Un vrai reseau ne se laisse pas ordonner ainsi.
 */
function faireJQuery() {
    const demandes = [];

    /*
     * **Les envois passent par `post`, et un faux muet ne prouve rien de ce qui y passe.** Ce banc
     * rendait une promesse qui ne se resolvait jamais : tout ce qui suit une reponse — un devis
     * affiche, un ordre confirme — etait inatteignable, et une assertion dessus serait verte quoi
     * qu il arrive. Le piege est le meme que celui deja paye sur le banc du glisser.
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
            /* Le faux porte la regle du vrai : jQuery appelle `always` apres `done` comme apres `fail`. */
            repondre(reponse) { if (rappels.done) { rappels.done(reponse); } if (rappels.always) { rappels.always(); } },
            echouer(erreur) { if (rappels.fail) { rappels.fail(erreur); } if (rappels.always) { rappels.always(); } }
        });

        return api;
    };

    jq.post = function (url, donnees) {
        const rappels = {};
        const api = {
            done(cb) { rappels.done = cb; return api; },
            fail(cb) { rappels.fail = cb; return api; },
            always(cb) { rappels.always = cb; return api; }
        };

        envois.push({
            url,
            donnees,
            /* Le faux porte la regle du vrai : jQuery appelle `always` apres `done` comme apres `fail`. */
            repondre(reponse) { if (rappels.done) { rappels.done(reponse); } if (rappels.always) { rappels.always(); } },
            echouer(erreur) { if (rappels.fail) { rappels.fail(erreur); } if (rappels.always) { rappels.always(); } }
        });

        return api;
    };

    jq.ajax = chainable;

    return { jq, demandes, envois };
}

/**
 * Une connexion de diffuseur pilotable : on declenche ses etats a la main.
 */
function faireConnexion() {
    const abonnes = {};

    return {
        connexion: {
            bind(etat, rappel) {
                abonnes[etat] = abonnes[etat] || [];
                abonnes[etat].push(rappel);
            }
        },
        declencher(etat) {
            (abonnes[etat] || []).forEach((rappel) => rappel());
        }
    };
}

/**
 * Un monde complet : document, module charge, carte amorcee sur un systeme.
 */
function unMonde({ avecDiffuseur = true } = {}) {
    const dom = new JSDOM('<!doctype html><html><body><div id="galaxyTactical"></div></body></html>', {
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        url: 'https://exemple.test/galaxy'
    });

    const { window } = dom;
    const { jq, demandes, envois } = faireJQuery();
    const diffuseur = faireConnexion();

    window.jQuery = jq;
    window.$ = jq;

    // Les globales que la page publie normalement.
    window.galaxyFleetsUrl = '/ajax/galaxy/fleets';
    window.galaxyContentLink = '/ajax/galaxy';
    window.playerId = 7;
    window.token = 'jeton';
    window.galaxyTacticalLoca = {};
    window.renderContentGalaxy = function () {};
    /* Les adresses que la vue publie pour les ordres de patrouille : sans elles, rien ne part. */
    /* Les vaisseaux du corps actif : sans eux, la composition depuis un corps ne rend aucune ligne. */
    window.galaxyPatrolShips = [
        { id: 206, title: 'Croiseur', name: 'cruiser', amount: 20, mobile: true }
    ];
    window.galaxyPatrolQuoteUrl = '/ajax/galaxy/patrol/quote';
    window.galaxyPatrolAttackUrl = '/ajax/galaxy/patrol/attack';

    /*
     * Les ecouteurs du diffuseur sont retenus, pas ignores : `FleetMovementChanged` est le seul
     * declencheur qui recharge la couche SANS redessiner la carte. Un redessin effacerait le DOM et
     * ferait disparaitre les contacts pour une raison etrangere a ce qu on juge.
     */
    const ecouteurs = {};

    if (avecDiffuseur) {
        window.Echo = {
            private: (nom) => ({
                listen: (evenement, rappel) => { ecouteurs[nom + evenement] = rappel; }
            }),
            leave: () => {},
            connector: { pusher: { connection: diffuseur.connexion } }
        };
    }

    /*
     * **Les veilles sont retenues, pas attendues.** La veille de la carte tourne toutes les dix
     * secondes ; un essai qui l attendrait durerait dix secondes. Le monde garde chaque minuterie
     * armee, et l essai declenche celle qu il vise.
     */
    const veilles = [];
    const setIntervalReel = window.setInterval.bind(window);

    window.setInterval = (fonction, delai) => {
        veilles.push({ fonction, delai });

        return setIntervalReel(fonction, delai);
    };

    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    const amorcer = (galaxie, systeme, lignes = []) => {
        window.renderContentGalaxy({ system: { galaxy: galaxie, system: systeme, galaxyContent: lignes } });
    };

    const contacts = () => Array.from(window.document.querySelectorAll('.gtSurveillanceContact'));

    /*
     * **Fermer le document est indispensable.** Le module arme des minuteries — orbites, veille des
     * compteurs — qui gardent la boucle d evenements de Node vivante : sans fermeture, le harnais ne
     * se termine jamais. Mesure faite : le premier passage a tourne jusqu au delai d expiration.
     */
    const fermer = () => window.close();

    const unMouvementAnnonce = (galaxie, systeme) => {
        const rappel = ecouteurs['galaxy.player.7.FleetMovementChanged'];
        if (!rappel) { throw new Error('le module ne s est pas abonne aux mouvements du joueur'); }
        rappel({ from: { galaxy: galaxie, system: systeme }, to: { galaxy: galaxie, system: systeme } });
    };

    /* La fiche ouverte, et les boutons qu elle propose. */
    const fiche = () => window.document.querySelector('.gtCard');
    const boutons = () => Array.from(window.document.querySelectorAll('.gtCard .gtAction--attack'));

    const cliquer = (cible) => {
        cible.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    };

    return { window, demandes, envois, diffuseur, veilles, amorcer, contacts, fiche, boutons, cliquer, fermer, unMouvementAnnonce };
}

/**
 * Une reponse du serveur portant ces contacts.
 */
function reponse(galaxie, systeme, contacts, maintenant = 1_700_000_000, patrouilles = [], mouvements = []) {
    return {
        success: true,
        galaxy: galaxie,
        system: systeme,
        server_now: maintenant,
        movements: mouvements,
        patrols: patrouilles,
        surveillance: contacts,
        counters: {}
    };
}

/**
 * Un retour de rappel : meme route, parcourue a l envers, avec la part d aller deja faite.
 *
 * Le serveur cree le retour **depuis la cible** — c est son modele — et la duree du retour vaut
 * exactement le temps que l aller a consomme.
 */
function unRetourRappele(fraction, route = uneRoute()) {
    const dureeAller = route.time_arrival - route.time_departure;
    const rappel = route.time_departure + Math.round(dureeAller * fraction);

    return {
        id: 778,
        mission_type: 3,
        label: 'Transport',
        side: 'friendly',
        is_return: true,
        patrol_id: null,
        from: route.to,
        to: route.from,
        time_departure: rappel,
        time_arrival: rappel + Math.round(dureeAller * fraction),
        recall_progress: fraction
    };
}
/** Une ligne de Galaxie telle que le serveur la rend : une position, un corps, un proprietaire. */
function uneLigne(position) {
    return { position, playerId: 9, planets: [{ planetType: 1, planetId: 5000 + position, planetName: 'Terra', playerId: 9 }] };
}

/** Un bout de segment : la planete en position 4, ou un point de l espace. */
const PLANETE_4 = { galaxy: 1, system: 5, position: 4, type: 1, x: null, y: null };
const POINT = { galaxy: 1, system: 5, position: 0, type: 5, x: -660, y: 580 };

/** La route de la planete 4 au point, en vol autour de l instant 1_700_000_000. */
function uneRoute(depart = 1_699_999_700, arrivee = 1_700_000_300) {
    return { from: PLANETE_4, to: POINT, time_departure: depart, time_arrival: arrivee };
}

/**
 * Un mouvement du joueur sur la meme route, tel que `FleetMovementProjection` le compose : c est
 * lui, dessine par la couche des flottes, qui sert d etalon au contact — meme point, meme cap.
 */
function unMouvementSurLaRoute(route = uneRoute()) {
    return {
        id: 777,
        mission_type: 3,
        label: 'Transport',
        side: 'friendly',
        is_return: false,
        patrol_id: null,
        from: route.from,
        to: route.to,
        time_departure: route.time_departure,
        time_arrival: route.time_arrival
    };
}

/**
 * Une patrouille du joueur telle que `PatrolProjection` la compose, avec son verdict de frappe.
 *
 * Les champs sont ceux du serveur, pas ceux qui rendraient l essai commode : un montage qui invente
 * sa charge utile ne prouve rien du jeu.
 */
function unePatrouille({ id = 3, numero = 1, frappePermise = true, x = -660, y = 580 } = {}) {
    return {
        id,
        number: numero,
        state: 'stationed',
        state_label: 'Stationnee',
        galaxy: 1,
        system: 5,
        point: { x, y },
        segment: {
            id: 900 + id,
            from: { galaxy: 1, system: 5, position: 0, type: 5, x: -660, y: 580 },
            to: { galaxy: 1, system: 5, position: 0, type: 5, x: -660, y: 580 },
            time_departure: 1_699_999_000,
            time_arrival: 1_699_999_900
        },
        units: [{ id: 204, label: 'Chasseur leger', amount: 5 }],
        cargo: { metal: 0, crystal: 0, deuterium: 0 },
        fuel_reserve: 500,
        upkeep_per_hour: 5,
        safety_return_cost: 1,
        safety_return_at: 1_700_100_000,
        stationed_since: 1_699_999_900,
        order_version: 4,
        home: { galaxy: 1, system: 5, position: 4 },
        commands: {
            move: { allowed: true, reason_key: null, reason: null },
            recall: { allowed: true, reason_key: null, reason: null },
            attack: frappePermise
                ? { allowed: true, reason_key: null, reason: null }
                : { allowed: false, reason_key: 'must_be_stationed_to_attack', reason: 'Seule une patrouille posee peut frapper.' }
        }
    };
}

/**
 * Un contact tel que `SurveillanceProjection` le compose depuis le 12 septembre 2026 : sa position
 * (nulle pendant un vol), sa relation, et sa route dans le systeme quand il en a une.
 */
function unContact(id, x, y, { tier = 1, relation = 'stranger', segment = null } = {}) {
    const contact = { contact_id: id, tier, computed_at: 1_700_000_000, relation, position: { galaxy: 1, system: 5, x, y } };

    if (segment) {
        contact.segment = segment;
    }

    return contact;
}

/** L angle d une rotation, ecrite en SVG (`rotate(12.3)`) ou en CSS (`rotate(12.3deg)`). */
function angleDe(rotation) {
    const m = /rotate\((-?[\d.]+)(?:deg)?\)/.exec(rotation || '');

    return m ? Number(m[1]) : null;
}

test('le module s amorce et demande la couche des flottes', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);

        assert.equal(monde.demandes.length, 1, 'la carte amorcee ne demande pas la couche des flottes');
        assert.equal(monde.demandes[0].url, '/ajax/galaxy/fleets');
    } finally {
        monde.fermer();
    }
});

/**
 * **Un contact autorise apparait — la ou la carte projette son point.**
 *
 * La premiere version de ce temoin exigeait `left: 640px` pour un contact a x = 640 : elle
 * epinglait le defaut. Le serveur donne des **unites** du systeme (un point de patrouille vaut par
 * exemple −660), et la carte les projette — comme elle le fait pour les patrouilles du joueur. Le
 * temoin compare donc au marqueur d une patrouille posee au **meme point** : egalite des deux
 * sources, jamais un nombre.
 */
test('un contact autorise apparait, la ou la carte projette son point', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, -660, 580)], 1_700_000_000, [unePatrouille()]));

        const vus = monde.contacts();
        const patrouille = monde.window.document.querySelector('.gtPatrolMarker[data-patrol-id="3"]');

        assert.equal(vus.length, 1, 'le contact autorise n apparait pas');
        assert.equal(vus[0].getAttribute('data-contact-id'), '11');
        assert.ok(patrouille, 'la premisse manque : aucune patrouille posee a comparer');
        assert.notEqual(vus[0].style.left, '-660px', 'le contact est place en unites brutes : hors carte');
        assert.equal(vus[0].style.left, patrouille.style.left, 'le contact n est pas la ou la carte projette son point (x)');
        assert.equal(vus[0].style.top, patrouille.style.top, 'le contact n est pas la ou la carte projette son point (y)');
    } finally {
        monde.fermer();
    }
});

test('une liste vide retire ce qui etait affiche', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));
        assert.equal(monde.contacts().length, 1, 'la premisse manque : rien n etait affiche');

        // Un evenement provoque un rechargement, et le serveur ne rend plus rien.
        monde.window.dispatchEvent(new monde.window.Event('online'));
        monde.diffuseur.declencher('disconnected');
        monde.diffuseur.declencher('connected');

        const derniere = monde.demandes[monde.demandes.length - 1];
        derniere.repondre(reponse(1, 5, []));

        assert.equal(monde.contacts().length, 0, 'la liste vide n a pas retire les contacts affiches');
    } finally {
        monde.fermer();
    }
});

/*
 * **Isoler le depart de la requete de la coupure de connexion.**
 *
 * La premiere version de cet essai provoquait le rechargement par une coupure — qui masque elle
 * aussi. Le masquage observe venait donc d elle, et la mutation qui retire l invalidation au depart
 * de la requete y survivait. Ici le rechargement vient d un simple redessin du MEME systeme :
 * aucune coupure, aucun changement de systeme, et `demarrerLaCoucheFlottes` ne remet a zero que les
 * mouvements et les patrouilles — jamais les contacts. Seule l invalidation dans la demande peut
 * donc vider la couche.
 */
/**
 * **Une simple demande ne masque plus la couche ; la reponse la met a jour sur place.**
 *
 * La premiere version de ce temoin exigeait le contraire — la couche vidée des le depart de la
 * demande (revue 124 de Codex). Avec une veille toutes les dix secondes, ce masquage faisait
 * clignoter chaque contact dix fois par minute. Decision de Keven, 12 septembre 2026 : tout ce qui
 * se passe sur la carte en temps reel. La revocation est appliquee a la reponse (temoin de la liste
 * vide), la reponse perimee reste jetee (temoin suivant), et le masquage immediat demeure a la
 * perte de connexion et au retour d onglet (deux temoins plus bas).
 *
 * Meme montage qu avant : une annonce de mouvement recharge la couche SANS redessiner la carte.
 */
test('une simple demande ne masque plus la couche, et la reponse la met a jour sans la recreer', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480), unContact(12, 100, 100)]));
        assert.equal(monde.contacts().length, 2);

        const avant = monde.contacts()[0];
        const imageAvant = avant.querySelector('img');

        monde.unMouvementAnnonce(1, 5);

        assert.ok(monde.demandes.length > 1, 'l annonce n a pas relance de demande : le cas ne prouverait rien');
        assert.equal(monde.contacts().length, 2, 'les contacts sont masques pendant le vol de la requete : ils clignotent a chaque veille');

        // La reponse : le contact 11 reste, le 12 est revoque.
        monde.demandes[monde.demandes.length - 1].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_010));

        assert.equal(monde.contacts().length, 1, 'la revocation n est pas appliquee a la reponse');
        assert.strictEqual(monde.contacts()[0], avant, 'le contact qui reste a ete recree : il a clignote');
        assert.strictEqual(monde.contacts()[0].querySelector('img'), imageAvant, 'son glyphe a ete recree');
    } finally {
        monde.fermer();
    }
});
test('une ancienne reponse arrivant apres une revocation ne reintroduit rien', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);

        const ancienne = monde.demandes[0];

        // Une coupure survient : la couche est masquee, et une nouvelle demande part au retour.
        monde.diffuseur.declencher('disconnected');
        monde.diffuseur.declencher('connected');

        const nouvelle = monde.demandes[monde.demandes.length - 1];
        assert.notEqual(nouvelle, ancienne, 'aucune nouvelle demande : le scenario ne prouverait rien');

        // Le serveur repond a la NOUVELLE : plus aucun droit.
        nouvelle.repondre(reponse(1, 5, []));
        assert.equal(monde.contacts().length, 0);

        // Puis l ANCIENNE arrive, chargee de ce que le joueur n a plus le droit de voir.
        ancienne.repondre(reponse(1, 5, [unContact(11, 640, 480)]));

        assert.equal(
            monde.contacts().length,
            0,
            'une reponse tardive a rehabille des renseignements revoques'
        );
    } finally {
        monde.fermer();
    }
});

test('un changement de systeme rejette la reponse de l ancien', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        const ancienne = monde.demandes[0];

        monde.amorcer(1, 6);
        const nouvelle = monde.demandes[monde.demandes.length - 1];

        assert.notEqual(nouvelle, ancienne, 'le changement de systeme n a pas relance de demande');

        ancienne.repondre(reponse(1, 5, [unContact(11, 640, 480)]));

        assert.equal(
            monde.contacts().length,
            0,
            'la reponse de l ancien systeme s est affichee sur le nouveau'
        );

        nouvelle.repondre(reponse(1, 6, [unContact(22, 100, 200)]));

        const vus = monde.contacts();
        assert.equal(vus.length, 1);
        assert.equal(vus[0].getAttribute('data-contact-id'), '22', 'ce n est pas le contact du systeme affiche');
    } finally {
        monde.fermer();
    }
});

/**
 * **C est l echec qui masque, pas une coupure.** La premiere version de ce temoin provoquait la
 * demande par une deconnexion — qui masque elle aussi —, et restait verte alors que rien ne
 * masquait a l echec (relecture du lot). Ici la demande vient d une annonce de mouvement, qui ne
 * masque rien : seul l echec peut vider la couche.
 */
test('une requete echouee laisse la couche vide plutot que l ancien contenu', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));
        assert.equal(monde.contacts().length, 1);

        monde.unMouvementAnnonce(1, 5);
        assert.equal(monde.contacts().length, 1, 'la premisse manque : la demande a masque avant son echec');

        const derniere = monde.demandes[monde.demandes.length - 1];
        derniere.echouer({ status: 500 });

        assert.equal(
            monde.contacts().length,
            0,
            'une requete echouee laisse a l ecran des renseignements que rien ne confirme'
        );
    } finally {
        monde.fermer();
    }
});

test('la perte de connexion masque avant meme qu une demande parte', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));
        assert.equal(monde.contacts().length, 1);

        const avant = monde.demandes.length;
        monde.diffuseur.declencher('disconnected');

        assert.equal(monde.demandes.length, avant, 'la perte de connexion a lance une demande : elle ne le peut pas');
        assert.equal(
            monde.contacts().length,
            0,
            'la connexion est perdue et les contacts restent affiches : rien ne peut plus confirmer leur validite'
        );
    } finally {
        monde.fermer();
    }
});

/*
 * ## Le temoin decisif de la revue 124, point 3
 *
 * Les temoins precedents lancaient TOUJOURS une nouvelle demande apres l invalidation. Comparer a
 * la derniere demande suffisait alors, et le scenario passait par accident : c est exactement ce que
 * Codex a nomme. Ici, rien ne repart. Seule une generation de contexte incrementee par
 * l invalidation elle-meme peut rejeter la reponse en vol.
 */
test('une invalidation sans nouvelle demande perime quand meme la demande en vol', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);

        const enVol = monde.demandes[monde.demandes.length - 1];
        const avant = monde.demandes.length;

        // La revocation arrive pendant le vol. Rien ne repart : la connexion est perdue.
        monde.diffuseur.declencher('disconnected');

        assert.equal(monde.demandes.length, avant, 'une demande est repartie : le temoin ne prouverait plus rien');

        // La reponse partie AVANT la revocation arrive maintenant.
        enVol.repondre(reponse(1, 5, [unContact(11, 640, 480)]));

        assert.equal(
            monde.contacts().length,
            0,
            'une demande en vol au moment de la revocation a rehabille des renseignements revoques'
        );
    } finally {
        monde.fermer();
    }
});

/*
 * L ordre exact decrit par Codex : reponse affichee, demande en vol, invalidation, seconde demande,
 * puis la PREMIERE arrive avant la seconde. Elle ne doit rien reafficher, et la seconde doit
 * pouvoir s afficher ensuite.
 *
 * **Ce temoin-ci passait deja sur l ancien code, et il faut le dire** : mesure faite en restaurant
 * le comportement d avant la revue 124, seul le temoin precedent tombe. La seconde demande
 * incrementait le compteur, donc la premiere se trouvait perimee par accident. C est precisement ce
 * que Codex a nomme — l exemple litteral etait couvert sans que la regle le soit. Il reste ecrit
 * parce qu il fixe l ordre d arrivee decrit dans la revue et qu il tombe sur d autres mutations
 * (retirer la comparaison de generation), mais il ne prouve pas la correction : c est le temoin
 * sans nouvelle demande qui la prouve.
 */
test('une demande d avant la revocation arrivant avant la demande d apres ne reaffiche rien', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[monde.demandes.length - 1].repondre(reponse(1, 5, [unContact(11, 640, 480)]));
        assert.equal(monde.contacts().length, 1, 'le point de depart n est pas celui du scenario');

        monde.amorcer(1, 5);
        const avantRevocation = monde.demandes[monde.demandes.length - 1];

        monde.diffuseur.declencher('disconnected');
        monde.diffuseur.declencher('connected');

        const apresRevocation = monde.demandes[monde.demandes.length - 1];
        assert.notEqual(apresRevocation, avantRevocation, 'aucune demande apres la reconnexion : le scenario est incomplet');

        // La plus ancienne arrive la premiere, chargee de ce que le joueur n a plus le droit de voir.
        avantRevocation.repondre(reponse(1, 5, [unContact(11, 640, 480)]));

        assert.equal(monde.contacts().length, 0, 'la reponse d avant la revocation s est affichee');

        // La plus recente arrive ensuite : elle, fait foi.
        apresRevocation.repondre(reponse(1, 5, [unContact(22, 100, 200)]));

        const vus = monde.contacts();
        assert.equal(vus.length, 1, 'la reponse courante n a pas ete affichee');
        assert.equal(vus[0].getAttribute('data-contact-id'), '22');
    } finally {
        monde.fermer();
    }
});

/**
 * **Une patrouille posee est offerte comme point de depart d une frappe.**
 *
 * C est le geste neuf du chantier : une patrouille voyait une cible sans aucun moyen de l atteindre.
 * Le bouton la propose ; le serveur decide de tout le reste.
 */
test('la fiche d un contact offre la frappe depuis une patrouille posee', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()]));

        const marqueur = monde.contacts()[0];

        assert.ok(marqueur, 'la premisse manque : aucun contact affiche');

        monde.cliquer(marqueur);

        const offerts = monde.boutons().filter((b) => !b.disabled);

        assert.ok(
            offerts.some((b) => (b.textContent || '').indexOf('Patrouille 1') !== -1),
            'aucune frappe depuis la patrouille n est offerte : ' + monde.boutons().map((b) => b.textContent).join(' | ')
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Le cas comparable, et il compte autant.** Sans lui, une fiche qui offrirait tout — y compris ce
 * que le serveur refuserait — passerait le temoin precedent.
 *
 * Une action ne s offre que si elle peut aboutir : refusee, elle reste visible mais grisee, et elle
 * **dit pourquoi**. La raison vient du serveur, jamais d une phrase inventee ici.
 */
test('une frappe refusee est grisee et porte la raison du serveur', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(
            reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille({ frappePermise: false })])
        );

        monde.cliquer(monde.contacts()[0]);

        const bouton = monde.boutons().find((b) => (b.textContent || '').indexOf('Patrouille 1') !== -1);

        assert.ok(bouton, 'la patrouille refusee a disparu de la fiche au lieu d etre grisee');
        assert.equal(bouton.disabled, true, 'la frappe refusee est cliquable : le joueur cliquerait dans le vide');

        const raison = bouton.parentNode.querySelector('.gtActionReason');

        assert.ok(raison && !raison.hidden, 'la raison du refus n est pas affichee');
        assert.equal(
            raison.textContent,
            'Seule une patrouille posee peut frapper.',
            'la raison affichee n est pas celle que le serveur a donnee'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Chiffrer puis confirmer, comme tout ordre de patrouille.**
 *
 * Le devis porte la version d ordre et le cout ; la confirmation les **rapporte**, et le serveur
 * refuse un devis perime au lieu de debiter autre chose que ce que le joueur a lu. Une frappe
 * confirmee sans avoir ete lue serait le seul ordre du chantier a echapper a cette regle.
 */
test('la frappe se chiffre, puis se confirme en rapportant sa version et son cout', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()]));
        monde.cliquer(monde.contacts()[0]);

        const bouton = monde.boutons().find((b) => (b.textContent || '').indexOf('Patrouille 1') !== -1);

        assert.ok(bouton, 'aucun bouton de frappe');

        monde.cliquer(bouton);

        assert.equal(monde.envois.length, 1, 'aucun devis n est parti');
        assert.equal(monde.envois[0].url, '/ajax/galaxy/patrol/quote');
        assert.equal(monde.envois[0].donnees.kind, 'attack');
        assert.equal(monde.envois[0].donnees.patrol_id, 3);
        assert.equal(monde.envois[0].donnees.contact_id, 11);

        monde.envois[0].repondre({
            success: true,
            quote: { possible: true, order_version: 4, fuel_cost: 1234, duration_seconds: 750, refusal: null }
        });

        assert.notEqual(
            (bouton.textContent || '').indexOf('Patrouille 1'),
            0,
            'le bouton n a pas change de role apres le devis'
        );

        monde.cliquer(bouton);

        assert.equal(monde.envois.length, 2, 'la confirmation n est pas partie');
        assert.equal(monde.envois[1].url, '/ajax/galaxy/patrol/attack');
        assert.equal(monde.envois[1].donnees.patrol_id, 3);
        assert.equal(monde.envois[1].donnees.contact_id, 11);
        assert.equal(monde.envois[1].donnees.order_version, 4, 'la version du devis n est pas rapportee : un devis perime serait debite');
        assert.equal(monde.envois[1].donnees.quoted_fuel_cost, 1234, 'le cout lu n est pas rapporte : le serveur pourrait debiter davantage');
    } finally {
        monde.fermer();
    }
});

/**
 * **Un refus perime le devis.** Le garder permettrait de reconfirmer sans rien relire, sur un monde
 * qui vient precisement de changer — c est le scenario meme que la version d ordre existe pour
 * fermer.
 */
test('une confirmation refusee oblige a redemander un devis', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()]));
        monde.cliquer(monde.contacts()[0]);

        const bouton = monde.boutons().find((b) => (b.textContent || '').indexOf('Patrouille 1') !== -1);

        monde.cliquer(bouton);
        monde.envois[0].repondre({
            success: true,
            quote: { possible: true, order_version: 4, fuel_cost: 1234, duration_seconds: 750, refusal: null }
        });

        monde.cliquer(bouton);
        assert.equal(monde.envois.length, 2, 'la premisse manque : la confirmation n est pas partie');

        monde.envois[1].echouer({ responseJSON: { message: 'Refuse.' } });

        monde.cliquer(bouton);

        assert.equal(monde.envois.length, 3, 'le troisieme clic n a rien envoye');
        assert.equal(
            monde.envois[2].url,
            '/ajax/galaxy/patrol/quote',
            'le clic suivant un refus reconfirme au lieu de redemander un devis'
        );
    } finally {
        monde.fermer();
    }
});
/**
 * **La carte montre la raison du serveur, jamais un message passe-partout.**
 *
 * Elle lisait `quote.refusal` — une clef, pas une phrase — et affichait « Cette attaque a ete
 * refusee » quoi qu il arrive. Le joueur ne pouvait pas savoir ce qui manquait : carburant, reserve
 * de retour, ecart de puissance. Le serveur compose la phrase a cote de la clef ; il suffisait de
 * la lire.
 */
test('un devis refuse affiche la raison du serveur, pas un message generique', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()]));
        monde.cliquer(monde.contacts()[0]);

        const bouton = monde.boutons().find((b) => (b.textContent || '').indexOf('Patrouille 1') !== -1);

        monde.cliquer(bouton);

        assert.equal(monde.envois.length, 1, 'la premisse manque : aucun devis n est parti');

        monde.envois[0].repondre({
            success: true,
            quote: {
                possible: false,
                refusal: 'no_return_reserve',
                refusal_reason: 'A l arrivee, la reserve ne couvrirait plus le retour de securite.',
                order_version: 4,
                fuel_cost: 9999,
                duration_seconds: 750
            }
        });

        const raison = bouton.parentNode.querySelector('.gtActionReason');

        assert.ok(raison && !raison.hidden, 'aucune raison affichee apres un devis refuse');
        assert.equal(
            raison.textContent,
            'A l arrivee, la reserve ne couvrirait plus le retour de securite.',
            'la carte affiche un message generique au lieu de la raison du serveur : ' + raison.textContent
        );

        assert.equal(
            raison.textContent.indexOf('no_return_reserve'),
            -1,
            'la clef de refus est montree au joueur : elle est pour le code, pas pour lui'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Le cas comparable : un devis possible ne declenche aucun refus.**
 *
 * Sans lui, une carte qui afficherait toujours une raison passerait le temoin precedent.
 */
test('un devis possible passe a la confirmation sans afficher de refus', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()]));
        monde.cliquer(monde.contacts()[0]);

        const bouton = monde.boutons().find((b) => (b.textContent || '').indexOf('Patrouille 1') !== -1);

        monde.cliquer(bouton);
        monde.envois[0].repondre({
            success: true,
            quote: { possible: true, refusal: null, refusal_reason: null, order_version: 4, fuel_cost: 1234, duration_seconds: 750 }
        });

        monde.cliquer(bouton);

        assert.equal(monde.envois.length, 2, 'un devis possible ne mene pas a la confirmation');
        assert.equal(monde.envois[1].url, '/ajax/galaxy/patrol/attack');
    } finally {
        monde.fermer();
    }
});

/**
 * **Aucun envoi de la carte ne part sans son jeton.**
 *
 * Rien dans le depot ne pose l en-tete CSRF globalement : le seul `ajaxSetup` pose `X-Socket-ID`, et
 * les `X-CSRF-TOKEN` du paquet herite sont poses appel par appel. Trois des quatre `post` de la carte
 * portaient `_token` ; celui du bouton d attaque d un contact, non.
 *
 * **Le banc PHP ne peut pas voir ce defaut** — Laravel desactive la verification CSRF quand il tourne
 * sous les essais —, et lire le fichier ne prouverait que la presence d un mot. Ce temoin lit la
 * charge **reellement envoyee**, telle que le faux l a retenue.
 */
test('chaque envoi de la fiche d un contact porte son jeton', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()]));
        monde.cliquer(monde.contacts()[0]);

        // 1. Le devis d une frappe depuis la patrouille.
        const depuisLaPatrouille = monde.boutons().find((b) => (b.textContent || '').indexOf('Patrouille 1') !== -1);

        monde.cliquer(depuisLaPatrouille);
        monde.envois[0].repondre({
            success: true,
            quote: { possible: true, refusal: null, refusal_reason: null, order_version: 4, fuel_cost: 1234, duration_seconds: 750 }
        });

        // 2. La confirmation.
        monde.cliquer(depuisLaPatrouille);

        // 3. L envoi depuis un corps, avec sa composition.
        const champ = monde.window.document.querySelector('.gtContactShipInput');

        assert.ok(champ, 'la premisse manque : aucune composition depuis un corps');
        champ.value = '3';

        const depuisUnCorps = monde.boutons().find((b) => b !== depuisLaPatrouille && !b.disabled);

        assert.ok(depuisUnCorps, 'la premisse manque : aucun bouton d envoi depuis un corps');
        monde.cliquer(depuisUnCorps);

        assert.equal(monde.envois.length, 3, 'les trois envois attendus ne sont pas partis');

        monde.envois.forEach((envoi, rang) => {
            assert.equal(
                typeof envoi.donnees._token === 'string' && envoi.donnees._token !== '',
                true,
                'l envoi ' + rang + ' vers ' + envoi.url + ' part sans jeton : le serveur le refusera avant de le lire'
            );
        });
    } finally {
        monde.fermer();
    }
});


/**
 * **Un contact en vol avance sur sa route, comme une flotte du joueur.**
 *
 * Le meme segment, deux dessins : un mouvement du joueur trace par la couche des flottes, et un
 * contact porte par la couche de surveillance. A un meme instant ils sont au meme point — egalite
 * des deux sources, a un pas d arrondi pres (deux calculs du meme point, arrondis au dixieme).
 * Un contact en vol n a pas de position (x et y nuls) : sans sa route, il n aurait rien a montrer.
 */
test('un contact en vol avance sur sa route comme une flotte du joueur', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, null, null, { segment: uneRoute() })], 1_700_000_000, [], [unMouvementSurLaRoute()]));

        const contact = monde.contacts()[0];
        const etalon = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtFleetMarker');
        const position = /translate\((-?[\d.]+),(-?[\d.]+)\)/.exec(etalon ? etalon.getAttribute('transform') : '');

        assert.ok(contact && etalon && position, 'la premisse manque : pas de contact ou pas d etalon');
        assert.equal(contact.hidden, false, 'le contact en vol est masque');

        const ecartX = Math.abs(parseFloat(contact.style.left) - Number(position[1]));
        const ecartY = Math.abs(parseFloat(contact.style.top) - Number(position[2]));

        assert.ok(ecartX < 0.15 && ecartY < 0.15, 'le contact n est pas la ou vole la flotte etalon : ' + contact.style.left + '/' + contact.style.top + ' contre ' + position[1] + '/' + position[2]);
    } finally {
        monde.fermer();
    }
});

/**
 * **Rouge pour un etranger, bleu pour un allie** — les deux glyphes de Keven, et l attribut que la
 * feuille lit. La relation vient du serveur ; la carte ne la devine pas.
 */
test('un contact etranger porte le glyphe rouge, un allie le bleu', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480, { relation: 'stranger' }), unContact(12, 100, 100, { relation: 'ally' })]));

        const [etranger, allie] = monde.contacts();

        assert.equal(etranger.getAttribute('data-relation'), 'stranger');
        assert.equal(allie.getAttribute('data-relation'), 'ally');
        assert.notEqual(etranger.querySelector('img').getAttribute('src').indexOf('fleet-stranger'), -1, 'l etranger ne porte pas le glyphe rouge');
        assert.notEqual(allie.querySelector('img').getAttribute('src').indexOf('fleet-ally'), -1, 'l allie ne porte pas le glyphe bleu');
        assert.notEqual(etranger.title.indexOf('ni ami ni allie'), -1, 'l intitule de l etranger ne dit pas sa relation : ' + etranger.title);
        assert.notEqual(allie.title.indexOf('allie'), -1, 'l intitule de l allie ne dit pas sa relation : ' + allie.title);
        assert.equal(allie.title.indexOf('ni ami'), -1, 'l intitule de l allie est celui de l etranger : ' + allie.title);
    } finally {
        monde.fermer();
    }
});

/**
 * **Le glyphe pointe la ou il va : le cap de la route, plus le quart de tour de son orientation.**
 *
 * Le vaisseau du jeu regarde a droite, les glyphes de Keven regardent en haut. L etalon est le cap
 * que la couche des flottes donne au meme segment ; le glyphe doit en differer d exactement 90
 * degres. La premisse refuse un cap nul, ou tourne et non tourne coincideraient.
 */
test('le glyphe pointe dans le cap de sa route, un quart de tour apres le vaisseau du jeu', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, null, null, { segment: uneRoute() })], 1_700_000_000, [], [unMouvementSurLaRoute()]));

        const glyphe = monde.contacts()[0].querySelector('img');
        const etalon = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtShip');
        const capEtalon = angleDe(etalon && etalon.getAttribute('transform'));

        assert.ok(capEtalon !== null && capEtalon !== 0, 'la premisse manque : pas de cap etalon non nul');

        const ecart = Math.abs(angleDe(glyphe.style.transform) - (capEtalon + 90));

        assert.ok(ecart < 0.15, 'le glyphe ne pointe pas dans le cap de sa route : ' + glyphe.style.transform + ' contre ' + capEtalon + ' + 90');
    } finally {
        monde.fermer();
    }
});

/**
 * **La route se trace a partir du troisieme palier, jamais avant.** Au troisieme, ses bouts sont
 * ceux que la couche des flottes donne au meme segment. Les deux moities comptent : sans la
 * premiere, tracer toutes les routes passerait la seconde.
 */
test('la route d un contact se dessine a partir du troisieme palier, et pas avant', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, null, null, { tier: 1, segment: uneRoute() })], 1_700_000_000, [], [unMouvementSurLaRoute()]));

        assert.equal(monde.window.document.querySelector('.gtContactRoute'), null, 'la route d un contact du premier palier est tracee');

        monde.unMouvementAnnonce(1, 5);
        monde.demandes[monde.demandes.length - 1].repondre(reponse(1, 5, [unContact(11, null, null, { tier: 3, segment: uneRoute() })], 1_700_000_010, [], [unMouvementSurLaRoute()]));

        const route = monde.window.document.querySelector('.gtContactRoute');
        const etalon = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtTrajectory');

        assert.ok(route && etalon, 'la premisse manque : pas de route au troisieme palier, ou pas d etalon');
        assert.notEqual(route.style.display, 'none', 'la route est tracee mais masquee');

        ['x1', 'y1', 'x2', 'y2'].forEach((bout) => {
            assert.ok(Math.abs(parseFloat(route.getAttribute(bout)) - parseFloat(etalon.getAttribute(bout))) < 0.15, 'le bout ' + bout + ' de la route ne suit pas la trajectoire etalon');
        });
    } finally {
        monde.fermer();
    }
});

/**
 * **Sans position ni route, un contact ne se dessine nulle part** — jamais dans le coin. Un contact
 * en vol dont le palier ne livrerait pas la route serait invisible plutot que faux.
 */
test('un contact sans position ni route n est dessine nulle part', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, null, null)]));

        const contact = monde.contacts()[0];

        assert.ok(contact, 'la premisse manque : le contact n a pas de marqueur');
        assert.equal(contact.hidden, true, 'un contact sans position ni route est dessine — dans le coin');
    } finally {
        monde.fermer();
    }
});

/**
 * **Le retour d un onglet masque avant de redemander.** C est l un des deux cas ou le masquage
 * immediat demeure : l onglet a pu rater une revocation pendant qu il dormait.
 */
test('le retour d un onglet masque la couche avant de redemander', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));
        assert.equal(monde.contacts().length, 1);

        const avant = monde.demandes.length;

        monde.window.document.dispatchEvent(new monde.window.Event('visibilitychange'));

        assert.equal(monde.contacts().length, 0, 'l onglet revenu garde a l ecran ce qu il a peut-etre perdu le droit de voir');
        assert.ok(monde.demandes.length > avant, 'l onglet revenu ne redemande pas la couche');
    } finally {
        monde.fermer();
    }
});

/**
 * **La veille de la carte applique toute la reponse, pas seulement les compteurs.** Elle est
 * declenchee a la main (le monde retient les minuteries) : sa reponse porte un contact, et le
 * contact apparait. Avant, la veille demandait les flottes et n en gardait que les compteurs.
 */
test('la veille de la carte applique toute la reponse, contacts compris', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, []));
        assert.equal(monde.contacts().length, 0);

        const veille = monde.veilles.find((v) => v.delai === 10000);

        assert.ok(veille, 'la premisse manque : aucune veille de dix secondes n est armee (' + monde.veilles.map((v) => v.delai).join(', ') + ')');

        const avant = monde.demandes.length;

        veille.fonction();

        assert.ok(monde.demandes.length > avant, 'la veille ne demande rien');
        monde.demandes[monde.demandes.length - 1].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_010));

        assert.equal(monde.contacts().length, 1, 'la veille n applique pas les contacts de sa reponse');
    } finally {
        monde.fermer();
    }
});


/**
 * **Une route qui quitte le systeme mene au bord, sans destination.**
 *
 * Le serveur retient le bout lointain (`outside`, sans galaxie ni systeme) ; la carte doit tracer
 * jusqu a la porte de bord, dans la direction du point de depart. L etalon est un mouvement du
 * joueur qui part du meme point vers un autre systeme : la couche des flottes lui donne la meme
 * porte. **Ce trajet part d un point de l espace** : la porte se prenait par la position d orbite
 * du bout (`pointDe(0)`), qui n existe pas, et rendait `NaN`.
 */
test('une route qui quitte le systeme mene au bord, dans la direction de son depart', () => {
    const monde = unMonde();

    try {
        const route = { from: POINT, to: { outside: true }, time_departure: 1_699_999_700, time_arrival: 1_700_000_300 };
        const etalon = unMouvementSurLaRoute({ from: POINT, to: { galaxy: 1, system: 6, position: 8, type: 1, x: null, y: null }, time_departure: 1_699_999_700, time_arrival: 1_700_000_300 });

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, null, null, { tier: 3, segment: route })], 1_700_000_000, [], [etalon]));

        const tracee = monde.window.document.querySelector('.gtContactRoute');
        const trajectoire = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtTrajectory');

        assert.ok(tracee && trajectoire, 'la premisse manque : pas de route tracee ou pas d etalon');

        ['x1', 'y1', 'x2', 'y2'].forEach((bout) => {
            const valeur = parseFloat(tracee.getAttribute(bout));

            assert.ok(Number.isFinite(valeur), 'le bout ' + bout + ' de la route n est pas un nombre : ' + tracee.getAttribute(bout));
            assert.ok(Math.abs(valeur - parseFloat(trajectoire.getAttribute(bout))) < 0.15, 'le bout ' + bout + ' ne mene pas a la meme porte que l etalon');
        });

        // Et le contact lui-meme suit cette route, sans revelation : aucun « 6 » de systeme dans la charge.
        assert.equal(JSON.stringify(route).indexOf('"system":6'), -1, 'la premisse manque : la route du contact revele le systeme de destination');
    } finally {
        monde.fermer();
    }
});


/**
 * **Un palier qui redescend retire ce qu il ne donne plus.** `adopter()` garde l objet ; il doit
 * oublier les clefs absentes de la reponse neuve — pour un contact, l absence EST la protection.
 * Relecture du lot : le proprietaire et la route survivaient a la baisse du palier.
 */
test('un contact adopte oublie les faits que la reponse ne porte plus', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        const riche = unContact(11, null, null, { tier: 3, segment: uneRoute() });
        riche.owner = { id: 42, name: 'Zorg' };
        monde.demandes[0].repondre(reponse(1, 5, [riche]));

        const marqueur = monde.contacts()[0];

        assert.notEqual(marqueur.title.indexOf('Zorg'), -1, 'la premisse manque : le proprietaire n est pas dans l intitule');
        assert.ok(monde.window.document.querySelector('.gtContactRoute'), 'la premisse manque : pas de route au troisieme palier');

        monde.unMouvementAnnonce(1, 5);
        monde.demandes[monde.demandes.length - 1].repondre(reponse(1, 5, [unContact(11, 640, 480, { tier: 1 })], 1_700_000_010));

        assert.strictEqual(monde.contacts()[0], marqueur, 'la premisse manque : le marqueur a ete recree');
        assert.equal(marqueur.title.indexOf('Zorg'), -1, 'le proprietaire survit a la baisse du palier : ' + marqueur.title);
        assert.equal(monde.window.document.querySelector('.gtContactRoute'), null, 'la route survit a la baisse du palier');
        assert.equal(marqueur.getAttribute('data-tier'), '1');
    } finally {
        monde.fermer();
    }
});

/**
 * **Un contact qui porte une position ET une route en vol est sur sa route.** Le cas du raid :
 * la patrouille garde son point (l adresse du retour) pendant que sa flotte vole. Inverser la
 * priorite laissait le temoin du vol vert, puisque sa position etait nulle. Relecture du lot.
 */
test('un contact qui a une position et une route en vol est sur sa route, pas a sa position', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, -660, 580, { segment: uneRoute() })], 1_700_000_000, [unePatrouille()], [unMouvementSurLaRoute()]));

        const contact = monde.contacts()[0];
        const posee = monde.window.document.querySelector('.gtPatrolMarker[data-patrol-id="3"]');
        const etalon = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtFleetMarker');
        const position = /translate\((-?[\d.]+),(-?[\d.]+)\)/.exec(etalon ? etalon.getAttribute('transform') : '');

        assert.ok(contact && posee && position, 'la premisse manque');
        assert.notEqual(contact.style.left, posee.style.left, 'le contact est a sa position posee alors que sa route est en vol');
        assert.ok(Math.abs(parseFloat(contact.style.left) - Number(position[1])) < 0.15, 'le contact n est pas sur sa route');
    } finally {
        monde.fermer();
    }
});

/**
 * **Arrivee, la route l emporte encore sur la position.** Le raid a l instant d arrivee : la flotte
 * est sur la cible, la patrouille garde son point. Le glyphe reste au bout de la route — l etalon
 * est une patrouille posee exactement la —, il ne saute pas au point.
 */
test('un contact dont la route est arrivee reste au bout de sa route, pas a sa position', () => {
    const monde = unMonde();

    try {
        const arrivee = { from: PLANETE_4, to: { galaxy: 1, system: 5, position: 0, type: 5, x: 700, y: 500 }, time_departure: 1_699_999_000, time_arrival: 1_699_999_900 };

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, -660, 580, { segment: arrivee })], 1_700_000_000, [unePatrouille({ id: 3, x: -660, y: 580 }), unePatrouille({ id: 4, x: 700, y: 500 })]));

        const contact = monde.contacts()[0];
        const auPoint = monde.window.document.querySelector('.gtPatrolMarker[data-patrol-id="3"]');
        const auBout = monde.window.document.querySelector('.gtPatrolMarker[data-patrol-id="4"]');

        assert.ok(contact && auPoint && auBout, 'la premisse manque');
        assert.notEqual(auPoint.style.left, auBout.style.left, 'la premisse manque : le point et le bout coincident');
        assert.equal(contact.style.left, auBout.style.left, 'le glyphe a saute au point de la patrouille a l arrivee de sa route');
        assert.equal(contact.style.top, auBout.style.top);
        assert.equal(contact.hidden, false);
    } finally {
        monde.fermer();
    }
});

/**
 * **En hyperespace, le contact n est sur aucune carte.** Une route sortante au-dela du premier
 * quart, une route entrante avant le dernier quart : masque ; dans le dernier quart d une route
 * entrante : visible. Relecture du lot : `hidden = enTransit` n etait mesure nulle part.
 */
test('un contact en hyperespace est masque, et reparait dans le dernier quart d une route entrante', () => {
    const monde = unMonde();

    try {
        // Sortante, a mi-vol : au-dela du quart local, donc partie.
        const sortante = { from: POINT, to: { outside: true }, time_departure: 1_699_999_000, time_arrival: 1_700_001_000 };
        // Entrante, a un dixieme : pas encore la. Une autre, a neuf dixiemes : dans le dernier quart.
        const entranteLoin = { from: { outside: true }, to: POINT, time_departure: 1_699_999_900, time_arrival: 1_700_000_900 };
        const entranteProche = { from: { outside: true }, to: POINT, time_departure: 1_699_999_100, time_arrival: 1_700_000_100 };

        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [
            unContact(11, null, null, { segment: sortante }),
            unContact(12, null, null, { segment: entranteLoin }),
            unContact(13, null, null, { segment: entranteProche })
        ]));

        const [partie, pasEncore, presque] = monde.contacts();

        assert.equal(partie.hidden, true, 'un contact parti en hyperespace reste dessine au bord');
        assert.equal(pasEncore.hidden, true, 'un contact pas encore arrive est dessine au bord');
        assert.equal(presque.hidden, false, 'un contact dans le dernier quart de son approche est masque');
        assert.ok(Number.isFinite(parseFloat(presque.style.left)), 'le contact entrant n a pas de position finie : ' + presque.style.left);
    } finally {
        monde.fermer();
    }
});

/**
 * **Les contacts seuls animent la carte.** Le cas de l observateur : un reseau sur sa planete,
 * aucune flotte ni patrouille a lui dans le systeme. Sans la troisieme condition de `animer()`, le
 * contact ne bougerait qu a chaque veille. Mesure sur deux images.
 */
test('un contact seul, sans flotte ni patrouille du joueur, avance a chaque image', async () => {
    const monde = unMonde();

    try {
        const maintenant = Math.floor(Date.now() / 1000);
        const route = { from: PLANETE_4, to: POINT, time_departure: maintenant - 30, time_arrival: maintenant + 30 };

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, null, null, { segment: route })], maintenant));

        const contact = monde.contacts()[0];
        const avant = contact.style.left;

        await new Promise((suite) => setTimeout(suite, 250));

        assert.notEqual(contact.style.left, avant, 'le contact seul ne bouge pas entre deux images : la boucle ne s arme pas pour lui');
    } finally {
        monde.fermer();
    }
});

/**
 * **Un contact revoque emporte sa fiche.** La revocation s applique a la reponse ; la fiche ouverte
 * etait le seul endroit ou le renseignement revoque restait lisible. Relecture du lot.
 */
test('un contact revoque pendant que sa fiche est ouverte la referme', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));

        monde.cliquer(monde.contacts()[0]);

        const f = monde.fiche();

        assert.ok(f && !f.hidden && f.gtContact, 'la premisse manque : la fiche du contact ne s est pas ouverte');

        monde.unMouvementAnnonce(1, 5);
        monde.demandes[monde.demandes.length - 1].repondre(reponse(1, 5, [], 1_700_000_010));

        assert.equal(monde.contacts().length, 0);
        assert.equal(f.hidden, true, 'la fiche d un contact revoque reste ouverte, avec son bouton de frappe');
    } finally {
        monde.fermer();
    }
});

/**
 * **La fiche suit le glyphe.** Un contact en vol : sa fiche, ouverte au clic, se replace avec lui.
 */
test('la fiche d un contact en vol suit son glyphe', async () => {
    const monde = unMonde();

    try {
        const maintenant = Math.floor(Date.now() / 1000);
        const route = { from: PLANETE_4, to: POINT, time_departure: maintenant - 30, time_arrival: maintenant + 30 };

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, null, null, { segment: route })], maintenant));

        monde.cliquer(monde.contacts()[0]);

        const f = monde.fiche();

        assert.ok(f && !f.hidden, 'la premisse manque : la fiche ne s est pas ouverte');

        const avant = f.style.left + '/' + f.style.top;

        await new Promise((suite) => setTimeout(suite, 250));

        assert.notEqual(f.style.left + '/' + f.style.top, avant, 'la fiche reste ou l on a clique pendant que le glyphe s eloigne');
    } finally {
        monde.fermer();
    }
});

/**
 * **La veille laisse finir la demande en cours.** Chaque demande perime la precedente : sous un
 * serveur lent, une veille qui repartait quand meme jetait toute reponse de plus de dix secondes,
 * indefiniment. Relecture du lot. La veille reprend des que la demande a repondu.
 */
test('la veille de la carte ne repart pas tant qu une demande est en vol', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);

        const veille = monde.veilles.find((v) => v.delai === 10000);

        assert.ok(veille, 'la premisse manque : aucune veille de dix secondes');
        assert.equal(monde.demandes.length, 1, 'la premisse manque : la demande d amorcage n est pas en vol');

        veille.fonction();

        assert.equal(monde.demandes.length, 1, 'la veille a ecrase une demande en vol : sa reponse sera jetee');

        monde.demandes[0].repondre(reponse(1, 5, []));
        veille.fonction();

        assert.equal(monde.demandes.length, 2, 'la veille ne repart pas une fois la demande finie');
    } finally {
        monde.fermer();
    }
});

/**
 * **Le demi-tour d un rappel**, signale par Keven le 12 septembre 2026 : « tu ne le vois pas
 * retourner de bord, c est comme s il sortait de l hyperespace alors qu il n y est jamais entre ».
 *
 * Le serveur cree le retour depuis la cible ; sans correction, la carte y place le vaisseau d un
 * coup. Avec `recall_progress`, le depart du trace est ramene la ou la flotte etait.
 */
test('le retour d un rappel part de la ou la flotte a fait demi-tour', () => {
    const monde = unMonde();
    monde.amorcer(1, 5, [uneLigne(4)]);

    const route = uneRoute();

    // L aller seul : son trace donne les deux bouts de reference.
    monde.demandes[0].repondre(reponse(1, 5, [], 1_700_000_000, [], [unMouvementSurLaRoute(route)]));

    const aller = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtTrajectory');
    assert.ok(aller, 'la premisse manque : l aller n est pas trace');

    const A = { x: Number(aller.getAttribute('x1')), y: Number(aller.getAttribute('y1')) };
    const B = { x: Number(aller.getAttribute('x2')), y: Number(aller.getAttribute('y2')) };

    // Puis le rappel a 40 % du trajet : l aller disparait, le retour le remplace.
    monde.demandes[0].repondre(reponse(1, 5, [], 1_700_000_000, [], [unRetourRappele(0.4, route)]));

    const retour = monde.window.document.querySelector('.gtMovement[data-mission-id="778"] .gtTrajectory');
    assert.ok(retour, 'le retour n est pas trace');

    const depart = { x: Number(retour.getAttribute('x1')), y: Number(retour.getAttribute('y1')) };
    const arrivee = { x: Number(retour.getAttribute('x2')), y: Number(retour.getAttribute('y2')) };

    // Le retour finit ou l aller commencait.
    assert.ok(Math.abs(arrivee.x - A.x) < 1 && Math.abs(arrivee.y - A.y) < 1, 'le retour ne revient pas au point de depart de l aller');

    // Et il commence au point des 40 % du trajet, pas a la cible.
    const attendu = { x: A.x + (B.x - A.x) * 0.4, y: A.y + (B.y - A.y) * 0.4 };

    assert.ok(
        Math.abs(depart.x - attendu.x) < 1 && Math.abs(depart.y - attendu.y) < 1,
        'le retour part de la cible au lieu du point de demi-tour : depart (' + depart.x + ', ' + depart.y + '), attendu (' + Math.round(attendu.x) + ', ' + Math.round(attendu.y) + ')'
    );

    assert.ok(
        Math.abs(depart.x - B.x) > 1 || Math.abs(depart.y - B.y) > 1,
        'le depart du retour est reste la cible'
    );

    monde.fermer();
});

/**
 * **La vitesse dessinee est conservee**, et c est ce qui distingue la bonne fraction d une autre.
 *
 * Un retour dure exactement le temps que l aller a consomme. Placer son depart a la fraction
 * parcourue fait donc parcourir au vaisseau la meme distance par seconde qu a l aller. Une valeur
 * fausse rendrait le retour visiblement plus rapide ou plus lent — le faux est observable.
 */
test('un retour rappele est dessine a la meme vitesse que son aller', () => {
    const monde = unMonde();
    monde.amorcer(1, 5, [uneLigne(4)]);

    const route = uneRoute();
    const dureeAller = route.time_arrival - route.time_departure;

    monde.demandes[0].repondre(reponse(1, 5, [], 1_700_000_000, [], [unMouvementSurLaRoute(route)]));

    const aller = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtTrajectory');
    const longueur = (l) => Math.hypot(
        Number(l.getAttribute('x2')) - Number(l.getAttribute('x1')),
        Number(l.getAttribute('y2')) - Number(l.getAttribute('y1'))
    );
    const vitesseAller = longueur(aller) / dureeAller;

    const fraction = 0.6;
    monde.demandes[0].repondre(reponse(1, 5, [], 1_700_000_000, [], [unRetourRappele(fraction, route)]));

    const retour = monde.window.document.querySelector('.gtMovement[data-mission-id="778"] .gtTrajectory');
    const vitesseRetour = longueur(retour) / (dureeAller * fraction);

    assert.ok(
        Math.abs(vitesseRetour - vitesseAller) / vitesseAller < 0.02,
        'le retour n est pas dessine a la vitesse de l aller : ' + vitesseRetour.toFixed(4) + ' contre ' + vitesseAller.toFixed(4)
    );

    monde.fermer();
});

/**
 * Un retour ordinaire ne porte pas ce fait : il part de la cible, comme avant. C est l etat de tous
 * les retours deja en vol au deploiement.
 */
test('un retour sans point de demi-tour part de la cible, comme avant', () => {
    const monde = unMonde();
    monde.amorcer(1, 5, [uneLigne(4)]);

    const route = uneRoute();
    monde.demandes[0].repondre(reponse(1, 5, [], 1_700_000_000, [], [unMouvementSurLaRoute(route)]));

    const aller = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtTrajectory');
    const B = { x: Number(aller.getAttribute('x2')), y: Number(aller.getAttribute('y2')) };

    const ordinaire = unRetourRappele(0.5, route);
    delete ordinaire.recall_progress;

    monde.demandes[0].repondre(reponse(1, 5, [], 1_700_000_000, [], [ordinaire]));

    const retour = monde.window.document.querySelector('.gtMovement[data-mission-id="778"] .gtTrajectory');
    const depart = { x: Number(retour.getAttribute('x1')), y: Number(retour.getAttribute('y1')) };

    assert.ok(
        Math.abs(depart.x - B.x) < 1 && Math.abs(depart.y - B.y) < 1,
        'un retour sans point de demi-tour ne part plus de la cible : le rendu d avant est casse'
    );

    monde.fermer();
});

/**
 * Une valeur aberrante est refusee, jamais corrigee : le depart d origine vaut.
 */
test('une part de trajet hors bornes laisse le depart d origine', () => {
    const monde = unMonde();
    monde.amorcer(1, 5, [uneLigne(4)]);

    const route = uneRoute();
    monde.demandes[0].repondre(reponse(1, 5, [], 1_700_000_000, [], [unMouvementSurLaRoute(route)]));

    const aller = monde.window.document.querySelector('.gtMovement[data-mission-id="777"] .gtTrajectory');
    const B = { x: Number(aller.getAttribute('x2')), y: Number(aller.getAttribute('y2')) };

    for (const valeur of [0, 1, -0.5, 1.5, NaN, null, 'beaucoup']) {
        const mouvement = unRetourRappele(0.5, route);
        mouvement.recall_progress = valeur;

        monde.demandes[0].repondre(reponse(1, 5, [], 1_700_000_000, [], [mouvement]));

        const retour = monde.window.document.querySelector('.gtMovement[data-mission-id="778"] .gtTrajectory');
        const depart = { x: Number(retour.getAttribute('x1')), y: Number(retour.getAttribute('y1')) };

        assert.ok(
            Math.abs(depart.x - B.x) < 1 && Math.abs(depart.y - B.y) < 1,
            'la valeur « ' + String(valeur) + ' » a deplace le depart au lieu d etre refusee'
        );
    }

    monde.fermer();
});

/*
 * ## Deux reponses perimees que la surveillance appliquait quand meme (Codex, 12 septembre 2026)
 *
 * Les quatre-vingts essais precedents passaient, et aucun ne suivait ces deux enchainements : une
 * reponse **mise de cote pendant un glisser**, puis une coupure ; une **ancienne demande qui echoue**
 * apres une reponse recente. Codex les a reproduits sur ce module ; ces temoins les rejouent.
 */
test('une coupure pendant un glisser perime aussi la reponse mise de cote', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()]));
        assert.equal(monde.contacts().length, 1, 'le point de depart n est pas celui du scenario');

        const document = monde.window.document;
        const marqueur = document.querySelector('.gtPatrolMarker[data-patrol-id="3"]');
        assert.ok(marqueur, 'la patrouille n est pas dessinee : aucun glisser ne peut commencer');

        marqueur.dispatchEvent(new monde.window.Event('dragstart', { bubbles: true, cancelable: true }));
        assert.ok(document.getElementById('galaxyTactical').classList.contains('gtDragging'), 'le glisser n a pas commence : la premisse manque');

        // Une reponse arrive pendant le geste : elle est mise de cote, rien ne bouge a l ecran.
        monde.unMouvementAnnonce(1, 5);
        monde.demandes[monde.demandes.length - 1].repondre(
            reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()])
        );

        const avantLaCoupure = monde.demandes.length;
        monde.diffuseur.declencher('disconnected');
        assert.equal(monde.demandes.length, avantLaCoupure, 'la coupure a lance une demande : le temoin ne prouverait plus rien');
        assert.equal(monde.contacts().length, 0, 'la coupure n a pas masque la surveillance');

        marqueur.dispatchEvent(new monde.window.Event('dragend', { bubbles: true, cancelable: true }));

        assert.equal(
            monde.contacts().length,
            0,
            'la fin du geste a reaffiche un contact que la coupure venait d oter, sans aucune reponse du serveur'
        );
    } finally {
        monde.fermer();
    }
});

/*
 * **La generation suffit, et c est elle qui est eprouvee ici.** Aucune coupure : une nouvelle demande
 * part pendant le geste. La reponse mise de cote est alors plus ancienne qu une demande en vol, et la
 * regle de toute reponse — la generation courante seule s applique — vaut pour elle aussi.
 */
test('une demande partie pendant le glisser perime la reponse mise de cote', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)], 1_700_000_000, [unePatrouille()]));

        const document = monde.window.document;
        const marqueur = document.querySelector('.gtPatrolMarker[data-patrol-id="3"]');
        assert.ok(marqueur, 'la patrouille n est pas dessinee : aucun glisser ne peut commencer');
        marqueur.dispatchEvent(new monde.window.Event('dragstart', { bubbles: true, cancelable: true }));

        monde.unMouvementAnnonce(1, 5);
        monde.demandes[monde.demandes.length - 1].repondre(
            reponse(1, 5, [unContact(22, 100, 200)], 1_700_000_000, [unePatrouille()])
        );

        // Une seconde demande part avant la fin du geste, et ne repond pas encore.
        monde.unMouvementAnnonce(1, 5);
        const enVol = monde.demandes[monde.demandes.length - 1];

        marqueur.dispatchEvent(new monde.window.Event('dragend', { bubbles: true, cancelable: true }));

        const vus = monde.contacts().map((n) => n.getAttribute('data-contact-id'));
        assert.deepEqual(vus, ['11'], 'la fin du geste a applique une reponse plus ancienne qu une demande en vol');

        enVol.repondre(reponse(1, 5, [unContact(33, 300, 300)], 1_700_000_000, [unePatrouille()]));
        assert.deepEqual(
            monde.contacts().map((n) => n.getAttribute('data-contact-id')),
            ['33'],
            'la reponse courante ne s est pas appliquee apres le geste'
        );
    } finally {
        monde.fermer();
    }
});

test('l echec d une demande perimee n efface pas les contacts d une reponse plus recente', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));

        monde.unMouvementAnnonce(1, 5);
        const ancienne = monde.demandes[monde.demandes.length - 1];

        monde.unMouvementAnnonce(1, 5);
        const recente = monde.demandes[monde.demandes.length - 1];
        assert.notEqual(recente, ancienne, 'aucune seconde demande n est partie : le scenario ne tient pas');

        recente.repondre(reponse(1, 5, [unContact(22, 100, 200)]));
        assert.equal(monde.contacts().length, 1, 'la reponse recente ne s est pas affichee');

        ancienne.echouer({ status: 500 });

        const vus = monde.contacts();
        assert.equal(vus.length, 1, 'l echec d une demande perimee a vide la surveillance');
        assert.equal(vus[0].getAttribute('data-contact-id'), '22');
    } finally {
        monde.fermer();
    }
});

/*
 * ## L espionnage d un systeme entier (audit du 12 septembre 2026)
 *
 * La premiere version cliquait chaque lien dans une boucle synchrone. `sendShips()` n accepte qu un
 * envoi a la fois : **un seul partait**, et la page annoncait N planetes. Le temoin d origine ne
 * regardait que la presence de l appel sur le bouton — une forme, pas un effet.
 *
 * **Le faux porte la regle du vrai** : `sendShips` baisse le drapeau et refuse tant qu il est bas,
 * exactement comme le paquet du jeu ; seul le retour du serveur le releve. Un faux qui accepterait
 * tout rendrait la boucle synchrone verte.
 */
function desLiensDEspionnage(monde, positions, { avertissement = [], vide = [] } = {}) {
    const document = monde.window.document;
    let contenu = document.getElementById('galaxyContent');

    if (!contenu) {
        contenu = document.createElement('div');
        contenu.id = 'galaxyContent';
        document.body.appendChild(contenu);
    }

    positions.forEach((position) => {
        const ligne = document.createElement('div');
        ligne.className = 'galaxyRow';
        const cellule = document.createElement('div');
        cellule.className = 'cellAction';
        const lien = document.createElement('a');
        lien.className = 'tooltip js_hideTipOnMobile espionage ipiHintable';
        lien.setAttribute('href', 'javascript: void(0);');

        // La forme exacte que `getEspionageMission()` ecrit dans le paquet du jeu.
        let appel = 'sendShips(6, 1, 5, ' + position + ', 1, 3);return false;';

        if (avertissement.includes(position)) {
            appel = 'outlawWarning(6, 1, 5, ' + position + ', 1, 3);return false;';
        }

        if (vide.includes(position)) {
            appel = '';
        }

        lien.setAttribute('onclick', appel);
        cellule.appendChild(lien);
        ligne.appendChild(cellule);
        contenu.appendChild(ligne);
    });
}

function unEnvoiDeSondes(monde) {
    const w = monde.window;
    const partis = [];
    const avertis = [];
    const annonces = [];

    w.shipsendingDone = 1;
    w.sendShips = function (ordre, galaxie, systeme, position, type, nombre) {
        if (w.shipsendingDone == 1) {
            w.shipsendingDone = 0;
            partis.push({ ordre, galaxie, systeme, position, type, nombre });
        }
    };
    w.outlawWarning = function (ordre, galaxie, systeme, position) {
        avertis.push(position);
    };
    w.fadeBox = function (message, erreur) {
        annonces.push({ message, erreur });
    };
    w.galaxyTacticalLoca = Object.assign({}, w.galaxyTacticalLoca, {
        systemEspionageNone: 'Aucune planete a espionner.',
        systemEspionageSent: 'Espionnage lance sur #count# planete(s).'
    });

    // Le retour du serveur : `displayMiniFleetMessage()` releve le drapeau, et lui seul.
    const repondre = () => { w.shipsendingDone = 1; };

    return { partis, avertis, annonces, repondre };
}

const patienter = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

test('l espionnage de systeme envoie une sonde par planete, chacune apres le retour de la precedente', async () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        desLiensDEspionnage(monde, [4, 7, 9]);
        const envoi = unEnvoiDeSondes(monde);

        monde.window.spyWholeSystem();

        assert.deepEqual(envoi.partis.map((p) => p.position), [4], 'le premier envoi n est pas parti');
        assert.equal(envoi.annonces.length, 0, 'la page annonce le resultat avant que les envois soient partis');

        await patienter(250);
        envoi.repondre();
        await patienter(250);
        envoi.repondre();
        await patienter(250);
        envoi.repondre();
        await patienter(250);

        assert.deepEqual(
            envoi.partis.map((p) => p.position),
            [4, 7, 9],
            'toutes les planetes du systeme ne sont pas parties : le bouton donne moins que la main'
        );
        assert.deepEqual(envoi.annonces, [{ message: 'Espionnage lance sur 3 planete(s).', erreur: false }]);
    } finally {
        monde.fermer();
    }
});

test('l espionnage de systeme ne confirme aucun avertissement et ne compte pas un lien vide', async () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        desLiensDEspionnage(monde, [4, 6, 8], { avertissement: [6], vide: [8] });
        const envoi = unEnvoiDeSondes(monde);

        monde.window.spyWholeSystem();
        await patienter(250);
        envoi.repondre();
        await patienter(250);

        assert.deepEqual(envoi.partis.map((p) => p.position), [4]);
        assert.deepEqual(envoi.avertis, [], 'le bouton a ouvert un avertissement de hors-la-loi a la place du joueur');
        assert.deepEqual(envoi.annonces, [{ message: 'Espionnage lance sur 1 planete(s).', erreur: false }]);
    } finally {
        monde.fermer();
    }
});

test('un retour qui ne vient jamais arrete la file sans figer le bouton', async () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        desLiensDEspionnage(monde, [4, 7]);
        const envoi = unEnvoiDeSondes(monde);

        let horloge = 1_000_000;
        monde.window.Date.now = () => horloge;

        monde.window.spyWholeSystem();
        assert.equal(envoi.partis.length, 1);

        // Le serveur ne repond jamais : passe le delai, la file s arrete.
        horloge += 16_000;
        await patienter(250);

        assert.deepEqual(envoi.annonces, [{ message: 'Espionnage lance sur 1 planete(s).', erreur: false }], 'la file ne s est pas arretee');

        // Le drapeau revient plus tard : la file arretee ne repart pas d elle-meme…
        envoi.repondre();
        await patienter(250);
        assert.equal(envoi.partis.length, 1, 'une file arretee a repris sans que le joueur le demande');

        // …mais le bouton n est pas fige : un nouveau clic repart.
        monde.window.spyWholeSystem();
        assert.equal(envoi.partis.length, 2, 'le bouton est reste bloque apres un retour perdu');
    } finally {
        monde.fermer();
    }
});

test('un systeme sans lien qui envoie le dit, sans rien envoyer', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        desLiensDEspionnage(monde, [6], { avertissement: [6] });
        const envoi = unEnvoiDeSondes(monde);

        monde.window.spyWholeSystem();

        assert.equal(envoi.partis.length, 0);
        assert.deepEqual(envoi.annonces, [{ message: 'Aucune planete a espionner.', erreur: true }]);
    } finally {
        monde.fermer();
    }
});
