/*
 * Ce que le bandeau des ressources fait REELLEMENT dans un navigateur.
 *
 * Le module `resource-bar.js` est charge tel quel dans un document jsdom qui porte le bandeau avec
 * son adresse (`data-resourcebox-url`), les balises `meta` que le jeu publie (`ogame-planet-id`,
 * `ogame-player-id`) et les fonctions du jeu dont il depend (`reloadResources`, le compteur
 * `resourcesBar`, un talon `getAjaxResourcebox` a remplacer). Ce qui est observe : les demandes qui
 * partent et ce qu'elles portent, ce que le compteur recoit, les rappels livres.
 *
 * ## Le faux porte la regle du vrai
 *
 * Le faux `reloadResources` refait les infobulles, comme le vrai (`ResourceTicker.reload()` appelle
 * `changeTooltip`, qui detruit puis recree) : c'est la seule facon de voir qu'une infobulle
 * survolee disparaitrait. Le faux compteur porte `resources` et `refresh()`, comme le vrai, et
 * `refresh()` ne touche a aucune infobulle.
 *
 * ## Le temps est tenu, pas attendu
 *
 * La veille tourne toutes les trente secondes ; un essai qui l attendrait durerait trente secondes.
 * Le monde retient chaque minuterie armee et l essai declenche celle qu il vise.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const SOURCE = new URL('../../resources/js/ingame/resource-bar.js', import.meta.url);
const ADRESSE = '/ajax/resourcebox';

/** Les titres d infobulle que le serveur rend, tels que les reponses du banc les portent. */
const TITRES = { metal: 'Metal', crystal: 'Cristal', deuterium: 'Deuterium', energy: 'Energie', darkmatter: 'Matiere noire', population: 'Population', food: 'Nourriture' };

/** Une reponse du serveur telle que `/ajax/resourcebox` la rend. */
function uneReponse(metal = 1000, {
    storage = 10000, production = 0.5, tooltip = 'Metal|<table></table>',
    productionHeure = 1800, energie = { production: 100, consumption: 80 },
    vie = null, instant = null, corps = null
} = {}) {
    const reponse = {
        resources: {
            metal: { amount: metal, storage, baseProduction: 0, production, tooltip, classesListItem: '' },
            crystal: { amount: 500, storage: 10000, baseProduction: 0, production: 0.25, tooltip: 'Cristal|<table></table>', classesListItem: '' },
            deuterium: { amount: 100, storage: 10000, baseProduction: 0, production: 0.1, tooltip: 'Deuterium|<table></table>', classesListItem: '' },
            energy: { amount: 20, tooltip: 'Energie|<table></table>', classesListItem: '' },
            darkmatter: { amount: 0, tooltip: 'Matiere noire|<table></table>', classesListItem: '' }
        },
        // **La structure dediee** : les faits derriere chaque infobulle, en nombres. Le montant n en fait pas
        // partie — c est tout l objet de la correction.
        facts: {
            metal: { storage, production_hour: productionHeure },
            crystal: { storage: 10000, production_hour: 900 },
            deuterium: { storage: 10000, production_hour: 360 },
            energy: { production: energie.production, consumption: energie.consumption },
            darkmatter: {}
        },
        techs: {},
        honorScore: 11
    };

    if (instant !== null) { reponse.generated_at = instant; }
    if (corps !== null) { reponse.body = corps; }

    if (vie !== null) {
        reponse.resources.population = { amount: vie.population, storage: vie.espaceVital, baseProduction: 0, production: vie.croissance, tooltip: vie.infobullePopulation || 'Population|<table></table>', classesListItem: '' };
        reponse.resources.food = { amount: vie.nourriture, storage: vie.grenier, baseProduction: 0, production: vie.bilan, tooltip: vie.infobulleNourriture || 'Nourriture|<table></table>', classesListItem: '' };
        reponse.facts.population = { cap: vie.espaceVital, per_second: vie.croissance, stable_for: vie.popStable === undefined ? null : vie.popStable, inhabitants_fed: vie.nourris || 0, full: !!vie.plein };
        reponse.facts.food = { cap: vie.grenier, per_second: vie.bilan, stable_for: vie.foodStable === undefined ? null : vie.foodStable, production_hour: 0, consumption_hour: 0, runs_out_in: null };
    }

    return reponse;
}

/**
 * Un faux jQuery qui retient ses demandes au lieu de les envoyer. Le faux porte la regle du vrai :
 * `always` tourne apres `done` comme apres `fail`.
 */
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
            repondre(reponse) { if (rappels.done) { rappels.done(reponse); } if (rappels.always) { rappels.always(); } },
            echouer(erreur) { if (rappels.fail) { rappels.fail(erreur); } if (rappels.always) { rappels.always(); } }
        });

        return api;
    };

    return { jq, demandes };
}

/**
 * Un monde complet : le bandeau dans le document, le compteur du jeu, le module charge.
 */
