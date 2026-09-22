/*
 * La fiche publique d une alliance, en fenetre superposee au jeu.
 *
 * ## Un lien ordinaire, une fenetre du jeu
 *
 * Les liens publics gardent leur adresse reelle en `href` et portent `data-alliance-profile="<id>"`. Ils ne
 * portent NI la classe `overlay` NI `overlay=1` : ces marqueurs declenchent le gestionnaire historique du jeu,
 * qui fait `preventDefault` sur tous les clics, modifies compris — Ctrl-clic ouvrirait la fenetre au lieu d un
 * onglet. Ici, un clic simple ouvre la fenetre ; un clic modifie (Ctrl, Cmd, Maj, Alt) ou du bouton du milieu
 * laisse le navigateur faire, et l acces direct sert la page autonome habillee. Le parametre `overlay=1` n est
 * ajoute que par le module, pour charger le seul fragment.
 *
 * ## Une seule requete, et la 404 s affiche
 *
 * `openOverlay(url)` charge par `$.get`, dont le rappel de succes n est jamais appele sur une 404 : la fenetre
 * resterait sur son chargement. La fenetre est donc ouverte par `openOverlay` en mode `inline` — c est LE
 * mecanisme du jeu qui tient le fond, la croix, Echap et le focus —, et le fragment est demande par UNE requete
 * dont l echec est traite : une 404 injecte le fragment d erreur du serveur, toute autre panne un message.
 *
 * ## Une fenetre par alliance, pas par titre
 *
 * `openOverlay` refuse un second dialogue du meme TITRE. Deux alliances ont deux titres (le tag y figure), et
 * le module reconnait de surcroit une fenetre deja ouverte par `data-alliance-id` : un second clic la ramene
 * au premier plan au lieu d en ouvrir une autre. L apostrophe droite du titre francais casserait le selecteur
 * `:contains('...')` du jeu : elle est rendue typographique dans le titre de la fenetre.
 *
 * ## La candidature
 *
 * Meme route POST, memes conditions cote serveur, meme confirmation. Le succes ferme la fiche par le dialogue —
 * jamais l onglet ; en page autonome le bouton reste desactive avec son libelle « envoyee ». Un refus ou une
 * panne laisse la fiche utilisable, bouton rendu, et n annonce aucun succes.
 *
 * Les gestionnaires sont delegues sur le document UNE fois, sous un espace de nom : un fragment reinjecte
 * n en repose aucun.
 */
