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

    /*
     * **Un `post` qu on peut resoudre.** Il rendait une promesse muette : le chemin du succes d un
     * ordre — celui qui previent le bandeau du jeu — n etait donc atteignable par aucun essai.
     */
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
            repondre(reponse) { if (rappels.done) { rappels.done(reponse); } },
            echouer(erreur) { if (rappels.fail) { rappels.fail(erreur); } }
        });

        return api;
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
    window.galaxyPatrolMoveUrl = '/ajax/galaxy/patrol/0/move';
    window.galaxyPatrolRecallUrl = '/ajax/galaxy/patrol/0/recall';
    window.galaxyPatrolLandUrl = '/ajax/galaxy/patrol/0/land';
    window.galaxyPatrolLaunchUrl = '/ajax/galaxy/patrol/launch';
    window.playerId = 7;
    window.token = 'jeton';
    /*
     * Les libelles de la legende, tels que la vue les publie. Les autres essais s appuient sur les
     * replis codes dans le module : ne poser que ces deux clefs les laisse intacts.
     */
    window.galaxyTacticalLoca = {
        hintCompose: 'Cliquez une case vide : composez une patrouille, elle partira la.',
        hintDrag: 'Glissez une patrouille sur la carte pour lui donner un ordre.'
    };
    /* Ce que la vue publie pour composer une patrouille depuis la carte. */
    window.galaxyCurrentPlanetId = 101;
    window.galaxyPatrolGridUnits = 10;
    window.galaxyPatrolSystemRadius = 1800;
    window.galaxyPatrolStarExclusion = 60;
    window.galaxyPatrolsEnabled = true;

    /*
     * Les deux rafraichisseurs du jeu, remplaces par des temoins : le bandeau compact et la liste
     * depliee « plus de details » se chargent separement.
     */
    const prevenus = [];

    /*
     * **Le faux rejoue la regle du vrai, il ne la contourne pas.**
     *
     * `refreshFleetEvents(force)` du jeu ne va chercher la liste que si le panneau est **deja
     * deplie**, ou si l appelant force. Or le panneau est replie pendant qu on glisse une
     * patrouille sur la carte, et le jeu retient par ailleurs dans `toggleEvents.loaded` que la
     * liste a ete chargee une fois : l ouvrir plus tard ne la redemande pas davantage.
     *
     * Le faux precedent se contentait de noter l appel. Il etait vert pendant que Keven lisait
     * « toujours rien sauf si je refresh la page ». Un canal qu on ne mesure qu a son entree ne
     * dit rien de ce qui en sort.
     */
    const panneauDEvenements = { replie: true, chargements: 0 };

    window.getAjaxEventbox = function () { prevenus.push('bandeau'); };
    window.refreshFleetEvents = function (force) {
        prevenus.push('liste');

        if (!panneauDEvenements.replie || force === true) {
            panneauDEvenements.chargements += 1;
        }
    };
    window.galaxyPatrolShips = [
        { id: 206, name: 'cruiser', label: 'Croiseur', amount: 20, mobile: true }
    ];
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

    const cliquer = (cible, x, y) => {
        cible.dispatchEvent(new window.MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            clientX: x,
            clientY: y
        }));
    };

    return { window, demandes, envois, prevenus, panneauDEvenements, amorcer, carte, marqueur, fiche, efface, fermer, geste, relacher, cliquer };
}

/**
 * Une patrouille telle que `PatrolProjection` la compose. Les champs sont ceux du serveur, pas ceux
 * qui rendraient l'essai commode : un montage qui invente sa charge utile ne prouve rien du jeu.
 */