function unMonde({ avecDiffuseur = true, avecBarre = true, avecAdresse = true, avecCorps = true, avecCompteur = true, amorcer = true } = {}) {
    const bandeau = avecBarre
        ? '<div id="resourcesbarcomponent"' + (avecAdresse ? ' data-resourcebox-url="' + ADRESSE + '"' : '') + '><span id="resources_metal">0</span>'
            + Object.entries(TITRES)
                .map(([nom, titre]) => '<li id="' + nom + '_box" title="' + titre + '|<table></table>"></li>').join('')
            + '</div>'
        : '<div id="rien"></div>';
    const metas = '<meta name="ogame-player-id" content="7">'
        + (avecCorps ? '<meta name="ogame-planet-id" content="4242">' : '');

    const dom = new JSDOM('<!doctype html><html><head>' + metas + '</head><body>' + bandeau + '</body></html>', {
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        url: 'https://exemple.test/overview'
    });

    const { window } = dom;
    const { jq, demandes } = faireJQuery();

    window.jQuery = jq;
    window.$ = jq;

    /*
     * Les infobulles du jeu : `changeTooltip()` les detruit puis les recree a chaque
     * `reloadResources`. Le monde les compte pour qu une infobulle perdue se voie.
     */
    let infobullesRefaites = 0;

    /* Le compteur du jeu, reduit a ce que le module touche : les montants et le redessin. */
    if (avecCompteur) {
        window.resourcesBar = {
            resources: {
                metal: { amount: 0 }, crystal: { amount: 0 }, deuterium: { amount: 0 },
                energy: { amount: 0 }, darkmatter: { amount: 0 },
                population: { amount: 0 }, food: { amount: 0 }
            },
            redessins: 0,
            refresh() { this.redessins++; }
        };
    }

    /*
     * `changeTooltip()` du jeu : il DETRUIT puis recree l infobulle de la boite qu on lui donne. Le faux porte la
     * regle du vrai — il note quelle boite a ete refaite —, sinon une reconstruction de trop passerait inapercue.
     */
    const refaites = [];
    window.changeTooltip = function (boite, texte) {
        const element = boite && boite.length ? boite[0] : boite;
        refaites.push({ boite: element && element.id, texte });
    };

    const recharges = [];
    window.reloadResources = function (donnees, rappel) {
        recharges.push(donnees);
        // Le vrai refait les cinq infobulles : le faux porte la regle du vrai.
        infobullesRefaites++;

        if (window.resourcesBar) {
            Object.keys(donnees.resources).forEach((nom) => {
                window.resourcesBar.resources[nom] = Object.assign({}, donnees.resources[nom]);
            });
        }

        if (typeof rappel === 'function') {
            rappel(donnees.resources);
        }
    };

    /* Le talon vide du bundle d origine, que le module doit remplacer. */
    const talon = function () {};
    window.getAjaxResourcebox = talon;

    const ecouteurs = {};
    /* Chaque abonnement est compte : deux ecouteurs sur la meme clef seraient invisibles autrement. */
    const abonnements = [];

    if (avecDiffuseur) {
        window.Echo = {
            private: (nom) => ({
                listen: (evenement, rappel) => { ecouteurs[nom + evenement] = rappel; abonnements.push(nom + evenement); }
            })
        };
    }

    /*
     * **L horloge du monde.** L animation lit `Date.now()` et calcule le temps ECOULE depuis la derniere charge :
     * compter les battements aurait masque un battement perdu ou double. Le banc la pilote donc a la seconde.
     */
    let horloge = 1_700_000_000_000;
    window.Date.now = () => horloge;

    const veilles = [];
    const setIntervalReel = window.setInterval.bind(window);

    window.setInterval = (fonction, delai) => {
        veilles.push({ fonction, delai });

        return setIntervalReel(fonction, delai);
    };

    /* La visibilite de l onglet, pilotable. */
    let cache = false;
    Object.defineProperty(window.document, 'hidden', { get: () => cache, configurable: true });
    const rendreVisible = (visible) => {
        cache = !visible;
        window.document.dispatchEvent(new window.Event('visibilitychange'));
    };

    /*
     * **Le document est encore « loading » quand le script tourne** (mesure : jsdom ne passe a
     * « complete » qu apres un tour de boucle). Le module s arme donc au `DOMContentLoaded`, comme
     * dans le navigateur ou le gabarit publie ses balises avant le corps. Le monde le prononce
     * lui-meme ; jsdom le prononcera une seconde fois plus tard, et l armement doit le supporter.
     */
    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    if (amorcer) {
        window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    }

    const unMouvementAnnonce = () => {
        const rappel = ecouteurs['galaxy.player.7.FleetMovementChanged'];

        if (!rappel) {
            throw new Error('le module ne s est pas abonne aux mouvements du joueur');
        }

        rappel({ missionId: 1, from: { galaxy: 1, system: 1 }, to: { galaxy: 1, system: 2 } });
    };

    const laVeille = () => {
        const veille = veilles.find((v) => v.delai === 30000);

        if (!veille) {
            throw new Error('aucune veille de trente secondes n est armee');
        }

        return veille;
    };

    const fermer = () => window.close();

    return {
        window, demandes, recharges, ecouteurs, abonnements, veilles, talon,
        unMouvementAnnonce, laVeille, rendreVisible, fermer,
        /* Le battement d une seconde qui anime la population et la nourriture. */
        lAnimation: () => veilles.find((v) => v.delai === 1000) || null,
        /* L horloge du monde, pilotable : l animation lit le temps ecoule, elle ne compte pas les battements. */
        avancerLHorloge: (secondes) => { horloge += secondes * 1000; },
        infobulles: () => infobullesRefaites,
        refaites,
        /*
         * Une infobulle OUVERTE, telle que Tipped la construit : un noeud `.tpd-tooltip` visible, en fin de
         * document, portant `.htmlTooltip > h1 + table`. Elle n a aucun lien DOM avec sa boite — c est ce qui
         * oblige le module a la retrouver.
         */
        ouvrirLInfobulle: (titre, tableau) => {
            const noeud = window.document.createElement('div');
            noeud.className = 'tpd-tooltip';
            noeud.innerHTML = '<div class="htmlTooltip"><h1>' + titre + '</h1><div class="splitLine"></div>' + tableau + '</div>';
            window.document.body.appendChild(noeud);
            return noeud;
        },
        infobulleOuverte: () => {
            const noeud = window.document.querySelector('.tpd-tooltip');
            return noeud ? { noeud, texte: noeud.textContent.replace(/\s+/g, ' ').trim() } : null;
        },
        titreDe: (nom) => {
            const boite = window.document.getElementById(nom + '_box');
            return boite ? boite.getAttribute('title') : null;
        },
        compteur: () => window.resourcesBar
    };
}

