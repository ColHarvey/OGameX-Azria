/*
 * La Galaxie tactique — carte du systeme solaire.
 *
 * ## Ou ce module se branche, et pourquoi la
 *
 * Toute la Galaxie passe par une seule fonction : `renderContentGalaxy(json)`, appelee par
 * `$.post(galaxyContentLink, ..., renderContentGalaxy)` a chaque changement de systeme. Ce module
 * l'**enveloppe** au lieu de la remplacer :
 *
 *   - la fonction historique continue de tenir les compteurs du bandeau (sondes, recycleurs,
 *     missiles, emplacements, colonies) — ils vivent hors de la carte et restent justes ;
 *   - ses ecritures de lignes visent `#galaxyRow{N} .cellX`. **Ces lignes existent toujours** —
 *     seize identifiants dans le Blade — et le rendu herite les remplit entierement, liens
 *     d'action compris. C'est une regle CSS qui les masque en vue tactique, pas le document ;
 *   - la carte se dessine ensuite depuis exactement le meme JSON.
 *
 * ## Les textures sont celles du serveur
 *
 * La premiere version posait `<div class="microplanet desert_7">`. **Mesure faite dans la feuille du
 * jeu** : la variante n'existe que sous `#galaxyContent .ctContentRow .cellPlanet .desert_7` ou
 * `#galaxyContent td.microplanet.desert_7`. La carte n'est ni l'un ni l'autre, donc le bloc n'avait
 * aucune image de fond — les planetes etaient invisibles.
 *
 * On emploie donc la source que le README du pack designe comme autoritative :
 * `public/img/planets/medium/{biome}_{variante}.png`. Couverture verifiee — sept biomes, dix
 * variantes, soixante-dix fichiers, exactement ce que `getPlanetBiomeType()` peut rendre. Aucun
 * tirage graphique cote client : l'identite d'une planete reste celle que le serveur a attribuee.
 *
 * ## Ce que ce module ne decide pas
 *
 * **Aucune autorisation.** Les actions permises sont calculees par le serveur
 * (`GalaxyController::getPlanetActions()` et `getAvailableMissions()`) et rendues dans la ligne du
 * tableau. La fiche **deplace** cette ligne, elle ne la reconstruit pas : le meme noeud garde les
 * gestionnaires du jeu — infobulles, overlay de missile, ami, ignore — et une carte qui recalculerait
 * un droit cote client serait une regression de securite, pas une refonte d'interface.
 *
 * Il n'affiche **aucune flotte** : la Galaxie n'en envoie encore aucune au client (`'fleet' => []`
 * sans exception). Les trajectoires, les portes de bord et le temps reel sont la passe suivante.
 */