function unePatrouille({ id = 3, galaxie = 1, systeme = 5, deplacementPermis = true, etat = 'stationed' } = {}) {
    const bout = (x, y) => ({ galaxy: galaxie, system: systeme, position: 0, type: 5, x, y });

    return {
        id,
        state: etat,
        state_label: etat === 'stationed' ? 'Stationnee' : 'En route',
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

/**
 * Le mouvement que le serveur publie pour le segment d'une patrouille en vol, tel que
 * `FleetMovementProjection::project()` le compose.
 */
function unMouvementDePatrouille(patrolId, { galaxie = 1, systeme = 5 } = {}) {
    return {
        id: 900 + patrolId,
        mission_type: 11,
        label: 'Patrouille',
        side: 'friendly',
        is_return: false,
        patrol_id: patrolId,
        from: { galaxy: galaxie, system: systeme, position: 4, type: 1, x: null, y: null },
        to: { galaxy: galaxie, system: systeme, position: 0, type: 5, x: -660, y: 580 },
        time_departure: 1_700_000_000,
        time_arrival: 1_700_000_600
    };
}

function reponse(galaxie, systeme, patrouilles, maintenant = 1_700_000_100, mouvements = []) {
    return {
        success: true,
        galaxy: galaxie,
        system: systeme,
        server_now: maintenant,
        movements: mouvements,
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

/**
 * **La fiche ne sort jamais de la carte.**
 *
 * Keven l'a vue deborder : « le menu rentre dans la map au complet ». Je l'avais posee par
 * `style.left/top` bruts au point clique, en contournant `placer()` — qui est precisement ce qui la
 * borne. Les deux chemins partagent desormais le meme calcul.
 *
 * Le temoin clique **au bord droit**, la ou le debordement se produit, et exige que la fiche reste
 * entre les marges. jsdom ne mesure pas les hauteurs (`offsetHeight` vaut zero), donc c'est le
 * bornage horizontal qui est etabli ici — celui que la capture montrait.
 */
test('la fiche de composition reste dans la carte, meme au bord', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, []));

        // Tout au bord droit de la carte, a hauteur du centre : le pire cas.
        monde.cliquer(monde.carte(), 640, 294);

        const f = monde.fiche();

        assert.ok(f && f.gtOrdre, 'la premisse manque : aucune composition ouverte au bord');

        const gauche = parseInt(f.style.left, 10);

        assert.ok(Number.isFinite(gauche), 'la fiche n a pas de position horizontale');
        assert.ok(gauche >= 8, 'la fiche sort par la gauche de la carte : left = ' + gauche);
        assert.ok(gauche <= 656 - 320 - 8, 'la fiche sort par la droite de la carte : left = ' + gauche);
    } finally {
        monde.fermer();
    }
});

/**
 * **Hors du systeme, rien ne s'ouvre.**
 *
 * Keven a clique bien au-dela de la derniere orbite : la composition s'ouvrait, et le serveur aurait
 * refuse au devis (`point_outside_system`). Le joueur composait une flotte pour rien.
 *
 * Les deux moities comptent : dehors rien ne s'ouvre, **et dedans tout s'ouvre encore**. Sans la
 * seconde, une borne trop serree fermerait la carte entiere sans que personne ne le voie.
 */
test('cliquer hors du systeme n ouvre pas la composition', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, []));

        // Le coin de la carte : bien au-dela de la derniere orbite.
        monde.cliquer(monde.carte(), 650, 20);

        const f = monde.fiche();

        assert.equal(
            f === null || f.hidden === true || !f.gtOrdre,
            true,
            'un clic hors du systeme ouvre la composition : le joueur composera une flotte pour rien'
        );
    } finally {
        monde.fermer();
    }
});

test('cliquer dans le systeme ouvre toujours la composition', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, []));

        // Pres du centre mais hors de l'etoile : dans l'anneau valide.
        monde.cliquer(monde.carte(), 400, 294);

        const f = monde.fiche();

        assert.ok(f && f.gtOrdre, 'un clic dans le systeme n ouvre plus rien : la borne est trop serree');
    } finally {
        monde.fermer();
    }
});

/**
 * **La carte annonce ses gestes.**
 *
 * Une fonction qu'on ne peut pas deviner n'existe pas : le clic sur une case vide et le glisser
 * d'une patrouille ne s'annoncaient nulle part, et Keven l'a dit — « via la vue galaxy c'est pas
 * trop clair ».
 */
