/*
 * La vue Empire : poser la charge utile, laisser le code du jeu dessiner, et rattraper ce qui lui manque.
 *
 * ## Un seul moteur de rendu
 *
 * `createImperiumHtml()` — le code client de la vue Empire, embarque dans le bundle — construit tout le tableau.
 * Ce module ne redessine rien : il pose les globales que ce code lit, l appelle, puis applique **au meme endroit**
 * les trois ajustements qui lui manquent : les totaux que seul le serveur sait calculer, les libelles des moyennes,
 * et le nom de colonne rendu collant. Pas de second moteur, pas de calcul duplique.
 *
 * ## Une reponse retardee ne gagne jamais
 *
 * Chaque demande porte une epoque. Seule la reponse a la **derniere** demande emise est appliquee : une reponse en
 * retard ne remplace ni un etat plus recent, ni l onglet choisi entre-temps — changer d onglet emet une demande, donc
 * incremente l epoque, donc perime tout ce qui volait encore. Un echec ne touche a rien : le tableau reste, l instant
 * de la photographie reste celui qui est affiche, et le message le dit.
 *
 * ## Rien ne s accumule
 *
 * Les gestionnaires du module sont delegues sur le document **une fois**, sous un espace de nom. Ceux du jeu
 * (`initEmpire`, la poignee de tri, les infobulles) vivent dans le tableau lui-meme, qui est vide avant chaque rendu :
 * ils meurent avec lui. `initTooltips()` commence d ailleurs par `removeTooltip()`.
 */
