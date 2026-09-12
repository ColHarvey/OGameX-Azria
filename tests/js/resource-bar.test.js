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

/** Une reponse du serveur telle que `/ajax/resourcebox` la rend. */
function uneReponse(metal = 1000, { storage = 10000, production = 0.5, tooltip = 'Metal|<table></table>' } = {}) {
    return {
        resources: {
            metal: { amount: metal, storage, baseProduction: 0, production, tooltip, classesListItem: '' },
            crystal: { amount: 500, storage: 10000, baseProduction: 0, production: 0.25, tooltip: 'Cristal|<table></table>', classesListItem: '' },
            deuterium: { amount: 100, storage: 10000, baseProduction: 0, production: 0.1, tooltip: 'Deuterium|<table></table>', classesListItem: '' },
            energy: { amount: 20, tooltip: 'Energie|<table></table>', classesListItem: '' },
            darkmatter: { amount: 0, tooltip: 'Matiere noire|<table></table>', classesListItem: '' }
        },
        techs: {},
        honorScore: 11
    };
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
        ? '<div id="resourcesbarcomponent"' + (avecAdresse ? ' data-resourcebox-url="' + ADRESSE + '"' : '') + '><span id="resources_metal">0</span></div>'
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
                energy: { amount: 0 }, darkmatter: { amount: 0 }
            },
            redessins: 0,
            refresh() { this.redessins++; }
        };
    }

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
        infobulles: () => infobullesRefaites,
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

test('une infobulle est refaite des qu un de ses faits change', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000));

    // Une mine finit : la production horaire change, donc le texte de l infobulle aussi.
    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1000, { production: 0.9, tooltip: 'Metal|<table>autre</table>' }));

    assert.equal(monde.infobulles(), 2, 'Une infobulle dont le texte change n est pas refaite : le joueur lit une production perimee.');

    monde.fermer();
});

test('un stockage agrandi refait les infobulles', () => {
    const monde = unMonde();

    monde.laVeille().fonction();
    monde.demandes[0].repondre(uneReponse(1000));

    monde.laVeille().fonction();
    monde.demandes[1].repondre(uneReponse(1000, { storage: 20000, tooltip: 'Metal|<table>plus grand</table>' }));

    assert.equal(monde.infobulles(), 2);

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