test('la carte affiche la legende de ses deux gestes', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);

        const lignes = monde.window.document.querySelectorAll('#galaxyTactical .gtHints .gtHint');

        assert.equal(lignes.length, 2, 'la legende ne montre pas ses deux gestes');
        assert.ok(
            Array.from(lignes).every((l) => (l.textContent || '').trim().length > 0),
            'une ligne de la legende est vide : le joueur lit une icone sans phrase'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Chantier eteint, la carte n'annonce rien.** Annoncer un geste que le serveur refusera est pire
 * que de ne rien dire.
 */
test('chantier eteint, aucune legende', () => {
    const monde = unMonde();

    try {
        monde.window.galaxyPatrolsEnabled = false;
        monde.amorcer(1, 5, [uneLigne(4)]);

        assert.equal(
            monde.window.document.querySelector('#galaxyTactical .gtHints'),
            null,
            'la carte annonce des gestes que le serveur refuse'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Et la legende ne mange pas le clic qu'elle explique.**
 *
 * Elle repose dans le coin bas gauche de la carte — exactement sur la surface ou le joueur doit
 * cliquer pour lancer une patrouille. Un clic a cet endroit doit traverser et ouvrir la composition.
 *
 * jsdom ne fait pas de test de survol : il ne choisit pas la cible par `pointer-events`. Ce temoin
 * etablit donc que le gestionnaire ne se laisse pas arreter par un clic **venu de** la legende ; la
 * regle de style qui rend cette traversee possible dans un vrai navigateur est epinglee a part, dans
 * `GalaxyTacticalMapTest`.
 */
test('un clic sur la legende ouvre quand meme la composition', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, []));

        const legende = monde.window.document.querySelector('#galaxyTactical .gtHints');
        assert.ok(legende, 'la premisse manque : aucune legende sur la carte');

        monde.cliquer(legende, 40, 300);

        const f = monde.fiche();

        assert.ok(f && f.gtOrdre, 'un clic dans le coin de la legende ne compose rien : ce coin de la carte est mort');
    } finally {
        monde.fermer();
    }
});

/** Le libelle du bouton qui termine la composition, s'il y en a un. */
function boutonDeFin(monde) {
    const boutons = boutonsDuPanneau(monde);

    return boutons.length > 0 ? boutons[boutons.length - 1] : null;
}

/**
 * **Cliquer une case vide compose une patrouille qui partira la.**
 *
 * Demande de Keven, 11 septembre 2026. C'est l'entree inverse de celle qui existait : au lieu
 * d'ouvrir sa planete, de composer, puis de choisir ou, on designe l'endroit d'abord.
 */
test('cliquer une case vide ouvre la composition d une patrouille', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, []));

        monde.cliquer(monde.carte(), 400, 294);

        const f = monde.fiche();

        assert.ok(f, 'aucune fiche ne s est ouverte sur un clic dans le vide');
        assert.equal(f.hidden, false, 'la fiche est ouverte mais cachee');
        assert.ok(f.gtOrdre, 'la fiche s ouvre sans ordre a composer');
        assert.equal(f.gtOrdre.genre, 'launch', 'le clic n ouvre pas un lancement');
        assert.equal(f.gtOrdre.etape, 'flotte', 'le clic n ouvre pas la composition de la flotte');
        assert.ok(f.gtOrdre.destinationImposee, 'la destination cliquee n est pas retenue');
    } finally {
        monde.fermer();
    }
});

/**
 * **Et elle part la, sans redemander ou.** C'est la moitie que Keven a decrite : une fois la flotte
 * formee, elle s'en va vers l'endroit clique. Reproposer « choisir la destination » lui ferait
 * refaire le geste qu'il vient de faire.
 */
test('la composition issue d un clic demande le devis, pas une destination', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, []));
        monde.cliquer(monde.carte(), 400, 294);

        assert.equal(boutonDeFin(monde), 'Devis', 'le bouton de fin de composition : ' + boutonsDuPanneau(monde).join(' | '));
    } finally {
        monde.fermer();
    }
});