(function () {
    'use strict';

    var CLASSE = 'allianceProfile';
    var ESPACE = '.azriaAllianceProfile';

    function textes() {
        var localisation = window.LocalizationStrings || {};

        return localisation.allianceProfile || {};
    }

    function titreDe(tag) {
        var titre = textes().title || 'Alliance';

        return (titre + (tag ? ' [' + tag + ']' : '')).replace(/'/g, '’');
    }

    function fenetreDe(id) {
        return jQuery('.overlayDiv.' + CLASSE + '[data-alliance-id="' + String(id).replace(/[^0-9]/g, '') + '"]');
    }

    function messageDErreur(texte) {
        return jQuery('<div>').addClass('azria-alliance-profile ap-missing').attr('role', 'alert')
            .append(jQuery('<p>').text(texte));
    }

    function recentrer(fenetre) {
        try {
            if (fenetre.hasClass('ui-dialog-content')) {
                fenetre.dialog('option', 'position', fenetre.dialog('option', 'position'));
            }
        } catch (e) {
            // Un dialogue deja ferme n a plus de position.
        }
    }

    function ouvrir(href, declencheur, id, tag) {
        var existante = fenetreDe(id);

        if (existante.length) {
            try {
                existante.dialog('moveToTop');
            } catch (e) {
                // Le dialogue existe sans etre encore initialise : rien a ramener.
            }

            return existante;
        }

        var chargement = jQuery('<p>').addClass('ap-loading').attr('role', 'status')
            .text(textes().loading || (window.LocalizationStrings && window.LocalizationStrings.loading) || '…');
        var fenetre = jQuery('<div>').addClass('overlayDiv ' + CLASSE)
            .attr('data-alliance-id', id)
            .css('display', 'none')
            .append(chargement)
            .appendTo('body');
        var largeur = Math.min(600, Math.max(280, jQuery(window).width() - 20));

        openOverlay(fenetre, {
            type: 'inline',
            title: titreDe(tag),
            width: largeur,
            close: function () {
                try {
                    fenetre.dialog('destroy');
                } catch (e) {
                    // Deja detruit.
                }

                fenetre.remove();

                if (declencheur && typeof declencheur.focus === 'function' && document.body.contains(declencheur)) {
                    declencheur.focus();
                }
            }
        });

        jQuery.ajax({
            url: href + (href.indexOf('?') === -1 ? '?' : '&') + 'overlay=1',
            type: 'GET',
            dataType: 'html'
        }).done(function (html) {
            fenetre.empty().append(html);
            recentrer(fenetre);
        }).fail(function (xhr) {
            var corps = xhr && xhr.status === 404 && xhr.responseText
                ? jQuery(jQuery.parseHTML(xhr.responseText))
                : messageDErreur(textes().loadFailed || 'Erreur');

            fenetre.empty().append(corps);
            recentrer(fenetre);
        });

        return fenetre;
    }

    function message(texte, echec) {
        if (typeof fadeBox === 'function') {
            fadeBox(texte, !!echec);
        } else {
            window.alert(texte);
        }
    }

    function estUnClicOrdinaire(e) {
        return !(e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || (e.which && e.which !== 1));
    }

    jQuery(document).off('click' + ESPACE).on('click' + ESPACE, 'a[data-alliance-profile]', function (e) {
        if (!estUnClicOrdinaire(e)) {
            return;
        }

        if (typeof openOverlay !== 'function' || typeof jQuery.fn.dialog !== 'function') {
            return;
        }

        e.preventDefault();
        // **Le meme clic ne doit pas remonter jusqu a `html`** : `initHideElements()` y ferme tout dialogue
        // ouvert des qu un clic tombe hors d un `.ui-dialog` — et le lien est hors du dialogue qu il vient
        // d ouvrir. Le gestionnaire historique des overlays s en protege par `return false` ; meme geste ici.
        // Mesure du 22 septembre 2026 : sans cela la fenetre s ouvrait puis se fermait dans la meme milliseconde.
        e.stopPropagation();
        ouvrir(this.getAttribute('href'), this, this.getAttribute('data-alliance-profile'), this.getAttribute('data-alliance-tag') || '');
    });

    jQuery(document).off('click' + ESPACE + 'Apply').on('click' + ESPACE + 'Apply', '.azria-alliance-profile .js_allianceApply', function (e) {
        e.preventDefault();

        var bouton = jQuery(this);
        var donnees = this.dataset || {};

        if (bouton.prop('disabled')) {
            return;
        }

        var lancer = function () {
            bouton.prop('disabled', true);

            jQuery.ajax({
                url: donnees.applyUrl,
                type: 'POST',
                dataType: 'json',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                data: { alliance_id: donnees.allianceId, message: '', _token: donnees.token }
            }).done(function (reponse) {
                if (reponse && reponse.success) {
                    message(reponse.message || donnees.success, false);

                    var fenetre = bouton.closest('.overlayDiv.' + CLASSE);

                    if (fenetre.length) {
                        fenetre.dialog('close');
                    } else {
                        bouton.text(donnees.applied || donnees.success);
                    }

                    return;
                }

                message((reponse && reponse.message) || donnees.error, true);
                bouton.prop('disabled', false);
            }).fail(function (xhr) {
                message((xhr && xhr.responseJSON && xhr.responseJSON.message) || donnees.error, true);
                bouton.prop('disabled', false);
            });
        };

        if (typeof errorBoxDecision === 'function' && window.LocalizationStrings) {
            errorBoxDecision(window.LocalizationStrings.attention, donnees.confirm, window.LocalizationStrings.yes, window.LocalizationStrings.no, lancer);
        } else if (window.confirm(donnees.confirm)) {
            lancer();
        }
    });

    window.AzriaAllianceProfile = {
        open: ouvrir,
        windowOf: fenetreDe,
        titleOf: titreDe,
        isPlainClick: estUnClicOrdinaire
    };
})();
