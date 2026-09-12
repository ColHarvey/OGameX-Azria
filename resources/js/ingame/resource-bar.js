/*
 * Le bandeau des ressources, en direct.
 *
 * ## Ce qu'il corrige
 *
 * Le bandeau part des valeurs que le serveur ecrit au rendu de la page, et `ResourceTicker` y
 * ajoute la production chaque seconde. C'est une extrapolation, pas une liaison : un transport qui
 * se pose, une flotte qui rentre avec son butin, un raid subi, un batiment fini sur une page sans
 * compte a rebours — rien de tout cela ne se voyait avant le changement de page suivant.
 *
 * Et la fonction que le jeu appelle pour se resynchroniser, `getAjaxResourcebox()`, telechargeait
 * la Vue generale entiere pour la lire comme du JSON : erreur dans la console, bandeau inchange,
 * requete lourde pour rien — apres chaque echange chez le marchand, chaque objet utilise.
 *
 * ## Ce qu'il fait
 *
 * 1. **Il est `getAjaxResourcebox()`.** La meme fonction, avec la meme signature (un rappel
 *    facultatif qui recoit `resources`), branchee sur la route que le bandeau nomme — celle qui
 *    rend exactement l'objet que `reloadResources()` attend. Les vingt-cinq appels existants du
 *    jeu marchent d'un coup, sans etre touches.
 * 2. **A l'instant d'un mouvement de flotte qui concerne le joueur.** Le serveur l'annonce deja
 *    sur `galaxy.player.{id}` (`FleetMovementChanged`) : arrivee, retour, livraison chez lui,
 *    raid subi. Le bandeau se resynchronise a la reception ; il n'additionne rien, il relit.
 * 3. **En veille toutes les trente secondes**, pour tout ce qui n'a pas d'annonce — jamais quand
 *    l'onglet est cache, et aussitot qu'il redevient visible.
 *
 * ## Ce que la page dit, et pourquoi le module ne lit aucune variable globale
 *
 * L'adresse est sur le bandeau (`data-resourcebox-url`) et la planete affichee dans la balise
 * `meta` que le jeu publie deja (`ogame-planet-id`). Une variable globale posee dans un bloc de
 * script qu'on envelopperait un jour dans une fermeture deviendrait locale, et le module se
 * tairait sans un mot — exactement le silence qu'on reproche a l'ancien talon.
 *
 * **La planete voyage avec la demande**, et ce n'est pas un ornement : la planete courante est une
 * colonne du **compte** (`users.planet_current`). Ouvrir une seconde planete dans un autre onglet
 * la change pour tout le monde ; sans ce parametre, ce bandeau afficherait les stocks de l'autre
 * planete sous une page qui en montre une autre.
 *
 * ## Une demande a la fois, et les infobulles qui survivent
 *
 * Deux annonces en rafale ne partent pas deux fois : une demande en vol retient la suivante, qui
 * part quand la premiere revient (`demandeDue`). Les rappels des appelants sont livres avec la
 * demande qui les a pris ; un rappel arrive pendant le vol attend la demande due.
 *
 * `reloadResources()` refait les cinq infobulles du bandeau (`changeTooltip` les detruit puis les
 * recree) : appele toutes les trente secondes, il fermait sous le curseur l'infobulle qu'un joueur
 * etait en train de lire. Ce module ne l'appelle donc que lorsque **le texte d'une infobulle
 * change vraiment** — une production, un stockage, un plafond. Le reste du temps il ecrit les
 * montants dans le compteur et lui demande de se redessiner : le joueur garde son infobulle
 * ouverte et voit quand meme le bon nombre.
 *
 * ## Quand le serveur va mal, le module se tait
 *
 * Trois echecs de suite et la veille s'arrete : une panne serveur ne doit pas devenir cent vingt
 * requetes et cent vingt traces par heure et par onglet. Le retour sur l'onglet, une annonce de
 * flotte ou un rechargement de page la relancent.
 *
 * ## Sans Echo, la veille seule
 *
 * Si le canal direct n'est pas disponible, la veille suffit : trente secondes au plus entre le
 * fait et le bandeau. Une degradation, pas une panne.
 */