test('le module remplace le talon du jeu sous le nom que le jeu appelle', () => {
    const monde = unMonde();

    assert.notEqual(monde.window.getAjaxResourcebox, monde.talon, 'getAjaxResourcebox est encore le talon vide : les vingt-cinq appels du jeu ne font toujours rien');
    assert.equal(typeof monde.window.getAjaxResourcebox, 'function');

    monde.fermer();
});

test('la demande part vers l adresse du bandeau et nomme la planete de la page', () => {
    const monde = unMonde();

    monde.unMouvementAnnonce();

    assert.equal(monde.demandes.length, 1);
    assert.equal(monde.demandes[0].url, ADRESSE, 'L adresse ne vient pas du bandeau.');
    // Les objets naissent dans jsdom : comparer leur contenu, pas leur prototype (realmes distincts).
    assert.deepEqual(Object.assign({}, monde.demandes[0].donnees), { body: 4242 }, 'La demande ne nomme pas la planete de la page : un autre onglet ferait afficher une autre planete.');

    monde.demandes[0].repondre(uneReponse(4242));

    assert.equal(monde.recharges.length, 1);
    assert.equal(monde.recharges[0].resources.metal.amount, 4242);

    monde.fermer();
});

test('sans planete dans la page, la demande part quand meme et sans corps', () => {
    const monde = unMonde({ avecCorps: false });

    monde.laVeille().fonction();

    assert.equal(monde.demandes.length, 1);
    assert.deepEqual(Object.assign({}, monde.demandes[0].donnees), {}, 'Un corps invente voyagerait avec la demande.');

    monde.fermer();
});

test('une infobulle survolee survit a une synchronisation qui ne change que les montants', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000));
    assert.equal(monde.infobulles(), 1, 'La premiere charge doit passer par le chemin complet.');

    // Trente secondes plus tard : la production a rempli les caisses, rien d autre n a bouge.
    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1015));

    assert.equal(monde.infobulles(), 1, 'Les infobulles ont ete refaites alors qu aucune ne change : celle que le joueur survole se ferme sous son curseur.');
    assert.equal(monde.compteur().resources.metal.amount, 1015, 'Le montant n a pas ete ecrit dans le compteur : le bandeau reste sur l ancienne valeur.');
    assert.equal(monde.compteur().redessins, 1, 'Le compteur n a pas redessine : le nouveau montant ne s affiche pas.');

    monde.fermer();
});

test('un fait qui change met a jour la seule infobulle concernee, en place', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000));

    // Une mine finit : la production horaire change, donc le texte de l infobulle aussi.
    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1000, { productionHeure: 3240, tooltip: 'Metal|<table>autre</table>' }));

    assert.deepEqual(monde.refaites.map((r) => r.boite), [], 'Une infobulle a ete reconstruite : initTooltips() fermerait au passage celle d une autre tuile.');
    assert.match(monde.titreDe('metal'), /autre/, 'Le nouveau texte du metal n a pas ete pose.');
    assert.equal(monde.titreDe('crystal'), TITRES.crystal + '|<table></table>', 'Le cristal, que rien ne concerne, a ete touche.');
    assert.equal(monde.infobulles(), 1, 'Le chemin complet ne sert qu a la premiere charge.');

    monde.fermer();
});