/**
 * **Un ordre en cours n'est jamais ecrase.** Le clic appartiendrait alors a cet ordre, et l'ecraser
 * ferait perdre au joueur la flotte qu'il vient de composer.
 */
test('cliquer le vide pendant un ordre en cours ne l ecrase pas', () => {
    const monde = unMondeEnTrainDeViser([uneLigne(4)]);

    try {
        const avant = monde.fiche().gtOrdre;
        assert.equal(avant.genre, 'move', 'la premisse manque : aucun ordre de deplacement en cours');

        monde.cliquer(monde.carte(), 400, 294);

        assert.equal(monde.fiche().gtOrdre.genre, 'move', 'le clic a remplace l ordre en cours par un lancement');
    } finally {
        monde.fermer();
    }
});

/**
 * **La carte n'offre pas ce que le serveur refusera.** Chantier eteint, composer une flotte ne
 * menerait qu'a un devis refuse : le joueur aurait travaille pour rien.
 */
test('chantier eteint, cliquer le vide n ouvre rien', () => {
    const monde = unMonde();

    try {
        monde.window.galaxyPatrolsEnabled = false;
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, []));

        monde.cliquer(monde.carte(), 400, 294);

        const f = monde.fiche();

        assert.equal(f === null || f.hidden === true || !f.gtOrdre, true, 'un chantier eteint ouvre quand meme la composition');
    } finally {
        monde.fermer();
    }
});

/**
 * **Un ordre accepte previent le bandeau du jeu — les deux moities, panneau replie compris.**
 *
 * Keven, 12 septembre 2026 : le bandeau compact se mettait a jour, mais la liste depliee « plus de
 * details » gardait l'etat d'avant et demandait un rechargement de page. Les deux se chargent
 * separement ; il faut donc les prevenir toutes les deux.
 *
 * Et prevenir ne suffisait pas. `refreshFleetEvents(force)` ne va chercher la liste que si le
 * panneau est **deja deplie** — il ne l est jamais pendant qu on glisse une patrouille — et le jeu
 * retient qu elle a ete chargee une fois, donc l ouvrir ensuite ne la redemande pas non plus. Le
 * joueur lisait l etat d avant son ordre jusqu au prochain rechargement de page. Ce temoin exige
 * donc **l effet** — la liste effectivement redemandee, panneau replie —, pas la trace de l appel.
 */
