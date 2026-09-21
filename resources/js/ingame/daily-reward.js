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
     * **L epoque de reclamation.** Elle avance a chaque reclamation lancee, et sert de critere de
     * validite aux lectures d etat : une lecture partie sous l epoque N et revenue sous l epoque N+1
     * decrit un monde d avant la reclamation, et se jette.
     *
     * **Numeroter les requetes a l emission ne suffisait pas.** Une lecture partie APRES la reclamation
     * peut lire l etat AVANT son commit, puis arriver apres le succes : son numero superieur
     * l autoriserait a remettre « non reclamee ». L ordre d emission cote navigateur ne dit rien de
     * l ordre des operations cote serveur.
     */
    epoque: 0,

    /** Une reclamation est-elle en cours ? Tant qu elle l est, aucune lecture ne part. */
    reclamationEnCours: false,

    /** Une lecture a-t-elle ete demandee pendant une reclamation ? Elle est due des la fin. */
    relectureDue: false,

    /** La relecture differee qui suit une issue non confirmee, s il y en a une d armee. */
    minuterieRelecture: null,

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
        // **Pendant une reclamation, aucune lecture ne part.** Elle lirait un etat que le serveur n a pas
        // encore valide, et sa reponse arriverait apres le succes. On la note due, et la fin de la
        // reclamation la declenchera.
        if (d.reclamationEnCours) {
            d.relectureDue = true;

            return;
        }
        d.enVol = true;
        var epoque = d.epoque;
        $.ajax({
            url: fenetre.getAttribute('data-state-url'),
            type: 'GET',
            dataType: 'json',
            success: function (etat) {
                d.enVol = false;
                d.appliquerSi(epoque, etat);
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

        // **La reclamation ouvre une epoque.** Les lectures en vol sont invalidees par ce seul
        // changement, et celles qui voudraient partir sont suspendues.
        d.epoque++;
        d.reclamationEnCours = true;
        d.relectureDue = false;
        window.clearTimeout(d.minuterieRelecture);

        // La reclamation est la seule a pouvoir ecrire pendant son epoque : sa reponse fait foi, quel que
        // soit ce qu une lecture aurait pu dire entre-temps.
        var appliquerLaReponse = function (reponse) {
            d.reclamationEnCours = false;
            d.appliquer(reponse);
            d.dire(reponse.message || '');
        };

        $.ajax({
            url: fenetre.getAttribute('data-claim-url'),
            type: 'POST',
            dataType: 'json',
            data: { _token: fenetre.getAttribute('data-token') },
            success: function (reponse) {
                appliquerLaReponse(reponse);
                d.relireSiDue();
            },
            error: function (xhr) {
                var reponse = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                if (reponse) {
                    appliquerLaReponse(reponse);

                    // **Une issue non confirmee** : le serveur n a pas pu trancher, et une demande
                    // concurrente du meme compte a pu aboutir entre-temps. La charge utile ci-dessus dit
                    // l etat au moment de la reponse ; on le relit **une fois**, apres le delai indique,
                    // pour que l affichage converge si l autre demande a gagne.
                    //
                    // Une seule relecture, jamais une boucle, et **jamais une nouvelle reclamation** : le
                    // joueur decide de reessayer, pas le navigateur.
                    if (reponse.retryable) {
                        var delai = parseInt(xhr.getResponseHeader('Retry-After') || '2', 10);
                        if (!(delai > 0)) {
                            delai = 2;
                        }
                        window.clearTimeout(d.minuterieRelecture);
                        d.minuterieRelecture = window.setTimeout(function () {
                            d.resynchroniser();
                        }, delai * 1000);
                    } else {
                        d.relireSiDue();
                    }
                } else {
                    // **Une reponse perdue laisse l issue indeterminee dans les deux sens.** Le POST peut
                    // encore etre en traitement cote serveur au moment ou le navigateur constate l echec :
                    // une lecture qui repondrait « non reclamee » n etablirait donc **pas** que la
                    // reclamation echouera. Elle dit ce que le serveur voyait a cet instant, rien de plus,
                    // et une lecture ulterieure fera converger l affichage.
                    //
                    // Redemander reste sans risque, mais pour une raison qui n est pas ici : c est la
                    // contrainte unique, **cote serveur**, qui interdit un second credit — jamais ce que
                    // le navigateur croit savoir.
                    d.reclamationEnCours = false;
                    bouton.disabled = false;
                    d.relectureDue = true;
                    d.relireSiDue();
                }
            }
        });
    },

    /**
     * Lancer la lecture qu une reclamation avait suspendue, s il y en avait une.
     *
     * Elle part **apres** que la reponse de la reclamation a ete appliquee.
     *
     * **Apres un succes**, elle lit un serveur qui a deja valide : sa reponse ne peut plus revenir en
     * arriere. **Apres une coupure ou un delai depasse**, en revanche, le POST peut encore etre en
     * traitement — la lecture ne tranche alors rien, et c est une lecture ulterieure qui fera converger.
     * Ne pas confondre les deux cas : seul le premier autorise a dire que l etat lu est definitif.
     */
    relireSiDue: function () {
        var d = azDailyReward;
        if (!d.relectureDue) {
            return;
        }
        d.relectureDue = false;
        d.resynchroniser();
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
    /**
     * Appliquer un etat **seulement s il n a pas ete invalide**.
     *
     * Le rejet se fait ici, **avant tout effet** : une reponse jetee ne touche ni le bouton, ni le
     * message, ni le compteur, ni la minuterie. Rend `true` si l etat a bien ete applique.
     */
    appliquerSi: function (epoque, etat) {
        var d = azDailyReward;
        if (d.reclamationEnCours || epoque !== d.epoque) {
            return false;
        }
        d.appliquer(etat);

        return true;
    },

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
