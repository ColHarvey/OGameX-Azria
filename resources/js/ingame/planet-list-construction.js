/*
 * La cle a molette de la liste des planetes, en direct.
 *
 * ## Ce qu'il corrige
 *
 * La cle a molette est ecrite par le serveur au rendu de la page. Une construction qui se terminait sur une
 * autre planete la laissait allumee jusqu'au changement de page suivant, et une construction lancee dans un
 * autre onglet n'apparaissait pas davantage.
 *
 * ## Ce qu'il fait
 *
 * 1. **Il suit le bandeau des ressources.** L'etat de la liste (`planetList`) voyage dans l'objet que la page
 *    amorce et que `/ajax/resourcebox` rend ; le bandeau annonce chaque reponse appliquee par l'evenement
 *    `ogamex:resourcebox`, et ce module l'ecoute. Aucune seconde veille, aucune requete de plus : la cadence,
 *    la relecture au retour sur l'onglet et le silence apres trois echecs sont ceux du bandeau.
 * 2. **Il relit a l'instant ou quelque chose change.** Le serveur dit dans combien de secondes l'etat changera
 *    au plus tot (`nextChangeIn`) ; le module redemande le bandeau a cet instant, plus une seconde. Il ne
 *    deduit rien : c'est le serveur qui dit si la cle s'eteint. Au chargement, la meme valeur est posee sur
 *    la liste (`data-construction-next-change-in`).
 * 3. **Il ne touche que les cles.** Une planete absente de la reponse perd la sienne ; une planete de la
 *    reponse que la page ne montre pas — une colonie fondee depuis le chargement — est ignoree : la liste
 *    elle-meme se refait au changement de page.
 *
 * ## Ce qu'il refuse de faire
 *
 * Une reponse qui ne porte pas un etat lisible ne change rien. Eteindre toutes les cles sur une reponse
 * tronquee serait pire que de les laisser telles quelles.
 */
(function () {
    'use strict';

    /** Relire une seconde apres l'echeance : a l'echeance exacte, la ligne ne serait pas encore terminee. */
    var MARGE_APRES_L_ECHEANCE = 1000;

    /** Au-dela d'une heure, la veille du bandeau aura de toute facon relu et reprogramme. */
    var PLAFOND_EN_SECONDES = 3600;

    var echeance = null;
    var arme = false;

    function laListe() {
        return document.getElementById('planetList');
    }

    function estUnEtat(etat) {
        return !!etat && typeof etat === 'object' && Array.isArray(etat.constructions);
    }

    /** La cle de cette planete, creee comme le gabarit l'ecrit si elle n'existe pas encore. */
    function poserLaCle(planete, demolition) {
        var cle = planete.querySelector('a.constructionIcon');

        if (!cle) {
            var lien = planete.querySelector('a.planetlink');

            if (!lien || !lien.parentNode) {
                return;
            }

            cle = document.createElement('a');
            cle.className = 'constructionIcon tooltip js_hideTipOnMobile tpd-hideOnClickOutside';
            cle.setAttribute('data-link', lien.getAttribute('href') || '');
            cle.setAttribute('href', lien.getAttribute('href') || '');
            cle.setAttribute('title', '');
            lien.parentNode.insertBefore(cle, lien.nextSibling);
        }

        var marque = cle.querySelector('span');

        if (!marque) {
            marque = document.createElement('span');
            cle.appendChild(marque);
        }

        marque.className = 'icon12px ' + (demolition ? 'icon_wrench_red' : 'icon_wrench');
    }

    function retirerLaCle(planete) {
        var cle = planete.querySelector('a.constructionIcon');

        if (cle && cle.parentNode) {
            cle.parentNode.removeChild(cle);
        }
    }

    /**
     * Redemander le bandeau quand le serveur annonce un changement.
     *
     * Une seule echeance a la fois : chaque etat recu remplace la precedente, puisqu'il dit la verite du moment.
     */
    function programmer(secondes) {
        if (echeance !== null) {
            window.clearTimeout(echeance);
            echeance = null;
        }

        if (typeof secondes !== 'number' || !isFinite(secondes) || secondes <= 0) {
            return;
        }

        echeance = window.setTimeout(function () {
            echeance = null;

            // Un onglet cache ne demande rien : le bandeau relit au retour sur l'onglet, et l'etat recu
            // reprogramme l'echeance.
            if (document.hidden || typeof window.getAjaxResourcebox !== 'function') {
                return;
            }

            window.getAjaxResourcebox();
        }, Math.min(secondes, PLAFOND_EN_SECONDES) * 1000 + MARGE_APRES_L_ECHEANCE);
    }

    function appliquer(etat) {
        var liste = laListe();

        if (!liste || !estUnEtat(etat)) {
            return;
        }

        var enCours = {};

        etat.constructions.forEach(function (chantier) {
            if (chantier && typeof chantier.planetId === 'number') {
                enCours[chantier.planetId] = chantier.downgrade === true;
            }
        });

        Array.prototype.forEach.call(liste.querySelectorAll('.smallplanet[data-planet-id]'), function (planete) {
            var id = parseInt(planete.getAttribute('data-planet-id'), 10);

            if (Object.prototype.hasOwnProperty.call(enCours, id)) {
                poserLaCle(planete, enCours[id]);
            } else {
                retirerLaCle(planete);
            }
        });

        programmer(etat.nextChangeIn);
    }

    function armer() {
        // Une seule fois : `DOMContentLoaded` et un appel direct ne doivent pas ecouter deux fois.
        if (arme || !laListe()) {
            return;
        }

        arme = true;

        document.addEventListener('ogamex:resourcebox', function (evenement) {
            try {
                appliquer(evenement.detail && evenement.detail.planetList);
            } catch (e) {
                if (window.console && window.console.error) {
                    window.console.error('Liste des planetes : l etat des chantiers n a pas pu etre applique', e);
                }
            }
        });

        var auChargement = parseInt(laListe().getAttribute('data-construction-next-change-in'), 10);

        if (!isNaN(auChargement)) {
            programmer(auChargement);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', armer);
    } else {
        armer();
    }
})();
