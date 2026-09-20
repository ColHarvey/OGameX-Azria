/*
 * La recompense quotidienne : le bouton de l en-tete, la fenetre, et la reclamation.
 *
 * **Le serveur decide de tout** — la journee, le montant, l eligibilite. Ce script n anime qu un compte a rebours
 * dont les secondes viennent du serveur, et il ne conclut jamais rien tout seul :
 *
 *   - a zero, il **redemande l etat** au lieu de decider que la journee a tourne. C est ce qui remet une fenetre
 *     laissee ouverte au passage de minuit, sans recharger la page ;
 *   - au retour sur un onglet inactif, il redemande aussi : pendant que l onglet dormait, le navigateur a pu ne
 *     pas faire tourner le minuteur, et l heure du serveur a avance sans lui ;
 *   - apres une reclamation, le compteur de matiere noire et l etat du bouton sont reecrits avec ce que le
 *     serveur vient de rendre — jamais par une addition faite ici.
 *
 * Tout est prefixe `azDailyReward` ou `.az-daily-reward` : rien n y touche le chat, les recompenses existantes
 * ni les pages de formes de vie.
 */
var azDailyReward = {
    /** Le minuteur du compte a rebours. Un seul, toujours. */
    minuteur: null,

    /** Les secondes restantes, telles que le serveur les a dites au dernier echange. */
    secondes: 0,

    /** Une demande d etat est-elle en vol ? On n en lance jamais deux. */
    enVol: false,

    /**
     * Le bouton de l en-tete. Il vit hors de la fenetre et doit refleter l etat meme fenetre fermee.
     */
    bouton: function () {
        return document.getElementById('dailyRewardButton');
    },

    fenetre: function () {
        return document.getElementById('dailyRewardWindow');
    },

    /**
     * Poser les gestionnaires. Appele une fois par page, et **idempotent** : `initDailyReward()` peut etre
     * rappele apres un fragment sans dedoubler quoi que ce soit.
     */
    init: function () {
        var d = azDailyReward;
        $(document).off('click.azDailyReward').on('click.azDailyReward', '#dailyRewardButton', function (e) {
            e.preventDefault();
            // **L ouverture attend la fin du clic.** Le jeu ferme les fenetres ouvertes sur un clic ailleurs
            // dans la page ; ouverte pendant que le clic remonte encore, la notre etait refermee aussitot —
            // le dialogue naissait, puis la reponse du serveur arrivait sur un element deja retire
            // (« cannot call methods on dialog prior to initialization », mesure au navigateur).
            window.setTimeout(function () {
                d.ouvrir();
            }, 0);
        });
        // Le clavier : le bouton est un vrai `<button>`, donc Entree et Espace declenchent deja le clic. Rien a
        // ajouter — et surtout rien qui doublerait l evenement.

        $(document).off('click.azDailyRewardClaim').on('click.azDailyRewardClaim', '.az-daily-reward__claim', function (e) {
            e.preventDefault();
            d.reclamer();
        });

        // **Le retour sur un onglet inactif.** Pendant le sommeil, le minuteur a pu ne pas tourner.
        $(document).off('visibilitychange.azDailyReward').on('visibilitychange.azDailyReward', function () {
            if (!document.hidden && d.fenetre()) {
                d.resynchroniser();
            }
        });
    },

    /**
     * Ouvrir la fenetre par le mecanisme du jeu, comme l abandon de planete.
     */
    ouvrir: function () {
        var bouton = azDailyReward.bouton();
        if (!bouton || typeof openOverlay !== 'function') {
            return;
        }
        openOverlay(bouton.getAttribute('data-overlay-url'), {
            title: bouton.getAttribute('data-window-title'),
            'class': 'dailyRewardOverlay',
            // **La largeur suit l ecran.** 390 px sur un bureau, moins sur un telephone : une largeur fixe
            // debordait a 390 px de large, l habillage du dialogue s ajoutant a la notre (mesure au navigateur).
            width: Math.min(390, Math.max(280, window.innerWidth - 30))
        });
        // Le fragment arrive par le reseau : on attend qu il soit la pour armer le compte a rebours.
        var essais = 0;
        var attente = window.setInterval(function () {
            essais++;
            if (azDailyReward.fenetre()) {
                window.clearInterval(attente);
                azDailyReward.armer();
            } else if (essais > 60) {
                window.clearInterval(attente);
            }
        }, 100);
    },

    /**
     * Armer le compte a rebours depuis ce que la fenetre porte.
     */
    armer: function () {
        var d = azDailyReward;
        var fenetre = d.fenetre();
        if (!fenetre) {
            return;
        }
        d.secondes = parseInt(fenetre.getAttribute('data-seconds'), 10);
        if (isNaN(d.secondes) || d.secondes < 0) {
            d.secondes = 0;
        }
        d.afficherLeTemps();

        window.clearInterval(d.minuteur);
        d.minuteur = window.setInterval(function () {
            if (!d.fenetre()) {
                window.clearInterval(d.minuteur);
                return;
            }
            d.secondes--;
            if (d.secondes <= 0) {
                d.secondes = 0;
                d.afficherLeTemps();
                // **On ne conclut pas : on demande.** Minuit est une affaire de serveur.
                d.resynchroniser();
                return;
            }
            d.afficherLeTemps();
        }, 1000);
    },

    /**
     * Le temps restant, en jours, heures, minutes et secondes.
     */
    formater: function (secondes) {
        if (secondes <= 0) {
            return '0s';
        }
        var h = Math.floor(secondes / 3600);
        var m = Math.floor((secondes % 3600) / 60);
        var s = secondes % 60;
        var deux = function (n) {
            return n < 10 ? '0' + n : String(n);
        };

        return deux(h) + ':' + deux(m) + ':' + deux(s);
    },

    afficherLeTemps: function () {
        var fenetre = azDailyReward.fenetre();
        if (!fenetre) {
            return;
        }
        var cible = fenetre.querySelector('.az-daily-reward__timer');
        if (cible) {
            cible.textContent = azDailyReward.formater(azDailyReward.secondes);
        }
    },

    /**
     * Redemander l etat au serveur et remettre la fenetre, le bouton et le compteur d aplomb.
     */
    resynchroniser: function () {
        var d = azDailyReward;
        var fenetre = d.fenetre();
        if (!fenetre || d.enVol) {
            return;
        }
        d.enVol = true;
        $.ajax({
            url: fenetre.getAttribute('data-state-url'),
            type: 'GET',
            dataType: 'json',
            success: function (etat) {
                d.enVol = false;
                d.appliquer(etat);
            },
            error: function () {
                d.enVol = false;
            }
        });
    },

    /**
     * Reclamer. Le bouton se desarme le temps de l aller-retour : deux clics ne partent pas deux fois — et si
     * l un passait quand meme, la base refuserait le second credit.
     */
    reclamer: function () {
        var d = azDailyReward;
        var fenetre = d.fenetre();
        if (!fenetre) {
            return;
        }
        var bouton = fenetre.querySelector('.az-daily-reward__claim');
        if (!bouton || bouton.disabled) {
            return;
        }
        bouton.disabled = true;

        $.ajax({
            url: fenetre.getAttribute('data-claim-url'),
            type: 'POST',
            dataType: 'json',
            data: { _token: fenetre.getAttribute('data-token') },
            success: function (reponse) {
                d.appliquer(reponse);
                d.dire(reponse.message || '');
            },
            error: function (xhr) {
                var reponse = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                if (reponse) {
                    d.appliquer(reponse);
                    d.dire(reponse.message || '');
                } else {
                    // **Une reponse perdue ne dit pas que rien n a ete credite.** On redemande l etat plutot que
                    // de supposer : une nouvelle tentative retrouverait la reclamation sans recrediter.
                    bouton.disabled = false;
                    d.resynchroniser();
                }
            }
        });
    },

    dire: function (texte) {
        var fenetre = azDailyReward.fenetre();
        if (!fenetre) {
            return;
        }
        var cible = fenetre.querySelector('.az-daily-reward__message');
        if (cible) {
            cible.textContent = texte;
        }
    },

    /**
     * Appliquer un etat rendu par le serveur : la fenetre, le bouton de l en-tete, et le compteur.
     */
    appliquer: function (etat) {
        var d = azDailyReward;
        if (!etat || typeof etat !== 'object') {
            return;
        }

        // 1. Le compteur de matiere noire, tel que le serveur le donne — jamais une addition faite ici.
        if (typeof etat.dark_matter_formatted === 'string') {
            var compteur = document.getElementById('resources_darkmatter');
            if (compteur) {
                compteur.textContent = etat.dark_matter_formatted;
                compteur.setAttribute('data-raw', String(etat.dark_matter));
            }
        }

        // 2. Le bouton de l en-tete, meme si la fenetre est fermee.
        var bouton = d.bouton();
        if (bouton) {
            var libelle = etat.claimed
                ? bouton.getAttribute('data-label-claimed')
                : bouton.getAttribute('data-label-available');
            bouton.classList.toggle('az-daily-gift--claimed', !!etat.claimed);
            bouton.setAttribute('aria-label', libelle);
            bouton.setAttribute('title', libelle);
        }

        // 3. La fenetre, si elle est ouverte.
        var fenetre = d.fenetre();
        if (!fenetre) {
            return;
        }
        fenetre.setAttribute('data-claimed', etat.claimed ? '1' : '0');
        fenetre.setAttribute('data-seconds', String(etat.seconds_remaining));

        var action = fenetre.querySelector('.az-daily-reward__claim');
        if (action) {
            action.disabled = !!etat.claimed || !etat.enabled;
            action.textContent = etat.claimed
                ? action.getAttribute('data-label-claimed')
                : action.getAttribute('data-label-claim');
        }

        var etiquette = fenetre.querySelector('.az-daily-reward__countdown-label');
        if (etiquette) {
            etiquette.textContent = etat.claimed
                ? etiquette.getAttribute('data-label-next')
                : etiquette.getAttribute('data-label-expiry');
        }

        d.secondes = parseInt(etat.seconds_remaining, 10);
        if (isNaN(d.secondes) || d.secondes < 0) {
            d.secondes = 0;
        }
        d.afficherLeTemps();
        d.armer();
    }
};

function initDailyReward() {
    azDailyReward.init();
}