test('un montant qui change seul n en refait aucune, et met l infobulle ouverte a jour en place', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000, { tooltip: 'Metal|<table><tr><th>Disponible:</th><td><span>1.000</span></td></tr></table>' }));

    const ouverte = monde.ouvrirLInfobulle('Metal', '<table><tr><th>Disponible:</th><td><span>1.000</span></td></tr></table>');

    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1200, { tooltip: 'Metal|<table><tr><th>Disponible:</th><td><span>1.200</span></td></tr></table>' }));

    assert.deepEqual(monde.refaites.map((r) => r.boite), [], 'Une infobulle a ete detruite alors que seul le montant a bouge : elle se ferme sous le curseur du joueur.');
    assert.equal(monde.infobulleOuverte().noeud, ouverte, 'Le noeud de l infobulle a ete remplace : ce n est plus une mise a jour en place.');
    assert.match(monde.infobulleOuverte().texte, /1\.200/, 'L infobulle ouverte porte encore l ancien montant.');
    assert.match(monde.titreDe('metal'), /1\.200/, 'Le titre n a pas ete repose : la prochaine ouverture montrerait l ancien montant.');

    monde.fermer();
});

test('plusieurs valeurs qui changent d un coup : chaque infobulle concernee suit, et elle seule', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000));

    monde.ouvrirLInfobulle('Energie', '<table><tr><th>Production:</th><td><span>100</span></td></tr></table>');

    // Une centrale finit : l energie change de faits ; le metal ne change que de montant.
    monde.laVeille().fonction();
    const reponse = uneReponse(1100, { tooltip: 'Metal|<table>metal neuf</table>', energie: { production: 300, consumption: 80 } });
    reponse.resources.energy.tooltip = 'Energie|<table><tr><th>Production:</th><td><span>300</span></td></tr></table>';
    monde.demandes[1].repondre(reponse);

    assert.deepEqual(monde.refaites.map((r) => r.boite), [], 'Une infobulle a ete reconstruite.');
    assert.match(monde.titreDe('energy'), /300/, 'L energie a change de faits : son titre devait etre repose en place.');
    assert.match(monde.titreDe('metal'), /metal neuf/, 'Le metal a change de montant : son titre devait etre repose en place.');

    monde.fermer();
});

test('un stockage agrandi met a jour l infobulle du metal, en place', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000));

    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1000, { storage: 20000, tooltip: 'Metal|<table>plus grand</table>' }));

    assert.deepEqual(monde.refaites.map((r) => r.boite), []);
    assert.match(monde.titreDe('metal'), /plus grand/);

    monde.fermer();
});

test('une reponse plus ancienne que la derniere appliquee, pour le meme corps, est ignoree', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    // Une depense a eu lieu : le stock tombe a 200, et cette reponse-la est la plus recente.
    monde.demandes[0].repondre(uneReponse(200, { instant: 1000.5 }));
    assert.equal(monde.window.resourcesBar.resources.metal.amount, 200);

    // Une reponse partie AVANT la depense arrive maintenant : l appliquer remettrait 5000 a l ecran.
    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(5000, { instant: 999.25 }));

    assert.equal(monde.window.resourcesBar.resources.metal.amount, 200, 'Une reponse perimee a ete appliquee : le joueur revoit le stock d avant sa depense.');

    // Et la suivante, plus recente, passe : la garde ne bloque pas le chemin.
    monde.laVeille().fonction();
    monde.demandes[2].repondre(uneReponse(260, { instant: 1030.75 }));
    assert.equal(monde.window.resourcesBar.resources.metal.amount, 260);

    monde.fermer();
});

test('une reponse qui parle d un autre corps est ignoree', () => {
    // Le corps de la page est 4242 (balise `meta` du monde).
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000, { corps: 4242, instant: 10 }));
    assert.equal(monde.window.resourcesBar.resources.metal.amount, 1000);

    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(7777, { corps: 99, instant: 20 }));

    assert.equal(monde.window.resourcesBar.resources.metal.amount, 1000, 'Le bandeau a pris les chiffres d un autre corps.');

    monde.fermer();
});

test('sans compteur du jeu, le chemin complet reprend la main', () => {
    const monde = unMonde({ avecCompteur: false });

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000));
    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1015));

    assert.equal(monde.infobulles(), 2, 'Sans compteur a ecrire, la reponse doit passer par reloadResources, sinon le montant est perdu.');

    monde.fermer();
});