test('un ordre accepte previent le bandeau et la liste, panneau replie compris', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unePatrouille()]));

        // Un ordre de deplacement, jusqu'a la confirmation.
        monde.geste(monde.marqueur(3), 'dragstart');
        monde.relacher(monde.carte(), 400, 294);

        assert.equal(monde.envois.length, 1, 'aucun devis n est parti');

        monde.envois[0].repondre({
            success: true,
            quote: {
                possible: true,
                order_version: 1,
                duration_seconds: 60,
                fuel_cost: 1,
                distance: 10,
                speed_percent: 10,
                reserve_on_arrival: 100,
                safety_return_cost: 1,
                safety_return_seconds: 60,
                autonomy_seconds: null,
                destination: { galaxy: 1, system: 5, orbit: 0, type: 5, x: 400, y: 0 }
            }
        });

        const confirmer = Array.from(monde.window.document.querySelectorAll('.gtAction'))
            .find((b) => (b.textContent || '').trim().toLowerCase() === 'confirmer');

        assert.ok(confirmer, 'aucun bouton de confirmation apres un devis : ' + Array.from(monde.window.document.querySelectorAll('.gtAction')).map((b) => b.textContent).join(' | '));

        confirmer.dispatchEvent(new monde.window.MouseEvent('click', { bubbles: true, cancelable: true }));

        assert.equal(monde.envois.length, 2, 'la confirmation n est pas partie');

        monde.envois[1].repondre({ success: true, message: 'Ordre transmis.' });

        assert.deepEqual(
            monde.prevenus.sort(),
            ['bandeau', 'liste'],
            'le jeu n a pas ete prevenu des deux cotes : ' + JSON.stringify(monde.prevenus)
        );

        assert.equal(monde.panneauDEvenements.replie, true, 'la premisse manque : le panneau est deja deplie, le defaut ne peut pas se produire');
        assert.equal(
            monde.panneauDEvenements.chargements,
            1,
            'la liste n a pas ete redemandee : panneau replie, le joueur lira l etat d avant son ordre jusqu au prochain rechargement de page'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Une patrouille qui arrive PENDANT qu on regarde perd sa ligne tout de suite.**
 *
 * Le masquage vivait d abord dans la fonction de **dessin**, qui ne rejoue qu a chaque chargement
 * de donnees : la ligne restait figee jusqu au suivant, et Keven devait recharger la page. Le
 * replacement des marqueurs, lui, tourne sur `requestAnimationFrame` — il voit l instant passer.
 *
 * Ce temoin attend donc du **temps reel** : le mouvement arrive une seconde apres le dessin, et la
 * ligne doit disparaitre sans que rien ne soit recharge.
 */
test('une patrouille qui arrive en cours de route perd sa ligne sans rechargement', async () => {
    const monde = unMonde();

    try {
        const maintenant = Math.floor(Date.now() / 1000);
        const bientot = unMouvementDePatrouille(3);

        bientot.time_departure = maintenant - 60;
        bientot.time_arrival = maintenant + 1;

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unePatrouille({ etat: 'en_route' })], maintenant, [bientot]));

        const groupe = monde.window.document.querySelector('.gtMovement');

        assert.ok(groupe, 'la premisse manque : aucun mouvement dessine');
        assert.notEqual(groupe.style.display, 'none', 'le mouvement est masque avant meme d etre arrive');

        await new Promise((suite) => setTimeout(suite, 1500));

        assert.equal(
            groupe.style.display,
            'none',
            'la ligne survit a l arrivee : il faut recharger la page pour la voir partir'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Posee, une patrouille porte le meme vaisseau que sur une trajectoire.**
 *
 * Decision de Keven, 12 septembre 2026 : « je veux le meme que le trajet ». Deux icones l ont
 * precedee — un glyphe « pause » qu il lisait comme un artefact, puis une fleche dessinee pour
 * l occasion qui ressemblait au vaisseau sans etre lui.
 *
 * Ce temoin exige l **egalite des deux sources**, jamais un nom de fichier ecrit ici : le jour ou
 * le jeu changera son vaisseau, la regle « les deux sont le meme » doit tenir sans qu on y touche.
 */
test('une patrouille posee porte le meme vaisseau que sur une trajectoire', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unePatrouille()], 1_700_000_100, [unMouvementDePatrouille(9)]));

        const posee = monde.marqueur(3).querySelector('img');
        const enVol = monde.window.document.querySelector('.gtFleetMarker image');

        assert.ok(posee, 'la patrouille posee n a pas d image');
        assert.ok(enVol, 'la premisse manque : aucun marqueur de mouvement sur la carte');

        const surLaCarte = posee.getAttribute('src');
        const surLaTrajectoire = enVol.getAttributeNS('http://www.w3.org/1999/xlink', 'href');

        assert.equal(
            surLaCarte,
            surLaTrajectoire,
            'posee et en vol ne montrent pas le meme vaisseau : ' + surLaCarte + ' contre ' + surLaTrajectoire
        );

        // Et le chemin absolu n a pas ete prefixe une seconde fois.
        assert.equal(
            surLaCarte.indexOf('/img/galaxy-tactical/'),
            -1,
            'le chemin du jeu a ete prefixe par celui du pack : l image serait un cadre vide'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Une patrouille arrivee ne traine plus sa trajectoire.**
 *
 * Le segment d'une patrouille posee reste `processed = 0` — c'est ce qui lui donne son creneau de
 * flotte —, donc le serveur continue de le publier comme mouvement. La carte dessinait sa ligne
 * indefiniment, vers un point ou la flotte etait deja posee. Signale par Keven, 12 septembre 2026.
 *
 * Les deux moities comptent : arrivee, plus rien ; en vol, la trajectoire reste. Sans la seconde,
 * masquer toutes les trajectoires passerait le premier temoin.
 */
test('une patrouille arrivee ne dessine plus sa trajectoire', () => {
    const monde = unMonde();

    try {
        const arrive = unMouvementDePatrouille(3);
        arrive.time_arrival = 1_700_000_050; // avant le `server_now` de la reponse

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unePatrouille()], 1_700_000_100, [arrive]));

        assert.equal(
            monde.window.document.querySelector('.gtTrajectory'),
            null,
            'la trajectoire survit a l arrivee : une ligne figee reste sur la carte'
        );
        assert.ok(monde.marqueur(3), 'la patrouille posee a disparu de la carte avec sa trajectoire');
    } finally {
        monde.fermer();
    }
});

