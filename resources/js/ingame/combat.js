/*
 * Le panneau des combats durables, vivant.
 *
 * Ce que ce script fait, et rien de plus : il redemande au serveur le fragment du panneau a
 * cadence fixe, remplace l'ancien par le nouveau, et arme les comptes a rebours que le fragment
 * porte. A zero, un compte a rebours redemande le fragment au lieu de deviner la suite.
 *
 * Ce qu'il ne fait jamais : calculer une perte, deduire une frontiere de round, ou choisir
 * l'heure et le joueur — le serveur seul les connait. La cadence est fixe precisement pour que
 * rien du futur ne transite : un « prochaine mise a jour dans N secondes » revelerait les periodes
 * de la bataille. Dix secondes quand un combat est affiche, une minute sinon.
 *
 * Il s'appuie sur jQuery et sur `simpleCountdown`, deja charges par le bundle du jeu.
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

    function armerLesComptes() {
        var courant = panneau();

        if (!courant || typeof simpleCountdown !== 'function') {
            return;
        }

        var comptes = courant.querySelectorAll('.combatpanel_countdown');

        for (var i = 0; i < comptes.length; i++) {
            var element = comptes[i];
            var secondes = parseInt(element.getAttribute('data-seconds'), 10);

            if (isNaN(secondes) || element.getAttribute('data-armed') === '1') {
                continue;
            }

            element.setAttribute('data-armed', '1');

            // A zero, on redemande au serveur : c'est lui qui sait ce qui vient ensuite.
            new simpleCountdown(element, secondes, function () {
                rafraichir();
            });
        }
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
                    armerLesComptes();
                }
            },
            complete: function () {
                requeteEnCours = false;
                planifier();
            }
        });
    }

    armerLesComptes();
    planifier();
})();
