/*
 * Ce que la barre de chat fait REELLEMENT d'un message quand l'envoi echoue ou est refuse (constats de Keven,
 * 19 septembre 2026, journal §167).
 *
 * Deux defauts, tous deux dans le fonctionnement que le nouveau design a conserve :
 *
 * 1. **Un message pouvait disparaitre sans etre envoye.** Le champ etait vide des l'envoi, avant la reponse du
 *    serveur, et le gestionnaire d'erreur etait vide : une connexion coupee effacait le texte, sans un mot.
 * 2. **Un refus d'acces se rejouait en boucle.** Sur `NOT_AUTHORIZED`, le script renvoyait aussitot la meme requete,
 *    sans limite — or c'est un vrai refus du serveur (le joueur n'est plus dans l'alliance), que rien ne leve.
 *
 * Le module `chat.js` est charge tel quel, avec le **vrai** jQuery du jeu ; seul `$.ajax` est remplace par un faux qui
 * **retient** chaque requete, pour que l'essai y reponde lui-meme — succes, coupure, statut d'erreur, refus. Ce qui est
 * observe : les requetes qui partent, le champ, et ce que le joueur lit.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const JQUERY = new URL('../../resources/js/ingame/jquery-1.12.4.min.js', import.meta.url);
const SOURCE = new URL('../../resources/js/ingame/chat.js', import.meta.url);

const LOCA = {
    TEXT_EMPTY: 'Le message est vide.',
    TEXT_TOO_LONG: 'Le message est trop long.',
    NETWORK_FAILED: 'Le message n a pas pu etre envoye. Votre texte est conserve.',
    NOT_AUTHORIZED: 'Vous ne pouvez plus ecrire dans cette conversation.'
};

/**
 * Une barre de chat avec une conversation d'alliance ouverte, et un faux `$.ajax` qui retient ce qui part.
 */
function unMonde() {
    const dom = new JSDOM(
        '<!doctype html><html><body><div class="chat_box" data-associationid="42"><textarea class="chat_box_textarea"></textarea></div></body></html>',
        { runScripts: 'dangerously', url: 'https://exemple.test/overview' }
    );
    const { window } = dom;

    const jq = window.document.createElement('script');
    jq.textContent = readFileSync(JQUERY, 'utf8');
    window.document.head.appendChild(jq);

    const requetes = [];
    window.$.ajax = function (options) {
        requetes.push({
            donnees: options.data,
            repondre(reponse) { options.success(reponse); },
            couper() { options.error({ status: 0 }, 'error', ''); }
        });
    };

    const avis = [];
    window.ogame = {};
    window.chatUrl = '/ajax/chat';
    window.chatLoca = LOCA;
    window.LocalizationStrings = { error: 'Erreur', ok: 'OK' };
    window.errorBoxNotify = function (titre, texte) {
        avis.push(texte);
    };

    const script = window.document.createElement('script');
    script.textContent = readFileSync(SOURCE, 'utf8');
    window.document.body.appendChild(script);

    // Ce qu'une confirmation declenche en plus du champ : sans objet ici.
    window.ogame.chat.addChatItem = () => {};
    window.ogame.chat.cleanupUrl = () => {};

    const champ = window.$('.chat_box_textarea');

    return {
        window,
        champ,
        requetes,
        avis,
        /** Le joueur tape ce texte puis appuie sur Entree — la touche a deja insere son saut de ligne. */
        envoyer(texte) {
            champ.val(texte + '\n');
            window.ogame.chat.submitChatBarMsg(champ, 13, false, 0);
        }
    };
}

test('le texte reste dans le champ jusqu a la confirmation, et une seconde Entree ne part pas', () => {
    const monde = unMonde();

    monde.envoyer('Bonjour');

    assert.equal(monde.requetes.length, 1, 'Le message part.');
    assert.equal(monde.requetes[0].donnees.associationId, 42);
    assert.equal(monde.champ.val(), 'Bonjour\n', 'Le texte n est pas vide avant la reponse du serveur.');
    assert.equal(monde.champ.prop('readonly'), true, 'Pendant l envoi, le champ ne se modifie pas.');

    monde.window.ogame.chat.submitChatBarMsg(monde.champ, 13, false, 0);
    assert.equal(monde.requetes.length, 1, 'Une seconde Entree pendant l envoi ne fait pas partir un double.');
});

test('une confirmation vide le champ et le rend', () => {
    const monde = unMonde();
    monde.envoyer('Bonjour');

    monde.requetes[0].repondre({ status: 'OK', text: 'Bonjour', id: 7, targetAssociationId: 42, date: 0 });

    assert.equal(monde.champ.val(), '', 'Envoye : le champ se vide.');
    assert.equal(monde.champ.prop('readonly'), false);
    assert.deepEqual(monde.avis, [], 'Aucun avertissement pour un envoi reussi.');
});

test('une coupure rend le brouillon tel que tape, et le dit', () => {
    const monde = unMonde();
    monde.envoyer('Bonjour');

    monde.requetes[0].couper();

    assert.equal(monde.champ.val(), 'Bonjour', 'Le brouillon est rendu, sans le saut de ligne de la touche Entree.');
    assert.equal(monde.champ.prop('readonly'), false, 'Le champ se modifie de nouveau.');
    assert.deepEqual(monde.avis, [LOCA.NETWORK_FAILED], 'Le joueur lit que le message n est pas parti.');

    // Et il peut reessayer : un second envoi part, et un seul.
    monde.window.ogame.chat.submitChatBarMsg(monde.champ, 13, false, 0);
    assert.equal(monde.requetes.length, 2, 'Le nouvel essai part.');
});

test('un statut d erreur du serveur rend aussi le brouillon', () => {
    const monde = unMonde();
    monde.envoyer('Un tres long message');

    monde.requetes[0].repondre({ status: 'TEXT_TOO_LONG' });

    assert.equal(monde.champ.val(), 'Un tres long message');
    assert.deepEqual(monde.avis, [LOCA.TEXT_TOO_LONG]);
});

test('un refus ne se rejoue pas : il est dit une fois, et la conversation se ferme', () => {
    const monde = unMonde();
    monde.envoyer('Bonjour l alliance');

    monde.requetes[0].repondre({ status: 'NOT_AUTHORIZED' });

    assert.equal(monde.requetes.length, 1, 'Le refus ne fait partir AUCUNE nouvelle requete : il bouclait sans fin.');
    assert.deepEqual(monde.avis, [LOCA.NOT_AUTHORIZED], 'Le refus est dit, une fois.');
    assert.equal(monde.champ.prop('disabled'), true, 'La conversation est desactivee jusqu a l actualisation des droits.');
    assert.equal(monde.champ.attr('placeholder'), LOCA.NOT_AUTHORIZED, 'Le refus se lit dans le champ lui-meme.');
    assert.equal(monde.champ.val(), 'Bonjour l alliance', 'Le texte n est pas perdu pour autant.');
});
