/*
 * La pastille de courrier, en direct.
 *
 * ## Ce qu'elle corrige
 *
 * Le nombre est calcule au rendu de la page par `IngameMainComposer` et ecrit en dur dans le HTML.
 * Rien ne le reprend ensuite : un courrier arrive pendant qu'on joue reste invisible jusqu'au
 * rechargement suivant, et un courrier lu laisse sa pastille allumee tout aussi longtemps.
 *
 * ## Le serveur compte, le navigateur affiche
 *
 * L'evenement porte le **total**, jamais un increment. Un increment se perd — deux courriers
 * pendant une reconnexion, un onglet endormi — et rien ne le rattrape. Un total perdu est corrige
 * par le suivant. Ce module ne calcule donc rien : il ecrit ce qu'il recoit.
 *
 * ## Sans Echo, l'ancien comportement
 *
 * Si le canal direct n'est pas disponible, ce module ne fait rien et la pastille reste celle du
 * rendu — exactement ce qu'elle est aujourd'hui. Une degradation, pas une panne.
 */
(function () {
    'use strict';

    /* Le titre de l'onglet sans son prefixe de comptage, capture avant toute modification. */
    var titreDeBase = null;

    function leLien() {
        return document.querySelector('#message-wrapper a.messages');
    }

    /*
     * La pastille du courrier. Elle **n'existe pas dans le HTML quand il n'y a rien a lire** — le
     * gabarit l'entoure d'une condition —, donc le premier courrier recu doit la creer.
     */
    function laPastille(lien, creerSiAbsente) {
        var pastille = lien.querySelector('.new_msg_count');

        if (pastille || !creerSiAbsente) {
            return pastille;
        }

        pastille = document.createElement('span');
        pastille.className = 'new_msg_count totalMessages news';
        lien.appendChild(pastille);

        return pastille;
    }

    /*
     * Le titre de l'onglet porte le nombre, comme sur OGame officiel.
     *
     * Le titre de reference est capture au premier passage : le relire ensuite reprendrait un
     * titre deja prefixe, et deux courriers donneraient « (2) (1) Vue generale ».
     */
    function marquerLeTitre(nombre) {
        if (titreDeBase === null) {
            titreDeBase = document.title.replace(/^\(\d+\)\s*/, '');
        }

        document.title = nombre > 0 ? '(' + nombre + ') ' + titreDeBase : titreDeBase;
    }

    function afficher(nombre) {
        var lien = leLien();

        if (!lien) {
            return;
        }

        var pastille = laPastille(lien, nombre > 0);

        if (pastille) {
            if (nombre > 0) {
                pastille.setAttribute('data-new-messages', String(nombre));
                pastille.textContent = String(nombre);
                pastille.classList.remove('noMessage');
            } else {
                // **Retiree, pas videe** : une pastille vide garde son fond et son cadre, et le
                // joueur voit une bulle sans chiffre au lieu de rien.
                pastille.parentNode.removeChild(pastille);
            }
        }

        var intitule = lien.getAttribute('title');

        if (intitule) {
            lien.setAttribute('title', intitule.replace(/^\d+/, String(nombre)));
        }

        marquerLeTitre(nombre);
    }

    function ecouter() {
        if (typeof window.Echo === 'undefined' || typeof window.Echo.private !== 'function') {
            return;
        }

        if (typeof playerId === 'undefined' || !playerId) {
            return;
        }

        try {
            window.Echo.private('messages.player.' + playerId)
                .listen('.UnreadMessageCountChanged', function (recu) {
                    if (!recu || typeof recu.unread === 'undefined') {
                        return;
                    }

                    var nombre = parseInt(recu.unread, 10);

                    if (isNaN(nombre) || nombre < 0) {
                        return;
                    }

                    afficher(nombre);
                });
        } catch (e) {
            // Un abonnement impossible laisse la pastille du rendu : c'est l'etat d'avant.
            if (window.console && window.console.error) {
                window.console.error('Pastille de courrier : abonnement impossible', e);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ecouter);
    } else {
        ecouter();
    }
})();