(function () {
    'use strict';

    var ESPACE = '.azriaEmpire';

    /* Ce qu on laisse sous le panneau pour le pied de page du jeu. Mesure : 24 px suffisent, 40 respirent. */
    var MARGE_DU_PIED = 40;

    var etat = {
        moons: false,
        charge: null,
        /* Incrementee a chaque demande : elle seule decide quelle reponse a le droit de s afficher. */
        epoque: 0,
    };

    function loca() {
        return window.empireLoca || {};
    }

    function conteneur() {
        return document.getElementById('empireComponent');
    }

    /* Les globales que le code client de la vue Empire lit, posees avant chaque appel. */
    function poserLesGlobales(charge) {
        var boite = conteneur();

        window.empireOrder = charge.order || [];
        window.moonCount = charge.moon_count || 0;
        window.planetType = charge.moons ? 1 : 0;
        window.saveUrl = boite ? boite.getAttribute('data-empire-order') : '';
        window.empireUrl = window.location.pathname + '?';
    }

    /*
     * Le tableau, dessine par le code du jeu. Le conteneur est **vide** d abord : `createImperiumHtml` ajoute, il ne
     * remplace pas — sans cela les colonnes s empileraient a chaque actualisation.
     */
    function rendre(charge) {
        etat.charge = charge;
        etat.moons = !!charge.moons;
        poserLesGlobales(charge);

        var contenant = jQuery('#mainWrapper');
        contenant.empty().append('<div id="empireLoading" style="display:none"></div>');

        if (charge.moons && (!charge.planets || charge.planets.length === 0)) {
            sansLune(contenant, charge);
        } else {
            createImperiumHtml('#mainWrapper', '#empireLoading', charge, !!charge.moons);
        }

        initEmpire();
        apresLeRendu(charge);
    }

    /*
     * Aucune lune : le bandeau et les onglets restent — c est une page du jeu, pas une erreur —, et la place du
     * tableau porte une phrase. Jamais une grille vide, jamais les corps d un autre compte.
     */
    function sansLune(contenant, charge) {
        var entete = createHeaderHtml({ translations: charge.translations, groups: {} });
        var bloc = jQuery('<div>').addClass('empireEmpty')
            .append(jQuery('<strong>').text(loca().noMoons || ''))
            .append(jQuery('<span>').text(loca().noMoonsHint || ''));

        contenant.attr('style', 'width: 675px').append(entete).append(bloc).append('<br class="clearfloat"/>');
    }

    function apresLeRendu(charge) {
        appliquerLesTotaux(charge);
        nommerLesMoyennes(charge);
        rendreLeNomCollant();
        poserLaLegende(charge);
        armerLesOnglets(charge);
        mesurerLePanneau();

        jQuery('#empireTakenAt').text(charge.taken_at_formatted || '');
    }

    /*
     * **La hauteur du panneau se mesure, elle ne se devine pas.**
     *
     * Le tableau defile sur lui-meme (voir la feuille) : il lui faut une hauteur. Une valeur en dur serait fausse des
     * que le bandeau des ressources, une banniere ou la barre du jeu changent de taille. On lit donc la position reelle
     * du panneau et on lui laisse tout ce qui reste sous lui, moins une marge pour le pied de page.
     */
    function mesurerLePanneau() {
        var panneau = document.getElementById('mainContent');

        if (!panneau) {
            return;
        }

        var haut = panneau.getBoundingClientRect().top + window.pageYOffset;
        var disponible = Math.max(320, window.innerHeight - (haut - window.pageYOffset) - MARGE_DU_PIED);

        panneau.style.maxHeight = disponible + 'px';
    }

    /*
     * Les totaux que le code client ne peut pas calculer : une moyenne nommee, le nombre de corps ou une technologie
     * contribue vraiment, et les effets du compte — qui figurent **une seule fois**, jamais multiplies par le nombre
     * de colonnes.
     */
    function appliquerLesTotaux(charge) {
        var totaux = charge.summary || {};

        Object.keys(totaux).forEach(function (clef) {
            var cellule = jQuery('#planet0 .values .' + clef);

            if (!cellule.length) {
                return;
            }

            cellule.html(totaux[clef].html);

            if (totaux[clef].title) {
                cellule.addClass('tooltipRight').attr('title', totaux[clef].title);
            }
        });
    }

    /* Une moyenne sans son libelle est un nombre qui ment : « ø 22,5 » ne dit pas sur quoi il porte. */
    function nommerLesMoyennes(charge) {
        var gabarit = loca().averageLevel;

        if (!gabarit) {
            return;
        }

        var texte = gabarit.replace(':count', String((charge.planets || []).length));

        jQuery('#planet0 .values.supply > div, #planet0 .values.station > div').each(function () {
            if (this.textContent.indexOf('ø') === 0 && !this.getAttribute('title')) {
                jQuery(this).addClass('tooltipRight').attr('title', texte);
            }
        });
    }

    /*
     * **Le nom de colonne doit sortir de son en-tete pour pouvoir coller.** Un element colle ne quitte jamais son bloc
     * conteneur : depuis l interieur de `.planetHead`, qui fait 257 px, il disparait des qu on descend. Devenu enfant
     * direct de la colonne, il tient tout du long. La frise de l en-tete est recoupee par la feuille.
     */
    function rendreLeNomCollant() {
        jQuery('#empireComponent .planet').each(function () {
            var nom = jQuery(this).children('.planetHead').children('.planetname');

            if (nom.length) {
                jQuery(this).prepend(nom);
            }
        });
    }

    /* La legende des etats de technologie, sur le titre du groupe : les formes se lisent sans la couleur. */
    function poserLaLegende(charge) {
        var legende = loca().legend;

        if (!legende || !charge.groups || !charge.groups.lifeforms) {
            return;
        }

        jQuery('#lifeforms h3').addClass('tooltipRight').attr('title', legende);
    }

    /*
     * Les onglets changent de jeu de donnees **sans quitter la page** : le code du jeu les ferait naviguer, ce qui
     * perdrait le defilement et rechargerait tout le document. Ils sont reconstruits a chaque rendu, donc rebranches
     * a chaque rendu — et meurent avec le tableau : rien ne s accumule.
     */
    function armerLesOnglets(charge) {
        jQuery('#planetsTab').off('click').on('click', function () {
            if (etat.moons) {
                demander(false);
            }
        });

        var lunes = jQuery('#moonsTab');
        lunes.off('click');

        if ((charge.moon_count || 0) === 0) {
            lunes.addClass('nomoons').attr('title', loca().noMoons || '');

            return;
        }

        lunes.on('click', function () {
            if (!etat.moons) {
                demander(true);
            }
        });
    }

    /* La reponse n est appliquee que si elle repond a la derniere demande emise. */
    function estCaduque(demande) {
        return demande.epoque !== etat.epoque;
    }

    function demander(moons) {
        var demande = { epoque: ++etat.epoque, moons: !!moons };
        var boite = conteneur();

        if (!boite) {
            return demande;
        }

        jQuery('#empireStale').prop('hidden', true);

        jQuery.ajax({
            url: boite.getAttribute('data-empire-refresh'),
            type: 'GET',
            dataType: 'json',
            data: { planetType: demande.moons ? 1 : 0 },
        }).done(function (charge) {
            if (estCaduque(demande) || !charge || !charge.translations) {
                return;
            }

            rendre(charge);
        }).fail(function () {
            if (estCaduque(demande)) {
                return;
            }

            /* Rien n est efface et l instant affiche ne bouge pas : ce qui est lu reste vrai de sa photographie. */
            jQuery('#empireStale').prop('hidden', false);
        });

        return demande;
    }

    jQuery(document).off('click' + ESPACE).on('click' + ESPACE, '#empireRefresh', function (e) {
        e.preventDefault();
        demander(etat.moons);
    });

    /* Une seule fois, comme tout le reste : la hauteur du panneau se remesure quand la fenetre change. */
    jQuery(window).off('resize' + ESPACE).on('resize' + ESPACE, mesurerLePanneau);

    /*
     * « Reinitialiser l ordre » : le code du jeu recharge la page apres avoir oublie l ordre, et n a aucune idee de
     * l onglet ouvert. Ici la definition qui compte est la derniere chargee — celle-ci : elle nomme l onglet et
     * redessine sans quitter la page.
     */
    window.clearImperiumOrder = function () {
        var boite = conteneur();

        if (!boite) {
            return false;
        }

        jQuery.ajax({
            url: boite.getAttribute('data-empire-order'),
            type: 'POST',
            dataType: 'json',
            data: { type: 'reset', moons: etat.moons ? 1 : 0, _token: window.empireToken },
        }).always(function () {
            demander(etat.moons);
        });

        return false;
    };

    jQuery(function () {
        if (!conteneur() || typeof createImperiumHtml !== 'function' || !window.empirePayload) {
            return;
        }

        rendre(window.empirePayload);
    });

    window.AzriaEmpire = {
        render: rendre,
        request: demander,
        state: etat,
    };
})();