(function () {
    'use strict';

    /** La cadence de la veille, en millisecondes. */
    var CADENCE_DE_LA_VEILLE = 30000;

    /** Au-dela, la veille se tait : une panne ne doit pas devenir une tempete de requetes. */
    var ECHECS_AVANT_DE_SE_TAIRE = 3;

    /** Les faits dont un changement refait les infobulles ; les montants seuls n'en refont aucune. */
    var FAITS_DE_L_INFOBULLE = ['storage', 'production', 'tooltip'];

    var demandeEnVol = false;
    var demandeDue = false;
    var rappelsEnAttente = [];
    var veille = null;
    var arme = false;
    var echecsConsecutifs = 0;
    var derniereCharge = null;

    function leBandeau() {
        return document.getElementById('resourcesbarcomponent');
    }

    function adresse() {
        var bandeau = leBandeau();

        return bandeau ? bandeau.getAttribute('data-resourcebox-url') : null;
    }

    /** La planete que CETTE page affiche, telle que le jeu la publie. */
    function corpsDeLaPage() {
        var meta = document.querySelector('meta[name="ogame-planet-id"]');
        var id = meta ? parseInt(meta.getAttribute('content'), 10) : NaN;

        return isNaN(id) || id <= 0 ? null : id;
    }

    function joueur() {
        var meta = document.querySelector('meta[name="ogame-player-id"]');
        var id = meta ? parseInt(meta.getAttribute('content'), 10) : NaN;

        return isNaN(id) || id <= 0 ? null : id;
    }

    /*
     * Tout ce qu'une demande exige : jQuery, la fonction du jeu qui applique la reponse, et
     * l'adresse que le bandeau porte. L'un manque, rien ne part — et rien ne casse.
     */
    function peutDemander() {
        return typeof window.jQuery !== 'undefined'
            && typeof window.jQuery.getJSON === 'function'
            && typeof window.reloadResources === 'function'
            && typeof adresse() === 'string'
            && adresse() !== '';
    }

    function signaler(message, erreur) {
        if (window.console && window.console.error) {
            window.console.error('Bandeau des ressources : ' + message, erreur);
        }
    }

    function livrer(rappels, ressources) {
        rappels.forEach(function (rappel) {
            try {
                rappel(ressources);
            } catch (e) {
                // Le rappel d'un appelant ne doit pas priver les autres, ni bloquer le bandeau.
                signaler('un rappel a echoue', e);
            }
        });
    }

    /**
     * Une infobulle du bandeau changerait-elle de texte ?
     *
     * Comparer la charge recue a la precedente, ressource par ressource, sur les seuls faits que
     * les infobulles portent. Une premiere charge, une ressource apparue ou disparue : on refait
     * tout, c'est le cas sur.
     */
    function lesInfobullesChangent(reponse) {
        if (derniereCharge === null) {
            return true;
        }

        var noms = Object.keys(reponse.resources);

        if (noms.length !== Object.keys(derniereCharge).length) {
            return true;
        }

        return noms.some(function (nom) {
            var avant = derniereCharge[nom];

            if (!avant) {
                return true;
            }

            return FAITS_DE_L_INFOBULLE.some(function (fait) {
                return reponse.resources[nom][fait] !== avant[fait];
            });
        });
    }

    function retenirLaCharge(reponse) {
        derniereCharge = {};

        Object.keys(reponse.resources).forEach(function (nom) {
            var fait = reponse.resources[nom];
            derniereCharge[nom] = {
                storage: fait.storage,
                production: fait.production,
                tooltip: fait.tooltip
            };
        });
    }

    /**
     * Ecrire les seuls montants dans le compteur du jeu, et le laisser redessiner.
     *
     * Rend faux quand le compteur n'est pas dans l'etat attendu : l'appelant refait alors le
     * chemin complet, qui ne suppose rien.
     */
    function ecrireLesMontants(reponse) {
        var compteur = window.resourcesBar;

        if (!compteur || !compteur.resources || typeof compteur.refresh !== 'function') {
            return false;
        }

        var noms = Object.keys(reponse.resources);

        if (noms.some(function (nom) { return !compteur.resources[nom]; })) {
            return false;
        }

        noms.forEach(function (nom) {
            compteur.resources[nom].amount = reponse.resources[nom].amount;
        });

        compteur.refresh();

        return true;
    }

    /**
     * Appliquer la reponse : le chemin complet quand une infobulle change, les montants sinon.
     */
    function appliquer(reponse, rappels) {
        if (lesInfobullesChangent(reponse) || !ecrireLesMontants(reponse)) {
            window.reloadResources(reponse, function (ressources) {
                livrer(rappels, ressources);
            });
        } else {
            livrer(rappels, reponse.resources);
        }

        retenirLaCharge(reponse);
    }

    /**
     * Resynchroniser le bandeau avec le serveur — c'est `getAjaxResourcebox()`.
     *
     * @param {Function=} rappel recoit `resources` une fois la reponse appliquee
     */
    function synchroniser(rappel) {
        if (typeof rappel === 'function') {
            rappelsEnAttente.push(rappel);
        }

        if (demandeEnVol) {
            demandeDue = true;

            return;
        }

        if (!peutDemander()) {
            return;
        }

        var rappels = rappelsEnAttente;
        rappelsEnAttente = [];
        demandeEnVol = true;

        var corps = corpsDeLaPage();

        window.jQuery.getJSON(adresse(), corps === null ? {} : { body: corps })
            .done(function (reponse) {
                /*
                 * Tout ce qui suit est enveloppe : une exception dans `done` empecherait `always`
                 * de tourner, et la demande resterait « en vol » pour toujours — plus aucune
                 * synchronisation jusqu'au rechargement.
                 */
                try {
                    if (!reponse || typeof reponse !== 'object' || !reponse.resources) {
                        return;
                    }

                    echecsConsecutifs = 0;
                    appliquer(reponse, rappels);
                } catch (e) {
                    signaler('la reponse n a pas pu etre appliquee', e);
                }
            })
            .fail(function () {
                echecsConsecutifs++;
            })
            .always(function () {
                demandeEnVol = false;

                if (demandeDue) {
                    demandeDue = false;
                    synchroniser();
                }
            });
    }

    /* Le jeu appelle cette fonction sous ce nom depuis toujours ; elle remplace le talon vide. */
    window.getAjaxResourcebox = synchroniser;

    /**
     * Une demande volontaire : elle repart meme apres des echecs, et remet la veille en marche.
     */
    function reprendre() {
        echecsConsecutifs = 0;
        synchroniser();
    }

    function ecouterLesFlottes() {
        if (typeof window.Echo === 'undefined' || typeof window.Echo.private !== 'function') {
            return;
        }

        var id = joueur();

        if (id === null) {
            return;
        }

        try {
            window.Echo.private('galaxy.player.' + id)
                .listen('.FleetMovementChanged', function () {
                    reprendre();
                });
        } catch (e) {
            // Un abonnement impossible laisse la veille faire le travail.
            signaler('abonnement impossible, la veille seule reste', e);
        }
    }

    function demarrerLaVeille() {
        if (veille !== null) {
            return;
        }

        veille = window.setInterval(function () {
            // Un onglet cache ne demande rien ; une demande en vol suffit ; un serveur qui refuse
            // trois fois de suite n'est pas sollicite cent vingt fois par heure.
            if (document.hidden || demandeEnVol || echecsConsecutifs >= ECHECS_AVANT_DE_SE_TAIRE) {
                return;
            }

            synchroniser();
        }, CADENCE_DE_LA_VEILLE);
    }

    function armer() {
        // Une seule fois : `DOMContentLoaded` et un appel direct ne doivent pas abonner deux fois.
        if (arme || !leBandeau()) {
            return;
        }

        arme = true;

        ecouterLesFlottes();
        demarrerLaVeille();

        document.addEventListener('visibilitychange', function () {
            // De retour sur l'onglet, la verite tout de suite — pas dans trente secondes.
            if (!document.hidden) {
                reprendre();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', armer);
    } else {
        armer();
    }
})();
