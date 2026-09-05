/*
 * Le panneau des combats durables, vivant.
 *
 * Ce que ce script fait, et rien de plus : il redemande au serveur le fragment du panneau a
 * cadence fixe et remplace l'ancien par le nouveau.
 *
 * Ce qu'il ne fait jamais : calculer une perte, deduire une frontiere de round, armer un compte
 * a rebours sur la fin de la bataille, ou choisir l'heure et le joueur — le serveur seul les
 * connait, et il n'envoie aucune echeance. La cadence est fixe pour la meme raison : un
 * « prochaine mise a jour dans N secondes » revelerait les periodes de la bataille.
 *
 * Il s'appuie sur jQuery, deja charge par le bundle du jeu.
 */
(function () {
    var CADENCE_EN_COMBAT = 10000;
    var CADENCE_AU_REPOS = 60000;

    var initial = document.getElementById('combatpanelcomponent');

    if (!initial) {
        return;
    }

    var url = initial.getAttribute('data-refresh-url');
    var requeteEnCours = false;
    var minuterie = null;

    function panneau() {
        return document.getElementById('combatpanelcomponent');
    }

    function afficheUnCombat() {
        var courant = panneau();

        return courant !== null && courant.querySelector('.combatpanel_combat') !== null;
    }

    function planifier() {
        if (minuterie !== null) {
            window.clearTimeout(minuterie);
        }

        minuterie = window.setTimeout(rafraichir, afficheUnCombat() ? CADENCE_EN_COMBAT : CADENCE_AU_REPOS);
    }

    function rafraichir() {
        if (requeteEnCours || !url) {
            planifier();

            return;
        }

        requeteEnCours = true;

        $.ajax({
            url: url,
            dataType: 'html',
            success: function (html) {
                var courant = panneau();

                if (!courant) {
                    return;
                }

                var conteneur = document.createElement('div');
                conteneur.innerHTML = html;
                var neuf = conteneur.querySelector('#combatpanelcomponent');

                if (neuf) {
                    courant.parentNode.replaceChild(neuf, courant);
                }
            },
            complete: function () {
                requeteEnCours = false;
                planifier();
            }
        });
    }

    planifier();
})();
