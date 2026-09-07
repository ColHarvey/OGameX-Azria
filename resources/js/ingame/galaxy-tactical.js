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
 * Les flottes qu'il affiche sont celles que `galaxyFleetsUrl` lui rend — les mouvements que ce
 * joueur voit deja dans sa boite d'evenements, filtres au systeme affiche. Rien n'est cache par
 * la feuille : ce qui n'est pas visible n'est pas envoye.
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
    var RAYON_MIN = 72;
    var RAYON_MAX = 300;
    var APLATISSEMENT = 0.58;

    /*
     * La distance minimale entre deux corps, centre a centre. Une vignette fait 44 px ; le nom
     * est sous elle, pas a cote, pour que l'empreinte horizontale reste celle de la vignette.
     * L'angle d'or seul ne garantit rien sur une ellipse aplatie : l'ecartement ci-dessous le fait.
     */
    var DISTANCE_MIN = 72;
    var PASSES_D_ECARTEMENT = 60;

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

    function pointSurOrbite(position, angle) {
        var c = centre();
        var rx = rayonDe(position);

        return {
            x: c.x + rx * Math.cos(angle),
            y: c.y + rx * APLATISSEMENT * Math.sin(angle),
            aDroite: Math.cos(angle) > 0
        };
    }

    /*
     * Les angles des quinze positions : l'angle d'or, puis un ecartement **deterministe**.
     *
     * A chaque passe, toute paire plus proche que DISTANCE_MIN est repoussee : chacun des deux
     * corps tourne sur sa propre orbite, de la moitie du manque convertie en angle, dans le sens
     * qui les eloigne. Meme entree, meme sortie — le meme systeme se dessine toujours pareil, et
     * aucune planete ne change d'orbite. `tests/Feature/GalaxyTacticalMapTest.php` rejoue cette
     * arithmetique en PHP et exige la distance minimale sur toutes les paires.
     */
    var anglesCalcules = null;

    function anglesDesPositions() {
        if (anglesCalcules !== null) {
            return anglesCalcules;
        }

        var angles = [];
        var i;
        var j;

        for (i = 1; i <= POSITIONS; i++) {
            angles[i] = ((i * ANGLE_OR - 90) * Math.PI) / 180;
        }

        for (var passe = 0; passe < PASSES_D_ECARTEMENT; passe++) {
            for (i = 1; i <= POSITIONS; i++) {
                for (j = i + 1; j <= POSITIONS; j++) {
                    var a = pointSurOrbite(i, angles[i]);
                    var b = pointSurOrbite(j, angles[j]);
                    var dx = b.x - a.x;
                    var dy = b.y - a.y;
                    var d = Math.sqrt(dx * dx + dy * dy);

                    if (d >= DISTANCE_MIN) {
                        continue;
                    }

                    var manque = (DISTANCE_MIN - d) / 2;
                    var ecart = angles[j] - angles[i];
                    ecart = Math.atan2(Math.sin(ecart), Math.cos(ecart));
                    var sens = ecart >= 0 ? 1 : -1;

                    angles[i] -= (sens * manque) / rayonDe(i);
                    angles[j] += (sens * manque) / rayonDe(j);
                }
            }
        }

        anglesCalcules = angles;

        return angles;
    }

    function pointDe(position) {
        return pointSurOrbite(position, anglesDesPositions()[position]);
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

    /* Un corps secondaire — lune, debris — devient une cible clavier et souris a part entiere. */
    function cibleDistincte(noeud, corps, intitule) {
        noeud.setAttribute('data-corps', corps);
        noeud.setAttribute('role', 'button');
        noeud.setAttribute('tabindex', '0');
        noeud.setAttribute('aria-label', intitule);
    }

    function choisir(carte, bloc, corps) {
        var position = Number(bloc.getAttribute('data-position'));
        var ligne = document.getElementById('galaxyRow' + position);
        var f = fiche(carte);

        if (!ligne) {
            return;
        }

        corps = corps || 'planet';

        /* Recliquer le corps deja ouvert le referme ; cliquer un autre corps de la meme position bascule. */
        if (deplacee && deplacee.noeud === ligne && f.getAttribute('data-corps') === corps) {
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

        /* La fiche dit quel corps est choisi ; la feuille met sa cellule en avant. */
        f.setAttribute('data-corps', corps);

        var contenant = f.querySelector('.gtCardBody');
        deplacee = { noeud: ligne, parent: ligne.parentNode, suivant: ligne.nextSibling, position: position, corps: corps };
        contenant.appendChild(ligne);

        f.hidden = false;
        bloc.classList.add('gtSelected');
        placer(f, bloc);
    }

    /*
     * Les gestionnaires vivent sur la carte, pas sur les corps : `dessiner()` vide la carte a chaque
     * rendu, et des gestionnaires poses sur les corps disparaitraient avec eux. Poses une fois sur
     * le contenant, ils survivent a tous les rendus.
     */
    /* Le corps vise par un clic ou un focus : la lune, les debris, ou la planete par defaut. */
    function corpsClique(cible) {
        var secondaire = cible && cible.closest ? cible.closest('[data-corps]') : null;

        return secondaire ? secondaire.getAttribute('data-corps') : 'planet';
    }

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
                choisir(carte, bloc, corpsClique(evenement.target));
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
                choisir(carte, bloc, corpsClique(evenement.target));
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
     * ## La couche des flottes
     *
     * **Le droit vient du serveur, la position vient de l'horloge du serveur.** Le point d'entree
     * `galaxyFleetsUrl` rend les mouvements que ce joueur a deja le droit de voir dans sa boite
     * d'evenements, filtres au systeme affiche — rien de plus. Ce module ne recoit jamais une
     * flotte qu'il devrait cacher : ce qui n'est pas visible n'est pas envoye.
     *
     * Chaque mouvement porte ses deux instants autoritatifs. La position dessinee est une simple
     * interpolation entre eux, sur l'heure du serveur (`server_now` corrige l'ecart des horloges).
     * Le navigateur ne calcule ni vitesse, ni trajet metier, ni arrivee : quand une flotte arrive,
     * c'est le serveur qui le dit, par le canal du joueur, et la couche est redemandee.
     *
     * Un vol vers ou depuis un autre systeme ne traverse pas la carte : il rejoint une **porte de
     * bord** dans la direction du corps concerne, et sa fiche porte les coordonnees exterieures.
     */
    var COUCHE_SVG = 'http://www.w3.org/2000/svg';
    var COUCHE_XLINK = 'http://www.w3.org/1999/xlink';
    var ICONES_DE_MISSION = {
        1: 'attack', 2: 'acs-attack', 3: 'transport', 4: 'deploy', 5: 'acs-defend',
        6: 'espionage', 7: 'colonize', 8: 'recycle', 9: 'moon-destruction', 10: 'missile', 15: 'expedition'
    };
    var MISSILE = 10;
    var RAFRAICHISSEMENT_SANS_MOUVEMENT = 5000;

    /* Le vaisseau de la page « Mouvement de flotte » : le meme GIF anime, il regarde vers la droite. */
    var VAISSEAU_BLANC = '/img/icons/f9cb590cdf265f499b0e2e5d91fc75.gif';

    /* Le cap d'une trajectoire, en degres, dans le sens du vol. */
    function capDe(bouts) {
        return (Math.atan2(bouts.arrivee.y - bouts.depart.y, bouts.arrivee.x - bouts.depart.x) * 180) / Math.PI;
    }

    /*
     * La part d'un vol inter-systemes qui se joue dans chaque systeme : le premier quart dans
     * celui du depart (planete → bord), le dernier dans celui de l'arrivee (bord → cible). Entre
     * les deux, la flotte est en **hyperespace** — decision de Keven : elle ne traverse pas les
     * systemes intermediaires ; une trainee anime le bord, et un bouton permet de la suivre.
     */
    var PART_LOCALE = 0.25;

    /* Charger un autre systeme, par le chemin du jeu quand il existe. */
    function allerAuSysteme(galaxie, systeme) {
        if (typeof window.loadContent === 'function') {
            window.loadContent(galaxie, systeme);

            return;
        }

        redemanderLeSysteme(galaxie, systeme);
    }

    var jetonDeSysteme = 0;
    var decalageHorloge = 0;
    var mouvements = [];
    var animation = null;
    var minuterieStatique = null;
    var systemeAbonne = null;
    var joueurAbonne = false;

    function mouvementReduit() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function maintenantServeur() {
        return Date.now() + decalageHorloge;
    }

    function svg(balise, attributs) {
        var e = document.createElementNS(COUCHE_SVG, balise);

        Object.keys(attributs || {}).forEach(function (nom) {
            e.setAttribute(nom, String(attributs[nom]));
        });

        return e;
    }

    /*
     * La porte de bord : le point ou une trajectoire quitte ou rejoint la carte. Elle est prise dans
     * la direction du corps local, poussee jusqu'au bord — un vol vers l'exterieur part donc du
     * cote ou il se dirige, et un vol entrant arrive par le cote oppose a l'etoile.
     */
    function porteDeBord(position) {
        var c = centre();
        var p = pointDe(position);
        var dx = p.x - c.x;
        var dy = p.y - c.y;
        var longueur = Math.sqrt(dx * dx + dy * dy) || 1;
        var bord = Math.min(LARGEUR, HAUTEUR - PIED) / 2 - 14;

        return { x: c.x + (dx / longueur) * bord * (LARGEUR / (HAUTEUR - PIED)), y: c.y + (dy / longueur) * bord };
    }

    /*
     * Le point d'un corps selon son type — constat de Codex : une mission vers la lune arrivait
     * au meme pixel que vers la planete, et la position 16 passait par le calcul des orbites.
     *
     * La lune est dessinee a droite de sa planete (`.gtMoonSlot`), les debris sous elle ; les
     * decalages suivent la mise en page de `poser()`. L'espace profond n'a pas d'orbite : son
     * point est au bas de la carte, au-dessus du pied, la ou son bandeau se trouve.
     */
    var TYPE_DEBRIS = 2;
    var TYPE_LUNE = 3;
    var POSITION_ESPACE_PROFOND = 16;

    /*
     * La nebuleuse d'espace profond : coin inferieur droit, au-dela des orbites, sans chevaucher la
     * position 15 (rayon maximal 310 x 0,58 = 180 px sous le centre, soit y = 474 au plus bas).
     * Ces deux nombres sont son centre ; les trajectoires d'expedition y aboutissent.
     */
    var NEBULEUSE_LARGEUR = 100;
    var NEBULEUSE_HAUTEUR = 66;
    var NEBULEUSE_MARGE = 10;

    function pointDeLaNebuleuse() {
        return {
            x: LARGEUR - NEBULEUSE_MARGE - NEBULEUSE_LARGEUR / 2,
            y: HAUTEUR - PIED - NEBULEUSE_MARGE - NEBULEUSE_HAUTEUR / 2 - 14,
            aDroite: true
        };
    }

    function pointDeCorps(position, type) {
        if (Number(position) === POSITION_ESPACE_PROFOND || Number(position) > POSITIONS) {
            return pointDeLaNebuleuse();
        }

        var p = pointDe(position);

        if (Number(type) === TYPE_LUNE) {
            return { x: p.x + (p.aDroite ? -26 : 26), y: p.y, aDroite: p.aDroite };
        }

        if (Number(type) === TYPE_DEBRIS) {
            return { x: p.x, y: p.y + 20, aDroite: p.aDroite };
        }

        return p;
    }

    function extremites(mouvement, galaxie, systeme) {
        var partIci = Number(mouvement.from.galaxy) === galaxie && Number(mouvement.from.system) === systeme;
        var arriveIci = Number(mouvement.to.galaxy) === galaxie && Number(mouvement.to.system) === systeme;

        var depart = partIci ? pointDeCorps(mouvement.from.position, mouvement.from.type) : porteDeBord(mouvement.to.position);
        var arrivee = arriveIci ? pointDeCorps(mouvement.to.position, mouvement.to.type) : porteDeBord(mouvement.from.position);

        return { depart: depart, arrivee: arrivee, partIci: partIci, arriveIci: arriveIci };
    }

    function coordonnees(bout) {
        return '[' + bout.galaxy + ':' + bout.system + ':' + bout.position + ']';
    }

    function coucheDe(carte) {
        var couche = carte.querySelector('.gtFleetLayer');

        if (couche) {
            return couche;
        }

        couche = svg('svg', {
            'class': 'gtFleetLayer',
            viewBox: '0 0 ' + LARGEUR + ' ' + (HAUTEUR - PIED),
            width: LARGEUR,
            height: HAUTEUR - PIED,
            'aria-hidden': 'true'
        });
        couche.setAttribute('aria-label', locaDeLaCarte(carte, 'fleets', 'Mouvements de flotte'));
        carte.appendChild(couche);

        return couche;
    }

    /*
     * La porte de bord d'un vol inter-systemes : la marque de sortie ou d'entree, le bouton qui
     * charge l'autre bout du vol, et **la trainee d'hyperespace** — un trait qui file dans le sens
     * du vol, visible tant que la flotte est entre les deux systemes. Le vaisseau, lui, n'est
     * alors sur aucune carte : c'est la trainee qui dit ou il est parti, ou il va arriver.
     */
    function poserLaPorte(carte, groupe, bouts, mouvement) {
        var sortie = bouts.partIci;
        var porte = sortie ? bouts.arrivee : bouts.depart;
        var image = svg('image', { 'class': 'gtEdge', width: 18, height: 18, x: (porte.x - 9).toFixed(1), y: (porte.y - 9).toFixed(1) });
        var fichier = sortie ? 'system-edge-outbound' : (mouvement.side === 'hostile' ? 'system-edge-inbound-hostile' : 'system-edge-inbound-personal');
        image.setAttributeNS(COUCHE_XLINK, 'href', '/img/galaxy-tactical/' + fichier + '.svg');
        groupe.appendChild(image);

        var cap = capDe(bouts);
        var trainee = svg('g', { 'class': 'gtWarp', transform: 'translate(' + porte.x.toFixed(1) + ',' + porte.y.toFixed(1) + ') rotate(' + cap.toFixed(1) + ')' });
        trainee.appendChild(svg('line', { 'class': 'gtWarpStreak', x1: sortie ? 0 : -44, y1: 0, x2: sortie ? 44 : 0, y2: 0 }));
        trainee.appendChild(svg('line', { 'class': 'gtWarpCore', x1: sortie ? 0 : -30, y1: 0, x2: sortie ? 30 : 0, y2: 0 }));

        var autre = sortie ? mouvement.to : mouvement.from;
        var titreTrainee = svg('title', {});
        titreTrainee.textContent = mouvement.label + ' — ' + locaDeLaCarte(carte, 'hyperspace', 'En hyperespace vers') + ' ' + coordonnees(autre);
        trainee.appendChild(titreTrainee);
        groupe.appendChild(trainee);
        mouvement._trainee = trainee;

        var saut = svg('image', { 'class': 'gtJump', width: 16, height: 16, x: (porte.x - 8).toFixed(1), y: (porte.y + 11).toFixed(1), role: 'button', tabindex: '0' });
        saut.setAttributeNS(COUCHE_XLINK, 'href', '/img/galaxy-tactical/' + (sortie ? 'route-jump-destination' : 'route-jump-origin') + '.svg');
        saut.setAttribute('data-galaxy', String(autre.galaxy));
        saut.setAttribute('data-system', String(autre.system));

        var titreSaut = svg('title', {});
        titreSaut.textContent = locaDeLaCarte(carte, 'follow', 'Suivre la flotte vers') + ' ' + coordonnees(autre);
        saut.appendChild(titreSaut);

        saut.addEventListener('click', function (evenement) {
            evenement.stopPropagation();
            allerAuSysteme(Number(autre.galaxy), Number(autre.system));
        });
        saut.addEventListener('keydown', function (evenement) {
            if (evenement.key === 'Enter' || evenement.key === ' ') {
                evenement.preventDefault();
                allerAuSysteme(Number(autre.galaxy), Number(autre.system));
            }
        });
        groupe.appendChild(saut);
    }

    function dessinerLesMouvements(carte, galaxie, systeme) {
        var couche = coucheDe(carte);

        while (couche.firstChild) {
            couche.removeChild(couche.firstChild);
        }

        mouvements.forEach(function (mouvement) {
            var bouts = extremites(mouvement, galaxie, systeme);
            var genre = Number(mouvement.mission_type) === MISSILE ? 'gtTrajMissile' : 'gtTraj' + mouvement.side.charAt(0).toUpperCase() + mouvement.side.slice(1);
            var groupe = svg('g', { 'class': 'gtMovement ' + genre + (mouvement.is_return ? ' gtTrajReturn' : '') });
            groupe.setAttribute('data-mission-id', String(mouvement.id));

            groupe.appendChild(svg('line', {
                'class': 'gtTrajectory',
                x1: bouts.depart.x.toFixed(1), y1: bouts.depart.y.toFixed(1),
                x2: bouts.arrivee.x.toFixed(1), y2: bouts.arrivee.y.toFixed(1)
            }));

            /*
             * Une porte de bord se voit, et elle se clique : un vol qui sort ou entre a une marque a
             * son extremite, et un bouton qui charge l'autre systeme pour suivre la flotte — c'est ainsi
             * qu'on la voit « continuer son chemin jusqu'a la planete ».
             */
            mouvement._trainee = null;

            if (!bouts.partIci || !bouts.arriveIci) {
                poserLaPorte(carte, groupe, bouts, mouvement);
            }

            var marqueur = svg('g', { 'class': 'gtFleetMarker' });
            var icone = svg('image', { width: 16, height: 16, x: -8, y: -8 });

            /*
             * **Le vaisseau blanc de la page de mouvement, tourne dans le sens du vol.** Le GIF regarde
             * vers la droite (cap natif 0 degre) ; l'angle du segment depart → arrivee le fait pointer
             * la ou il va. Un missile garde son marqueur : ce n'est pas un vaisseau.
             */
            if (Number(mouvement.mission_type) === MISSILE) {
                icone.setAttributeNS(COUCHE_XLINK, 'href', '/img/galaxy-tactical/' + (mouvement.side === 'hostile' ? 'missile-incoming' : 'missile-outgoing') + '.svg');
                marqueur.appendChild(icone);
            } else {
                var cap = svg('g', { 'class': 'gtShip', transform: 'rotate(' + capDe(bouts).toFixed(1) + ')' });
                icone.setAttributeNS(COUCHE_XLINK, 'href', VAISSEAU_BLANC);
                cap.appendChild(icone);
                marqueur.appendChild(cap);
            }

            var titre = svg('title', {});
            titre.textContent = mouvement.label
                + (mouvement.is_return ? ' — ' + locaDeLaCarte(carte, 'return', 'Retour') : '')
                + ' ' + coordonnees(mouvement.from) + ' → ' + coordonnees(mouvement.to)
                + ((!bouts.partIci || !bouts.arriveIci) ? ' — ' + locaDeLaCarte(carte, 'edge', 'Vers un autre systeme') : '');
            marqueur.appendChild(titre);

            groupe.appendChild(marqueur);
            couche.appendChild(groupe);

            mouvement._bouts = bouts;
            mouvement._marqueur = marqueur;
        });

        placerLesMarqueurs();
    }

    /*
     * La progression **dans ce systeme** d'un mouvement, a partir de sa progression globale.
     *
     *   - vol interne : la meme ;
     *   - vol sortant : la premiere moitie se joue ici (planete → bord), puis la flotte est sortie
     *     et le marqueur reste au bord, en transit ;
     *   - vol entrant : la premiere moitie, la flotte n'est pas encore la (marqueur au bord, en
     *     transit), puis la seconde se joue ici (bord → cible).
     */
    function progressionLocale(global, bouts) {
        if (bouts.partIci && bouts.arriveIci) {
            return { avancement: global, enTransit: false };
        }

        if (bouts.partIci) {
            return global >= PART_LOCALE
                ? { avancement: 1, enTransit: true }
                : { avancement: global / PART_LOCALE, enTransit: false };
        }

        return global <= 1 - PART_LOCALE
            ? { avancement: 0, enTransit: true }
            : { avancement: (global - (1 - PART_LOCALE)) / PART_LOCALE, enTransit: false };
    }

    /* La position d'un marqueur : interpolation lineaire entre les deux instants du serveur. */
    function placerLesMarqueurs() {
        var maintenant = maintenantServeur() / 1000;

        mouvements.forEach(function (mouvement) {
            if (!mouvement._marqueur || !mouvement._bouts) {
                return;
            }

            var duree = Math.max(1, mouvement.time_arrival - mouvement.time_departure);
            var global = Math.min(1, Math.max(0, (maintenant - mouvement.time_departure) / duree));
            var local = progressionLocale(global, mouvement._bouts);
            var x = mouvement._bouts.depart.x + (mouvement._bouts.arrivee.x - mouvement._bouts.depart.x) * local.avancement;
            var y = mouvement._bouts.depart.y + (mouvement._bouts.arrivee.y - mouvement._bouts.depart.y) * local.avancement;

            mouvement._marqueur.setAttribute('transform', 'translate(' + x.toFixed(1) + ',' + y.toFixed(1) + ')');
            mouvement._marqueur.classList.toggle('gtInTransit', local.enTransit);

            /* En hyperespace, le vaisseau n'est sur aucune carte : la trainee du bord le dit a sa place. */
            if (mouvement._trainee) {
                mouvement._trainee.classList.toggle('gtWarpActive', local.enTransit);
            }

            /*
             * **La fenetre ne se joue que sur une transition observee.** L'etat precedent doit etre
             * connu et different : au premier trace, au retour dans le systeme ou a la reconnexion,
             * `etatPrecedent` est indefini et rien ne se joue — consigne de Codex.
             */
            if (mouvement._etatPrecedent !== undefined && mouvement._etatPrecedent !== local.enTransit) {
                if (local.enTransit && mouvement._bouts.partIci) {
                    jouerLaFenetre(mouvement, 'entree', mouvement._bouts.arrivee, false);
                } else if (!local.enTransit && mouvement._bouts.arriveIci && !mouvement._bouts.partIci) {
                    jouerLaFenetre(mouvement, 'sortie', mouvement._bouts.depart, true);
                }
            }

            mouvement._etatPrecedent = local.enTransit;
        });
    }

    /*
     * ## Les fenetres d'hyperespace
     *
     * `playOGameXWormhole(canvas)` (galaxy-wormhole.js, ressource de Codex) joue une ouverture, un
     * tourbillon et une fermeture, puis s'efface. La carte en pose une a l'entree en hyperespace
     * (le vaisseau atteint le bord de sortie) et une, **a l'envers**, a la sortie (le vaisseau
     * apparait au bord d'entree). A l'envers : la carte pilote les images par `previewAt(D − t)`.
     *
     * Un registre par mission et par phase ferme tout doublon ; les fenetres sont annulees au
     * changement de systeme ; leur nombre simultane est borne. Un canvas decoratif, sans clic,
     * masque aux lecteurs d'ecran.
     */
    var FENETRE_DUREE = 4200;
    var FENETRES_MAX = 4;
    var fenetresJouees = {};
    var fenetresEnCours = [];

    function coucheDesFenetres(carte) {
        var couche = carte.querySelector('.gtWormholes');

        if (!couche) {
            couche = element('div', 'gtWormholes');
            couche.setAttribute('aria-hidden', 'true');
            carte.appendChild(couche);
        }

        return couche;
    }

    function annulerLesFenetres() {
        fenetresEnCours.forEach(function (f) {
            f.arreter();
        });

        fenetresEnCours = [];
    }

    function jouerLaFenetre(mouvement, phase, point, aLEnvers) {
        var clef = mouvement.id + ':' + phase;
        var carte = document.getElementById('galaxyTactical');

        if (fenetresJouees[clef] || !carte || typeof window.playOGameXWormhole !== 'function') {
            return;
        }

        fenetresJouees[clef] = true;

        while (fenetresEnCours.length >= FENETRES_MAX) {
            fenetresEnCours.shift().arreter();
        }

        var canvas = document.createElement('canvas');
        canvas.className = 'gtWormhole';
        canvas.setAttribute('aria-hidden', 'true');
        canvas.style.left = Math.round(point.x) + 'px';
        canvas.style.top = Math.round(point.y) + 'px';
        coucheDesFenetres(carte).appendChild(canvas);

        var effet = window.playOGameXWormhole(canvas);
        var minuterie = null;
        var image = null;
        var fenetre = {
            arreter: function () {
                if (image !== null) {
                    window.cancelAnimationFrame(image);
                }

                if (minuterie !== null) {
                    window.clearTimeout(minuterie);
                }

                effet.cancel();
                canvas.remove();
            }
        };

        fenetresEnCours.push(fenetre);

        var finir = function () {
            fenetresEnCours = fenetresEnCours.filter(function (f) {
                return f !== fenetre;
            });
            fenetre.arreter();
        };

        if (!aLEnvers) {
            minuterie = window.setTimeout(finir, FENETRE_DUREE + 100);

            return;
        }

        /* A l'envers : la meme fenetre, remontee image par image depuis sa fin. */
        var depart = null;
        var remonter = function (maintenant) {
            if (depart === null) {
                depart = maintenant;
            }

            var ecoule = maintenant - depart;

            if (ecoule >= FENETRE_DUREE) {
                finir();

                return;
            }

            effet.previewAt(FENETRE_DUREE - ecoule);
            image = window.requestAnimationFrame(remonter);
        };

        image = window.requestAnimationFrame(remonter);
    }

    function arreterLAnimation() {
        if (animation !== null) {
            window.cancelAnimationFrame(animation);
            animation = null;
        }

        if (minuterieStatique !== null) {
            window.clearInterval(minuterieStatique);
            minuterieStatique = null;
        }
    }

    /*
     * L'animation ne tourne que si elle sert : aucun mouvement, onglet cache ou reduction des
     * mouvements demandee — et dans ce dernier cas les marqueurs sont reposes toutes les cinq
     * secondes, sans transition.
     */
    function animer() {
        arreterLAnimation();

        if (mouvements.length === 0 || document.hidden) {
            return;
        }

        if (mouvementReduit()) {
            minuterieStatique = window.setInterval(placerLesMarqueurs, RAFRAICHISSEMENT_SANS_MOUVEMENT);

            return;
        }

        var boucle = function () {
            placerLesMarqueurs();
            animation = window.requestAnimationFrame(boucle);
        };

        animation = window.requestAnimationFrame(boucle);
    }

    /*
     * La demande, marquee d'un jeton : une reponse tardive de l'ancien systeme n'ecrase jamais le
     * nouveau. Le serveur seul dit quels mouvements existent ; la reponse remplace tout.
     */
    function chargerLesFlottes(carte, galaxie, systeme) {
        if (typeof galaxyFleetsUrl === 'undefined' || !galaxyFleetsUrl || !window.jQuery) {
            return;
        }

        var jeton = ++jetonDeSysteme;

        window.jQuery.getJSON(galaxyFleetsUrl, { galaxy: galaxie, system: systeme })
            .done(function (reponse) {
                if (jeton !== jetonDeSysteme || !reponse || !reponse.success) {
                    return;
                }

                decalageHorloge = Number(reponse.server_now) * 1000 - Date.now();
                mouvements = Array.isArray(reponse.movements) ? reponse.movements : [];
                dessinerLesMouvements(carte, galaxie, systeme);
                animer();
            });
    }

    function redemanderLeSysteme(galaxie, systeme) {
        if (!window.jQuery || typeof galaxyContentLink === 'undefined' || typeof window.renderContentGalaxy !== 'function') {
            return;
        }

        /*
         * **Une reponse tardive ne remplace jamais le systeme affiche.** Le joueur a pu changer de
         * systeme entre la demande et la reponse ; on compare avant de rendre. La couche des
         * flottes avait ce garde-fou, la photographie non — constat de Codex.
         */
        window.jQuery.post(galaxyContentLink, {
            galaxy: galaxie,
            system: systeme,
            _token: typeof token !== 'undefined' ? token : undefined
        }, function (reponse) {
            var carte = document.getElementById('galaxyTactical');
            var courant = carte && carte.gtSysteme ? carte.gtSysteme : null;

            if (courant && (courant.galaxie !== galaxie || courant.systeme !== systeme)) {
                return;
            }

            window.renderContentGalaxy(reponse);
        }, 'json');
    }

    /*
     * ## Les abonnements
     *
     * Le canal du systeme annonce un changement public (colonie, lune, debris, destruction) : la
     * photographie est redemandee. Le canal du joueur annonce un mouvement qu'il a le droit de voir :
     * si l'un de ses deux bouts est le systeme affiche, la couche est redemandee.
     *
     * Le changement de systeme quitte l'ancien canal avant de rejoindre le nouveau : une annonce
     * tardive de l'ancien n'a plus personne pour l'entendre.
     */
    function ecouterLeSysteme(carte, galaxie, systeme) {
        if (typeof window.Echo === 'undefined' || typeof window.Echo.private !== 'function') {
            return;
        }

        var nom = 'galaxy.system.' + galaxie + '.' + systeme;

        if (systemeAbonne === nom) {
            return;
        }

        try {
            if (systemeAbonne !== null && typeof window.Echo.leave === 'function') {
                window.Echo.leave(systemeAbonne);
            }

            systemeAbonne = nom;
            window.Echo.private(nom).listen('.GalaxySystemChanged', function (recu) {
                if (!recu || Number(recu.galaxy) !== galaxie || Number(recu.system) !== systeme) {
                    return;
                }

                redemanderLeSysteme(galaxie, systeme);
            });
        } catch (e) {
            systemeAbonne = null;
        }
    }

    function ecouterLeJoueur(carte) {
        if (joueurAbonne || typeof window.Echo === 'undefined' || typeof window.Echo.private !== 'function') {
            return;
        }

        if (typeof playerId === 'undefined' || !playerId) {
            return;
        }

        try {
            joueurAbonne = true;
            window.Echo.private('galaxy.player.' + playerId).listen('.FleetMovementChanged', function (recu) {
                if (!recu || !recu.from || !recu.to || !carte.gtSysteme) {
                    return;
                }

                var g = carte.gtSysteme.galaxie;
                var s = carte.gtSysteme.systeme;
                var concerne = (Number(recu.from.galaxy) === g && Number(recu.from.system) === s)
                    || (Number(recu.to.galaxy) === g && Number(recu.to.system) === s);

                if (concerne) {
                    chargerLesFlottes(carte, g, s);
                }
            });
        } catch (e) {
            joueurAbonne = false;
        }
    }

    function demarrerLaCoucheFlottes(carte, galaxie, systeme) {
        if (!galaxie || !systeme) {
            return;
        }

        carte.gtSysteme = { galaxie: galaxie, systeme: systeme };
        mouvements = [];
        arreterLAnimation();
        annulerLesFenetres();
        chargerLesFlottes(carte, galaxie, systeme);
        ecouterLeSysteme(carte, galaxie, systeme);
        ecouterLeJoueur(carte);
    }

    /* Un onglet qui revient au premier plan reprend l'animation ; un onglet cache l'arrete. */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            arreterLAnimation();
        } else {
            animer();
        }
    });

    /*
     * ## L'espace profond
     *
     * La position 16 n'est pas une orbite : c'est une nebuleuse dans le coin inferieur droit, et
     * son libelle est du HTML traduit, hors de l'image. Le bloc est un corps comme les autres
     * pour la selection (`data-position="16"`) : la fiche deplace `#galaxyRow16`, la boite
     * historique qui porte le bouton d'expedition, le choix de flotte et les debris — tous
     * raccordes par le rendu herite. Rien n'est recode, aucun portail n'est ajoute.
     */
    function poserLaNebuleuse(carte, galaxie, systeme) {
        var p = pointDeLaNebuleuse();
        var bloc = element('div', 'gtBody gtDeepSpace');
        var nuage = element('span', 'gtNebula');
        var etiquette = element('span', 'gtName');
        var libelle = locaDeLaCarte(carte, 'deep', 'Espace profond') + ' \u00b7 ' + POSITION_ESPACE_PROFOND;

        etiquette.textContent = libelle;
        bloc.style.left = Math.round(p.x) + 'px';
        bloc.style.top = Math.round(p.y) + 'px';
        bloc.setAttribute('data-position', String(POSITION_ESPACE_PROFOND));
        bloc.setAttribute('data-titre', libelle);
        bloc.setAttribute('data-coords', '[' + galaxie + ':' + systeme + ':' + POSITION_ESPACE_PROFOND + ']');
        bloc.setAttribute('tabindex', '0');
        bloc.setAttribute('role', 'button');
        bloc.setAttribute('aria-label', libelle);
        bloc.appendChild(nuage);
        bloc.appendChild(etiquette);
        carte.appendChild(bloc);
    }

    /* La fiche rouvre sur le meme corps apres un redessin du meme systeme, avec la ligne rafraichie. */
    function restaurerLaSelection(carte, memoire) {
        if (!memoire) {
            return;
        }

        var bloc = carte.querySelector('.gtBody[data-position="' + memoire.position + '"]');

        if (bloc) {
            choisir(carte, bloc, memoire.corps);
        }
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
     * ## Le soleil
     *
     * Celui du pack (revue 113 de Codex) : `sun-detailed-v1.png`, surface granuleuse, protuberances
     * et fond transparent, dans le composant `.ogx-sun` dont la feuille anime le halo et la
     * lumiere. Le disque est stable — ce n'est ni un GIF ni un plasma qui coule ; Keven l'a
     * choisi ainsi. Decoratif : masque aux lecteurs d'ecran, aucun clic capte. La feuille de Codex
     * eteint ses animations sous `prefers-reduced-motion` d'elle-meme.
     */
    var SOLEIL_DE_CODEX = '/img/galaxy-tactical/sun-detailed-v1.png';
    var SOLEIL_TAILLE = '76px';

    function soleilDeCodex() {
        var composant = element('span', 'ogx-sun');
        var image = element('img', '');

        composant.style.setProperty('--sun-size', SOLEIL_TAILLE);
        composant.setAttribute('aria-hidden', 'true');
        image.src = SOLEIL_DE_CODEX;
        image.alt = '';
        composant.appendChild(image);

        return composant;
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

        /*
         * **Les lignes sont sous `system.galaxyContent`, pas sous `galaxy`.** La premiere version
         * lisait `json.galaxy`, qui n'existe pas, et sortait ici sans rien tracer : l'etoile et les
         * orbites vus en jeu venaient du fond JPG du pack. `renderContentGalaxy()` lit
         * `json.system.galaxyContent` depuis toujours ; la carte lit desormais la meme chose.
         */
        var systeme = json && json.system ? json.system : null;
        var lignes = systeme && Array.isArray(systeme.galaxyContent) ? systeme.galaxyContent : null;

        if (!carte || !lignes) {
            return;
        }

        /*
         * La ligne rentre **avant** que la carte soit videe, et la selection est memorisee : un
         * redessin du **meme** systeme — un evenement en direct, une colonie voisine — ne doit pas
         * fermer la fiche que le joueur consulte (constat de Codex). Un changement de systeme,
         * lui, la ferme : la position choisie n'y a plus de sens.
         */
        var memeSysteme = carte.gtSysteme
            && carte.gtSysteme.galaxie === Number(systeme.galaxy)
            && carte.gtSysteme.systeme === Number(systeme.system);
        var aRestaurer = memeSysteme && deplacee ? { position: deplacee.position, corps: deplacee.corps } : null;

        deselectionner(carte);

        carte.innerHTML = '';
        armerLaSelection(carte);
        armerLaBascule(carte);
        dessinerOrbites(carte);
        /*
         * **Un soleil, dessine ici, au meme centre que les orbites.** Le fond v2 du pack n'en
         * porte aucun — mesure : un seul pixel au-dessus du seuil de luminance. La premiere
         * version le posait a 50 % / 50 % de la boite, quatorze pixels sous le centre de la
         * geometrie (le pied de 22 px n'entre pas dans le calcul des orbites) : deux astres a
         * l'ecran avec le fond v1, qui en peignait un. Le point vient de `centre()`, comme tout.
         */
        var c = centre();
        var etoile = element('div', 'gtStar');
        etoile.style.left = Math.round(c.x) + 'px';
        etoile.style.top = Math.round(c.y) + 'px';
        etoile.appendChild(soleilDeCodex());
        carte.appendChild(etoile);

        var parPosition = {};

        lignes.forEach(function (ligne) {
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
            /*
             * **La lune et les debris sont des cibles a part.** Chacun prend le focus et repond au
             * clic pour lui-meme : la fiche s'ouvre sur la meme position, mais dit quel corps est
             * choisi et met sa cellule en avant. Avant, le clic remontait au bloc de la planete et
             * la lune n'etait jamais choisie — constat de Codex.
             */
            if (lune) {
                var creneau = element('span', 'gtMoonSlot');
                creneau.appendChild(element('span', 'gtMoon' + (lune.isDestroyed ? ' gtDestroyed' : '')));
                cibleDistincte(creneau, 'moon', locaDeLaCarte(carte, 'moon', 'Lune'));
                contenu.push(creneau);
            }

            if (debris) {
                var champ = element('span', 'gtDebris');
                cibleDistincte(champ, 'debris', locaDeLaCarte(carte, 'debris', 'Debris'));
                contenu.push(champ);
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

        poserLaNebuleuse(carte, Number(systeme.galaxy), Number(systeme.system));
        appliquerLesFiltres(carte);
        restaurerLaSelection(carte, aRestaurer);
        demarrerLaCoucheFlottes(carte, Number(systeme.galaxy), Number(systeme.system));

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

    /*
     * **Envelopper des maintenant, pas a `DOMContentLoaded`.** Le premier chargement du systeme
     * est lance par un script du corps de la page, qui s'execute a la lecture, avant
     * `DOMContentLoaded` ; et le rendu herite passe la fonction par valeur au `$.post`. Attendre
     * l'evenement laissait ce premier appel capturer la fonction nue : carte vide jusqu'au premier
     * changement de systeme. `renderContentGalaxy` est une declaration hissee du meme script
     * concatene, donc deja definie ici. L'appel a `DOMContentLoaded` reste en secours ; il ne fait
     * rien si l'enveloppe est deja posee.
     */
    brancher();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', brancher);
    }
})();
