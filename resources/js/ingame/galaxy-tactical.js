/*
 * La Galaxie tactique — carte du systeme solaire, en remplacement du tableau.
 *
 * ## Ou ce module se branche, et pourquoi la
 *
 * Toute la Galaxie passe par une seule fonction : `renderContentGalaxy(json)`, appelee par
 * `$.post(galaxyContentLink, ..., renderContentGalaxy)` a chaque changement de systeme. Ce module
 * l'**enveloppe** au lieu de la remplacer :
 *
 *   - la fonction historique continue de tenir les compteurs du bandeau (sondes, recycleurs,
 *     missiles, emplacements, colonies) — ils vivent hors de la carte et restent justes ;
 *   - ses trente-deux ecritures de lignes visent `#galaxyRow{N} .cellX`, qui n'existent plus dans
 *     le Blade. Ce sont des methodes jQuery sur une collection vide : elles ne font rien, sans
 *     lever. C'est ce qui permet de debrancher l'ancien rendu **sans toucher au bloc herite de
 *     1,4 Mo** ;
 *   - la carte se dessine ensuite depuis exactement le meme JSON.
 *
 * ## Ce que ce module ne fait pas, et ne doit pas faire
 *
 * Il ne decide **aucune autorisation**. Les actions permises sur un corps sont calculees par le
 * serveur (`GalaxyController::getPlanetActions()`) et voyagent dans la charge utile ; les
 * raccorder est l'etape suivante. Une carte qui recalculerait un droit cote client serait une
 * regression de securite, pas une refonte d'interface.
 *
 * Il n'affiche **aucune flotte** : la Galaxie n'en a jamais envoye au client (`'fleet' => []` sans
 * exception), et les montrer est une fonction neuve qui exigera son autorisation serveur.
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
     * La vignette d'un corps, rendue **par le mecanisme du jeu** : la classe `microplanet` porte la
     * planche de sprites et sa decoupe 38 x 33, la classe de variante porte seulement la position
     * de fond. Reprendre ce couple garantit qu'une planete garde exactement l'apparence que le
     * serveur lui a attribuee.
     */
    function vignette(classeDeBase, corps) {
        var e = element('div', classeDeBase);

        if (corps && corps.imageInformation) {
            e.className += ' ' + corps.imageInformation;
        }

        return e;
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

        carte.innerHTML = '';
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
                poser(carte, position, [element('div', 'gtEmpty'), numero(position)], {
                    classe: 'gtFree',
                    intitule: position + ' — ' + (window.galaxyTacticalLoca ? window.galaxyTacticalLoca.freeSlot : 'position libre')
                });

                continue;
            }

            var contenu = [vignette('microplanet', planete)];
            var lune = corpsDeGenre(ligne, LUNE);
            var debris = corpsDeGenre(ligne, DEBRIS);

            if (lune) {
                var creneau = element('span', 'gtMoonSlot');
                creneau.appendChild(vignette('micromoon', lune));
                contenu.push(creneau);
            }

            if (debris) {
                contenu.push(vignette('microdebris', debris));
            }

            contenu.push(libelle(planete.planetName || ''));
            contenu.push(numero(position));

            poser(carte, position, contenu, {
                classe: ligne.playerId && window.playerId && Number(ligne.playerId) === Number(window.playerId) ? 'gtOwn' : '',
                intitule: position + ' — ' + (planete.planetName || '') + (ligne.playerName ? ' (' + ligne.playerName + ')' : '')
            });
        }

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