test('deux annonces pendant un vol ne font qu une demande de plus, apres la reponse', () => {
    const monde = unMonde();

    monde.unMouvementAnnonce();
    monde.unMouvementAnnonce();
    monde.unMouvementAnnonce();

    assert.equal(monde.demandes.length, 1, 'des demandes se sont empilees pendant le vol');

    monde.demandes[0].repondre(uneReponse());

    assert.equal(monde.demandes.length, 2, 'l annonce recue pendant le vol a ete perdue : le bandeau raterait le dernier fait');

    monde.demandes[1].repondre(uneReponse());

    assert.equal(monde.demandes.length, 2, 'une demande due est repartie deux fois');

    monde.fermer();
});

test('le rappel d un appelant recoit les ressources, meme s il est arrive pendant un vol', () => {
    const monde = unMonde();
    const recus = [];

    monde.window.getAjaxResourcebox((ressources) => recus.push(['premier', ressources.metal.amount]));
    monde.window.getAjaxResourcebox((ressources) => recus.push(['second', ressources.metal.amount]));

    assert.equal(monde.demandes.length, 1);

    monde.demandes[0].repondre(uneReponse(10));

    assert.deepEqual(recus, [['premier', 10]], 'le rappel arrive pendant le vol a ete livre avec une reponse qui ne le concernait pas');

    monde.demandes[1].repondre(uneReponse(20));

    assert.deepEqual(recus, [['premier', 10], ['second', 20]], 'le rappel arrive pendant le vol n a jamais ete livre');

    monde.fermer();
});

test('un rappel est livre aussi quand seuls les montants changent', () => {
    const monde = unMonde();
    const recus = [];

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000));

    monde.window.getAjaxResourcebox((ressources) => recus.push(ressources.metal.amount));
    monde.demandes[1].repondre(uneReponse(1015));

    assert.deepEqual(recus, [1015], 'Le chemin court oublie de livrer les rappels : le marchand attendrait indefiniment.');

    monde.fermer();
});

test('une demande echouee ne bloque pas les suivantes', () => {
    const monde = unMonde();

    monde.unMouvementAnnonce();
    monde.demandes[0].echouer(new Error('reseau'));

    assert.equal(monde.recharges.length, 0, 'un echec a ete applique comme une reponse');

    monde.unMouvementAnnonce();

    assert.equal(monde.demandes.length, 2, 'apres un echec, plus rien ne part : la demande est restee « en vol »');

    monde.fermer();
});

test('apres trois echecs la veille se tait, et une annonce la relance', () => {
    const monde = unMonde();

    for (let i = 0; i < 3; i++) {
        monde.laVeille().fonction();
        monde.demandes[i].echouer(new Error('serveur'));
    }

    assert.equal(monde.demandes.length, 3);

    monde.laVeille().fonction();
    assert.equal(monde.demandes.length, 3, 'La veille continue de frapper un serveur en panne : cent vingt requetes et cent vingt traces par heure.');

    monde.unMouvementAnnonce();
    assert.equal(monde.demandes.length, 4, 'Une annonce ne relance pas : le bandeau resterait mort jusqu au rechargement.');
    monde.demandes[3].repondre(uneReponse());

    monde.laVeille().fonction();
    assert.equal(monde.demandes.length, 5, 'Une reponse reussie ne remet pas le compteur d echecs a zero.');

    monde.fermer();
});

test('une reponse reussie efface les echecs precedents', () => {
    const monde = unMonde();

    /*
     * Deux echecs, une reussite, deux echecs : le compte doit repartir de zero a la reussite,
     * sinon quatre echecs etales sur une panne intermittente feraient taire la veille alors que
     * le serveur repond une fois sur trois.
     */
    for (let i = 0; i < 2; i++) {
        monde.laVeille().fonction();
        monde.demandes[i].echouer(new Error('serveur'));
    }

    monde.laVeille().fonction();
    monde.demandes[2].repondre(uneReponse());

    for (let i = 3; i < 5; i++) {
        monde.laVeille().fonction();
        monde.demandes[i].echouer(new Error('serveur'));
    }

    monde.laVeille().fonction();

    assert.equal(monde.demandes.length, 6, 'Les echecs d avant une reponse reussie comptent encore : la veille se tait trop tot.');

    monde.fermer();
});
test('le retour sur l onglet relance meme apres trois echecs', () => {
    const monde = unMonde();

    for (let i = 0; i < 3; i++) {
        monde.laVeille().fonction();
        monde.demandes[i].echouer(new Error('serveur'));
    }

    monde.rendreVisible(false);
    monde.rendreVisible(true);

    assert.equal(monde.demandes.length, 4, 'Le retour sur l onglet ne relance pas apres une panne.');

    /*
     * Et il RELEVE la veille : meme si cette demande-la echoue encore, le compte est reparti de
     * zero, donc la veille suivante repart. Sans cela, un onglet qui a connu trois echecs resterait
     * muet jusqu au rechargement de la page.
     */
    monde.demandes[3].echouer(new Error('serveur'));
    monde.laVeille().fonction();

    assert.equal(monde.demandes.length, 5, 'Le retour sur l onglet n a pas releve la veille : elle reste muette pour toujours.');

    monde.fermer();
});

