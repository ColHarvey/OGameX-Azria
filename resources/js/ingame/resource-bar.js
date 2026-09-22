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
 * `reloadResources()` refait toutes les infobulles du bandeau (`changeTooltip` les detruit puis les
 * recree) : appele toutes les trente secondes, il fermait sous le curseur l'infobulle qu'un joueur
 * etait en train de lire. La parade d'origine — « ne refaire que si le texte change » — etait
 * **inerte** : ce texte porte le stock courant, qui bouge chaque seconde, donc la condition etait
 * toujours vraie (mesure au navigateur, journal §184.4). Ce module ne detruit plus rien : toute
 * infobulle dont le texte change est mise a jour **en place** — la seule tuile concernee, le contenu
 * tel que le serveur l'a rendu et echappe, le `title` repose pour la prochaine ouverture. Le serveur
 * publie a cote une **structure dediee de faits** (`facts`) : production, capacite, etats
 * particuliers, taux et duree de validite des tuiles animees. Rien n'est masque, puisque rien n'est
 * compare pour decider d'ecrire : un texte qui change s'ecrit. La reconstruction (`changeTooltip`)
 * n'est plus qu'un secours, quand le noeud ouvert n'a pas la structure attendue — car
 * `initTooltips()` ferme au passage l'infobulle ouverte d'une autre tuile (mesure en jeu).
 *
 * ## La population et la nourriture s'animent ici
 *
 * Le compteur herite les anime a partir de clefs que le serveur ne publie pas — ses deux branches
 * sont mortes —, et le serveur ne projetait pas l'horloge demographique sur cette route : les deux
 * tuiles affichaient l'etat du dernier chargement de page. Le serveur projette desormais, et ce
 * module les anime a partir de champs **dedies** (`facts.population`, `facts.food`), dont la
 * semantique est la notre et jamais celle du compteur herite. Le taux n'est cru que pendant
 * `stable_for` secondes : au-dela — plafond, grenier plein, famine — rien n'est invente, la valeur
 * est tenue et l'etat redemande.
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

    /** Les tuiles qui portent une infobulle, et l'identifiant de leur boite. */
    var TUILES = ['metal', 'crystal', 'deuterium', 'energy', 'darkmatter', 'population', 'food'];

    /** Les deux tuiles que le serveur decrit assez pour qu'on les anime ici. */
    var TUILES_ANIMEES = ['population', 'food'];

    /** La cadence de l'animation des tuiles animees, en millisecondes. */
    var CADENCE_DE_L_ANIMATION = 1000;

    var demandeEnVol = false;
    var demandeDue = false;
    var rappelsEnAttente = [];
    var veille = null;
    var arme = false;
    var echecsConsecutifs = 0;
    var animation = null;
    var horlogeDeLAnimation = null;
    var dernierInstantApplique = null;
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
     * Les tuiles dont l'infobulle a change de texte depuis la derniere charge — ou depuis l'amorce de la page.
     */
    function lesInfobullesATraiter(reponse) {
        var travaux = [];

        TUILES.forEach(function (nom) {
            var tuile = reponse.resources[nom];

            if (!tuile || typeof tuile.tooltip !== 'string') {
                return;
            }

            var avant = derniereCharge === null ? null : derniereCharge.infobulles[nom];

            if (avant === tuile.tooltip) {
                return;
            }

            travaux.push({ nom: nom, texte: tuile.tooltip, precedent: avant });
        });

        return travaux;
    }

    /**
     * Partir de ce que le compteur de la page tient deja.
     *
     * **La premiere reponse n'est pas une premiere charge.** Le gabarit amorce le compteur lui-meme
     * (`reloadResources(@json(...))`), sans passer par ce module : sans cette amorce, la premiere resynchronisation
     * etait traitee comme une premiere charge, refaisait toutes les infobulles, et detruisait celle que le joueur
     * lisait — mesure en jeu, a la seconde 29 (journal §186). Les textes des infobulles sont dans le compteur ; les
     * faits, eux, ne seront connus qu'a la premiere reponse, et jusque-la rien n'est refait.
     */
    function retenirLAmorce() {
        var compteur = window.resourcesBar;

        if (!compteur || !compteur.resources) {
            return;
        }

        var infobulles = {};
        var trouvee = false;

        TUILES.forEach(function (nom) {
            var tuile = compteur.resources[nom];

            if (tuile && typeof tuile.tooltip === 'string') {
                infobulles[nom] = tuile.tooltip;
                trouvee = true;
            }
        });

        if (trouvee) {
            derniereCharge = { infobulles: infobulles };
        }
    }

    function retenirLaCharge(reponse) {
        derniereCharge = { infobulles: {} };

        Object.keys(reponse.resources).forEach(function (nom) {
            derniereCharge.infobulles[nom] = reponse.resources[nom].tooltip;
        });
    }

    /**
     * Remplacer le contenu d'une infobulle **ouverte**, sans la detruire.
     *
     * Mesure faite sur le Tipped reellement servi (journal §185.1) : `Tipped.refresh()` ne relit pas l'attribut
     * `title` — le texte ne change pas —, et `changeTooltip()` ferme l'infobulle sous le curseur. Ce qui marche :
     * ecrire dans le noeud ouvert pour le joueur qui lit maintenant, **et** poser le `title` pour la prochaine
     * ouverture, que Tipped relit alors.
     *
     * Le contenu est celui que le serveur a compose et **echappe** ; la structure visee est celle que
     * `initTooltips()` construit : `.htmlTooltip` > `h1` + `.splitLine` + le tableau.
     */
    function ecrireLInfobulleEnPlace(nom, texte, textePrecedent) {
        var boite = document.getElementById(nom + '_box');

        if (!boite) {
            return false;
        }

        // **A qui appartient l'infobulle ouverte ?** Tipped n'en montre qu'une, sans lien DOM avec sa boite. On ne
        // devine pas : elle est a cette boite si son titre est celui que la boite portait **avant** cette ecriture.
        // Sans ce contrôle, une reponse qui change plusieurs infobulles reecrivait l'infobulle ouverte pour chacune
        // d'elles, et le joueur finissait par lire la derniere de la liste sous le nom d'une autre (vu au banc).
        //
        // **Ce titre vient de la memoire du module, pas de l'attribut** : Tipped retire `title` de la boite apres
        // l'avoir lu, donc a la premiere resynchronisation l'attribut est vide et rien ne correspondait — mesure en
        // jeu, « Disponible » n'etait reecrit qu'a la seconde. Le texte precedent (l'amorce de la page, puis la
        // reponse d'avant) porte le titre ; l'attribut n'est qu'un repli.
        var titrePrecedent = String(textePrecedent || boite.getAttribute('title') || '').split('|')[0].trim();

        // L'attribut est pose directement : `changeTooltip()` du jeu fait de meme, et le module ne depend pas de
        // jQuery pour une ecriture que le DOM sait faire.
        boite.setAttribute('title', texte);

        var ouverte = lInfobulleOuverteDe(titrePrecedent);

        if (!ouverte) {
            // Rien d'ouvert : le `title` suffit, la prochaine ouverture le lira.
            return true;
        }

        var enveloppe = ouverte.querySelector('.htmlTooltip') || ouverte;
        var ancienne = enveloppe.querySelector('table');

        if (!ancienne) {
            return false;
        }

        var morceaux = String(texte).split('|');
        var titre = morceaux.length > 1 ? morceaux[0] : null;
        var provisoire = document.createElement('div');
        provisoire.innerHTML = morceaux.length > 1 ? morceaux.slice(1).join('|') : morceaux[0];
        var neuve = provisoire.querySelector('table');

        if (!neuve) {
            return false;
        }

        var entete = enveloppe.querySelector('h1');

        if (entete && titre !== null) {
            entete.textContent = titre;
        }

        ancienne.parentNode.replaceChild(neuve, ancienne);

        return true;
    }

    /**
     * L'infobulle visible, s'il y en a une, et si elle appartient bien a cette boite.
     *
     * Tipped n'en montre qu'une a la fois ; elle vit en fin de document, sans lien DOM avec sa boite. Le lien se
     * lit sur l'etat de la boite (`data-tooltipLoaded`) et sur la visibilite calculee.
     */
    function lInfobulleOuverteDe(titreAttendu) {
        var liste = document.querySelectorAll('.tpd-tooltip');

        // **Celle dont le titre correspond, quel que soit son rang** : Tipped laisse derriere lui des noeuds d'autres
        // survols, et prendre le premier visible manquait la notre (mesure en jeu : « Disponible » non reecrit a la
        // premiere resynchronisation). Le titre affiche est celui que la boite portait — c'est le lien, et il ne
        // depend d'aucune bibliotheque.
        for (var i = 0; i < liste.length; i++) {
            var style = window.getComputedStyle(liste[i]);

            if (style.display === 'none' || style.visibility === 'hidden') {
                continue;
            }

            var entete = liste[i].querySelector('h1');

            if (entete && entete.textContent.trim() === titreAttendu) {
                return liste[i];
            }
        }

        return null;
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
        if (derniereCharge === null) {
            retenirLAmorce();
        }

        var travaux = lesInfobullesATraiter(reponse);
        var premiere = derniereCharge === null;

        if (premiere || !ecrireLesMontants(reponse)) {
            // Premiere charge, ou compteur pas dans l'etat attendu : le chemin complet, qui ne suppose rien.
            window.reloadResources(reponse, function (ressources) {
                livrer(rappels, ressources);
            });
        } else {
            travaux.forEach(function (travail) {
                // **La seule ressource concernee, et toujours en place.** Le contenu vient rendu du serveur, et ses
                // lignes ne changent pas de nombre : un fait qui change (production, capacite) s'ecrit comme un
                // montant. Reconstruire ne servait qu'a remesurer la boite — et `initTooltips()` fermait au passage
                // l'infobulle ouverte d'une AUTRE tuile (mesure en jeu, journal §186). La reconstruction n'est plus
                // qu'un secours, quand le noeud ouvert n'a pas la structure attendue.
                if (!ecrireLInfobulleEnPlace(travail.nom, travail.texte, travail.precedent)) {
                    refaireLInfobulle(travail.nom, travail.texte);
                }
            });

            livrer(rappels, reponse.resources);
        }

        retenirLaCharge(reponse);
        reglerLAnimation(reponse);
    }

    /**
     * Le chemin d'origine pour une seule boite : detruire et reconstruire. Il ferme l'infobulle ouverte ; il n'est
     * pris qu'en secours, quand l'ecriture en place n'a pas trouve la structure qu'elle attend.
     */
    function refaireLInfobulle(nom, texte) {
        var boite = document.getElementById(nom + '_box');

        if (!boite || typeof window.changeTooltip !== 'function') {
            return;
        }

        try {
            // L'element brut : `changeTooltip()` l'enveloppe lui-meme (`$(object)`).
            window.changeTooltip(boite, texte);
        } catch (e) {
            signaler('l infobulle de ' + nom + ' n a pas pu etre refaite', e);
        }
    }

    /**
     * Regler l'animation de la population et de la nourriture sur la charge qui vient d'arriver.
     *
     * **Ces deux tuiles ne bougeaient pas du tout** : le compteur herite les anime a partir de clefs que le serveur
     * ne publie pas (`capableToFeed`, `growthRate`, `singleFoodConsumption`), donc ses deux branches sont mortes ;
     * et le serveur ne projetait pas l'horloge demographique sur cette route. Les valeurs affichees etaient celles
     * du dernier chargement de page (journal §185.4). Le serveur projette desormais, et l'animation vit ici, sur des
     * champs **dedies** dont la semantique est la notre — jamais ceux du compteur herite.
     */
    function reglerLAnimation(reponse) {
        var faits = reponse.facts || {};
        var tuiles = {};
        var aAnimer = false;

        TUILES_ANIMEES.forEach(function (nom) {
            var fait = faits[nom];
            var tuile = reponse.resources[nom];

            if (!fait || !tuile || typeof fait.per_second !== 'number' || typeof tuile.amount !== 'number') {
                return;
            }

            tuiles[nom] = {
                depart: tuile.amount,
                parSeconde: fait.per_second,
                plafond: typeof fait.cap === 'number' ? fait.cap : null,
                stableJusqua: typeof fait.stable_for === 'number' ? fait.stable_for : null
            };

            // **Un taux qui ne vaut pour aucune seconde n'anime rien.** En famine, ou au grenier plein, le serveur
            // publie `stable_for = 0` : interpoler serait inventer, et redemander l'etat a chaque battement etait une
            // boucle de requetes (sept en trente-cinq secondes, mesure en jeu). La veille de trente secondes suffit.
            if (fait.per_second !== 0 && (typeof fait.stable_for !== 'number' || fait.stable_for > 0)) {
                aAnimer = true;
            }
        });

        animation = { depuis: Date.now(), tuiles: tuiles };

        if (aAnimer) {
            demarrerLAnimation();
        } else {
            // Rien ne croit ni ne decroit : plafond atteint, famine stable, ou aucune forme de vie. On n'anime pas —
            // une tuile immobile est la verite, une tuile qui avance serait une invention.
            arreterLAnimation();
        }
    }

    /**
     * Avancer les tuiles animees d'un battement.
     *
     * **Le taux est celui du serveur, et il ne vaut que pendant `stable_for` secondes** — jusqu'au plafond d'espace
     * vital, jusqu'au grenier plein, ou jusqu'a la derniere bouchee. Au-dela, la suite depend de regles (famine,
     * retour a la population de base) que le navigateur n'a pas a rejouer : on tient la derniere valeur sure et on
     * **redemande l'etat** plutot que d'afficher une progression que le serveur ne confirmerait pas.
     */
    function avancerLAnimation() {
        var compteur = window.resourcesBar;

        if (animation === null || !compteur || !compteur.resources || typeof compteur.refresh !== 'function') {
            return;
        }

        var ecoule = (Date.now() - animation.depuis) / 1000;
        var aResynchroniser = false;
        var ecrit = false;

        Object.keys(animation.tuiles).forEach(function (nom) {
            var tuile = animation.tuiles[nom];

            if (!compteur.resources[nom]) {
                return;
            }

            var duree = ecoule;

            if (tuile.stableJusqua !== null && ecoule > tuile.stableJusqua) {
                duree = tuile.stableJusqua;
                aResynchroniser = true;
            }

            var valeur = tuile.depart + tuile.parSeconde * duree;

            if (tuile.plafond !== null) {
                valeur = Math.min(valeur, tuile.plafond);
            }

            valeur = Math.max(0, valeur);

            if (compteur.resources[nom].amount !== valeur) {
                compteur.resources[nom].amount = valeur;
                ecrit = true;
            }
        });

        if (ecrit) {
            compteur.refresh();
        }

        if (aResynchroniser) {
            arreterLAnimation();
            synchroniser();
        }
    }

    function demarrerLAnimation() {
        if (horlogeDeLAnimation !== null) {
            return;
        }

        horlogeDeLAnimation = window.setInterval(function () {
            // Onglet masque : on n'anime pas. Le retour sur l'onglet resynchronise, et l'animation repart de la.
            if (!document.hidden) {
                avancerLAnimation();
            }
        }, CADENCE_DE_L_ANIMATION);
    }

    function arreterLAnimation() {
        if (horlogeDeLAnimation !== null) {
            window.clearInterval(horlogeDeLAnimation);
            horlogeDeLAnimation = null;
        }
    }

    /**
     * Cette reponse est-elle recevable ?
     *
     * Deux refus, et le second est le moins evident. **Un autre corps** : la page nomme le sien, une reponse qui
     * parle d'un autre n'est pas la sienne. **Une reponse plus ancienne que la derniere appliquee pour ce meme
     * corps** : entre son depart et son arrivee, une depense, une livraison ou une recompense a pu changer le
     * stock, et l'appliquer remettrait a l'ecran l'etat d'avant. Le rechargement de page lors d'un changement de
     * planete ne couvre pas cette course-la : elle se joue sur une page qui ne bouge pas.
     */
    function reponseRecevable(reponse) {
        var corps = corpsDeLaPage();

        if (corps !== null && typeof reponse.body === 'number' && reponse.body !== corps) {
            return false;
        }

        if (typeof reponse.generated_at !== 'number') {
            return true;
        }

        return dernierInstantApplique === null || reponse.generated_at >= dernierInstantApplique;
    }

    /**
     * Annoncer la reponse appliquee a qui suit le bandeau — la cle a molette de la liste des planetes.
     *
     * Un evenement plutot qu'un appel : le bandeau ne connait pas ceux qui l'ecoutent, et un ecouteur qui leve ne
     * prive ni le bandeau ni les rappels des appelants.
     */
    function annoncer(reponse) {
        try {
            document.dispatchEvent(new CustomEvent('ogamex:resourcebox', { detail: reponse }));
        } catch (e) {
            signaler('la reponse n a pas pu etre annoncee', e);
        }
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

                    // **Le reseau a repondu, quoi qu'il advienne de la suite** : une reponse perimee n'est pas une
                    // panne, et la veille ne doit pas se taire a cause d'elle.
                    echecsConsecutifs = 0;

                    if (!reponseRecevable(reponse)) {
                        return;
                    }

                    if (typeof reponse.generated_at === 'number') {
                        dernierInstantApplique = reponse.generated_at;
                    }

                    appliquer(reponse, rappels);
                } catch (e) {
                    signaler('la reponse n a pas pu etre appliquee', e);
                }

                // Hors de l enveloppe : une reponse qui n a pas pu etre appliquee au bandeau porte quand meme
                // l etat de la liste des planetes. Une reponse illisible, elle, est sortie plus haut.
                annoncer(reponse);
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