test('une patrouille encore en vol garde sa trajectoire', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unePatrouille({ etat: 'en_route' })], 1_700_000_100, [unMouvementDePatrouille(3)]));

        assert.ok(
            monde.window.document.querySelector('.gtTrajectory'),
            'la trajectoire d une flotte en vol a disparu : on ne voit plus ou elle va'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **En vol, une patrouille n'est qu'un triangle blanc.**
 *
 * Decision de Keven, 12 septembre 2026 : elle dessinait deux marqueurs au meme endroit — le triangle
 * que le jeu dessine pour toute flotte, et l'icone de patrouille par-dessus.
 */
test('une patrouille en vol ne dessine pas d icone de patrouille', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unePatrouille({ etat: 'en_route' })], 1_700_000_100, [unMouvementDePatrouille(3)]));

        assert.equal(monde.marqueur(3), null, 'la patrouille en vol dessine encore son icone : deux marqueurs pour une flotte');
        assert.ok(
            monde.window.document.querySelector('.gtFleetMarker--patrol'),
            'aucun triangle ne porte la patrouille : elle ne serait plus selectionnable du tout'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Et posee, elle garde la sienne** — aucun triangle ne vole pour elle.
 *
 * Sans cette moitie, masquer l'icone dans tous les etats passerait le temoin precedent et ferait
 * disparaitre les patrouilles posees de la carte.
 */
test('une patrouille posee garde son icone', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unePatrouille()]));

        assert.ok(monde.marqueur(3), 'une patrouille posee n a plus de marqueur : elle est invisible sur la carte');
    } finally {
        monde.fermer();
    }
});

/**
 * **Le triangle est la poignee** : cliquer dessus ouvre la fiche, donc le rappel reste possible en
 * vol. C'est la moitie fonctionnelle de la decision — sans elle, on aurait retire le seul moyen de
 * rappeler une flotte partie.
 */
test('cliquer le triangle d une patrouille ouvre sa fiche', () => {
    const monde = unMonde();

    try {
        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [unePatrouille({ etat: 'en_route' })], 1_700_000_100, [unMouvementDePatrouille(3)]));

        const triangle = monde.window.document.querySelector('.gtFleetMarker--patrol');
        assert.ok(triangle, 'la premisse manque : aucun triangle de patrouille');

        triangle.dispatchEvent(new monde.window.MouseEvent('click', { bubbles: true, cancelable: true }));

        const f = monde.fiche();

        assert.ok(f && !f.hidden, 'la fiche ne s ouvre pas : la patrouille en vol est devenue inatteignable');
        assert.equal(Number(f.gtPatrouille && f.gtPatrouille.id), 3, 'la fiche ouverte n est pas celle de cette patrouille');
    } finally {
        monde.fermer();
    }
});

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

/**
 * **Une patrouille qui rentre pendant qu on regarde fait bouger les compteurs.**
 *
 * Keven, 12 septembre 2026 : « quand mes vaisseau rentre de ma patrouille les compteur ici ne ce
 * met pas a jour en temps reel ». Rien ne previent le navigateur : le serveur traite une arrivee a
 * la premiere requete qui lui parvient, et sans requete il ne se passe rien du tout.
 *
 * Ce temoin prend le cas **ou le defaut vivait** : une patrouille au retour n a aucun marqueur — le
 * triangle de la couche des mouvements la porte —, et le placeur sortait donc avant tout controle.
 *
 * Il exige les deux effets, parce qu ils repondent a deux plaintes distinctes : une demande de
 * flottes (dont la reponse porte les compteurs, et dont le trajet fait avancer l arrivee cote
 * serveur) et une liste d evenements redemandee.
 */
