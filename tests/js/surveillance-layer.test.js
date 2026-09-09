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

    jq.post = chainable;
    jq.ajax = chainable;

    return { jq, demandes };
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
    const { jq, demandes } = faireJQuery();
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

    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    const amorcer = (galaxie, systeme) => {
        window.renderContentGalaxy({ system: { galaxy: galaxie, system: systeme, galaxyContent: [] } });
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

    return { window, demandes, diffuseur, amorcer, contacts, fermer, unMouvementAnnonce };
}

/**
 * Une reponse du serveur portant ces contacts.
 */
function reponse(galaxie, systeme, contacts, maintenant = 1_700_000_000) {
    return {
        success: true,
        galaxy: galaxie,
        system: systeme,
        server_now: maintenant,
        movements: [],
        patrols: [],
        surveillance: contacts,
        counters: {}
    };
}

function unContact(id, x, y) {
    return { contact_id: id, tier: 1, computed_at: 1_700_000_000, position: { galaxy: 1, system: 5, x, y } };
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

test('un contact autorise apparait, et rien de plus', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));

        const vus = monde.contacts();

        assert.equal(vus.length, 1, 'le contact autorise n apparait pas');
        assert.equal(vus[0].getAttribute('data-contact-id'), '11');
        assert.equal(vus[0].style.left, '640px');
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
test('la couche est masquee des le depart de la demande, sans attendre la reponse', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));
        assert.equal(monde.contacts().length, 1);

        // Une annonce de mouvement recharge la couche SANS redessiner la carte : le DOM reste en
        // place, donc seule l invalidation dans la demande peut vider les contacts.
        monde.unMouvementAnnonce(1, 5);

        assert.ok(monde.demandes.length > 1, 'l annonce n a pas relance de demande : le cas ne prouverait rien');

        assert.equal(
            monde.contacts().length,
            0,
            'les contacts restent affiches pendant le vol de la requete : une revocation en cours serait invisible'
        );
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

test('une requete echouee laisse la couche vide plutot que l ancien contenu', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5);
        monde.demandes[0].repondre(reponse(1, 5, [unContact(11, 640, 480)]));
        assert.equal(monde.contacts().length, 1);

        monde.diffuseur.declencher('disconnected');
        monde.diffuseur.declencher('connected');

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