test('une reponse sans ressources est ignoree, et la suivante repart', () => {
    const monde = unMonde();

    monde.unMouvementAnnonce();
    monde.demandes[0].repondre({ pas: 'ca' });

    assert.equal(monde.recharges.length, 0, 'une reponse sans forme a ete appliquee au compteur');

    monde.unMouvementAnnonce();

    assert.equal(monde.demandes.length, 2);

    monde.fermer();
});

test('un rappel qui echoue n empeche ni les autres rappels ni la synchronisation suivante', () => {
    const monde = unMonde();
    monde.window.console.error = () => {};
    const recus = [];

    /* Les deux rappels dans le meme lot : pris pendant un vol, ils partent ensemble avec la demande due. */
    monde.unMouvementAnnonce();
    monde.window.getAjaxResourcebox(() => { throw new Error('rappel casse'); });
    monde.window.getAjaxResourcebox(() => recus.push('livre'));
    monde.demandes[0].repondre(uneReponse());

    assert.equal(monde.demandes.length, 2);

    monde.demandes[1].repondre(uneReponse());

    assert.deepEqual(recus, ['livre'], 'le rappel casse a prive celui qui le suivait');

    monde.unMouvementAnnonce();

    assert.equal(monde.demandes.length, 3, 'un rappel casse a laisse la demande « en vol »');

    monde.fermer();
});

test('reloadResources qui leve ne laisse pas la demande en vol pour toujours', () => {
    const monde = unMonde();
    monde.window.console.error = () => {};
    monde.window.reloadResources = () => { throw new Error('infobulle absente'); };

    monde.unMouvementAnnonce();
    monde.demandes[0].repondre(uneReponse());
    monde.unMouvementAnnonce();

    assert.equal(monde.demandes.length, 2, 'apres une exception du jeu, plus aucune synchronisation ne part');

    monde.fermer();
});

test('la veille de trente secondes demande, sauf onglet cache ou demande en vol', () => {
    const monde = unMonde();
    const veille = monde.laVeille();

    veille.fonction();
    assert.equal(monde.demandes.length, 1, 'la veille n a rien demande');

    veille.fonction();
    assert.equal(monde.demandes.length, 1, 'la veille a redemande pendant un vol');

    monde.demandes[0].repondre(uneReponse());
    assert.equal(monde.demandes.length, 1, 'la veille a marque une demande due pendant son vol : elle en fait deux au lieu d une');

    monde.rendreVisible(false);
    veille.fonction();
    assert.equal(monde.demandes.length, 1, 'un onglet cache a demande');

    monde.fermer();
});

test('le retour sur l onglet resynchronise tout de suite', () => {
    const monde = unMonde();

    monde.rendreVisible(false);
    assert.equal(monde.demandes.length, 0, 'cacher l onglet a demande');

    monde.rendreVisible(true);
    assert.equal(monde.demandes.length, 1, 'de retour sur l onglet, rien ne part avant la veille suivante');

    monde.fermer();
});

test('sans Echo, la veille seule reste armee', () => {
    const monde = unMonde({ avecDiffuseur: false });

    assert.equal(Object.keys(monde.ecouteurs).length, 0);
    monde.laVeille().fonction();

    assert.equal(monde.demandes.length, 1, 'sans canal direct, plus aucune synchronisation');

    monde.fermer();
});

test('sans bandeau dans la page, rien n est arme', () => {
    const monde = unMonde({ avecBarre: false });

    assert.equal(Object.keys(monde.ecouteurs).length, 0, 'le module s est abonne sur une page sans bandeau');
    assert.equal(monde.veilles.filter((v) => v.delai === 30000).length, 0, 'une veille tourne sur une page sans bandeau');

    monde.fermer();
});

test('sans adresse sur le bandeau, rien ne part et rien ne casse', () => {
    const monde = unMonde({ avecAdresse: false });

    assert.doesNotThrow(() => monde.unMouvementAnnonce());
    assert.equal(monde.demandes.length, 0);

    monde.fermer();
});

test('sans jQuery ou sans reloadResources, rien ne part et rien ne casse', () => {
    const sansJQuery = unMonde();
    delete sansJQuery.window.jQuery;
    assert.doesNotThrow(() => sansJQuery.unMouvementAnnonce());
    assert.equal(sansJQuery.demandes.length, 0);
    sansJQuery.fermer();

    const sansJeu = unMonde();
    delete sansJeu.window.reloadResources;
    assert.doesNotThrow(() => sansJeu.unMouvementAnnonce());
    assert.equal(sansJeu.demandes.length, 0, 'Une demande part alors que rien ne saurait appliquer la reponse.');
    sansJeu.fermer();
});