(function () {
    'use strict';

    /*
     * La geometrie, derivee de la mesure du panneau reel (670 x 720 hors tout, table 656 x 678,
     * viewport 1365 x 951) et non du 654 x 610 annonce par le README, dont la hauteur etait fausse
     * de 68 px.
     */
    var LARGEUR = 656;
    var HAUTEUR = 610;
    var PIED = 22;

    /*
     * L'angle d'or. Deux positions consecutives sont separees de 137,5 degres : elles ne se
     * groupent jamais, quel que soit leur nombre, et deux orbites voisines ne superposent pas
     * leurs corps. Le tirage est **deterministe** — le meme systeme se dessine toujours pareil.
     */
    var ANGLE_OR = 137.508;

    var POSITIONS = 15;
    var RAYON_MIN = 46;
    var RAYON_MAX = 310;
    var APLATISSEMENT = 0.58;

    /* Les trois genres de corps que la charge utile distingue. */
    var PLANETE = 1;
    var DEBRIS = 2;
    var LUNE = 3;

    /* La fiche, et la largeur qu'elle occupe : elle doit rester entierement dans la carte. */
    var FICHE_LARGEUR = 232;
    var FICHE_MARGE = 8;

    function centre() {
        return { x: LARGEUR / 2, y: (HAUTEUR - PIED) / 2 };
    }

    /* Le demi-grand axe de l'orbite d'une position : il croit avec l'eloignement de l'etoile. */
    function rayonDe(position) {
        return RAYON_MIN + ((position - 1) * (RAYON_MAX - RAYON_MIN)) / (POSITIONS - 1);
    }

    function pointDe(position) {
        var c = centre();
        var rx = rayonDe(position);
        var angle = ((position * ANGLE_OR - 90) * Math.PI) / 180;

        return {
            x: c.x + rx * Math.cos(angle),
            y: c.y + rx * APLATISSEMENT * Math.sin(angle),
            aDroite: Math.cos(angle) > 0
        };
    }

    function element(balise, classe) {
        var e = document.createElement(balise);

        if (classe) {
            e.className = classe;
        }

        return e;
    }

    /* Le corps d'un genre donne dans une ligne, ou `null` : la charge utile ne les ordonne pas. */
    function corpsDeGenre(ligne, genre) {
        var liste = (ligne && ligne.planets) || [];

        for (var i = 0; i < liste.length; i++) {
            if (Number(liste[i].planetType) === genre) {
                return liste[i];
            }
        }

        return null;
    }

    /*
     * La texture reelle d'un corps.
     *
     * `imageInformation` vaut `{biome}_{variante}` — ou `npc_pirate` pour une base hostile, qui a sa
     * propre image dans le jeu. Une image absente ne doit pas laisser un cadre casse : `onerror`
     * efface l'element plutot que d'afficher l'icone de lien brise du navigateur.
     */
    function texture(corps) {
        var nom = (corps && corps.imageInformation) || '';
        var img = element('img', 'gtTexture');

        img.src = nom === 'npc_pirate'
            ? '/img/planets/npc/pirate_base.png'
            : '/img/planets/medium/' + nom + '.png';

        img.alt = '';
        img.setAttribute('aria-hidden', 'true');
        img.addEventListener('error', function () {
            img.remove();
        });

        return img;
    }

    /*
     * Le verdict du serveur sur la colonisation d'une position libre, lu dans la charge utile.
     *
     * `GalaxyController::createEmptySpaceRow()` place la mission 7 avec un lien reel quand elle est
     * permise, et le seul caractere `#` quand elle ne l'est pas. C'est ce verdict qu'on lit — la
     * carte ne connait ni le niveau d'astrophysique, ni les positions reservees, et n'a pas a les
     * connaitre.
     */
    function colonisationPermise(ligne) {
        var missions = (ligne && ligne.availableMissions) || [];

        for (var i = 0; i < missions.length; i++) {
            if (Number(missions[i].missionType) === 7) {
                return typeof missions[i].link === 'string' && missions[i].link !== '#';
            }
        }

        return false;
    }

    /*
     * Les coordonnees d'une position, telles que la charge utile les porte.
     *
     * Une ligne vide n'en porte pas : la position, elle, est toujours connue, et c'est elle qui
     * fait le troisieme nombre. Rien n'est devine — la galaxie et le systeme affiches sont ceux
     * que le serveur vient de rendre.
     */
    function coordonneesDe(ligne, position) {
        if (!ligne || ligne.galaxy === undefined || ligne.system === undefined) {
            return '';
        }

        return '[' + ligne.galaxy + ':' + ligne.system + ':' + position + ']';
    }

    /*
     * Les libelles viennent de la page, traduits par le serveur.
     *
     * Deux sources, et elles ne se valent pas. `jsloca` est la table du jeu : elle sert pour une
     * clef dont on sait qu'elle y est. Pour les textes de la carte, l'attribut de la vue est
     * preferable — une clef absente de `jsloca` rendrait `undefined` sans erreur, et le repli
     * anglais s'afficherait a un joueur francais sans que personne ne le remarque.
     */
    function loca(clef, defaut) {
        var table = window.jsloca || {};

        return table[clef] || defaut;
    }

    function locaDeLaCarte(carte, nom, defaut) {
        var valeur = carte.getAttribute('data-loca-' + nom);

        return valeur !== null && valeur !== '' ? valeur : defaut;
    }

    function libelle(texte) {
        var e = element('span', 'gtName');
        e.textContent = texte;

        return e;
    }

    function numero(position) {
        var e = element('span', 'gtIndex');
        e.textContent = String(position);

        return e;
    }

    /*
     * Un corps place sur son orbite. Le bloc est ancre par son centre : la position exprime une
     * coordonnee d'orbite, pas un coin de boite.
     *
     * Le nom passe **a gauche** de la vignette quand le corps est dans la moitie droite, sinon il
     * deborderait du panneau — un debordement horizontal que le cahier des charges interdit.
     */
    function poser(carte, position, contenu, options) {
        var p = pointDe(position);
        var bloc = element('div', 'gtBody' + (options && options.classe ? ' ' + options.classe : ''));

        bloc.style.left = Math.round(p.x) + 'px';
        bloc.style.top = Math.round(p.y) + 'px';
        bloc.setAttribute('data-position', String(position));
        bloc.setAttribute('tabindex', '0');
        bloc.setAttribute('role', 'button');

        if (options && options.intitule) {
            bloc.setAttribute('aria-label', options.intitule);
        }

        if (p.aDroite) {
            bloc.style.flexDirection = 'row-reverse';
        }

        contenu.forEach(function (n) {
            bloc.appendChild(n);
        });

        carte.appendChild(bloc);

        return bloc;
    }

    function dessinerOrbites(carte) {
        for (var i = 1; i <= POSITIONS; i++) {
            var rx = rayonDe(i);
            var o = element('div', 'gtOrbit');
            o.style.width = Math.round(rx * 2) + 'px';
            o.style.height = Math.round(rx * APLATISSEMENT * 2) + 'px';
            carte.appendChild(o);
        }
    }

    /*
     * ## La fiche contextuelle
     *
     * **La ligne du tableau est deplacee dedans, jamais recopiee.** `renderContentGalaxy` accroche
     * ses gestionnaires sur ces noeuds a chaque rendu — infobulles, overlay de missile, demande
     * d'ami, mise a l'ignore. Un clone les perdrait tous, et *en silence* : la fiche s'afficherait,
     * les liens seraient la, et rien ne repondrait au clic. Le meme noeud garde tout.
     *
     * La feuille se charge de la presentation : dans la fiche, la ligne devient une colonne et sa
     * cellule d'actions une grille d'icones. Les regles, les droits et les textes restent
     * exactement ceux du serveur.
     */
    var deplacee = null;

    function fiche(carte) {
        var f = carte.querySelector('.gtCard');

        if (f) {
            return f;
        }

        f = element('div', 'gtCard');
        f.setAttribute('role', 'dialog');
        f.setAttribute('aria-label', locaDeLaCarte(carte, 'card', 'Fiche'));
        f.hidden = true;

        var tete = element('div', 'gtCardHead');
        var identite = element('span', 'gtCardIdentity');
        var titre = element('span', 'gtCardTitle');
        var coords = element('span', 'gtCardCoords');

        identite.appendChild(titre);
        identite.appendChild(coords);

        var fermer = element('button', 'gtCardClose');
        fermer.type = 'button';
        fermer.setAttribute('aria-label', locaDeLaCarte(carte, 'close', 'Fermer'));
        fermer.textContent = '×';
        fermer.addEventListener('click', function () {
            deselectionner(carte);
        });

        tete.appendChild(identite);
        tete.appendChild(fermer);

        f.appendChild(tete);
        f.appendChild(element('div', 'gtCardBody'));
        carte.appendChild(f);

        return f;
    }

    /* La ligne retourne exactement d'ou elle venait — meme parent, meme rang. */
    function rendreLaLigne() {
        if (!deplacee) {
            return;
        }

        deplacee.parent.insertBefore(deplacee.noeud, deplacee.suivant);
        deplacee = null;
    }

    function deselectionner(carte) {
        rendreLaLigne();

        var f = carte.querySelector('.gtCard');

        if (f) {
            f.hidden = true;
        }

        var choisis = carte.querySelectorAll('.gtBody.gtSelected');

        for (var i = 0; i < choisis.length; i++) {
            choisis[i].classList.remove('gtSelected');
        }
    }

    /*
     * La fiche se colle au corps choisi, sans jamais sortir de la carte : a droite si la place y
     * est, a gauche sinon. Une fiche coupee par le bord serait illisible, et le cahier des charges
     * l'interdit explicitement.
     */
    function placer(f, bloc) {
        var x = bloc.offsetLeft + 26;

        if (x + FICHE_LARGEUR + FICHE_MARGE > LARGEUR) {
            x = bloc.offsetLeft - FICHE_LARGEUR - 26;
        }

        f.style.left = Math.max(FICHE_MARGE, Math.min(x, LARGEUR - FICHE_LARGEUR - FICHE_MARGE)) + 'px';

        var y = bloc.offsetTop - 20;
        var hauteurUtile = HAUTEUR - PIED - FICHE_MARGE;

        f.style.top = Math.max(FICHE_MARGE, Math.min(y, hauteurUtile - f.offsetHeight)) + 'px';
    }

    function choisir(carte, bloc) {
        var position = Number(bloc.getAttribute('data-position'));
        var ligne = document.getElementById('galaxyRow' + position);
        var f = fiche(carte);

        if (!ligne) {
            return;
        }

        /* Recliquer la position deja ouverte la referme : le meme geste dans les deux sens. */
        if (deplacee && deplacee.noeud === ligne) {
            deselectionner(carte);

            return;
        }

        deselectionner(carte);

        var titre = f.querySelector('.gtCardTitle');
        var coords = f.querySelector('.gtCardCoords');

        if (titre) {
            titre.textContent = bloc.getAttribute('data-titre') || String(position);
        }

        if (coords) {
            coords.textContent = bloc.getAttribute('data-coords') || '';
        }

        var corps = f.querySelector('.gtCardBody');
        deplacee = { noeud: ligne, parent: ligne.parentNode, suivant: ligne.nextSibling };
        corps.appendChild(ligne);

        f.hidden = false;
        bloc.classList.add('gtSelected');
        placer(f, bloc);
    }

    /*
     * Les gestionnaires vivent sur la carte, pas sur les corps : `dessiner()` vide la carte a chaque
     * rendu, et des gestionnaires poses sur les corps disparaitraient avec eux. Poses une fois sur
     * le contenant, ils survivent a tous les rendus.
     */
    function armerLaSelection(carte) {
        if (carte.gtArmee) {
            return;
        }

        carte.gtArmee = true;

        carte.addEventListener('click', function (evenement) {
            /* Un clic dans la fiche appartient a la fiche : il ne rechoisit pas un corps. */
            if (evenement.target.closest && evenement.target.closest('.gtCard')) {
                return;
            }

            var bloc = evenement.target.closest ? evenement.target.closest('.gtBody') : null;

            if (bloc) {
                choisir(carte, bloc);
            }
        });

        carte.addEventListener('keydown', function (evenement) {
            if (evenement.key === 'Escape' || evenement.key === 'Esc') {
                deselectionner(carte);

                return;
            }

            var bloc = evenement.target.closest ? evenement.target.closest('.gtBody') : null;

            if (!bloc) {
                return;
            }

            /* Un element qui annonce `role="button"` doit repondre a Entree et a Espace. */
            if (evenement.key === 'Enter' || evenement.key === ' ' || evenement.key === 'Spacebar') {
                evenement.preventDefault();
                choisir(carte, bloc);
            }
        });
    }

    /*
     * ## La bascule tactique / liste
     *
     * Un seul interrupteur porte les deux vues : la classe `gtReplaced` sur `.galaxyTable`. Presente,
     * les lignes sont masquees et la carte s'affiche ; absente, le tableau revient tel qu'il a
     * toujours ete. Le README du pack demande de garder cette vue liste comme reference de parite
     * tant que chaque fonction n'a pas ete verifiee sur la carte.
     */
    function armerLaBascule(carte) {
        var tactique = document.getElementById('gtViewTactical');
        var liste = document.getElementById('gtViewList');
        var table = carte.closest ? carte.closest('.galaxyTable') : null;

        if (!tactique || !liste || !table || tactique.gtArmee) {
            return;
        }

        tactique.gtArmee = true;

        var appliquer = function (versLaCarte) {
            /* La ligne rentre avant tout changement de vue : sinon elle resterait dans la fiche. */
            deselectionner(carte);

            table.classList.toggle('gtReplaced', versLaCarte);
            tactique.classList.toggle('gtViewActive', versLaCarte);
            liste.classList.toggle('gtViewActive', !versLaCarte);
            tactique.setAttribute('aria-pressed', versLaCarte ? 'true' : 'false');
            liste.setAttribute('aria-pressed', versLaCarte ? 'false' : 'true');
        };

        tactique.addEventListener('click', function () {
            appliquer(true);
        });

        liste.addEventListener('click', function () {
            appliquer(false);
        });
    }

    /*
     * L'etat des filtres survit au redessin.
     *
     * `filterToggle()` pose `filtered_filter_empty` au moment du clic, sur les elements presents
     * a cet instant. La carte etant reconstruite a chaque changement de systeme, ses corps
     * naissent apres le clic et n'auraient rien : le filtre paraitrait s'eteindre tout seul.
     * On relit donc l'etat du bouton, seule source de verite, et on l'applique.
     */
    function appliquerLesFiltres(carte) {
        var bouton = document.getElementById('filter_empty');

        if (!bouton || !bouton.classList.contains('filter_active')) {
            return;
        }

        var libres = carte.querySelectorAll('.empty_filter');

        for (var i = 0; i < libres.length; i++) {
            libres[i].classList.add('filtered_filter_empty');
        }
    }

    /*
     * Le systeme entier, redessine a neuf.
     *
     * Vider puis reconstruire est volontaire : une carte qui se met a jour par differences devrait
     * savoir ce qui a change, et un changement de systeme change tout. Quinze positions ne coutent
     * rien a reconstruire, et l'etat affiche ne peut pas deriver de la charge utile.
     */
    function dessiner(json) {
        var carte = document.getElementById('galaxyTactical');

        if (!carte || !json || !json.galaxy) {
            return;
        }

        /*
         * La ligne rentre **avant** que la carte soit videe. Le systeme a change : la position
         * choisie n'a plus de sens, et une ligne laissee dans la fiche y afficherait les donnees
         * du nouveau systeme sous l'ancienne selection.
         */
        deselectionner(carte);

        carte.innerHTML = '';
        armerLaSelection(carte);
        armerLaBascule(carte);
        dessinerOrbites(carte);
        carte.appendChild(element('div', 'gtStar'));

        var parPosition = {};

        json.galaxy.forEach(function (ligne) {
            parPosition[Number(ligne.position)] = ligne;
        });

        for (var position = 1; position <= POSITIONS; position++) {
            var ligne = parPosition[position];
            var planete = corpsDeGenre(ligne, PLANETE);

            if (!planete) {
                /*
                 * **Le droit de coloniser se lit, il ne se recalcule pas.** Le serveur a deja
                 * tranche : il envoie un vrai lien quand la colonisation est permise, et `#` sinon
                 * (astrophysique, vaisseau disponible, position reservee, portee). Rederiver la
                 * regle ici en ferait une seconde source de verite, qui divergerait un jour.
                 */
                var libre = loca('LOCA_GALAXY_EMPTY_SLOT', 'position libre');
                var silhouette = element('div', 'gtEmpty' + (colonisationPermise(ligne) ? '' : ' gtUnavailable'));
                /*
                 * `empty_filter` est la classe que le filtre « E » du bandeau vise. En la portant,
                 * une position libre de la carte s'attenue comme la ligne du tableau le faisait.
                 * Mesure faite : c'est le seul des cinq filtres que ce fork alimente reellement.
                 */
                var bloc = poser(carte, position, [silhouette, numero(position)], {
                    classe: 'gtFree empty_filter',
                    intitule: position + ' — ' + libre
                });

                bloc.setAttribute('data-titre', libre);
                bloc.setAttribute('data-coords', coordonneesDe(ligne, position));

                continue;
            }

            var contenu = [texture(planete)];
            var lune = corpsDeGenre(ligne, LUNE);
            var debris = corpsDeGenre(ligne, DEBRIS);

            /*
             * La lune et les debris prennent les visuels du pack tactique, la ou les planetes
             * gardent la texture du serveur : une lune n'a que deux etats et un champ de debris une
             * seule variante, donc aucune identite par corps ne se perd.
             */
            if (lune) {
                var creneau = element('span', 'gtMoonSlot');
                creneau.appendChild(element('span', 'gtMoon' + (lune.isDestroyed ? ' gtDestroyed' : '')));
                contenu.push(creneau);
            }

            if (debris) {
                contenu.push(element('span', 'gtDebris'));
            }

            var nom = planete.planetName || '';
            contenu.push(libelle(nom));
            contenu.push(numero(position));

            var intitule = position + ' — ' + nom + (ligne.playerName ? ' (' + ligne.playerName + ')' : '');
            var corpsBloc = poser(carte, position, contenu, {
                classe: ligne.playerId && window.playerId && Number(ligne.playerId) === Number(window.playerId) ? 'gtOwn' : '',
                intitule: intitule
            });

            corpsBloc.setAttribute('data-titre', nom || String(position));
            corpsBloc.setAttribute('data-coords', coordonneesDe(ligne, position));
        }

        appliquerLesFiltres(carte);

        var pied = element('div', 'gtFooter');
        pied.id = 'galaxyTacticalFooter';
        carte.appendChild(pied);
    }

    /*
     * Le branchement. Il attend que la fonction historique existe : ce module est concatene apres
     * le bloc herite, mais l'ordre d'un bundle n'est pas une garantie qu'on veut supposer.
     */
    function brancher() {
        if (typeof window.renderContentGalaxy !== 'function' || window.renderContentGalaxy.gtEnveloppee) {
            return;
        }

        var historique = window.renderContentGalaxy;

        var enveloppe = function (json) {
            var issue = historique.apply(this, arguments);

            try {
                dessiner(json);
            } catch (e) {
                // Le rendu de la carte ne doit jamais empecher le reste de la page de fonctionner.
                if (window.console && window.console.error) {
                    window.console.error('Galaxie tactique : rendu impossible', e);
                }
            }

            return issue;
        };

        enveloppe.gtEnveloppee = true;
        window.renderContentGalaxy = enveloppe;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', brancher);
    } else {
        brancher();
    }
})();
