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

    /*
     * La fiche, et la largeur qu'elle occupe : elle doit rester entierement dans la carte. 320 px,
     * la largeur de `planet-card.css` de Codex (340 indicatifs, « reductible sans casser les
     * libelles ») : deux colonnes de boutons a icone et libelle y tiennent, et la fiche reste dans
     * les 656 px de la carte avec sa marge des deux cotes.
     */
    var FICHE_LARGEUR = 320;
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
     * ## Les planetes orbitent, en temps reel
     *
     * Decision de Keven (7 septembre 2026), qui remplace la revue 112 §3 de Codex (« positions
     * stables pendant l'interaction ») : « je veux orbite… si quelqu'un laisse une heure la page
     * ouverte, qu'il voie que sa planete orbite en temps reel ».
     *
     * **Tout le systeme tourne d'un bloc**, d'un tour en HEURES_PAR_TOUR heures, sur l'heure du
     * serveur : tout le monde voit la meme chose au meme instant, et une page rouverte montre la
     * planete la ou elle est vraiment. D'un bloc, et non chacune a sa vitesse : les orbites sont a
     * seize pixels l'une de l'autre et les planetes en font quarante-quatre ; des vitesses
     * differentes feraient se chevaucher une planete interieure et son exterieure a chaque
     * conjonction — ce que Keven avait demande d'eviter. En bloc, les ecarts angulaires ne
     * changent jamais ; seul l'aplatissement de l'ellipse rapproche deux corps quand ils passent
     * en haut ou en bas, et l'ecartement est donc rejoue a chaque instant sur les angles tournes.
     *
     * Rien de metier ne bouge : distances, vitesses et durees de vol restent celles du serveur ;
     * c'est une representation. Le temps est quantifie par PAS_ORBITAL pour ne recalculer les
     * angles que quand ils peuvent avoir change d'un pixel.
     */
    var HEURES_PAR_TOUR = 8;
    var PAS_ORBITAL = 500;

    /* Les angles de depart : l'angle d'or, deux positions consecutives ne se groupent jamais. */
    var anglesDeDepart = null;

    function anglesDeBase() {
        if (anglesDeDepart !== null) {
            return anglesDeDepart;
        }

        var angles = [];

        for (var i = 1; i <= POSITIONS; i++) {
            angles[i] = ((i * ANGLE_OR - 90) * Math.PI) / 180;
        }

        anglesDeDepart = angles;

        return angles;
    }

    /* La phase de l'orbite a un instant du serveur : un tour complet par HEURES_PAR_TOUR heures. */
    function phaseOrbitale(instant) {
        var periode = HEURES_PAR_TOUR * 3600000;

        return ((instant % periode) / periode) * 2 * Math.PI;
    }

    /*
     * L'ecartement **deterministe** : a chaque passe, toute paire plus proche que DISTANCE_MIN est
     * repoussee — chacun des deux corps tourne sur sa propre orbite, de la moitie du manque
     * convertie en angle, dans le sens qui les eloigne. Meme entree, meme sortie, et aucune
     * planete ne change d'orbite. `tests/Feature/GalaxyTacticalMapTest.php` rejoue cette
     * arithmetique en PHP, a douze phases de l'orbite, et exige la distance minimale partout.
     */
    function relaxer(entree) {
        var angles = entree.slice();
        var i;
        var j;

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

        return angles;
    }

    /* Les angles a un instant : la base tournee de la phase, puis ecartee. Memorises par pas de temps. */
    var anglesCalcules = null;
    var pasDesAngles = null;

    function anglesDesPositions(instant) {
        var pas = Math.floor((instant === undefined ? maintenantServeur() : instant) / PAS_ORBITAL);

        if (anglesCalcules !== null && pasDesAngles === pas) {
            return anglesCalcules;
        }

        var base = anglesDeBase();
        var phase = phaseOrbitale(pas * PAS_ORBITAL);
        var angles = [];

        for (var i = 1; i <= POSITIONS; i++) {
            angles[i] = base[i] + phase;
        }

        anglesCalcules = relaxer(angles);
        pasDesAngles = pas;

        return anglesCalcules;
    }

    function pointDe(position, instant) {
        return pointSurOrbite(position, anglesDesPositions(instant)[position]);
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
     * ## La fiche contextuelle — les trois fiches de Codex (revue 115)
     *
     * **La ligne du tableau est deplacee dedans, jamais recopiee.** `renderContentGalaxy` accroche
     * ses gestionnaires sur ces noeuds a chaque rendu — infobulles, overlay de missile, demande
     * d'ami, mise a l'ignore. Un clone les perdrait tous, et *en silence* : la fiche s'afficherait,
     * les liens seraient la, et rien ne repondrait au clic. Le meme noeud garde tout — et il garde
     * son identifiant, donc le rendu herite continue d'ecrire dans `#galaxyRow{N} .cellX` ou que la
     * ligne soit. C'est pour cela que la ligne bouge entiere et que ses cellules ne bougent jamais :
     * une cellule sortie de sa ligne ne serait plus retrouvee par `$("#galaxyRow3 .cellPlanet")`, et
     * la fiche ouverte se figerait au premier evenement en direct.
     *
     * ## La presentation est celle de Codex, les droits sont ceux du serveur
     *
     * Les trois fiches V2 — planete, expedition, position libre — sont reprises telles qu'approuvees :
     * cadre droit sans decoupe, boutons a icone et libelle, etats grises. La ligne, en
     * `display: contents`, prete ses cellules a la grille de la fiche ; les cellules que le serveur
     * a remplies (joueur, alliance, infobulles, phalange, compte a rebours) s'y placent par la
     * feuille. Chaque bouton d'action **delegue son clic au lien que le serveur a rendu** dans la
     * ligne (`.cellAction a.espionage`, `.phalanxlink`, ...) ou ouvre l'adresse de mission que la
     * charge utile porte. Pas de lien rendu : bouton **reellement** desactive (`disabled`, qui bloque
     * souris et clavier), avec la raison a cote, derivee des faits que le serveur envoie — la
     * planete est la mienne, aucun missile, hors de portee, pas de sonde — jamais d'un motif invente.
     * Aucun droit n'est recalcule ici : la fiche ne peut proposer que ce que la ligne propose.
     */
    var deplacee = null;

    /* Les icones du pack, une par action. `tests/Feature/GalaxyTacticalMapTest.php` ouvre chaque fichier. */
    var ICONES_D_ACTION = {
        espionner: 'action-espionage.svg',
        attaquer: 'action-attack.svg',
        transporter: 'planet-card-transport.svg',
        deployer: 'mission-deploy.svg',
        acs: 'action-acs.svg',
        missiles: 'action-missile.svg',
        phalange: 'action-phalanx.svg',
        detruireLune: 'mission-moon-destruction.svg',
        recycler: 'action-recycle.svg',
        message: 'action-message.svg',
        ami: 'action-buddy.svg',
        ignorer: 'action-ignore.svg',
        classement: 'action-info.svg',
        alliance: 'action-alliance.svg',
        coloniser: 'action-colonize.svg',
        demenager: 'action-relocate.svg',
        expedition: 'action-expedition.svg'
    };

    /*
     * Les actions que chaque genre de corps peut porter — l'inventaire de la revue 112, section 5.
     * Une action absente de la ligne n'est pas cachee : elle est grisee avec sa raison.
     */
    var ACTIONS_PAR_GENRE = {
        planete: ['espionner', 'attaquer', 'transporter', 'deployer', 'acs', 'missiles', 'phalange', 'message', 'ami', 'ignorer', 'classement', 'alliance'],
        lune: ['espionner', 'attaquer', 'transporter', 'deployer', 'acs', 'detruireLune', 'message', 'ami', 'classement', 'alliance'],
        debris: ['recycler'],
        libre: ['coloniser', 'demenager'],
        profond: ['expedition']
    };

    /*
     * Les libelles de la fiche : `galaxyTacticalLoca`, publie par la vue et traduit par le serveur.
     * Un chemin `labels.attaquer` descend dans la table ; une clef absente rend le defaut, jamais
     * `undefined`.
     */
    function locaFiche(chemin, defaut) {
        var noeud = window.galaxyTacticalLoca || {};
        var morceaux = chemin.split('.');

        for (var i = 0; i < morceaux.length; i++) {
            if (noeud === null || typeof noeud !== 'object' || !(morceaux[i] in noeud)) {
                return defaut;
            }

            noeud = noeud[morceaux[i]];
        }

        return typeof noeud === 'string' && noeud !== '' ? noeud : defaut;
    }

    /* Un texte HTML du serveur (description de colonisation) rendu en texte, sans ses balises. */
    function texteSansBalises(html) {
        var boite = document.createElement('div');
        boite.innerHTML = String(html || '');

        return (boite.textContent || '').replace(/\s+/g, ' ').trim();
    }

    /* La mission d'un type donne dans une liste de missions disponibles, ou `null`. */
    function mission(porteur, type) {
        var liste = (porteur && porteur.availableMissions) || [];

        for (var i = 0; i < liste.length; i++) {
            if (Number(liste[i].missionType) === type) {
                return liste[i];
            }
        }

        return null;
    }

    function estLaMienne(ligne, systeme) {
        var moi = systeme && systeme.playerId !== undefined ? systeme.playerId : window.playerId;

        return !!(ligne && ligne.playerId && moi && Number(ligne.playerId) === Number(moi));
    }

    /* Un lien herite ne compte que s'il porte un vrai gestionnaire en ligne : `onclick=""` n'en est pas un. */
    function avecClic(lien) {
        return !!(lien && (lien.getAttribute('onclick') || '').trim() !== '');
    }

    function cliquer(lien) {
        return function () {
            lien.click();
        };
    }

    function ouvrir(adresse) {
        return function () {
            window.location.href = adresse;
        };
    }

    function actif(executer, classe) {
        return { actif: true, executer: executer, classe: classe || '' };
    }

    function inactif(raison) {
        return { actif: false, raison: raison || locaFiche('reasons.unavailable', 'Indisponible ici') };
    }

    /*
     * La raison d'un refus de colonisation, lue dans la description que le serveur rend : le nom de
     * la mission, puis la description de la position, puis une ligne par empechement (vaisseau de
     * colonisation, astrophysique, emplacements de flotte, amiral). Les empechements seuls font la
     * raison ; la description de la position fait la note de la fiche.
     */
    function segmentsDeColonisation(m) {
        return String((m && m.description) || '').split(/<br\s*\/?>/i).map(texteSansBalises).filter(function (s) {
            return s !== '';
        });
    }

    /*
     * ## Les decisions, corps par corps
     *
     * Chaque action rend `actif(executer)` quand la ligne du serveur porte le lien correspondant, ou
     * `inactif(raison)` sinon. Les raisons sont des faits de la charge utile, dans l'ordre ou ils
     * expliquent le mieux le refus.
     */
    function decisionsDe(contexte) {
        var f = contexte.fiche;
        var ligne = contexte.ligne || {};
        var objet = contexte.objet || {};
        var systeme = contexte.systeme || {};
        var position = contexte.position;
        var joueur = ligne.player || {};
        var droitsDuJoueur = joueur.actions || {};
        var droits = ligne.actions || {};
        var mienne = estLaMienne(ligne, systeme);
        var admin = !!joueur.isAdmin;
        var detruit = !!objet.isDestroyed;
        var chercher = function (selecteur) {
            return f.querySelector(selecteur);
        };
        /*
         * Un lien porte par une infobulle heritee : Tipped peut l'avoir sorti de la ligne. Il est
         * cherche dans la fiche, puis dans le document — par un identifiant, jamais par une classe
         * seule, pour ne pas tomber sur une autre position.
         */
        var chercherDansLInfobulle = function (identifiant, selecteur) {
            return f.querySelector('#' + identifiant + ' ' + selecteur) || document.querySelector('#' + identifiant + ' ' + selecteur);
        };
        var raison = function (clef) {
            return locaFiche('reasons.' + clef, clef);
        };
        var refusOrdinaire = function () {
            return inactif(detruit ? raison('destroyed') : mienne ? raison('own') : raison('unavailable'));
        };
        var parMission = function (type, classe) {
            var m = mission(objet, type);

            return m && typeof m.link === 'string' && m.link !== '' && m.link !== '#' ? actif(ouvrir(m.link), classe) : null;
        };

        return {
            espionner: function () {
                var m = mission(objet, 6);
                var lien = chercher(contexte.genre === 'lune' ? '.cellMoon a[onclick]' : '.cellAction a.espionage');

                if (m && m.canSpy && droits.canEspionage !== false && !admin && avecClic(lien)) {
                    return actif(cliquer(lien));
                }

                if (droits.canEspionage === false && !mienne) {
                    return inactif(raison('noProbes'));
                }

                return admin ? inactif(raison('player')) : refusOrdinaire();
            },
            attaquer: function () {
                return parMission(1, 'gtAction--attack') || refusOrdinaire();
            },
            transporter: function () {
                return parMission(3) || refusOrdinaire();
            },
            deployer: function () {
                return parMission(4) || inactif(detruit ? raison('destroyed') : raison('foreign'));
            },
            acs: function () {
                return parMission(5) || inactif(detruit ? raison('destroyed') : mienne ? raison('own') : raison('buddy'));
            },
            missiles: function () {
                var lien = chercher('.cellAction a.missleattack');

                if (lien && !lien.querySelector('.grayscale') && (avecClic(lien) || lien.classList.contains('overlay'))) {
                    return actif(cliquer(lien));
                }

                if (mienne) {
                    return inactif(raison('own'));
                }

                if (admin) {
                    return inactif(raison('player'));
                }

                if (!droits.canMissileAttack) {
                    return inactif(raison('range'));
                }

                return inactif(Number(systeme.availableMissiles) > 0 ? raison('unavailable') : raison('noMissiles'));
            },
            phalange: function () {
                var lien = chercher('.phalanxlink');

                if (droits.phalanxActive && lien) {
                    return actif(cliquer(lien));
                }

                if (droits.phalanxInactive && droits.phalanxInactiveReason) {
                    return inactif(droits.phalanxInactiveReason);
                }

                return inactif(mienne ? raison('own') : raison('phalanx'));
            },
            detruireLune: function () {
                return parMission(9) || refusOrdinaire();
            },
            recycler: function () {
                var lien = chercherDansLInfobulle('debris' + position, 'a[onclick]');

                if (avecClic(lien)) {
                    return actif(cliquer(lien));
                }

                if (systeme.canFly === false) {
                    return inactif(raison('noSlots'));
                }

                var disponibles = Number(position === POSITION_ESPACE_PROFOND ? systeme.availablePathfinders : systeme.availableRecyclers);

                return inactif(disponibles > 0 ? raison('unavailable') : raison(position === POSITION_ESPACE_PROFOND ? 'pathfinders' : 'recyclers'));
            },
            message: function () {
                var droit = droitsDuJoueur.message || {};
                var lien = chercher('.cellAction a.sendMail') || chercher('.cellAction a[data-playerId][href]');

                if (droit.available && lien) {
                    return actif(cliquer(lien));
                }

                return inactif(mienne ? raison('own') : raison('player'));
            },
            ami: function () {
                var lien = chercher('.cellAction a.buddyrequest');

                return lien ? actif(cliquer(lien)) : inactif(mienne ? raison('own') : raison('player'));
            },
            ignorer: function () {
                var lien = joueur.playerId ? chercherDansLInfobulle('player' + joueur.playerId, '.ignorePlayerLink') : null;

                return lien ? actif(cliquer(lien)) : inactif(mienne ? raison('own') : raison('player'));
            },
            classement: function () {
                var droit = droitsDuJoueur.highscore || {};

                return droit.available && droit.link ? actif(ouvrir(droit.link)) : inactif(raison('unavailable'));
            },
            alliance: function () {
                var droit = droitsDuJoueur.alliance || {};

                if (!joueur.allianceId) {
                    return inactif(raison('noAlliance'));
                }

                return actif(ouvrir(joueur.isAllianceMember && droit.infoPageLink ? droit.infoPageLink : '/alliance/info/' + joueur.allianceId));
            },
            coloniser: function () {
                var m = mission(ligne, 7);
                var lien = chercher('.cellAction a.colonize-active');

                if (m && typeof m.link === 'string' && m.link !== '#' && lien) {
                    return actif(ouvrir(m.link));
                }

                var empechements = segmentsDeColonisation(m).slice(2);

                return inactif(empechements.length ? empechements.join(' · ') : locaFiche('reasons.colonize', raison('unavailable')));
            },
            demenager: function () {
                var m = mission(ligne, 0);
                var lien = chercher('.cellAction a.planetMoveDefault');

                if (m && m.planetMovePossible && lien) {
                    return actif(cliquer(lien));
                }

                return inactif(m ? raison('move') : raison('unavailable'));
            },
            expedition: function () {
                var e = f.gtExpedition;

                /* Une flotte standard choisie : le verdict du serveur decide, puis l'envoi par le parcours de la page Flotte. */
                if (e && e.modele) {
                    if (e.envoiEnCours || !e.verdict || e.verdict.enCours) {
                        return inactif(locaFiche('expeditionChecking', ''));
                    }

                    if (!e.verdict.ok) {
                        return inactif(e.verdict.raison || raison('unavailable'));
                    }

                    return actif(function () {
                        lancerLExpedition(contexte.carte, f, e.modele);
                    });
                }

                /* Sans flotte choisie : la page Flotte, avec l'espace profond de ce systeme pour cible (bouton herite). */
                var direct = document.getElementById('expeditionbutton');

                return direct ? actif(cliquer(direct)) : inactif(raison('unavailable'));
            }
        };
    }

    /*
     * Un bouton d'action de la fiche. Actif : un vrai bouton qui execute la decision. Inactif : un
     * vrai `disabled` — la souris et le clavier sont bloques par le navigateur, pas par un style —
     * et la raison, visible a cote du libelle, portee aussi par `title`.
     */
    function boutonDAction(clef, decision) {
        var b = element('button', 'gtAction' + (decision.classe ? ' ' + decision.classe : ''));
        var icone = element('img', '');
        var texte = element('span', 'gtActionText');
        var libelle = element('span', 'gtActionLabel');

        b.type = 'button';
        b.setAttribute('data-action', clef);
        icone.src = '/img/galaxy-tactical/' + ICONES_D_ACTION[clef];
        icone.alt = '';
        icone.setAttribute('aria-hidden', 'true');
        libelle.textContent = locaFiche('labels.' + clef, clef);
        texte.appendChild(libelle);

        if (decision.actif) {
            b.addEventListener('click', function (evenement) {
                evenement.preventDefault();
                decision.executer();
            });
        } else {
            var motif = element('small', 'gtActionReason');
            motif.textContent = decision.raison;
            texte.appendChild(motif);
            b.disabled = true;
            b.setAttribute('aria-disabled', 'true');
            b.title = decision.raison;
        }

        b.appendChild(icone);
        b.appendChild(texte);

        return b;
    }

    /* La grille des actions, recomposee a chaque ouverture — et quand le choix de flotte d'expedition change. */
    function composerLesActions(f) {
        var contexte = f.gtContexte;
        var grille = f.querySelector('.gtCardActions');

        if (!contexte || !grille) {
            return;
        }

        var decisions = decisionsDe(contexte);
        var noms = ACTIONS_PAR_GENRE[contexte.genre] || [];

        if (contexte.genre === 'profond' && contexte.objet) {
            noms = noms.concat(['recycler']);
        }

        grille.innerHTML = '';

        noms.forEach(function (clef) {
            grille.appendChild(boutonDAction(clef, decisions[clef]()));
        });
    }

    /*
     * L'activite d'un corps, telle que le serveur la mesure (`getPlanetActivityStatus()`) : moins de
     * quinze minutes, ou le nombre de minutes jusqu'a une heure, rien au-dela. C'est ce que la
     * vignette du tableau montrait par une etoile et une infobulle ; la fiche l'ecrit.
     */
    function activiteDe(objet, pageLoca) {
        var a = objet && objet.activity;

        if (!a || !a.showActivity) {
            return '';
        }

        var libelle = pageLoca.LOCA_ALL_ACTIVITY || 'Activite';
        var minute = pageLoca.LOCA_ALL_TIME_MINUTE || 'm';

        return a.showActivity === 60 && a.idleTime ? libelle + ' : ' + a.idleTime + minute : libelle + ' : < 15' + minute;
    }

    function avecDetail(base, detail) {
        return detail ? base + ' \u00b7 ' + detail : base;
    }

    /*
     * ## Les flottes standard pour l'expedition
     *
     * Le jeu enregistre des flottes standard sur la page Flotte (un nom, des vaisseaux) et les rend
     * par `galaxyFleetTemplatesUrl`. La boite historique de la Galaxie avait une liste pour elles,
     * mais rien ne la remplissait, son controle de cible visait une adresse fictive et son envoi
     * passait par le point d'entree des mini-flottes, qui refuse l'expedition : un parcours mort
     * dans ce fork. La fiche prend donc le parcours **vivant**, celui de la page Flotte : controle
     * de la cible (`galaxyCheckTargetUrl`) des qu'une flotte est choisie, puis envoi
     * (`galaxySendFleetUrl`) avec la composition de la flotte, la vitesse et la duree par defaut de
     * la page Flotte — 100 % et une heure. Le serveur decide de tout (emplacements, astrophysique,
     * classe, carburant) et sa raison est ecrite sous le bouton. Sans flotte choisie, le bouton
     * ouvre la page Flotte, par le bouton herite. Le droit d'employer une flotte standard depuis
     * la Galaxie vient du serveur : `hasAdmiral` dans la charge utile du systeme.
     */
    var VITESSE_PAR_DEFAUT = 10;
    var DUREE_D_EXPEDITION_PAR_DEFAUT = 1;

    function jetonCsrf() {
        if (typeof window.token === 'string' && window.token !== '') {
            return window.token;
        }

        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') || '' : '';
    }

    /* Les flottes standard, demandees une fois par page ; les fiches ouvertes entre-temps attendent. */
    function chargerLesFlottesStandard(carte, apres) {
        var etat = carte.gtFlottesStandard;

        if (etat && etat.chargees) {
            apres(etat.modeles);

            return;
        }

        if (etat && etat.enCours) {
            etat.attentes.push(apres);

            return;
        }

        etat = { chargees: false, enCours: true, modeles: [], attentes: [apres] };
        carte.gtFlottesStandard = etat;

        var finir = function (modeles) {
            var attentes = etat.attentes;
            etat.enCours = false;
            etat.chargees = true;
            etat.modeles = modeles;
            etat.attentes = [];
            attentes.forEach(function (attente) {
                attente(modeles);
            });
        };

        if (typeof galaxyFleetTemplatesUrl === 'undefined' || !galaxyFleetTemplatesUrl || !window.jQuery) {
            finir([]);

            return;
        }

        window.jQuery.getJSON(galaxyFleetTemplatesUrl)
            .done(function (reponse) {
                finir(reponse && Array.isArray(reponse.templates) ? reponse.templates : []);
            })
            .fail(function () {
                finir([]);
            });
    }

    /* La note sous la liste : le droit d'abord, puis l'absence de flotte, sinon les valeurs par defaut. */
    function noteDExpedition(systeme, modeles) {
        if (!systeme || !systeme.hasAdmiral) {
            var conteneur = document.getElementById('galaxyExpeditionFleetTemplateContainer');

            return (conteneur && conteneur.getAttribute('title')) || locaFiche('reasons.unavailable', '');
        }

        if (modeles !== null && modeles.length === 0) {
            return locaFiche('expeditionNone', '');
        }

        return locaFiche('expeditionNote', '');
    }

    function mettreLaNote(f, texte) {
        var note = f.querySelector('.gtCardNote');

        if (note) {
            note.textContent = texte;
        }
    }

    /* La charge d'une expedition avec cette flotte : celle que la page Flotte envoie. */
    function chargeDExpedition(carte, modele) {
        var s = carte.gtSysteme || {};
        var charge = {
            galaxy: s.galaxie,
            system: s.systeme,
            position: POSITION_ESPACE_PROFOND,
            type: 1,
            mission: 15,
            speed: VITESSE_PAR_DEFAUT,
            holdingtime: DUREE_D_EXPEDITION_PAR_DEFAUT,
            _token: jetonCsrf()
        };

        Object.keys(modele.ships || {}).forEach(function (id) {
            if (Number(modele.ships[id]) > 0) {
                charge['am' + id] = Number(modele.ships[id]);
            }
        });

        return charge;
    }

    /* Le serveur dit si cette flotte peut partir en expedition d'ici ; sa raison est gardee pour le bouton. */
    function verifierLaCible(carte, f, modele) {
        var e = f.gtExpedition;

        if (!e || typeof galaxyCheckTargetUrl === 'undefined' || !galaxyCheckTargetUrl || !window.jQuery) {
            return;
        }

        var demande = ++e.jeton;
        e.verdict = { enCours: true, ok: false, raison: '' };
        composerLesActions(f);

        var conclure = function (ok, raison) {
            if (demande !== e.jeton) {
                return;
            }

            e.verdict = { enCours: false, ok: ok, raison: raison };
            composerLesActions(f);
        };

        window.jQuery.post(galaxyCheckTargetUrl, chargeDExpedition(carte, modele), null, 'json')
            .done(function (reponse) {
                var ok = !!(reponse && reponse.orders && reponse.orders[15] === true);
                var raison = reponse && reponse.errors && reponse.errors.length ? String(reponse.errors[0].message || '') : '';
                conclure(ok, ok ? '' : raison || locaFiche('reasons.unavailable', ''));
            })
            .fail(function () {
                conclure(false, locaFiche('reasons.unavailable', ''));
            });
    }

    /* L'envoi, par le point d'entree de la page Flotte ; le message du serveur est montre, la couche redemandee. */
    function lancerLExpedition(carte, f, modele) {
        var e = f.gtExpedition;

        if (!e || e.envoiEnCours || typeof galaxySendFleetUrl === 'undefined' || !galaxySendFleetUrl || !window.jQuery) {
            return;
        }

        e.envoiEnCours = true;
        composerLesActions(f);

        var dire = function (message, erreur) {
            if (typeof window.fadeBox === 'function' && message) {
                window.fadeBox(message, erreur);
            }
        };

        window.jQuery.post(galaxySendFleetUrl, chargeDExpedition(carte, modele), null, 'json')
            .done(function (reponse) {
                e.envoiEnCours = false;

                if (reponse && reponse.success) {
                    dire(locaFiche('expeditionSent', ''), false);
                    e.liste.value = '';
                    e.modele = null;
                    e.verdict = null;
                    composerLesActions(f);

                    if (carte.gtSysteme) {
                        chargerLesFlottes(carte, carte.gtSysteme.galaxie, carte.gtSysteme.systeme);
                    }

                    return;
                }

                var raison = reponse && reponse.errors && reponse.errors.length ? String(reponse.errors[0].message || '') : '';
                e.verdict = { enCours: false, ok: false, raison: raison || locaFiche('reasons.unavailable', '') };
                dire(raison, true);
                composerLesActions(f);
            })
            .fail(function () {
                e.envoiEnCours = false;
                e.verdict = { enCours: false, ok: false, raison: locaFiche('reasons.unavailable', '') };
                composerLesActions(f);
            });
    }

    /* Le choix d'une flotte dans la liste : le verdict du serveur est redemande a chaque changement. */
    function choisirLaFlotteStandard(carte, f) {
        var e = f.gtExpedition;

        if (!e) {
            return;
        }

        var id = Number(e.liste.value);
        var modele = null;

        e.modeles.forEach(function (m) {
            if (Number(m.id) === id) {
                modele = m;
            }
        });

        e.modele = modele;
        e.verdict = null;
        e.jeton++;
        composerLesActions(f);

        if (modele) {
            verifierLaCible(carte, f, modele);
        }
    }

    /* La liste de la fiche, remplie des flottes standard du joueur des qu'elles sont connues. */
    function listeDesFlottesStandard(carte, f, systeme) {
        var liste = element('select', 'gtSelect');
        var choisir = document.createElement('option');

        liste.id = 'gtExpeditionSelect';
        liste.disabled = true;
        choisir.value = '';
        choisir.textContent = locaFiche('expeditionChoose', '');
        liste.appendChild(choisir);

        f.gtExpedition = { liste: liste, modeles: [], modele: null, verdict: null, jeton: 0, envoiEnCours: false };

        liste.addEventListener('change', function () {
            choisirLaFlotteStandard(carte, f);
        });

        chargerLesFlottesStandard(carte, function (modeles) {
            if (!f.gtExpedition || f.gtExpedition.liste !== liste) {
                return;
            }

            f.gtExpedition.modeles = modeles;
            modeles.forEach(function (m) {
                var option = document.createElement('option');
                option.value = String(m.id);
                option.textContent = String(m.name);
                liste.appendChild(option);
            });
            liste.disabled = !(systeme && systeme.hasAdmiral) || modeles.length === 0;
            mettreLaNote(f, noteDExpedition(systeme, modeles));
        });

        return liste;
    }

    function imageDeFiche(chemin, classe) {
        var img = element('img', classe);
        img.src = chemin;
        img.alt = '';
        img.setAttribute('aria-hidden', 'true');

        return img;
    }

    function nombre(valeur) {
        return Number(valeur || 0).toLocaleString();
    }

    /*
     * La composition d'une fiche : ce qui precede la ligne dans la grille (vignette, nature, cibles)
     * et ce qui la suit (note, actions). Le titre revient quand le corps choisi n'est pas la planete.
     */
    function composerLaFiche(carte, f, bloc, position, corps) {
        var lignes = carte.gtLignes || {};
        var ligne = lignes[position] || null;
        var systeme = carte.gtSystemeJson || {};
        var genre;
        var objet;

        if (position === POSITION_ESPACE_PROFOND) {
            genre = 'profond';
            objet = ligne && ligne.planets && !Array.isArray(ligne.planets) ? ligne.planets : null;
        } else if (corps === 'moon') {
            genre = 'lune';
            objet = corpsDeGenre(ligne, LUNE);
        } else if (corps === 'debris') {
            genre = 'debris';
            objet = corpsDeGenre(ligne, DEBRIS);
        } else {
            objet = corpsDeGenre(ligne, PLANETE);
            genre = objet ? 'planete' : 'libre';
        }

        f.gtContexte = { carte: carte, fiche: f, ligne: ligne, objet: objet, systeme: systeme, position: position, genre: genre };

        var avant = [];
        var apres = [];
        var titre = null;
        var nature = element('div', 'gtCardKind');
        var pageLoca = window.loca || {};

        if (genre === 'planete') {
            avant.push(texture(objet));
            avant[0].classList.add('gtCardArt');
            nature.textContent = avecDetail(locaFiche('planet', 'Planete'), activiteDe(objet, pageLoca));
        } else if (genre === 'lune') {
            avant.push(imageDeFiche('/img/galaxy-tactical/moon-tactical-v1.png', 'gtCardArt' + (objet && objet.isDestroyed ? ' gtDestroyed' : '')));
            nature.textContent = avecDetail(
                avecDetail(locaFiche('moon', 'Lune'), objet && objet.size ? nombre(objet.size) + ' ' + (pageLoca.LOCA_OVERVIEW_JS_KM || 'km') : ''),
                activiteDe(objet, pageLoca)
            );
            titre = objet && objet.planetName ? objet.planetName : locaFiche('moon', 'Lune');
        } else if (genre === 'debris') {
            avant.push(imageDeFiche('/img/galaxy-tactical/debris-marker.svg', 'gtCardArt'));
            var ressources = (objet && objet.resources) || {};
            nature.textContent = [
                (pageLoca.LOCA_ALL_METAL || 'Metal') + ' ' + nombre(ressources.metal && ressources.metal.amount),
                (pageLoca.LOCA_ALL_CRYSTAL || 'Cristal') + ' ' + nombre(ressources.crystal && ressources.crystal.amount),
                (pageLoca.LOCA_ALL_DEUTERIUM || 'Deuterium') + ' ' + nombre(ressources.deuterium && ressources.deuterium.amount)
            ].join(' \u00b7 ');
            titre = locaFiche('debris', 'Champ de debris');
        } else if (genre === 'libre') {
            avant.push(imageDeFiche('/img/galaxy-tactical/empty-position-preview.svg', 'gtCardArt gtCardGhost'));
            var description = segmentsDeColonisation(mission(ligne, 7));
            nature.textContent = avecDetail(locaFiche('emptySlot', 'Emplacement inoccupe'), description.length > 1 ? description[1] : '');
        } else {
            avant.push(imageDeFiche('/img/galaxy-tactical/deep-space-nebula-v2.png', 'gtCardNebula'));
            var etiquette = element('label', 'gtCardLabel');
            etiquette.setAttribute('for', 'gtExpeditionSelect');
            etiquette.textContent = locaFiche('expeditionFleet', 'Flotte d\'expedition');
            nature = etiquette;
        }

        avant.push(nature);

        if (genre === 'profond') {
            avant.push(listeDesFlottesStandard(carte, f, systeme));
        }

        /* Les cibles d'une meme position : la planete, sa lune, son champ de debris — celles qui existent. */
        if (genre === 'planete' || genre === 'lune' || genre === 'debris') {
            var cibles = element('div', 'gtCardTargets');
            cibles.setAttribute('role', 'group');
            cibles.setAttribute('aria-label', locaFiche('targets', 'Corps de la position'));

            [['planet', PLANETE, 'planet'], ['moon', LUNE, 'moon'], ['debris', DEBRIS, 'debris']].forEach(function (cible) {
                if (!corpsDeGenre(ligne, cible[1])) {
                    return;
                }

                var b = element('button', 'gtTarget');
                b.type = 'button';
                b.textContent = locaFiche(cible[2], cible[2]);
                b.setAttribute('aria-pressed', corps === cible[0] ? 'true' : 'false');
                b.addEventListener('click', function () {
                    if (corps !== cible[0]) {
                        choisir(carte, bloc, cible[0]);
                    }
                });
                cibles.appendChild(b);
            });

            if (cibles.children.length > 1) {
                avant.push(cibles);
            }
        }

        var note = element('p', 'gtCardNote');

        if (genre === 'libre') {
            note.textContent = locaFiche('emptyServer', '');
        } else if (genre === 'profond') {
            note.textContent = noteDExpedition(systeme, null);
        }

        if (note.textContent !== '') {
            apres.push(note);
        }

        var grille = element('div', 'gtCardActions');
        grille.setAttribute('role', 'group');
        grille.setAttribute('aria-label', locaFiche('actions', 'Actions'));
        apres.push(grille);

        return { avant: avant, apres: apres, titre: titre };
    }

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
            deselectionner(carte, true);
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

    /*
     * Fermer la fiche. `rendreLeFocus` : quand la fermeture vient du joueur (croix, Echap), le focus
     * revient sur le corps choisi — Codex, revue 115 : « retour du focus sur le corps selectionne ».
     * Un redessin ne le demande pas : il ne doit pas voler le focus.
     */
    function deselectionner(carte, rendreLeFocus) {
        rendreLaLigne();

        var f = carte.querySelector('.gtCard');
        var bloc = f ? f.gtBloc : null;

        if (f) {
            f.hidden = true;
            f.gtBloc = null;
            f.gtContexte = null;
            f.gtExpedition = null;
        }

        var choisis = carte.querySelectorAll('.gtBody.gtSelected');

        for (var i = 0; i < choisis.length; i++) {
            choisis[i].classList.remove('gtSelected');
        }

        if (rendreLeFocus && bloc && carte.contains(bloc) && typeof bloc.focus === 'function') {
            bloc.focus();
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
            deselectionner(carte, true);

            return;
        }

        deselectionner(carte, false);

        var titre = f.querySelector('.gtCardTitle');
        var coords = f.querySelector('.gtCardCoords');

        if (coords) {
            coords.textContent = bloc.getAttribute('data-coords') || '';
        }

        /* La fiche dit quel corps est choisi ; la feuille met sa cellule en avant. */
        f.setAttribute('data-corps', corps);

        var contenant = f.querySelector('.gtCardBody');
        contenant.innerHTML = '';

        var composition = composerLaFiche(carte, f, bloc, position, corps);

        composition.avant.forEach(function (n) {
            contenant.appendChild(n);
        });

        deplacee = { noeud: ligne, parent: ligne.parentNode, suivant: ligne.nextSibling, position: position, corps: corps };
        contenant.appendChild(ligne);

        composition.apres.forEach(function (n) {
            contenant.appendChild(n);
        });

        if (titre) {
            titre.textContent = composition.titre || bloc.getAttribute('data-titre') || String(position);
        }

        composerLesActions(f);

        f.hidden = false;
        f.gtBloc = bloc;
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
                deselectionner(carte, true);

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
    var systemeAbonne = null;
    var joueurAbonne = false;

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
        mouvement._porteImage = image;

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
        mouvement._saut = saut;
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

            var trajectoire = svg('line', {
                'class': 'gtTrajectory',
                x1: bouts.depart.x.toFixed(1), y1: bouts.depart.y.toFixed(1),
                x2: bouts.arrivee.x.toFixed(1), y2: bouts.arrivee.y.toFixed(1)
            });
            groupe.appendChild(trajectoire);
            mouvement._trajectoire = trajectoire;

            /*
             * Une porte de bord se voit, et elle se clique : un vol qui sort ou entre a une marque a
             * son extremite, et un bouton qui charge l'autre systeme pour suivre la flotte — c'est ainsi
             * qu'on la voit « continuer son chemin jusqu'a la planete ».
             */
            mouvement._trainee = null;
            mouvement._porteImage = null;
            mouvement._saut = null;
            mouvement._cap = null;

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
                mouvement._cap = cap;
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
    }

    /*
     * L'animation ne tourne que si elle sert : aucun mouvement, ou onglet cache. Elle tourne a
     * chaque image, **y compris sous la reduction des mouvements** : la position d'un vaisseau est
     * une information, pas un effet — la reposer toutes les cinq secondes faisait sauter le
     * marqueur (retour de Keven : « ca fait quelques secondes, l'image change de place »). Les
     * effets decoratifs — trainee, fenetre d'hyperespace, soleil — s'eteignent par leurs propres
     * regles sous cette preference.
     */
    function animer() {
        arreterLAnimation();

        if (mouvements.length === 0 || document.hidden) {
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

    /*
     * ## La boucle orbitale
     *
     * Toutes les PAS_ORBITAL millisecondes : les quinze corps sont reposes a leur point du moment,
     * la fiche ouverte suit son corps, et chaque trace (trajectoire, cap du vaisseau, marque de
     * porte, trainee, bouton de saut) est recalcule depuis les corps qui ont bouge — une flotte
     * qui rentre vise la position **actuelle** de sa planete. Le marqueur lui-meme est place a
     * chaque image par `placerLesMarqueurs()`, sur ces bouts rafraichis.
     */
    var orbite = null;

    function rafraichirLeTrace(mouvement, bouts) {
        if (mouvement._trajectoire) {
            mouvement._trajectoire.setAttribute('x1', bouts.depart.x.toFixed(1));
            mouvement._trajectoire.setAttribute('y1', bouts.depart.y.toFixed(1));
            mouvement._trajectoire.setAttribute('x2', bouts.arrivee.x.toFixed(1));
            mouvement._trajectoire.setAttribute('y2', bouts.arrivee.y.toFixed(1));
        }

        var cap = capDe(bouts);

        if (mouvement._cap) {
            mouvement._cap.setAttribute('transform', 'rotate(' + cap.toFixed(1) + ')');
        }

        var porte = bouts.partIci ? bouts.arrivee : bouts.depart;

        if (mouvement._porteImage) {
            mouvement._porteImage.setAttribute('x', (porte.x - 9).toFixed(1));
            mouvement._porteImage.setAttribute('y', (porte.y - 9).toFixed(1));
        }

        if (mouvement._trainee) {
            mouvement._trainee.setAttribute('transform', 'translate(' + porte.x.toFixed(1) + ',' + porte.y.toFixed(1) + ') rotate(' + cap.toFixed(1) + ')');
        }

        if (mouvement._saut) {
            mouvement._saut.setAttribute('x', (porte.x - 8).toFixed(1));
            mouvement._saut.setAttribute('y', (porte.y + 11).toFixed(1));
        }
    }

    function tournerLesOrbites(carte) {
        var corps = carte.querySelectorAll('.gtBody[data-position]');
        var bouge = false;

        for (var k = 0; k < corps.length; k++) {
            var position = Number(corps[k].getAttribute('data-position'));

            if (position < 1 || position > POSITIONS) {
                continue;
            }

            var p = pointDe(position);
            var gauche = Math.round(p.x) + 'px';
            var haut = Math.round(p.y) + 'px';

            if (corps[k].style.left !== gauche || corps[k].style.top !== haut) {
                corps[k].style.left = gauche;
                corps[k].style.top = haut;
                bouge = true;
            }
        }

        if (!bouge) {
            return;
        }

        var f = carte.querySelector('.gtCard');

        if (f && !f.hidden && f.gtBloc && carte.contains(f.gtBloc)) {
            placer(f, f.gtBloc);
        }

        if (!carte.gtSysteme) {
            return;
        }

        mouvements.forEach(function (mouvement) {
            if (!mouvement._marqueur) {
                return;
            }

            mouvement._bouts = extremites(mouvement, carte.gtSysteme.galaxie, carte.gtSysteme.systeme);
            rafraichirLeTrace(mouvement, mouvement._bouts);
        });
    }

    function arreterLesOrbites() {
        if (orbite !== null) {
            window.clearInterval(orbite);
            orbite = null;
        }
    }

    function demarrerLesOrbites(carte) {
        arreterLesOrbites();
        tournerLesOrbites(carte);
        orbite = window.setInterval(function () {
            tournerLesOrbites(carte);
        }, PAS_ORBITAL);
    }

    /* Un onglet qui revient au premier plan reprend l'animation et les orbites ; un onglet cache les arrete. */
    document.addEventListener('visibilitychange', function () {
        var carte = document.getElementById('galaxyTactical');

        if (document.hidden) {
            arreterLAnimation();
            arreterLesOrbites();

            return;
        }

        animer();

        if (carte && carte.gtSysteme) {
            demarrerLesOrbites(carte);
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

        /* La fiche lit la charge utile : missions, droits et faits du systeme, tels que le serveur les rend. */
        carte.gtLignes = parPosition;
        carte.gtSystemeJson = systeme;

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
                /*
                 * `LOCA_GALAXY_EMPTY_SLOT` n'a jamais existe dans `jsloca` : la premiere version
                 * rendait son repli francais a tout le monde. Le libelle vient de la vue, traduit.
                 */
                var libre = locaFiche('freeSlot', 'Position libre');
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
        demarrerLesOrbites(carte);

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