test('une patrouille qui rentre fait redemander les flottes et la liste, sans rechargement', async () => {
    const monde = unMonde();

    try {
        const maintenant = Math.floor(Date.now() / 1000);
        const rentre = unePatrouille({ etat: 'returning' });

        rentre.segment.time_departure = maintenant - 60;
        rentre.segment.time_arrival = maintenant + 1;

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [rentre], maintenant));

        const flottesAvant = monde.demandes.length;
        const listeAvant = monde.panneauDEvenements.chargements;

        await new Promise((suite) => setTimeout(suite, 2600));

        assert.ok(
            monde.demandes.length > flottesAvant,
            'personne ne redemande les flottes a l arrivee : les compteurs gardent la valeur d avant le retour jusqu a la prochaine veille'
        );
        assert.ok(
            monde.panneauDEvenements.chargements > listeAvant,
            'la liste d evenements n est pas redemandee a l arrivee : la mission finie y reste affichee'
        );
    } finally {
        monde.fermer();
    }
});

/**
 * **Le cas comparable, et il compte autant que le precedent.**
 *
 * Une patrouille posee garde un segment dont l arrivee est **deja passee** — c est ce segment qui
 * lui donne son creneau de flotte, et le serveur continue de le publier. Declencher sur l etat au
 * lieu de la bascule relancerait donc une demande a chaque reponse, indefiniment : une carte
 * ouverte sur une patrouille posee mitraillerait le serveur.
 *
 * Sans ce temoin, la garde « sur une transition observee seulement » pourrait disparaitre sans que
 * rien ne rougisse.
 */
test('une patrouille deja posee au chargement ne redemande rien', async () => {
    const monde = unMonde();

    try {
        const maintenant = Math.floor(Date.now() / 1000);
        const posee = unePatrouille();

        posee.segment.time_departure = maintenant - 600;
        posee.segment.time_arrival = maintenant - 300;

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [posee], maintenant));

        const flottesAvant = monde.demandes.length;

        await new Promise((suite) => setTimeout(suite, 2600));

        assert.equal(
            monde.demandes.length,
            flottesAvant,
            'une patrouille posee relance des demandes : son arrivee est passee depuis toujours, et la carte la redecouvre a chaque image'
        );
    } finally {
        monde.fermer();
    }
});
/**
 * **Deux patrouilles qui rentrent a la meme seconde valent une seule demande.**
 *
 * Avec une seule patrouille, fondre les demandes ou ne pas les fondre donne le meme compte : le
 * juste et le faux coincident, et le temoin precedent resterait vert si la fusion disparaissait.
 * Deux arrivees simultanees rendent le faux observable — c est tout l objet de cet essai.
 */
test('deux patrouilles qui rentrent ensemble ne font qu une demande', async () => {
    const monde = unMonde();

    try {
        const maintenant = Math.floor(Date.now() / 1000);
        const rentrer = (id) => {
            const p = unePatrouille({ id, etat: 'returning' });

            p.segment.time_departure = maintenant - 60;
            p.segment.time_arrival = maintenant + 1;

            return p;
        };

        monde.amorcer(1, 5, [uneLigne(4)]);
        monde.demandes[0].repondre(reponse(1, 5, [rentrer(3), rentrer(4)], maintenant));

        const flottesAvant = monde.demandes.length;

        await new Promise((suite) => setTimeout(suite, 2600));

        assert.equal(
            monde.demandes.length,
            flottesAvant + 1,
            'deux arrivees simultanees ont declenche ' + (monde.demandes.length - flottesAvant) + ' demandes : elles ne sont pas fondues'
        );
    } finally {
        monde.fermer();
    }
});