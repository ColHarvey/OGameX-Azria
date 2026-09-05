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
 * Le transport nominal est la **diffusion serveur** : le navigateur ecoute son canal prive et recoit
 * une perte des qu'elle devient visible, sans rechargement ni attente. Le rafraichissement
 * periodique n'est que le secours degrade — connexion coupee, onglet revenu au premier plan,
 * navigateur sans WebSocket. Sa cadence est fixe et jamais annoncee : un « prochaine mise a jour
 * dans N secondes » revelerait les periodes de la bataille.
 *
 * Une perte recue en direct est ajoutee a sa carte et brievement mise en evidence. La diffusion
 * garantit « au moins une fois » : une meme perte peut arriver deux fois, et c'est ce script qui
 * rend la repetition invisible. La clef de deduplication est **(bataille, rang)** — jamais le rang
 * seul, deux batailles simultanees portant chacune un rang 1. Une clef deja affichee n'ajoute rien
 * et ne rejoue aucune animation, ce qui rend une reconnexion silencieuse.
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

    /**
     * Ajoute a sa carte une perte recue en direct, si elle n'y est pas deja.
     *
     * Rend vrai quand la perte a ete ajoutee : un rang deja present n'ajoute rien, et ne rejoue
     * donc aucune animation — c'est ce qui rend une reconnexion silencieuse.
     */
    function ajouterUnePerte(identifiantCombat, perte) {
        var details = document.getElementById('combatDetails-' + identifiantCombat);

        if (!details) {
            return false;
        }

        // **La deduplication porte sur (bataille, rang)**, jamais sur le rang seul : deux
        // batailles simultanees portent chacune un rang 1. La diffusion garantit « au moins une
        // fois » — une meme perte peut donc arriver deux fois, et c'est ici qu'elle est ignoree.
        var clef = perte.key || (identifiantCombat + ':' + perte.sequence);

        if (details.querySelector('li[data-key="' + clef + '"]')) {
            return false;
        }

        var liste = details.querySelector('ul.combatEvent_losses');

        if (!liste) {
            var vide = details.querySelector('.combatEvent_empty');

            if (vide) {
                vide.parentNode.removeChild(vide);
            }

            liste = document.createElement('ul');
            liste.className = 'combatEvent_losses';
            var panneau = details.querySelector('.combatEvent_panel');

            if (!panneau) {
                return false;
            }

            panneau.appendChild(liste);
        }

        var ligne = document.createElement('li');
        ligne.setAttribute('data-key', clef);
        ligne.setAttribute('data-sequence', perte.sequence);
        ligne.className = 'combatEvent_new';

        var heure = document.createElement('span');
        heure.className = 'combatEvent_at';
        heure.textContent = new Date(perte.at * 1000).toLocaleTimeString();

        var texte = document.createElement('span');
        texte.className = 'overmark';
        texte.textContent = perte.amount + ' × ' + perte.unit_label;

        ligne.appendChild(heure);
        ligne.appendChild(texte);
        liste.appendChild(ligne);

        // La mise en evidence retombe d'elle-meme : elle signale une nouveaute, elle ne clignote pas.
        window.setTimeout(function () {
            ligne.classList.remove('combatEvent_new');
        }, 4000);

        return true;
    }

    function mettreAJourLeCumul(identifiantCombat, ajoutees) {
        var ligne = document.getElementById('combatRow-' + identifiantCombat);

        if (!ligne || !ajoutees) {
            return;
        }

        var cumul = ligne.querySelector('.combatEvent_losses_total');

        if (cumul) {
            cumul.textContent = String((parseInt(cumul.textContent, 10) || 0) + ajoutees);
        }
    }

    /**
     * L'abonnement au canal prive du joueur. Sans Echo, le secours periodique suffit.
     */
    function ecouter() {
        if (typeof window.Echo === 'undefined' || typeof window.Echo.private !== 'function') {
            return;
        }

        if (typeof playerId === 'undefined' || !playerId) {
            return;
        }

        try {
            window.Echo.private('combat.player.' + playerId)
                .listen('.CombatLossesPublished', function (recu) {
                    if (!recu || !recu.losses) {
                        return;
                    }

                    var ajoutees = 0;

                    for (var i = 0; i < recu.losses.length; i++) {
                        if (ajouterUnePerte(recu.combatId, recu.losses[i])) {
                            ajoutees++;
                        }
                    }

                    mettreAJourLeCumul(recu.combatId, ajoutees);

                    // Une bataille dont la carte n'est pas encore affichee : on la demande une fois,
                    // au lieu d'attendre le secours.
                    if (ajoutees === 0 && !document.getElementById('combatRow-' + recu.combatId)) {
                        rafraichir();
                    }
                })
                // **Le debut et la fin arrivent aussi en direct**, meme sans aucune perte : la carte
                // est redemandee — c'est le serveur qui la dessine, avec l'acces au rapport quand il
                // existe — et le bandeau recompte. Une annonce repetee ne fait que relire.
                .listen('.CombatStateChanged', function (recu) {
                    if (!recu || !recu.combatId) {
                        return;
                    }

                    var ligne = document.getElementById('combatRow-' + recu.combatId);

                    if (ligne && recu.status_label) {
                        ligne.setAttribute('data-combat-status', recu.status);
                        var etat = ligne.querySelector('.combatEvent_state');

                        if (etat) {
                            etat.textContent = recu.status_label;
                        }
                    }

                    rafraichir();

                    if (typeof getAjaxEventbox === 'function') {
                        getAjaxEventbox();
                    }
                });
        } catch (erreur) {
            // Pas de temps reel : le secours periodique reste.
        }
    }

    ecouter();
    planifier();
})();