test('charge pendant que la page se construit, le module s arme au DOMContentLoaded, une seule fois', () => {
    const monde = unMonde({ amorcer: false });

    assert.equal(Object.keys(monde.ecouteurs).length, 0, 'le module s est abonne avant que les balises et le bandeau existent');

    monde.window.document.dispatchEvent(new monde.window.Event('DOMContentLoaded'));
    monde.window.document.dispatchEvent(new monde.window.Event('DOMContentLoaded'));

    assert.equal(monde.abonnements.length, 1, 'deux DOMContentLoaded ont abonne deux fois aux mouvements');
    assert.equal(monde.veilles.filter((v) => v.delai === 30000).length, 1, 'deux DOMContentLoaded ont arme deux veilles');

    /*
     * Deux ecouteurs du retour d onglet ne se voient pas a la premiere demande — le second marque
     * seulement une demande due. Ils se voient a la reponse : une demande de plus repart.
     */
    monde.rendreVisible(false);
    monde.rendreVisible(true);
    assert.equal(monde.demandes.length, 1);
    monde.demandes[0].repondre(uneReponse());
    assert.equal(monde.demandes.length, 1, 'deux DOMContentLoaded ont abonne deux fois le retour d onglet : une demande due de trop');

    monde.fermer();
});

/*
 * ## L animation de la population et de la nourriture
 *
 * Ces deux tuiles ne bougeaient pas du tout : le compteur herite les anime a partir de clefs que le serveur ne
 * publie pas, et le serveur ne projetait pas l horloge demographique sur cette route. Elles sont desormais animees
 * ici, a partir de champs DEDIES — `facts.population` et `facts.food` —, et le taux n est cru que pendant
 * `stable_for` secondes.
 */

const UNE_VIE = {
    population: 1000, espaceVital: 15000, croissance: 2,
    nourriture: 500, grenier: 9000, bilan: 3,
    popStable: null, foodStable: null, nourris: 4000
};

function avecVie(surcharges = {}) {
    return uneReponse(1000, { vie: Object.assign({}, UNE_VIE, surcharges) });
}

test('population et nourriture avancent au taux que le serveur publie', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(avecVie());

    const animation = monde.lAnimation();
    assert.ok(animation, 'aucun battement d animation n est arme alors que les deux tuiles croissent');

    monde.avancerLHorloge(10);
    animation.fonction();

    assert.equal(monde.window.resourcesBar.resources.population.amount, 1020, 'La population n avance pas de sa croissance.');
    assert.equal(monde.window.resourcesBar.resources.food.amount, 530, 'La nourriture n avance pas de son bilan.');

    monde.fermer();
});

test('l energie et la matiere noire ne s accumulent jamais', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(avecVie());

    monde.avancerLHorloge(60);
    monde.lAnimation().fonction();

    assert.equal(monde.window.resourcesBar.resources.energy.amount, 20, 'L energie s est accumulee : c est un bilan, pas un stock.');
    assert.equal(monde.window.resourcesBar.resources.darkmatter.amount, 0, 'La matiere noire s est accumulee toute seule.');

    monde.fermer();
});

test('au plafond d espace vital, la population ne depasse pas et rien n est invente', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    // Cinq secondes avant le plafond : au-dela, le serveur seul sait ce qui suit.
    monde.demandes[0].repondre(avecVie({ population: 14990, croissance: 2, popStable: 5 }));

    monde.avancerLHorloge(60);
    monde.lAnimation().fonction();

    assert.equal(monde.window.resourcesBar.resources.population.amount, 15000, 'La population a depasse son espace vital, ou n a pas atteint son plafond.');
    assert.equal(monde.demandes.length, 2, 'Le regime a change et aucune resynchronisation n a ete demandee : la tuile resterait sur une valeur inventee.');

    monde.fermer();
});

test('nourriture epuisee : l animation s arrete a zero et redemande l etat', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    // Dix secondes de reserve a -5 par seconde : la famine commence ensuite, et ses regles ne s interpolent pas.
    monde.demandes[0].repondre(avecVie({ nourriture: 50, bilan: -5, foodStable: 10, croissance: 0, popStable: 0 }));

    monde.avancerLHorloge(30);
    monde.lAnimation().fonction();

    assert.equal(monde.window.resourcesBar.resources.food.amount, 0, 'La nourriture est passee sous zero, ou ne s est pas videe.');
    assert.equal(monde.demandes.length, 2, 'La famine commence et rien n a ete redemande au serveur.');

    monde.fermer();
});

test('rien ne s anime quand aucun taux ne bouge', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(avecVie({ croissance: 0, bilan: 0 }));

    assert.equal(monde.lAnimation(), null, 'Un battement tourne alors que rien ne croit : il ecrirait une progression inventee.');

    monde.fermer();
});

test('onglet masque : l animation ne court pas, et le retour resynchronise', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(avecVie());

    monde.rendreVisible(false);
    monde.avancerLHorloge(20);
    monde.lAnimation().fonction();

    assert.equal(monde.window.resourcesBar.resources.population.amount, 1000, 'La tuile a avance alors que l onglet est masque.');

    monde.rendreVisible(true);
    assert.equal(monde.demandes.length, 2, 'Le retour sur l onglet n a pas redemande l etat.');

    monde.fermer();
});

