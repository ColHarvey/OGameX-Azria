/*
 * Les batailles en cours, dans les bandeaux du jeu.
 *
 * Ce script fait trois choses, et rien de plus :
 *
 *   1. il pose dans le bandeau ferme une indication discrete — icone et libelle — quand une
 *      bataille est en cours, a partir de ce que la boite d'evenements du jeu renvoie deja ;
 *   2. il remplace, dans le deroulant « Evenements », **les seules cartes de combat** — jamais le
 *      deroulant entier : cela fermerait un detail ouvert et deplacerait le defilement du joueur ;
 *   3. il ouvre et ferme le detail d'une carte, comme le jeu ouvre celui d'une union.
 *
 * Ce qu'il ne fait jamais : calculer une perte, deduire une frontiere de round, armer un compte a
 * rebours sur la fin de la bataille, ou choisir l'heure et le joueur. Le serveur seul les connait,
 * et il n'envoie aucune echeance — ni instant, ni secondes restantes.
 *
 * Le transport nominal est la diffusion serveur (Reverb) ; ce rafraichissement periodique est le
 * secours degrade, quand la connexion temps reel n'est pas disponible. Sa cadence est fixe, jamais
 * annoncee : un « prochaine mise a jour dans N secondes » revelerait les periodes de la bataille.
 */
(function () {
    var SECOURS_EN_COMBAT = 10000;
    var SECOURS_AU_REPOS = 60000;

    var minuterie = null;
    var requeteEnCours = false;

    function url() {
        return typeof combatRowsUrl === 'undefined' ? null : combatRowsUrl;
    }

    function corpsDesCombats() {
        return document.getElementById('combatEvents');
    }

    function afficheUnCombat() {
        var corps = corpsDesCombats();

        return corps !== null && corps.querySelector('.combatEvent') !== null;
    }

    /**
     * Les identifiants des cartes dont le detail est ouvert, pour les rouvrir apres remplacement.
     */
    function detailsOuverts() {
        var ouverts = [];
        var corps = corpsDesCombats();

        if (!corps) {
            return ouverts;
        }

        var lignes = corps.querySelectorAll('.combatEvent.detailsOpened');

        for (var i = 0; i < lignes.length; i++) {
            ouverts.push(lignes[i].getAttribute('data-combat-id'));
        }

        return ouverts;
    }

    function rouvrir(identifiants) {
        for (var i = 0; i < identifiants.length; i++) {
            basculer(identifiants[i], true);
        }
    }

    /**
     * Ouvre ou ferme le detail d'une carte. `force` ouvre sans basculer.
     */
    function basculer(identifiant, force) {
        var ligne = document.getElementById('combatRow-' + identifiant);
        var details = document.getElementById('combatDetails-' + identifiant);

        if (!ligne || !details) {
            return;
        }

        var ouvrir = force === true || details.style.display === 'none';

        details.style.display = ouvrir ? '' : 'none';
        ligne.classList.toggle('detailsOpened', ouvrir);
        ligne.classList.toggle('detailsClosed', !ouvrir);

        var bouton = ligne.querySelector('.toggleCombat a');

        if (bouton) {
            bouton.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
        }
    }

    /**
     * L'indication du bandeau ferme, posee a cote du resume des missions.
     */
    function marquerLeBandeau(nombre, libelle) {
        var bandeau = document.getElementById('eventboxFilled');

        if (!bandeau) {
            return;
        }

        var marque = bandeau.querySelector('.combatIndicator');

        if (!nombre) {
            if (marque) {
                marque.parentNode.removeChild(marque);
            }

            return;
        }

        if (!marque) {
            marque = document.createElement('span');
            marque.className = 'combatIndicator overmark';
            // L'icone accompagne le libelle : une couleur seule ne se lit pas.
            marque.innerHTML = '<img src="/img/fleet/2.gif" height="12" width="12" alt="" /> <span class="combatIndicator_text"></span>';
            bandeau.insertBefore(marque, bandeau.firstChild);
        }

        var texte = marque.querySelector('.combatIndicator_text');

        if (texte && texte.textContent !== libelle) {
            texte.textContent = libelle;
        }
    }

    function planifier() {
        if (minuterie !== null) {
            window.clearTimeout(minuterie);
        }

        minuterie = window.setTimeout(rafraichir, afficheUnCombat() ? SECOURS_EN_COMBAT : SECOURS_AU_REPOS);
    }

    /**
     * Redemande les cartes et remplace **elles seules**, en rouvrant ce qui l'etait.
     */
    function rafraichir() {
        var adresse = url();
        var corps = corpsDesCombats();

        if (requeteEnCours || !adresse || !corps) {
            planifier();

            return;
        }

        requeteEnCours = true;

        $.ajax({
            url: adresse,
            dataType: 'html',
            success: function (html) {
                var courant = corpsDesCombats();

                if (!courant) {
                    return;
                }

                var ouverts = detailsOuverts();
                courant.innerHTML = html;
                rouvrir(ouverts);
            },
            complete: function () {
                requeteEnCours = false;
                planifier();
            }
        });
    }

    // Le bouton de details, delegue : les cartes sont remplacees, un abonnement direct mourrait
    // avec elles.
    $(document).on('click', '#combatEvents .toggleCombat a', function (e) {
        e.preventDefault();
        var ligne = $(this).closest('.combatEvent');
        basculer(ligne.attr('data-combat-id'), false);

        return false;
    });

    // Le bandeau du jeu se recharge tout seul ; on lit sa reponse au passage pour poser l'indication.
    $(document).ajaxSuccess(function (evenement, requete, options, donnees) {
        if (!options || typeof options.url !== 'string' || options.url.indexOf('/ajax/fleet/eventbox/fetch') === -1) {
            return;
        }

        var lu = donnees;

        if (typeof lu === 'string') {
            try {
                lu = JSON.parse(lu);
            } catch (erreur) {
                return;
            }
        }

        if (lu && typeof lu.combats !== 'undefined') {
            marquerLeBandeau(lu.combats, lu.combatText || '');
        }
    });

    planifier();
})();