test('la page a amorce le compteur : la premiere resynchronisation ne refait aucune infobulle', () => {
    const monde = unMonde();

    // Le gabarit a deja donne au compteur ses textes d infobulle (`reloadResources(@json(...))`).
    ['metal', 'crystal', 'deuterium', 'energy', 'darkmatter'].forEach((nom) => {
        monde.window.resourcesBar.resources[nom].tooltip = TITRES[nom] + '|<table><tr><td>amorce</td></tr></table>';
        monde.window.document.getElementById(nom + '_box').setAttribute('title', TITRES[nom] + '|<table><tr><td>amorce</td></tr></table>');
    });
    // Des lignes, comme le serveur les rend : du texte nu dans une table en serait sorti par l analyseur HTML.
    const ouverte = monde.ouvrirLInfobulle('Metal', '<table><tr><td>amorce</td></tr></table>');

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000, { tooltip: 'Metal|<table><tr><td>a jour</td></tr></table>' }));

    assert.equal(monde.infobulles(), 0, 'La premiere reponse a pris le chemin complet alors que la page avait amorce le compteur : toutes les infobulles ont ete refaites, celle du joueur avec.');
    assert.deepEqual(monde.refaites.map((r) => r.boite), [], 'Une infobulle a ete refaite sans qu aucun fait connu ait change.');
    assert.equal(monde.infobulleOuverte().noeud, ouverte, 'L infobulle ouverte a ete detruite a la premiere resynchronisation.');
    assert.match(monde.infobulleOuverte().texte, /a jour/, 'L infobulle ouverte n a pas ete mise a jour en place : ' + monde.infobulleOuverte().texte + ' | title=' + monde.titreDe('metal'));

    // Et la seconde reponse, ou un fait change, s ecrit en place elle aussi.
    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1000, { productionHeure: 9999, tooltip: 'Metal|<table><tr><td>mine finie</td></tr></table>' }));
    assert.deepEqual(monde.refaites.map((r) => r.boite), []);
    assert.match(monde.infobulleOuverte().texte, /mine finie/);

    monde.fermer();
});

test('en famine, un taux qui ne vaut pour aucune seconde n arme aucun battement et ne redemande rien', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(avecVie({ nourriture: 0, bilan: -5, foodStable: 0, croissance: 0, popStable: 0 }));

    assert.equal(monde.lAnimation(), null, 'Un battement tourne alors que le taux ne vaut pour aucune seconde : il redemanderait l etat a chaque seconde.');
    assert.equal(monde.demandes.length, 1, 'Une requete est partie sans qu aucun battement ait franchi de borne : la boucle de requetes de la famine.');

    monde.fermer();
});

test('l infobulle ouverte est reconnue par son titre, meme derriere un autre noeud visible', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000, { tooltip: 'Metal|<table><tr><td>avant</td></tr></table>' }));

    // Un noeud d un autre survol, visible, vient AVANT le notre dans le document.
    monde.ouvrirLInfobulle('Cristal', '<table><tr><td>autre</td></tr></table>');
    const notre = monde.ouvrirLInfobulle('Metal', '<table><tr><td>avant</td></tr></table>');

    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1200, { tooltip: 'Metal|<table><tr><td>apres</td></tr></table>' }));

    assert.match(notre.textContent, /apres/, 'L infobulle du metal n a pas ete reecrite : le premier noeud visible a ete pris pour la notre.');
    assert.doesNotMatch(monde.window.document.querySelectorAll('.tpd-tooltip')[0].textContent, /apres/, 'Le noeud d un autre survol a ete reecrit avec le texte du metal.');

    monde.fermer();
});

test('la boite sans attribut title (Tipped le retire) : l infobulle ouverte est quand meme reconnue, par la memoire du module', () => {
    const monde = unMonde();

    ['metal', 'crystal', 'deuterium', 'energy', 'darkmatter'].forEach((nom) => {
        monde.window.resourcesBar.resources[nom].tooltip = TITRES[nom] + '|<table><tr><td>amorce</td></tr></table>';
        // Comme dans le jeu : Tipped a lu le title et l a retire de la boite.
        monde.window.document.getElementById(nom + '_box').removeAttribute('title');
    });
    const ouverte = monde.ouvrirLInfobulle('Metal', '<table><tr><td>amorce</td></tr></table>');

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000, { tooltip: 'Metal|<table><tr><td>a jour</td></tr></table>' }));

    assert.equal(monde.infobulleOuverte().noeud, ouverte);
    assert.match(monde.infobulleOuverte().texte, /a jour/, 'Sans attribut title, l infobulle ouverte n a pas ete reconnue : la premiere resynchronisation ne la met pas a jour.');
    assert.match(monde.titreDe('metal'), /a jour/, 'Le title n a pas ete repose.');

    monde.fermer();
});
