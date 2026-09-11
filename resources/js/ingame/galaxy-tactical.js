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
        expedition: 'action-expedition.svg',
        /* Les patrouilles : les icones du pack `patrols-v1` de Codex, prefixees `patrol-`. */
        patrouiller: 'patrol-patrol.svg',
        deplacer: 'patrol-move.svg',
        rappeler: 'patrol-return.svg',
        confirmer: 'patrol-confirm.svg',
        annuler: 'patrol-cancel.svg'
    };

    /*
     * Les actions que chaque genre de corps peut porter — l'inventaire de la revue 112, section 5.
     * Une action absente de la ligne n'est pas cachee : elle est grisee avec sa raison.
     */
    var ACTIONS_PAR_GENRE = {
        planete: ['espionner', 'attaquer', 'transporter', 'deployer', 'acs', 'missiles', 'phalange', 'message', 'ami', 'ignorer', 'classement', 'alliance', 'patrouiller'],
        lune: ['espionner', 'attaquer', 'transporter', 'deployer', 'acs', 'detruireLune', 'message', 'ami', 'classement', 'alliance'],
        debris: ['recycler'],
        libre: ['coloniser', 'demenager'],
        profond: ['expedition'],
        patrouille: ['deplacer', 'rappeler']
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
            /*
             * ## Les actions de patrouille : le serveur a deja decide
             *
             * `commands.move` et `commands.recall` viennent de la charge des patrouilles, avec la
             * raison du refus traduite : c est la meme methode que la confirmation lira. Le bouton
             * ne fait que la montrer. Le lancement depuis une planete demande trois choses que la
             * fiche verifie sans rien decider : la planete est la mienne, c est la planete active
             * (le serveur lance depuis elle, comme tout envoi de flotte), et le droit d employer une
             * flotte standard depuis la Galaxie est celui de l expedition (`hasAdmiral`).
             */
            patrouiller: function () {
                if (f.gtOrdre) {
                    return inactif(locaFiche('patrolOrderPending', ''));
                }

                if (detruit) {
                    return inactif(raison('destroyed'));
                }

                if (!patrouillesActives()) {
                    return inactif(raison('patrolDisabled'));
                }

                if (!mienne) {
                    return inactif(raison('patrolFromOwn'));
                }

                if (typeof window.galaxyCurrentPlanetId === 'undefined' || Number(objet.planetId) !== Number(window.galaxyCurrentPlanetId)) {
                    return inactif(raison('patrolCurrentOnly'));
                }


                return actif(function () {
                    commencerUnLancement(contexte.carte, f, objet);
                });
            },
            deplacer: function () {
                return decisionDeCommande(f, contexte.patrouille, 'move', function () {
                    commencerUnDeplacement(contexte.carte, f, contexte.patrouille);
                });
            },
            rappeler: function () {
                return decisionDeCommande(f, contexte.patrouille, 'recall', function () {
                    commencerUnRappel(contexte.carte, f, contexte.patrouille);
                }, 'patrol-danger');
            },
            confirmer: function () {
                var o = f.gtOrdre;

                if (!o || !o.devis) {
                    return inactif(raison('patrolNoDestination'));
                }

                if (o.enCours) {
                    return inactif(locaFiche('patrolOrderPending', ''));
                }

                if (!o.devis.possible) {
                    return inactif(o.devis.refusal_reason || raison('unavailable'));
                }

                return actif(function () {
                    envoyerLOrdre(contexte.carte, f);
                }, 'patrol-primary');
            },
            annuler: function () {
                return actif(function () {
                    annulerLOrdre(contexte.carte, f);
                });
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

        /* Un ordre en cours remplace les actions du corps par celles de l ordre : annuler, puis confirmer. */
        if (f.gtOrdre) {
            noms = f.gtOrdre.etape === 'devis' ? ['confirmer', 'annuler'] : ['annuler'];
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
            f.gtPatrouille = null;
        }

        finirLOrdre(carte, f);

        var choisis = carte.querySelectorAll('.gtBody.gtSelected, .gtPatrolMarker.gtSelected');

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

            /* En choix de destination, le clic designe la cible d un ordre : un corps, ou un point de l espace. */
            if (carte.gtChoix) {
                evenement.preventDefault();

                /* Sur un corps, on ne devine pas ce que le joueur veut : la fiche le lui demande. */
                if (bloc && proposerLesActionsDuCorps(carte, bloc, corpsClique(evenement.target))) {
                    return;
                }

                choisirLaDestination(carte, bloc ? destinationDuCorps(carte, bloc, corpsClique(evenement.target)) : destinationDuClic(carte, evenement));

                return;
            }

            if (bloc) {
                choisir(carte, bloc, corpsClique(evenement.target));

                return;
            }

            /*
             * ## Cliquer le vide compose une patrouille qui part la
             *
             * L entree inverse de celle qui existe (demande de Keven, 11 septembre 2026) : au lieu
             * d ouvrir sa planete, de composer, puis de choisir ou, on **designe l endroit d abord**
             * et la composition suit. Les deux chemins finissent au meme endroit — devis du serveur,
             * puis confirmation —, et c est ce qui les rend sûrs tous les deux.
             *
             * Rien ne s ouvre si un ordre est deja en cours : le clic appartiendrait alors a cet
             * ordre, et l ecraser ferait perdre au joueur la flotte qu il vient de composer.
             */
            var ouverte = carte.querySelector('.gtCard');

            if ((!ouverte || !ouverte.gtOrdre) && patrouillesActives()) {
                composerUnePatrouilleVers(carte, destinationDuClic(carte, evenement));
            }
        });

        /*
         * Le depot : la ou la flotte glissee est relachee. Il designe, il ne deplace pas — le
         * panneau du devis s'ouvre, et rien ne part avant la confirmation.
         */
        carte.addEventListener('dragover', function (evenement) {
            if (!carte.gtChoix || (evenement.target.closest && evenement.target.closest('.gtCard'))) {
                return;
            }

            evenement.preventDefault();

            if (evenement.dataTransfer) {
                evenement.dataTransfer.dropEffect = 'move';
            }
        });

        carte.addEventListener('drop', function (evenement) {
            /* Avant les gardes : un depot refuse doit rendre la fiche comme un depot accepte. */
            carte.classList.remove('gtDragging');

            if (!carte.gtChoix || (evenement.target.closest && evenement.target.closest('.gtCard'))) {
                return;
            }

            evenement.preventDefault();

            var cible = evenement.target.closest ? evenement.target.closest('.gtBody') : null;

            /*
             * **Deposer sur un corps ne veut pas dire une seule chose.** Stationner a cote pour le
             * surveiller, ou s y poser et dissoudre la patrouille, sont deux ordres differents vers
             * le meme point de la carte. La fiche les propose avec leur devis ; le depot, lui, ne
             * decide de rien — c est la regle de toute la carte depuis le debut.
             */
            if (cible && proposerLesActionsDuCorps(carte, cible, corpsClique(evenement.target))) {
                return;
            }

            choisirLaDestination(carte, cible
                ? destinationDuCorps(carte, cible, corpsClique(evenement.target))
                : destinationDuClic(carte, evenement));
        });

        /*
         * **La fin du geste rend la fiche, quelle qu'elle soit** : depot reussi, depot refuse,
         * echappement, relachement hors de la fenetre. `dragend` part toujours de la source et il
         * remonte — l'ecouter ici couvre donc tous les marqueurs, y compris ceux qu'un
         * rafraichissement a recrees pendant le vol. Un ecouteur pose sur chaque marqueur serait
         * perdu avec lui, et la carte resterait effacee.
         */
        carte.addEventListener('dragend', function () {
            carte.classList.remove('gtDragging');
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

                if (carte.gtChoix) {
                    choisirLaDestination(carte, destinationDuCorps(carte, bloc, corpsClique(evenement.target)));

                    return;
                }

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

    /*
     * ## La generation du contexte d affichage
     *
     * Elle s incremente a chaque fois que ce qui est affichable **peut avoir change** : une
     * nouvelle demande, mais aussi une invalidation — perte de connexion, revocation annoncee,
     * changement de systeme. Toute reponse nee sous une generation anterieure est jetee.
     *
     * **Comparer a la derniere reponse affichee ne suffit pas** (revue 124 de Codex, point 3).
     * L ancien jeton ne bougeait qu au depart d une demande : une revocation qui masquait sans
     * relancer laissait la demande en vol parfaitement « courante », et sa reponse reintroduisait a
     * l ecran ce que la revocation venait d oter. Le scenario de Codex — reponse n10 affichee,
     * demande n11 en vol, invalidation, demande n12, n11 arrive avant n12 — n etait couvert que par
     * accident, parce qu une seconde demande suivait. Sans elle, rien ne rejetait n11.
     *
     * C est le meme compteur qu avant, pas un second garde-fou : deux mecanismes de peremption
     * divergeraient, et le depot a deja paye cette lecon.
     */
    var generationDuContexte = 0;
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
     * ## Les points de l'espace : du repere du serveur a l'ecran, et retour
     *
     * Le serveur mesure un systeme en **unites de reference** : l'orbite d'une position vaut cent
     * unites de rayon (`SystemGeometry::ORBIT_STEP`) et l'angle de base d'un corps est l'angle d'or
     * moins 90 degres — exactement `anglesDeBase()` avant la rotation decorative. Un point libre
     * (x, y) du serveur se projette donc a l'ecran par les transformations des corps : le rayon en
     * unites devient le rayon en pixels de l'orbite equivalente (`rayonDe`, lineaire, prolongee
     * au-dela de la quinzieme orbite), l'angle est tourne de la phase orbitale du moment, et
     * l'ellipse aplatit la verticale. Sans l'ecartement, qui est propre a chaque corps : un point
     * n'est pas un corps, et une destination « pres d'un corps » est dessinee **sur le corps**
     * (`pointDeBout`), la ou le joueur le voit.
     *
     * L'inverse rend un clic en unites du serveur, **arrondi a la grille que le serveur impose**
     * (PATROUILLE_GRILLE = `patrol_grid_units`) : le serveur refuse un point hors grille au lieu de
     * l'arrondir, pour que la carte ne puisse pas envoyer une flotte ailleurs que la ou le joueur
     * a clique. Rien ici ne decide d'une distance, d'un cout ni d'un droit : ce sont des pixels.
     */
    var TYPE_POINT_SPATIAL = 5;
    var UNITES_PAR_ORBITE = 100;

    /*
     * **La grille est un reglage d'administration, pas une constante.** Le serveur refuse un point
     * hors grille au lieu de l'arrondir — c'est la bonne regle —, donc une carte qui arrondirait a
     * une autre valeur que la sienne ferait refuser presque tous les clics. La vue la publie ; le
     * repli a dix ne sert que si la page est servie par un rendu qui ne la porte pas encore.
     */
    var PATROUILLE_GRILLE = typeof galaxyPatrolGridUnits !== 'undefined' && Number(galaxyPatrolGridUnits) > 0
        ? Number(galaxyPatrolGridUnits)
        : 10;

    /* La phase du moment, quantifiee comme celle des corps : un point tourne avec eux, jamais a cote. */
    function phaseDuMoment() {
        return phaseOrbitale(Math.floor(maintenantServeur() / PAS_ORBITAL) * PAS_ORBITAL);
    }

    function pointSpatial(x, y) {
        var c = centre();
        var unites = Math.sqrt(x * x + y * y);
        var angle = Math.atan2(y, x) + phaseDuMoment();
        var rx = rayonDe(unites / UNITES_PAR_ORBITE);

        return { x: c.x + rx * Math.cos(angle), y: c.y + rx * APLATISSEMENT * Math.sin(angle), aDroite: Math.cos(angle) > 0 };
    }

    function pointServeur(sx, sy) {
        var c = centre();
        var dx = sx - c.x;
        var dy = (sy - c.y) / APLATISSEMENT;
        var rx = Math.sqrt(dx * dx + dy * dy);
        var angle = Math.atan2(dy, dx) - phaseDuMoment();
        /*
         * **Jamais un rayon negatif.** La premiere orbite est a RAYON_MIN pixels du centre, et la
         * droite qui prolonge l'echelle vers l'interieur passe par zero avant le centre : un clic
         * dans les cinquante-six pixels autour de l'etoile donnait un rayon negatif, donc un point
         * **miroir**, a trois cents unites de la ou le joueur avait clique — et hors de l'exclusion
         * de l'etoile, le serveur l'acceptait. Le plancher a zero rend le centre : le serveur
         * refuse « trop pres de l'etoile », et le joueur lit pourquoi. Mesure faite par calcul sur
         * les fonctions du module, pas supposee.
         */
        var unites = Math.max(0, (1 + ((rx - RAYON_MIN) * (POSITIONS - 1)) / (RAYON_MAX - RAYON_MIN)) * UNITES_PAR_ORBITE);

        return {
            x: Math.round((unites * Math.cos(angle)) / PATROUILLE_GRILLE) * PATROUILLE_GRILLE,
            y: Math.round((unites * Math.sin(angle)) / PATROUILLE_GRILLE) * PATROUILLE_GRILLE
        };
    }

    /* Le point d'un bout de mouvement : un point libre par ses coordonnees du serveur, sinon un corps. */
    function pointDeBout(bout) {
        if (Number(bout.type) === TYPE_POINT_SPATIAL && bout.x !== null && bout.x !== undefined && bout.y !== null && bout.y !== undefined) {
            return pointSpatial(Number(bout.x), Number(bout.y));
        }

        return pointDeCorps(bout.position, bout.type);
    }

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

        /* Les memes points que la feuille : la lune en haut a gauche, les debris a gauche, a la meme distance. */
        if (Number(type) === TYPE_LUNE) {
            return { x: p.x - 24, y: p.y - 24, aDroite: p.aDroite };
        }

        if (Number(type) === TYPE_DEBRIS) {
            return { x: p.x - 34, y: p.y, aDroite: p.aDroite };
        }

        return p;
    }

    function extremites(mouvement, galaxie, systeme) {
        var partIci = Number(mouvement.from.galaxy) === galaxie && Number(mouvement.from.system) === systeme;
        var arriveIci = Number(mouvement.to.galaxy) === galaxie && Number(mouvement.to.system) === systeme;

        var depart = partIci ? pointDeBout(mouvement.from) : porteDeBord(mouvement.to.position);
        var arrivee = arriveIci ? pointDeBout(mouvement.to) : porteDeBord(mouvement.from.position);

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

        if ((mouvements.length === 0 && patrouilles.length === 0) || document.hidden) {
            return;
        }

        var boucle = function () {
            placerLesPatrouilles();
            placerLesMarqueurs();
            animation = window.requestAnimationFrame(boucle);
        };

        animation = window.requestAnimationFrame(boucle);
    }

    /*
     * ## La couche des patrouilles
     *
     * Les patrouilles du joueur, telles que le serveur les rend sous `patrols` — **les siennes
     * seulement** ; une patrouille etrangere n'est pas dans la reponse (les reseaux de surveillance
     * viendront a l'etape 5). Chacune porte un marqueur du pack de Codex : un anneau, cliquable et
     * focusable, pose sur son point — celui ou elle est posee, ou la position que son segment lui
     * donne a chaque image. Le vaisseau blanc de la couche des flottes reste dessous : l'anneau dit
     * « c'est une patrouille, elle se commande », le vaisseau dit ou elle va.
     *
     * Tout ce que le marqueur et la fiche montrent vient du serveur : l'etat, la reserve de
     * maintenant, l'echeance du retour de securite, les commandes avec leur raison. Rien n'est
     * calcule ici — pas un cout, pas une autorisation, pas une distance. Un ordre est un devis du
     * serveur, puis une confirmation qui rapporte la version du devis ; le serveur refuse un devis
     * perime au lieu de debiter autre chose (revue 121).
     *
     * **Le marqueur se saisit a la souris** (`draggable`, plus bas) : glisser designe une
     * destination, et rien de plus — le serveur chiffre, le joueur confirme, la flotte voyage. Le
     * clic fait la meme chose pour le tactile, et les champs X et Y pour le clavier. Ce
     * commentaire affirmait l inverse, et decrivait une version anterieure du geste.
     */
    var ICONES_DE_PATROUILLE = {
        stationed: 'patrol-station.svg',
        /*
         * **Un etat n est pas une action.** `patrol-move.svg` est la croix a quatre fleches, celle
         * que toutes les interfaces emploient pour dire « deplacer » — son propre `aria-label` le
         * dit. La poser sur une patrouille en vol la faisait lire comme une poignee collee sur le
         * marqueur, pas comme une flotte : Keven l a signalee au premier controle navigateur.
         * La croix reste l icone du bouton ; l etat a desormais son vaisseau.
         */
        en_route: 'patrol-flight.svg',
        returning: 'patrol-return.svg',
        attacking: 'patrol-locked.svg',
        immobilised: 'patrol-fuel.svg'
    };
    var VITESSE_DE_PATROUILLE = 10;
    var patrouilles = [];

    /*
     * Les contacts de surveillance, tels que le serveur les a rendus a la derniere reponse acceptee.
     *
     * **Remplacee en bloc, jamais fusionnee.** Une fusion garderait un contact que le serveur ne
     * renvoie plus — c est-a-dire exactement ce qu une perte de couverture doit retirer. Une liste
     * vide est donc une information : « plus rien », et non « rien de neuf ».
     */
    var contactsDeSurveillance = [];

    function coucheDesPatrouilles(carte) {
        var couche = carte.querySelector('.gtPatrolLayer');

        if (couche) {
            return couche;
        }

        couche = element('div', 'gtPatrolLayer');
        couche.setAttribute('role', 'group');
        couche.setAttribute('aria-label', locaFiche('patrolLayer', 'Patrouilles'));
        carte.appendChild(couche);

        return couche;
    }

    function coucheDeSurveillance(carte) {
        var couche = carte.querySelector('.gtSurveillanceLayer');

        if (couche) {
            return couche;
        }

        couche = element('div', 'gtSurveillanceLayer');
        couche.setAttribute('role', 'group');
        couche.setAttribute('aria-label', locaFiche('surveillanceLayer', 'Contacts de surveillance'));
        carte.appendChild(couche);

        return couche;
    }

    /*
     * Ce qu un contact permet de dire, et rien de plus.
     *
     * Le serveur **omet** les faits qu il n autorise pas : leur clef est absente, pas vide. Le
     * navigateur teste donc la presence de la clef et ne fabrique aucune valeur de remplacement —
     * inventer « proprietaire inconnu » la ou le serveur s est tu reviendrait a affirmer qu il y a
     * un proprietaire a connaitre.
     */
    function intituleDuContact(contact) {
        var morceaux = [locaFiche('surveillanceContact', 'Contact')];

        if (contact.owner && contact.owner.name) {
            morceaux.push(contact.owner.name);
        }

        if (contact.heading) {
            morceaux.push(contact.heading.moving
                ? (contact.heading.leaves_system
                    ? locaFiche('surveillanceLeaving', 'quitte le systeme')
                    : locaFiche('surveillanceMoving', 'en deplacement'))
                : locaFiche('surveillanceStationed', 'stationnee'));
        }

        if (typeof contact.strength === 'number') {
            morceaux.push(String(contact.strength));
        } else if (contact.size_estimate) {
            morceaux.push(contact.size_estimate.to === null
                ? String(contact.size_estimate.from) + '+'
                : String(contact.size_estimate.from) + '-' + String(contact.size_estimate.to));
        }

        return morceaux.join(' — ');
    }

    /*
     * La couche est reconstruite a chaque reponse acceptee : ce que le serveur ne renvoie plus
     * disparait de l ecran sans rechargement, et sans qu aucun code n ait a se souvenir de ce
     * qu il fallait retirer.
     */
    function dessinerLaSurveillance(carte) {
        var couche = coucheDeSurveillance(carte);

        couche.innerHTML = '';

        contactsDeSurveillance.forEach(function (contact) {
            if (!contact || !contact.position) {
                return;
            }

            var marqueur = element('div', 'gtSurveillanceContact');
            var intitule = intituleDuContact(contact);

            marqueur.setAttribute('data-contact-id', String(contact.contact_id));
            marqueur.setAttribute('data-tier', String(contact.tier));
            marqueur.setAttribute('aria-label', intitule);
            marqueur.title = intitule;
            marqueur.setAttribute('role', 'button');
            marqueur.setAttribute('tabindex', '0');
            marqueur.addEventListener('click', function (evenement) {
                evenement.stopPropagation();
                choisirLeContact(carte, contact, marqueur);
            });

            marqueur.style.left = String(contact.position.x) + 'px';
            marqueur.style.top = String(contact.position.y) + 'px';

            couche.appendChild(marqueur);
        });
    }

    /*
     * ## Attaquer un contact
     *
     * Le joueur clique un marqueur de detection, une fiche s'ouvre, et le bouton d'attaque envoie sa
     * flotte. Ce qui compte est ce que la requete **ne** porte **pas** : jamais l'identifiant de la
     * patrouille, seulement celui du contact.
     *
     * ### Pourquoi le contact et pas la patrouille
     *
     * Un identifiant de patrouille suivrait sa cible d'un systeme a l'autre et d'une couverture a la
     * suivante. Un joueur qui l'aurait vu une fois pourrait viser ce qu'il ne detecte plus — et
     * apprendre qu'une patrouille existe encore rien qu'en essayant de l'attaquer. La clef du
     * contact, elle, nait avec l'acquisition et meurt avec elle : hors couverture, elle ne vaut rien.
     *
     * Le serveur resout donc le contact pour ce joueur-la, et la patrouille reste invisible du
     * navigateur d'un bout a l'autre.
     *
     * ### La composition vient de la meme liste que les patrouilles
     *
     * Aucune seconde interface de selection : `galaxyPatrolShips` porte deja les vaisseaux du corps
     * actif, et les champs `am<id>` sont ceux de la page Flotte. Deux compositions differentes pour
     * deux departs auraient diverge.
     */
    function choisirLeContact(carte, contact, marqueur) {
        var f = fiche(carte);

        /* Recliquer le contact ouvert le referme. */
        if (!f.hidden && f.gtContact && Number(f.gtContact.contact_id) === Number(contact.contact_id)) {
            deselectionner(carte, true);

            return;
        }

        deselectionner(carte, false);

        var titre = f.querySelector('.gtCardTitle');
        var coords = f.querySelector('.gtCardCoords');
        var contenant = f.querySelector('.gtCardBody');

        f.setAttribute('data-corps', 'contact');
        f.classList.add('gtCard--contact');

        if (coords && contact.position) {
            coords.textContent = '[' + contact.position.galaxy + ':' + contact.position.system + ']'
                + ' · X ' + contact.position.x + ' · Y ' + contact.position.y;
        }

        contenant.innerHTML = '';

        /*
         * **Ce que le palier ne revele pas n'est pas affiche du tout.** Une ligne vide apprendrait
         * qu'il y a quelque chose a savoir : c'est la regle de tout le chantier de surveillance, et
         * elle vaut ici comme dans la charge utile.
         */
        if (contact.owner && contact.owner.name) {
            var proprietaire = element('div', 'gtCardNote');
            proprietaire.textContent = locaFiche('surveillanceOwner', 'Proprietaire') + ' : ' + contact.owner.name;
            contenant.appendChild(proprietaire);
        }

        var etat = element('div', 'gtCardNote');
        etat.textContent = intituleDuContact(contact);
        contenant.appendChild(etat);

        contenant.appendChild(compositionDAttaque(carte, contact));

        if (titre) {
            titre.textContent = locaFiche('surveillanceContact', 'Contact');
        }

        f.gtContact = contact;
        f.gtPatrouille = null;
        f.hidden = false;
        f.gtBloc = marqueur || null;

        if (marqueur) {
            marqueur.classList.add('gtSelected');
            placer(f, marqueur);
        }
    }

    /*
     * La boite de composition et le bouton d'envoi.
     *
     * Le bouton est desarme pendant le vol de la requete : une double soumission enverrait deux
     * flottes, et la seconde partirait sur une cible que la premiere a peut-etre deja detruite.
     */
    function compositionDAttaque(carte, contact) {
        var boite = element('div', 'gtCardActions');
        var champs = element('div', 'gtContactShips');
        var disponibles = typeof galaxyPatrolShips !== 'undefined' ? galaxyPatrolShips : [];

        disponibles.forEach(function (vaisseau) {
            if (!vaisseau || !vaisseau.amount) {
                return;
            }

            var ligne = element('label', 'gtContactShipRow');
            var nom = element('span', 'gtContactShipName');
            nom.textContent = vaisseau.title + ' (' + vaisseau.amount + ')';

            var champ = document.createElement('input');
            champ.type = 'number';
            champ.min = '0';
            champ.max = String(vaisseau.amount);
            champ.value = '0';
            champ.className = 'gtContactShipInput';
            champ.setAttribute('data-ship-id', String(vaisseau.id));

            ligne.appendChild(nom);
            ligne.appendChild(champ);
            champs.appendChild(ligne);
        });

        boite.appendChild(champs);

        var bouton = element('button', 'gtAction gtAction--attack');
        bouton.type = 'button';
        bouton.textContent = locaFiche('surveillanceAttack', 'Attaquer');

        var raison = element('div', 'gtActionReason');
        raison.hidden = true;

        bouton.addEventListener('click', function () {
            var corps = { contact_id: contact.contact_id };
            var total = 0;

            champs.querySelectorAll('.gtContactShipInput').forEach(function (champ) {
                var nombre = Math.max(0, parseInt(champ.value, 10) || 0);

                if (nombre > 0) {
                    corps['am' + champ.getAttribute('data-ship-id')] = nombre;
                    total += nombre;
                }
            });

            if (total === 0) {
                raison.hidden = false;
                raison.textContent = locaFiche('surveillanceNoShips', 'Choisissez au moins un vaisseau.');

                return;
            }

            if (!window.jQuery || typeof galaxyPatrolAttackUrl === 'undefined') {
                return;
            }

            bouton.disabled = true;
            raison.hidden = true;

            window.jQuery.post(galaxyPatrolAttackUrl, corps, null, 'json')
                .done(function () {
                    /*
                     * La flotte est partie : la fiche se ferme, et le joueur lit sa confirmation la
                     * ou le jeu annonce deja tous ses envois.
                     */
                    deselectionner(carte, true);

                    if (typeof window.fadeBox === 'function') {
                        window.fadeBox(locaFiche('surveillanceSent', 'Flotte envoyee.'), false);
                    }
                })
                .fail(function (reponse) {
                    bouton.disabled = false;
                    raison.hidden = false;
                    raison.textContent = raisonDeLaReponse(reponse)
                        || locaFiche('surveillanceRefused', 'Cette attaque a ete refusee.');
                });
        });

        boite.appendChild(bouton);
        boite.appendChild(raison);

        return boite;
    }

    /*
     * Le droit du joueur redevient inconnu : on masque, on ne conserve pas.
     *
     * **Remplacer a reception ne suffit pas.** Si la reponse tarde, ce qui vient de devenir
     * interdit resterait a l ecran pendant tout le vol de la requete. La couche des flottes n est
     * pas interrogee en boucle : elle se recharge quand quelque chose a change — un ordre, un
     * evenement du serveur, un changement de systeme, un onglet qui revient. Chaque rechargement
     * signifie donc que le droit peut avoir change, et l inconnu ne s affiche pas.
     *
     * Ce n est volontairement pas une peremption a l horloge : sans interrogation periodique, une
     * peremption effacerait des contacts parfaitement valides pendant une periode calme.
     */
    function invaliderLaSurveillance(carte) {
        /*
         * **Une invalidation perime aussi ce qui est en vol.** Masquer l ecran ne suffit pas : une
         * demande partie avant la revocation reviendrait avec des renseignements auxquels le joueur
         * n a plus droit, et les reafficherait. La generation change donc ici aussi, et cette
         * reponse-la sera jetee — meme si aucune demande plus recente n a ete lancee.
         */
        generationDuContexte++;
        contactsDeSurveillance = [];
        dessinerLaSurveillance(carte);
    }

    function adresseDePatrouille(modele, id) {
        return String(modele).replace('/patrol/0/', '/patrol/' + Number(id) + '/');
    }

    /* Une duree en h:mm:ss — un format que toute langue lit, sans mot a traduire. */
    function dureeLisible(secondes) {
        var s = Math.max(0, Math.floor(Number(secondes) || 0));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var r = s % 60;

        return h + ':' + (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r;
    }

    function dessinerLesPatrouilles(carte, galaxie, systeme) {
        var couche = coucheDesPatrouilles(carte);
        var aRouvrir = carte.gtPatrouilleARestaurer;

        carte.gtPatrouilleARestaurer = null;
        couche.innerHTML = '';

        patrouilles.forEach(function (p) {
            var b = element('button', 'patrol-marker gtPatrolMarker gtPatrol--' + String(p.state || ''));
            var icone = element('img', '');
            var intitule = locaFiche('patrolTitle', 'Patrouille') + ' ' + p.id + ' — ' + (p.state_label || p.state);

            b.type = 'button';
            b.setAttribute('data-patrol-id', String(p.id));
            b.setAttribute('aria-label', intitule);
            b.title = intitule;
            icone.src = '/img/galaxy-tactical/' + (ICONES_DE_PATROUILLE[p.state] || 'patrol-patrol.svg');
            icone.alt = '';
            icone.setAttribute('aria-hidden', 'true');
            b.appendChild(icone);

            b.addEventListener('click', function (evenement) {
                evenement.preventDefault();
                evenement.stopPropagation();

                /* En choix de destination, une patrouille n'est pas une cible : le clic ne fait rien. */
                if (!carte.gtChoix) {
                    choisirLaPatrouille(carte, p);
                }
            });

            /*
             * ## Prendre la flotte a la souris et la poser ou on veut l'envoyer
             *
             * Le geste demande par Keven : on saisit la patrouille, on la glisse, et en relachant un
             * panneau montre le trajet, sa duree et son cout ; on confirme, et **elle voyage
             * vraiment** jusque-la avant d'y stationner. Elle ne se teleporte pas : le relachement
             * ne fait que designer une destination, et c'est le serveur qui chiffre puis execute.
             *
             * Le glisser n'a aucun pouvoir propre : il ouvre exactement l'ordre que le bouton
             * « Deplacer » ouvre, et il est refuse pour les memes raisons — une patrouille engagee
             * dans un combat ou dont le segment se pose avant la fin du delai ne se saisit pas, et
             * la fiche dit pourquoi. Les champs X et Y restent l'alternative au clavier ; le clic
             * reste celle du tactile, ou le glisser HTML5 n'existe pas.
             */
            b.draggable = true;

            b.addEventListener('dragstart', function (evenement) {
                var commande = (p.commands && p.commands.move) || {};

                if (!commande.allowed) {
                    evenement.preventDefault();

                    /* Refuse : la fiche s'ouvre quand meme, et le bouton grise porte la raison. */
                    choisirLaPatrouille(carte, p, true);

                    return;
                }

                if (evenement.dataTransfer) {
                    evenement.dataTransfer.effectAllowed = 'move';

                    try {
                        evenement.dataTransfer.setData('text/plain', 'patrol:' + p.id);
                    } catch (e) {
                        /* Certains navigateurs refusent setData hors interaction : sans effet ici. */
                    }
                }

                choisirLaPatrouille(carte, p, true);
                commencerUnDeplacement(carte, fiche(carte), p);

                /*
                 * La fiche vient de s'ouvrir sur le marqueur et recouvre une partie du systeme.
                 * Elle s'efface le temps du geste : la feuille lui retire le pointeur et presque
                 * toute son opacite, donc la destination cachee redevient visible et atteignable.
                 */
                carte.classList.add('gtDragging');
            });

            couche.appendChild(b);
            p._marqueur = b;
            p._bouts = p.segment ? extremites(p.segment, galaxie, systeme) : null;
        });

        placerLesPatrouilles();

        /* La fiche d'une patrouille ouverte suit sa patrouille d'un chargement a l'autre. */
        var f = carte.querySelector('.gtCard');
        var ouverte = f && !f.hidden && f.gtPatrouille && !f.gtOrdre ? Number(f.gtPatrouille.id) : aRouvrir;

        if (ouverte !== null && ouverte !== undefined) {
            patrouilles.forEach(function (p) {
                if (Number(p.id) === ouverte) {
                    choisirLaPatrouille(carte, p, true);
                }
            });
        }

        /*
         * **Un ordre en cours garde sa fiche telle quelle, mais son marqueur est neuf.** La couche
         * vient d'etre reconstruite : le marqueur que la fiche tenait n'est plus dans le document,
         * et `placer()` sur un noeud detache l'enverrait en haut a gauche. La fiche se rattache au
         * marqueur neuf de la meme patrouille, sans etre recomposee — recomposer effacerait l'ordre.
         */
        if (f && !f.hidden && f.gtPatrouille && f.gtOrdre) {
            patrouilles.forEach(function (p) {
                if (Number(p.id) === Number(f.gtPatrouille.id) && p._marqueur) {
                    f.gtBloc = p._marqueur;
                    p._marqueur.classList.add('gtSelected');
                    placer(f, p._marqueur);
                }
            });
        }
    }

    /* La position d'un marqueur de patrouille : son point si elle est posee, sinon celle de son segment. */
    function pointDePatrouille(p, maintenant) {
        if (p.point) {
            return { point: pointSpatial(Number(p.point.x), Number(p.point.y)), enTransit: false };
        }

        if (!p.segment || !p._bouts) {
            return null;
        }

        var duree = Math.max(1, p.segment.time_arrival - p.segment.time_departure);
        var global = Math.min(1, Math.max(0, (maintenant - p.segment.time_departure) / duree));
        var local = progressionLocale(global, p._bouts);

        return {
            point: {
                x: p._bouts.depart.x + (p._bouts.arrivee.x - p._bouts.depart.x) * local.avancement,
                y: p._bouts.depart.y + (p._bouts.arrivee.y - p._bouts.depart.y) * local.avancement
            },
            enTransit: local.enTransit
        };
    }

    function placerLesPatrouilles() {
        var maintenant = maintenantServeur() / 1000;

        patrouilles.forEach(function (p) {
            if (!p._marqueur) {
                return;
            }

            var ou = pointDePatrouille(p, maintenant);

            if (!ou) {
                p._marqueur.hidden = true;

                return;
            }

            p._marqueur.hidden = ou.enTransit;
            p._marqueur.style.left = ou.point.x.toFixed(1) + 'px';
            p._marqueur.style.top = ou.point.y.toFixed(1) + 'px';

            /* Le compte a rebours du retour de securite, sur la fiche ouverte, a la seconde. */
            if (p._compte) {
                var reste = dureeLisible(Number(p.safety_return_at) - maintenant);

                if (p._compte.textContent !== reste) {
                    p._compte.textContent = reste;
                }
            }
        });

        var d = document.querySelector('#galaxyTactical .patrol-destination');

        if (d && d.gtDestination) {
            var pd = pointDeDestination(d.gtDestination);
            d.style.left = pd.x.toFixed(1) + 'px';
            d.style.top = pd.y.toFixed(1) + 'px';
        }
    }

    /*
     * ## La fiche d'une patrouille
     *
     * Le meme cadre que les fiches des corps, sans ligne de tableau a deplacer : etat, base,
     * reserve de maintenant, consommation horaire, cout et echeance du retour de securite,
     * composition, et la jauge du pack — la part de la reserve encore libre avant le retour de
     * securite. Puis la grille des actions, grisees par le serveur avec sa raison.
     */
    function ligneDeStat(dl, intitule, valeur) {
        var dt = element('dt', '');
        var dd = element('dd', '');

        dt.textContent = intitule;
        dd.textContent = valeur;
        dl.appendChild(dt);
        dl.appendChild(dd);

        return dd;
    }

    function composerLaFichePatrouille(carte, f, p) {
        var pageLoca = window.loca || {};
        var deut = pageLoca.LOCA_ALL_DEUTERIUM || 'D';
        var avant = [];
        var apres = [];

        avant.push(imageDeFiche('/img/galaxy-tactical/patrol-patrol.svg', 'gtCardArt patrol-art'));

        var nature = element('div', 'gtCardKind');
        nature.textContent = avecDetail(
            p.state_label || p.state,
            p.home ? locaFiche('patrolHome', 'Base') + ' [' + p.home.galaxy + ':' + p.home.system + ':' + p.home.position + ']' : ''
        );
        avant.push(nature);

        var resume = element('div', 'patrol-summary');
        var stats = element('dl', 'patrol-stats');

        ligneDeStat(stats, locaFiche('patrolReserve', 'Reserve'), nombre(p.fuel_reserve) + ' ' + deut);
        ligneDeStat(stats, locaFiche('patrolUpkeep', 'Consommation'), nombre(p.upkeep_per_hour) + ' ' + deut + '/h');
        ligneDeStat(stats, locaFiche('patrolSafetyReturn', 'Retour de securite'), nombre(p.safety_return_cost) + ' ' + deut);

        p._compte = null;

        if (p.safety_return_at) {
            p._compte = ligneDeStat(stats, locaFiche('patrolSafetyReturnAt', 'Depart du retour dans'), dureeLisible(Number(p.safety_return_at) - maintenantServeur() / 1000));
            p._compte.setAttribute('data-patrol-deadline', String(p.safety_return_at));
        } else if (p.state === 'stationed') {
            ligneDeStat(stats, locaFiche('patrolSafetyReturnAt', 'Depart du retour dans'), locaFiche('patrolNoEnd', 'sans terme'));
        }

        ligneDeStat(stats, locaFiche('patrolShips', 'Vaisseaux'), (p.units || []).map(function (u) {
            return nombre(u.amount) + ' \u00d7 ' + u.label;
        }).join(', '));

        resume.appendChild(stats);

        /* La jauge : ce qui reste au-dessus du cout du retour, rapporte a la reserve. Ses deux nombres sont ceux du serveur. */
        var reserve = Number(p.fuel_reserve) || 0;
        var part = reserve > 0 ? Math.max(0, Math.min(1, (reserve - (Number(p.safety_return_cost) || 0)) / reserve)) : 0;
        var jauge = element('div', 'patrol-gauge');
        var niveau = element('span', '');

        jauge.setAttribute('role', 'img');
        jauge.setAttribute('aria-label', locaFiche('patrolGauge', 'Reserve disponible') + ' ' + Math.round(part * 100) + ' %');
        niveau.style.width = Math.round(part * 100) + '%';
        jauge.appendChild(niveau);
        resume.appendChild(jauge);
        avant.push(resume);

        var grille = element('div', 'gtCardActions');
        grille.setAttribute('role', 'group');
        grille.setAttribute('aria-label', locaFiche('actions', 'Actions'));
        apres.push(grille);

        return { avant: avant, apres: apres, titre: locaFiche('patrolTitle', 'Patrouille') + ' ' + p.id };
    }

    function choisirLaPatrouille(carte, p, sansBasculer) {
        var f = fiche(carte);

        /* Recliquer la patrouille ouverte la referme. */
        if (!sansBasculer && !f.hidden && f.gtPatrouille && Number(f.gtPatrouille.id) === Number(p.id)) {
            deselectionner(carte, true);

            return;
        }

        deselectionner(carte, false);

        var titre = f.querySelector('.gtCardTitle');
        var coords = f.querySelector('.gtCardCoords');
        var contenant = f.querySelector('.gtCardBody');

        f.setAttribute('data-corps', 'patrol');

        if (coords) {
            coords.textContent = '[' + p.galaxy + ':' + p.system + ']' + (p.point ? ' \u00b7 X ' + p.point.x + ' \u00b7 Y ' + p.point.y : '');
        }

        contenant.innerHTML = '';

        var composition = composerLaFichePatrouille(carte, f, p);

        composition.avant.forEach(function (n) {
            contenant.appendChild(n);
        });
        composition.apres.forEach(function (n) {
            contenant.appendChild(n);
        });

        if (titre) {
            titre.textContent = composition.titre;
        }

        f.gtPatrouille = p;
        f.gtContexte = { carte: carte, fiche: f, ligne: {}, objet: null, systeme: carte.gtSystemeJson || {}, position: null, genre: 'patrouille', patrouille: p };
        composerLesActions(f);

        f.hidden = false;
        f.gtBloc = p._marqueur || null;

        if (p._marqueur) {
            p._marqueur.classList.add('gtSelected');
            placer(f, p._marqueur);

            return;
        }

        /*
         * Sans marqueur — une patrouille d'un autre systeme, dont l'ordre est en cours — la fiche
         * n'a rien a quoi se coller : elle se pose en haut a gauche, dans la carte.
         */
        f.style.left = FICHE_MARGE + 'px';
        f.style.top = FICHE_MARGE + 'px';
    }

    /* Une commande de patrouille : permise ou refusee par le serveur, avec sa raison telle quelle. */
    function decisionDeCommande(f, p, nom, executer, classe) {
        if (!p) {
            return inactif(locaFiche('reasons.unavailable', ''));
        }

        var commande = (p.commands && p.commands[nom]) || {};

        if (!commande.allowed) {
            return inactif(commande.reason || locaFiche('reasons.unavailable', ''));
        }

        if (f.gtOrdre) {
            return inactif(locaFiche('patrolOrderPending', ''));
        }

        return actif(executer, classe);
    }

    /*
     * ## Un ordre, en trois etapes
     *
     *   flotte      — lancement seulement : la flotte standard et la reserve a embarquer ;
     *   destination — un clic sur la carte ou un corps, ou X et Y saisis, puis le devis ;
     *   devis       — ce que le serveur a chiffre : destination, duree, cout, reserve a l'arrivee,
     *                 retour de securite, autonomie ; Confirmer rapporte la version du devis.
     *
     * L'ordre vit sur la fiche (`f.gtOrdre`) ; la carte sait seulement qu'un choix est en cours
     * (`carte.gtChoix`) pour rediriger les clics. Fermer la fiche annule tout.
     */
    /*
     * Les vaisseaux de la planete active, tels que la vue les publie. Le nombre date du chargement
     * de la page : le serveur refuse (`not_enough_on_planet`) si la planete ne les porte plus, et
     * c'est lui qui a raison — une valeur affichee n'autorise rien.
     */
    /**
     * Le chantier des patrouilles est-il arme sur ce serveur ?
     *
     * **La carte n offre pas ce que le serveur refusera.** Le drapeau vient de la vue ; absent — une
     * page servie par un cache anterieur a ce changement —, on suppose **oui**, parce que le serveur
     * reste le decideur et qu une carte muette serait pire qu un refus lisible.
     */
    function patrouillesActives() {
        return typeof galaxyPatrolsEnabled === 'undefined' ? true : !!galaxyPatrolsEnabled;
    }

    /**
     * ## La carte dit ce qu on peut y faire
     *
     * Deux gestes, deux lignes, en bas a gauche. Keven l a demande en ces termes : « faut que le
     * joueur comprenne facilement comment le faire ». Une fonction qu on ne peut pas deviner
     * n existe pas — et ces deux-la ne s annoncaient nulle part.
     *
     * **Elle est transparente au pointeur, et ce n est pas un detail de style** : elle est posee sur
     * la surface meme ou le joueur doit cliquer pour lancer une patrouille. Sans
     * `pointer-events: none`, la legende volerait exactement les clics qu elle explique — dans le
     * coin qu elle occupe, la carte deviendrait morte.
     *
     * Elle ne parait que si le chantier est arme : annoncer un geste que le serveur refuse serait
     * pire que de ne rien dire.
     */
    function poserLaLegende(carte) {
        carte.classList.toggle('gtPatrolsOn', patrouillesActives());

        if (!patrouillesActives()) {
            return;
        }

        var bloc = element('div', 'gtHints');

        bloc.setAttribute('role', 'note');

        [
            ['patrol-patrol.svg', locaFiche('hintCompose', '')],
            ['patrol-move.svg', locaFiche('hintDrag', '')]
        ].forEach(function (paire) {
            if (!paire[1]) {
                return;
            }

            var ligne = element('span', 'gtHint');
            var icone = element('img', '');

            icone.src = '/img/galaxy-tactical/' + paire[0];
            icone.alt = '';
            icone.setAttribute('aria-hidden', 'true');

            var texte = element('span', '');
            texte.textContent = paire[1];

            ligne.appendChild(icone);
            ligne.appendChild(texte);
            bloc.appendChild(ligne);
        });

        if (bloc.childNodes.length > 0) {
            carte.appendChild(bloc);
        }
    }

    function vaisseauxDeLaPlanete() {
        return typeof galaxyPatrolShips !== 'undefined' && Array.isArray(galaxyPatrolShips) ? galaxyPatrolShips : [];
    }

    function vaisseauParId(flotte, id) {
        var trouve = null;

        flotte.forEach(function (v) {
            if (Number(v.id) === id) {
                trouve = v;
            }
        });

        return trouve;
    }

    function panneauDOrdre(f) {
        var panneau = f.querySelector('.patrol-order');

        if (!panneau) {
            panneau = element('div', 'patrol-order');
            panneau.setAttribute('role', 'group');
            var grille = f.querySelector('.gtCardActions');
            var contenant = f.querySelector('.gtCardBody');

            if (grille && contenant) {
                contenant.insertBefore(panneau, grille);
            } else if (contenant) {
                contenant.appendChild(panneau);
            }
        }

        return panneau;
    }

    function noteDOrdre(f, texte, erreur) {
        var panneau = panneauDOrdre(f);
        var note = panneau.querySelector('.patrol-note');

        if (!note) {
            note = element('p', 'patrol-note');
            note.setAttribute('role', 'status');
            panneau.appendChild(note);
        }

        note.textContent = texte || '';
        note.classList.toggle('patrol-error', !!erreur);
    }

    function champNumerique(intitule, nom, valeur, pas) {
        var etiquette = element('label', 'patrol-field');
        var texte = element('span', '');
        var champ = element('input', 'patrol-input');

        texte.textContent = intitule;
        champ.type = 'number';
        champ.name = nom;
        champ.step = String(pas);
        champ.value = valeur === null || valeur === undefined ? '' : String(valeur);
        etiquette.appendChild(texte);
        etiquette.appendChild(champ);

        return { etiquette: etiquette, champ: champ };
    }

    function boutonDePanneau(intitule, clef, executer) {
        var b = element('button', 'gtAction patrol-button');
        var icone = element('img', '');
        var libelle = element('span', 'gtActionLabel');

        b.type = 'button';
        icone.src = '/img/galaxy-tactical/' + ICONES_D_ACTION[clef];
        icone.alt = '';
        icone.setAttribute('aria-hidden', 'true');
        libelle.textContent = intitule;
        b.appendChild(icone);
        b.appendChild(libelle);
        b.addEventListener('click', function (evenement) {
            evenement.preventDefault();
            executer();
        });

        return b;
    }

    function composerLePanneau(carte, f) {
        var o = f.gtOrdre;
        var panneau = panneauDOrdre(f);
        var pageLoca = window.loca || {};
        var deut = pageLoca.LOCA_ALL_DEUTERIUM || 'D';

        panneau.innerHTML = '';

        if (!o) {
            panneau.remove();

            return;
        }

        if (o.etape === 'flotte') {
            /*
             * ## La composition, vaisseau par vaisseau
             *
             * **Aucun officier n'est demande pour patrouiller** (decision de Keven, 8 septembre
             * 2026) : la carte est l'interface centrale du systeme. La flotte standard reste ce
             * qu'elle a toujours ete, un raccourci reserve a l'Amiral ; sans lui, le joueur compose
             * ici comme il le fait sur la page Flotte. Choisir un modele **remplit** la composition,
             * il ne la remplace pas — et elle reste modifiable ensuite.
             *
             * Les vaisseaux et leur nombre viennent du serveur (`galaxyPatrolShips`), le fait « ce
             * vaisseau ne peut pas voler » compris : un satellite solaire a une vitesse nulle, et
             * c'est le joueur qui la determine. La carte grise, elle ne decide pas.
             */
            var flotte = vaisseauxDeLaPlanete();

            if (flotte.length === 0) {
                noteDOrdre(f, locaFiche('patrolNoShips', ''), true);
                panneau.appendChild(boutonDePanneau(locaFiche('labels.annuler', 'Annuler'), 'annuler', function () {
                    annulerLOrdre(carte, f);
                }));

                return;
            }

            /*
             * Le raccourci « flotte standard » garde la restriction qui a toujours ete la sienne :
             * l employer depuis la Galaxie demande un Amiral, comme pour l expedition (decision de
             * Keven, 8 septembre 2026 : « aucun Amiral requis pour creer ou commander une patrouille
             * […] les autres avantages existants de l Amiral restent inchanges »). La composition a
             * la main, elle, est ouverte a tous — c est elle qui rend la carte utilisable sans lui.
             */
            if ((o.modeles || []).length > 0 && (carte.gtSystemeJson || {}).hasAdmiral) {
                var liste = element('select', 'gtSelect');
                var vide = document.createElement('option');

                vide.value = '';
                vide.textContent = locaFiche('patrolTemplate', '');
                liste.appendChild(vide);
                liste.setAttribute('aria-label', locaFiche('patrolFleet', 'Flotte standard'));
                o.modeles.forEach(function (m) {
                    var option = document.createElement('option');
                    option.value = String(m.id);
                    option.textContent = String(m.name);
                    liste.appendChild(option);
                });
                liste.addEventListener('change', function () {
                    o.modeles.forEach(function (m) {
                        if (String(m.id) !== liste.value) {
                            return;
                        }

                        /* Le modele remplit ce que la planete porte vraiment, jamais plus. */
                        o.composition = {};
                        Object.keys(m.ships || {}).forEach(function (id) {
                            var v = vaisseauParId(flotte, Number(id));

                            if (v && v.mobile) {
                                o.composition[v.id] = Math.max(0, Math.min(v.amount, Number(m.ships[id]) || 0));
                            }
                        });
                    });
                    composerLePanneau(carte, f);
                });
                panneau.appendChild(liste);
            }

            var titre = element('p', 'patrol-note');
            titre.textContent = locaFiche('patrolCompose', '');
            panneau.appendChild(titre);

            flotte.forEach(function (v) {
                var ligne = champNumerique(v.label + ' (' + nombre(v.amount) + ')', 'am' + v.id, o.composition[v.id] || '', 1);

                ligne.champ.min = '0';
                ligne.champ.max = String(v.amount);
                ligne.champ.disabled = !v.mobile;

                if (!v.mobile) {
                    ligne.etiquette.title = locaFiche('patrolImmobile', '');
                    ligne.etiquette.classList.add('patrol-immobile');
                } else {
                    ligne.champ.addEventListener('change', function () {
                        var voulu = Math.max(0, Math.min(v.amount, Math.floor(Number(ligne.champ.value) || 0)));

                        if (voulu > 0) {
                            o.composition[v.id] = voulu;
                        } else {
                            delete o.composition[v.id];
                        }

                        ligne.champ.value = voulu > 0 ? String(voulu) : '';
                        composerLesActions(f);
                        majDuBoutonSuivant();
                    });
                }

                panneau.appendChild(ligne.etiquette);
            });

            var reserve = champNumerique(locaFiche('patrolReserveInput', 'Reserve de deuterium'), 'reserve', o.reserve, 100);
            reserve.champ.min = '0';
            reserve.champ.addEventListener('change', function () {
                o.reserve = Math.max(0, Math.floor(Number(reserve.champ.value) || 0));
            });
            panneau.appendChild(reserve.etiquette);

            /*
             * **Quand la destination est deja designee, il n y a plus rien a choisir.** Le bouton
             * demande le devis directement : proposer « choisir la destination » apres un clic qui
             * l a designee ferait refaire au joueur le geste qu il vient de faire.
             */
            var imposee = o.destinationImposee || null;

            var suivant = boutonDePanneau(
                imposee ? locaFiche('patrolQuote', 'Devis') : locaFiche('patrolChooseDestination', 'Choisir la destination'),
                imposee ? 'confirmer' : 'deplacer',
                function () {
                    o.reserve = Math.max(0, Math.floor(Number(reserve.champ.value) || 0));

                    if (imposee) {
                        choisirLaDestination(carte, imposee);

                        return;
                    }

                    commencerLeChoixDeDestination(carte, f);
                }
            );

            var majDuBoutonSuivant = function () {
                suivant.disabled = Object.keys(o.composition).length === 0;
                noteDOrdre(f, suivant.disabled ? locaFiche('reasons.patrolNoFleet', '') : '', false);
            };

            panneau.appendChild(suivant);
            majDuBoutonSuivant();

            return;
        }

        /*
         * ## Le corps designe, et ce qu'on peut y faire
         *
         * **Deposer n'execute rien** : cette etape propose, avec un bouton par ordre possible, et
         * chacun repart par le chemin ordinaire — devis du serveur, puis confirmation. Aucun
         * raccourci, aucune action directe.
         *
         * « Atterrir » n'apparait que si le corps est au joueur **et** qu'il y a une patrouille a
         * poser : un lancement compose une flotte qui n'existe pas encore, il n'a rien a faire
         * atterrir. Le serveur refuse de toute facon un corps qui n'est pas le sien — c'est la
         * garde qui compte —, mais offrir un bouton qui echoue a coup sur serait mentir.
         */
        if (o.etape === 'choix' && o.choix) {
            var choix = o.choix;

            panneau.appendChild(boutonDePanneau(locaFiche('patrolStationNear', 'Stationner a cote'), 'deplacer', function () {
                o.genre = o.genre === 'land' ? 'move' : o.genre;
                choisirLaDestination(carte, choix.destination);
            }));

            if (choix.mienne && choix.bodyId && o.patrouille) {
                panneau.appendChild(boutonDePanneau(locaFiche('patrolLand', 'Atterrir'), 'rappeler', function () {
                    o.genre = 'land';
                    o.corpsVise = choix.bodyId;
                    choisirLaDestination(carte, choix.destination);
                }));
            }

            noteDOrdre(f, choix.nom
                ? locaFiche('patrolBodyChoice', '') + ' ' + choix.nom
                : locaFiche('patrolBodyChoice', ''), false);

            return;
        }

        if (o.etape === 'destination') {
            var x = champNumerique('X', 'x', o.destination && o.destination.x !== undefined ? o.destination.x : '', PATROUILLE_GRILLE);
            var y = champNumerique('Y', 'y', o.destination && o.destination.y !== undefined ? o.destination.y : '', PATROUILLE_GRILLE);
            var demander = boutonDePanneau(locaFiche('patrolQuote', 'Devis'), 'confirmer', function () {
                var s = carte.gtSysteme || {};

                choisirLaDestination(carte, {
                    galaxy: s.galaxie,
                    system: s.systeme,
                    x: Math.round(Number(x.champ.value) / PATROUILLE_GRILLE) * PATROUILLE_GRILLE,
                    y: Math.round(Number(y.champ.value) / PATROUILLE_GRILLE) * PATROUILLE_GRILLE
                });
            });

            panneau.appendChild(x.etiquette);
            panneau.appendChild(y.etiquette);
            panneau.appendChild(demander);
            noteDOrdre(f, o.erreur || (o.genre === 'move'
                ? locaFiche('patrolDrag', '') + ' ' + locaFiche('patrolOtherSystem', '')
                : locaFiche('patrolChoose', '')), !!o.erreur);

            return;
        }

        if (o.etape === 'attente') {
            noteDOrdre(f, locaFiche('patrolQuoteWaiting', ''), false);

            return;
        }

        if (o.etape === 'devis' && o.devis) {
            var q = o.devis;
            var stats = element('dl', 'patrol-stats');
            var destination = q.destination || {};
            var cible = Number(destination.type) === TYPE_POINT_SPATIAL
                ? '[' + destination.galaxy + ':' + destination.system + '] \u00b7 X ' + destination.x + ' \u00b7 Y ' + destination.y
                : locaFiche('patrolNear', 'pres de') + ' [' + destination.galaxy + ':' + destination.system + ':' + destination.orbit + ']';

            ligneDeStat(stats, locaFiche('patrolDestination', 'Destination'), cible);
            ligneDeStat(stats, locaFiche('patrolDuration', 'Duree'), dureeLisible(q.duration_seconds));
            ligneDeStat(stats, locaFiche('patrolCost', 'Cout'), nombre(q.fuel_cost) + ' ' + deut);
            ligneDeStat(stats, locaFiche('patrolReserveOnArrival', 'Reserve a l\'arrivee'), nombre(q.reserve_on_arrival) + ' ' + deut);
            ligneDeStat(stats, locaFiche('patrolSafetyReturn', 'Retour de securite'), nombre(q.safety_return_cost) + ' ' + deut + ' \u00b7 ' + dureeLisible(q.safety_return_seconds));
            ligneDeStat(stats, locaFiche('patrolAutonomy', 'Autonomie'), q.autonomy_seconds === null || q.autonomy_seconds === undefined ? locaFiche('patrolNoEnd', 'sans terme') : dureeLisible(q.autonomy_seconds));
            panneau.appendChild(stats);
            noteDOrdre(f, q.possible ? (o.genre === 'recall' ? locaFiche('patrolRecallAsk', '') : '') : q.refusal_reason || '', !q.possible);
        }
    }

    function poserLOrdre(carte, f, ordre) {
        f.gtOrdre = ordre;
        composerLePanneau(carte, f);
        composerLesActions(f);

        if (f.gtBloc) {
            placer(f, f.gtBloc);
        }
    }

    function commencerUnDeplacement(carte, f, p) {
        poserLOrdre(carte, f, { genre: 'move', patrouille: p, etape: 'destination', destination: null, devis: null, erreur: null, enCours: false });
        commencerLeChoixDeDestination(carte, f);
    }

    /*
     * Le rappel ne compose rien : ni destination, ni vitesse.
     *
     * Il volait ici vers `p.home` — de simples coordonnees, dont le genre etait suppose planete —
     * et le devis etait chiffre a 100 %, alors que l'ordre confirme vole a la vitesse du retour de
     * securite vers la base que le serveur resout, planete **ou lune**. Le joueur lisait une duree
     * et un cout qui n'etaient pas les siens. La demande ne porte donc plus que `kind: 'recall'`.
     */
    function commencerUnRappel(carte, f, p) {
        poserLOrdre(carte, f, { genre: 'recall', patrouille: p, etape: 'attente', destination: null, devis: null, erreur: null, enCours: false });
        choisirLaDestination(carte, null);
    }

    /**
     * Ouvre la composition d une patrouille **vers un point deja designe**.
     *
     * La fiche n a aucun corps auquel se coller : elle se pose au point clique, comme celle d une
     * patrouille sans marqueur. Le marqueur de destination est pose tout de suite — le joueur voit
     * ou sa flotte ira pendant qu il la compose, au lieu de le decouvrir au devis.
     *
     * **Le depart reste la planete active.** C est la regle du lancement depuis toujours, et elle ne
     * change pas ici : ce clic choisit une destination, jamais une origine.
     */
    function composerUnePatrouilleVers(carte, destination) {
        if (!destination || typeof galaxyCurrentPlanetId === 'undefined') {
            return;
        }

        var f = fiche(carte);

        deselectionner(carte, false);

        var titre = f.querySelector('.gtCardTitle');
        var coords = f.querySelector('.gtCardCoords');
        var contenant = f.querySelector('.gtCardBody');
        var s = carte.gtSysteme || {};

        f.setAttribute('data-corps', 'patrol');

        if (coords) {
            coords.textContent = '[' + s.galaxie + ':' + s.systeme + '] \u00b7 X ' + destination.x + ' \u00b7 Y ' + destination.y;
        }

        if (titre) {
            titre.textContent = locaFiche('patrolNew', 'Nouvelle patrouille');
        }

        contenant.innerHTML = '';

        var grille = element('div', 'gtCardActions');
        grille.setAttribute('role', 'group');
        grille.setAttribute('aria-label', locaFiche('actions', 'Actions'));
        contenant.appendChild(grille);

        f.gtPatrouille = null;
        f.gtContexte = { carte: carte, fiche: f, ligne: {}, objet: null, systeme: carte.gtSystemeJson || {}, position: null, genre: 'patrouille', patrouille: null };
        f.hidden = false;
        f.gtBloc = null;

        commencerUnLancement(carte, f, { planetId: galaxyCurrentPlanetId }, destination);
        poserLeMarqueurDeDestination(carte, destination);

        /* Le meme convertisseur que le marqueur : la fiche se pose exactement dessus. */
        var p = pointDeDestination(destination);

        f.style.left = Math.round(p.x) + 'px';
        f.style.top = Math.round(p.y) + 'px';
    }

    function commencerUnLancement(carte, f, objet, destinationImposee) {
        poserLOrdre(carte, f, { genre: 'launch', planete: objet, etape: 'flotte', modeles: [], composition: {}, reserve: 0, destination: null, destinationImposee: destinationImposee || null, devis: null, erreur: null, enCours: false });

        chargerLesFlottesStandard(carte, function (modeles) {
            if (f.gtOrdre && f.gtOrdre.genre === 'launch') {
                f.gtOrdre.modeles = modeles;
                composerLePanneau(carte, f);
            }
        });
    }

    function commencerLeChoixDeDestination(carte, f) {
        var o = f.gtOrdre;

        if (!o) {
            return;
        }

        o.etape = 'destination';
        o.devis = null;
        carte.gtChoix = { fiche: f };
        carte.classList.add('gtChoosingDestination');
        composerLePanneau(carte, f);
        composerLesActions(f);
        replacerLaFiche(carte, f);
    }

    /*
     * La fiche se replace des que son contenu change de hauteur : un panneau qui grandit sous le
     * bord bas de la carte serait coupe, et le cahier des charges l'interdit. Sans marqueur — une
     * patrouille d'un autre systeme —, elle reste ou elle est.
     */
    function replacerLaFiche(carte, f) {
        if (f && f.gtBloc && carte.contains(f.gtBloc)) {
            placer(f, f.gtBloc);
        }
    }

    function finirLeChoixDeDestination(carte) {
        carte.gtChoix = null;
        carte.classList.remove('gtChoosingDestination');
    }

    function finirLOrdre(carte, f) {
        finirLeChoixDeDestination(carte);
        retirerLeMarqueurDeDestination(carte);

        if (f) {
            f.gtOrdre = null;

            var panneau = f.querySelector('.patrol-order');

            if (panneau) {
                panneau.remove();
            }
        }
    }

    function annulerLOrdre(carte, f) {
        finirLOrdre(carte, f);
        composerLesActions(f);

        if (f.gtBloc) {
            placer(f, f.gtBloc);
        }
    }

    /* Le corps clique en choix de destination : sa position et son genre ; le serveur retrouvera son identite. */
    /**
     * Ouvre le choix des ordres possibles sur un corps. Rend `true` si elle a pris la main.
     *
     * **Elle rend `false` pour une position libre**, et c'est la moitie importante : une orbite sans
     * corps n'offre aucun choix, et le depot doit alors continuer son chemin ordinaire vers le point
     * de l'espace. La distinguer par la presence du corps dans la ligne — et non par ce que
     * `destinationDuCorps()` a compose — evite de rejouer sa geometrie ici.
     */
    function proposerLesActionsDuCorps(carte, bloc, corps) {
        var f = carte.querySelector('.gtCard');
        var o = f ? f.gtOrdre : null;

        if (!o) {
            return false;
        }

        var position = Number(bloc.getAttribute('data-position'));
        var ligne = (carte.gtLignes || {})[position];
        var genre = corps === 'moon' ? corpsDeGenre(ligne, LUNE) : corpsDeGenre(ligne, PLANETE);

        if (!genre) {
            return false;
        }

        var destination = destinationDuCorps(carte, bloc, corps);

        if (!destination) {
            return false;
        }

        finirLeChoixDeDestination(carte);
        retirerLeMarqueurDeDestination(carte);

        o.choix = {
            destination: destination,
            bodyId: Number(genre.planetId) || null,
            mienne: estLaMienne(ligne, carte.gtSysteme),
            nom: genre.planetName || ''
        };
        o.destination = null;
        o.devis = null;
        o.erreur = null;
        o.etape = 'choix';

        composerLePanneau(carte, f);
        composerLesActions(f);
        replacerLaFiche(carte, f);

        return true;
    }

    function destinationDuCorps(carte, bloc, corps) {
        var s = carte.gtSysteme || {};
        var position = Number(bloc.getAttribute('data-position'));

        if (position === POSITION_ESPACE_PROFOND || position < 1) {
            return null;
        }

        var ligne = (carte.gtLignes || {})[position];
        var genre = corps === 'moon' ? corpsDeGenre(ligne, LUNE) : corpsDeGenre(ligne, PLANETE);

        if (!genre) {
            /* Une position libre : un point de l'espace a la place du corps, sur l'orbite. */
            var p = pointServeur(bloc.offsetLeft, bloc.offsetTop);

            return { galaxy: s.galaxie, system: s.systeme, x: p.x, y: p.y };
        }

        return { galaxy: s.galaxie, system: s.systeme, position: position, type: corps === 'moon' ? TYPE_LUNE : 1 };
    }

    /* Le point clique, en unites du serveur, arrondi a sa grille. La carte peut etre mise a l'echelle : les pixels sont ramenes a son repere. */
    function destinationDuClic(carte, evenement) {
        var s = carte.gtSysteme || {};
        var r = carte.getBoundingClientRect();
        var echelle = r.width > 0 ? LARGEUR / r.width : 1;
        var p = pointServeur((evenement.clientX - r.left) * echelle, (evenement.clientY - r.top) * echelle);

        return { galaxy: s.galaxie, system: s.systeme, x: p.x, y: p.y };
    }

    /*
     * Le point d'une destination a l'ecran, qu'elle vienne d'un clic (`x`/`y` seuls, ou
     * `position`/`type`) ou du devis du serveur (`orbit`, `type`, `x`, `y`).
     *
     * **Un corps se dessine sur le corps**, jamais sur le point de reference de son orbite : ce
     * point-la ne tourne pas avec lui, et le marqueur se poserait a cote de la planete.
     */
    function pointDeDestination(destination) {
        var genre = Number(destination.type);

        if (destination.type !== undefined && destination.type !== null && genre !== TYPE_POINT_SPATIAL) {
            return pointDeCorps(destination.position !== undefined ? destination.position : destination.orbit, genre);
        }

        return pointSpatial(Number(destination.x), Number(destination.y));
    }

    function poserLeMarqueurDeDestination(carte, destination) {
        retirerLeMarqueurDeDestination(carte);

        var couche = coucheDesPatrouilles(carte);
        var d = element('div', 'patrol-marker patrol-destination');
        var icone = element('img', '');
        var pd = pointDeDestination(destination);

        icone.src = '/img/galaxy-tactical/patrol-move.svg';
        icone.alt = '';
        d.setAttribute('aria-hidden', 'true');
        d.appendChild(icone);
        d.style.left = pd.x.toFixed(1) + 'px';
        d.style.top = pd.y.toFixed(1) + 'px';
        d.gtDestination = destination;
        couche.appendChild(d);
    }

    function retirerLeMarqueurDeDestination(carte) {
        var d = carte.querySelector('.patrol-destination');

        if (d) {
            d.remove();
        }
    }

    function chargeDeDestination(destination) {
        var charge = { galaxy: destination.galaxy, system: destination.system };

        if (destination.x !== undefined) {
            charge.x = destination.x;
            charge.y = destination.y;
        } else {
            charge.position = destination.position;
            charge.type = destination.type;
        }

        return charge;
    }

    /*
     * La charge d'un ordre.
     *
     * Un **rappel** ne porte que son genre : le serveur en compose la destination et la vitesse, et
     * la carte ne pourrait donc pas en decrire un autre que celui qu'il executera. Un **lancement**
     * nomme le corps d'ou il part, au lieu de laisser le serveur lire la planete courante de la
     * session — partagee par tous les onglets, elle pouvait avoir change entre le devis et la
     * confirmation, et un autre corps aurait ete debite.
     */
    function chargeDOrdre(o, destination) {
        var charge = { _token: jetonCsrf() };

        if (o.genre === 'recall') {
            charge.kind = 'recall';
            charge.patrol_id = o.patrouille.id;

            return charge;
        }

        /*
         * **Un atterrissage nomme son corps, et rien d autre.** Ni destination ni vitesse ne
         * voyagent : le serveur les compose depuis le corps, exactement comme pour un rappel. La
         * carte ne peut donc pas decrire un atterrissage que la confirmation n executerait pas.
         */
        if (o.genre === 'land') {
            charge.kind = 'land';
            charge.patrol_id = o.patrouille.id;
            charge.body_id = o.corpsVise;

            return charge;
        }

        charge = chargeDeDestination(destination);
        charge.speed = VITESSE_DE_PATROUILLE;
        charge._token = jetonCsrf();

        if (o.genre === 'launch') {
            charge.reserve = o.reserve;
            charge.planet_id = o.planete && o.planete.planetId;
            Object.keys(o.composition || {}).forEach(function (id) {
                if (Number(o.composition[id]) > 0) {
                    charge['am' + id] = Number(o.composition[id]);
                }
            });
        } else if (o.patrouille) {
            charge.patrol_id = o.patrouille.id;
        }

        return charge;
    }

    function raisonDeLaReponse(reponse) {
        if (reponse && typeof reponse.reason === 'string' && reponse.reason !== '') {
            return reponse.reason;
        }

        if (reponse && reponse.errors && reponse.errors.length) {
            return String(reponse.errors[0].message || '');
        }

        return locaFiche('reasons.unavailable', '');
    }

    /* La destination est choisie : le serveur la chiffre. Le devis qui revient est le seul qui compte. */
    function choisirLaDestination(carte, destination) {
        var f = carte.querySelector('.gtCard');
        var o = f ? f.gtOrdre : null;

        /* Une destination nulle n'a de sens que pour un rappel : c'est le serveur qui la compose. */
        if (!o || (!destination && o.genre !== 'recall') || typeof galaxyPatrolQuoteUrl === 'undefined' || !galaxyPatrolQuoteUrl || !window.jQuery) {
            return;
        }

        finirLeChoixDeDestination(carte);
        o.destination = destination;
        o.devis = null;
        o.erreur = null;
        o.etape = 'attente';
        o.jeton = (o.jeton || 0) + 1;

        var demande = o.jeton;

        composerLePanneau(carte, f);
        composerLesActions(f);

        /* Le point clique se marque tout de suite ; la reponse du serveur le remplacera par le sien. */
        if (destination) {
            poserLeMarqueurDeDestination(carte, destination);
        }

        window.jQuery.post(galaxyPatrolQuoteUrl, chargeDOrdre(o, destination), null, 'json')
            .done(function (reponse) {
                if (f.gtOrdre !== o || demande !== o.jeton) {
                    return;
                }

                if (reponse && reponse.success && reponse.quote) {
                    o.devis = reponse.quote;
                    o.depart = reponse.departure || null;
                    o.etape = 'devis';
                    /*
                     * **Le marqueur se pose sur la destination du serveur**, jamais sur celle que la
                     * carte croyait : c'est la seule qui soit celle de l'ordre — et pour un rappel,
                     * la seule qui existe.
                     */
                    if (o.devis.destination) {
                        poserLeMarqueurDeDestination(carte, o.devis.destination);
                    }
                } else {
                    o.erreur = raisonDeLaReponse(reponse);
                    o.etape = 'destination';
                    retirerLeMarqueurDeDestination(carte);
                    carte.gtChoix = { fiche: f };
                    carte.classList.add('gtChoosingDestination');
                }

                composerLePanneau(carte, f);
                composerLesActions(f);

                if (f.gtBloc) {
                    placer(f, f.gtBloc);
                }
            })
            .fail(function (xhr) {
                if (f.gtOrdre !== o || demande !== o.jeton) {
                    return;
                }

                o.erreur = raisonDeLaReponse(xhr && xhr.responseJSON ? xhr.responseJSON : null);
                o.etape = 'destination';
                retirerLeMarqueurDeDestination(carte);
                carte.gtChoix = { fiche: f };
                carte.classList.add('gtChoosingDestination');
                composerLePanneau(carte, f);
                composerLesActions(f);
                replacerLaFiche(carte, f);
            });
    }

    /* La confirmation : la version du devis part avec l'ordre ; un devis perime revient en refus, et le joueur en redemande un. */
    function envoyerLOrdre(carte, f) {
        var o = f.gtOrdre;

        if (!o || !o.devis || o.enCours || !window.jQuery) {
            return;
        }

        var adresse = null;

        if (o.genre === 'move' && typeof galaxyPatrolMoveUrl !== 'undefined') {
            adresse = adresseDePatrouille(galaxyPatrolMoveUrl, o.patrouille.id);
        } else if (o.genre === 'recall' && typeof galaxyPatrolRecallUrl !== 'undefined') {
            adresse = adresseDePatrouille(galaxyPatrolRecallUrl, o.patrouille.id);
        } else if (o.genre === 'land' && typeof galaxyPatrolLandUrl !== 'undefined') {
            adresse = adresseDePatrouille(galaxyPatrolLandUrl, o.patrouille.id);
        } else if (o.genre === 'launch' && typeof galaxyPatrolLaunchUrl !== 'undefined') {
            adresse = galaxyPatrolLaunchUrl;
        }

        if (!adresse) {
            return;
        }

        var charge = chargeDOrdre(o, o.destination);
        charge.order_version = o.devis.order_version;
        /*
         * **Le cout lu voyage avec la confirmation.** La version d'ordre ne bouge pas avec le temps,
         * et une patrouille en vol se deplace : le point de depart d'une manoeuvre est interpole a
         * l'instant de la confirmation, donc la distance et le cout peuvent avoir change depuis
         * l'affichage. Le serveur refuse alors de debiter plus que ce que le joueur a lu.
         */
        charge.quoted_fuel_cost = o.devis.fuel_cost;

        o.enCours = true;
        composerLesActions(f);

        var dire = function (message, erreur) {
            if (typeof window.fadeBox === 'function' && message) {
                window.fadeBox(message, erreur);
            }
        };

        var refuse = function (reponse) {
            o.enCours = false;
            o.devis = null;
            o.erreur = raisonDeLaReponse(reponse);
            o.etape = 'destination';
            retirerLeMarqueurDeDestination(carte);
            carte.gtChoix = { fiche: f };
            carte.classList.add('gtChoosingDestination');
            composerLePanneau(carte, f);
            composerLesActions(f);
            replacerLaFiche(carte, f);
            dire(o.erreur, true);
        };

        window.jQuery.post(adresse, charge, null, 'json')
            .done(function (reponse) {
                if (f.gtOrdre !== o) {
                    return;
                }

                if (!reponse || !reponse.success) {
                    refuse(reponse);

                    return;
                }

                o.enCours = false;
                dire(reponse.message || '', false);
                deselectionner(carte, false);

                if (carte.gtSysteme) {
                    chargerLesFlottes(carte, carte.gtSysteme.galaxie, carte.gtSysteme.systeme);
                }
            })
            .fail(function (xhr) {
                if (f.gtOrdre !== o) {
                    return;
                }

                refuse(xhr && xhr.responseJSON ? xhr.responseJSON : null);
            });
    }

    /*
     * ## Les compteurs du bandeau
     *
     * « Esp.Sonde », « Recy. », « IPM » et « Emplacements utilises » ne se mettaient a jour qu'au
     * chargement du systeme (retour de Keven). Le point d'entree des flottes porte desormais ces
     * compteurs (`counters`, meme source que la photographie : `GalaxyHeaderCounters`), et la carte
     * les ecrit a chaque reponse — a chaque mouvement annonce sur le canal du joueur, qu'il touche
     * ou non le systeme affiche —, puis en veille toutes les VEILLE_DES_COMPTEURS millisecondes : le
     * chantier spatial n'annonce rien, et c'est la requete qui fait avancer sa file.
     */
    var VEILLE_DES_COMPTEURS = 30000;
    var veilleDesCompteurs = null;

    function mettreAJourLesCompteurs(compteurs) {
        if (!compteurs) {
            return;
        }

        [['probeValue', 'probes'], ['recyclerValue', 'recyclers'], ['missileValue', 'missiles'], ['slotUsed', 'slotsUsed'], ['slotValue', 'slotsMax']].forEach(function (paire) {
            var cible = document.getElementById(paire[0]);

            if (cible && typeof compteurs[paire[1]] === 'number') {
                cible.textContent = String(compteurs[paire[1]]);
            }
        });
    }

    /* Les compteurs seuls, sans redessiner la couche : la reponse du systeme affiche, quel qu'il soit. */
    function rafraichirLesCompteurs(carte) {
        if (!carte.gtSysteme || typeof galaxyFleetsUrl === 'undefined' || !galaxyFleetsUrl || !window.jQuery) {
            return;
        }

        window.jQuery.getJSON(galaxyFleetsUrl, { galaxy: carte.gtSysteme.galaxie, system: carte.gtSysteme.systeme })
            .done(function (reponse) {
                if (reponse && reponse.success) {
                    mettreAJourLesCompteurs(reponse.counters);
                }
            });
    }

    function arreterLaVeilleDesCompteurs() {
        if (veilleDesCompteurs !== null) {
            window.clearInterval(veilleDesCompteurs);
            veilleDesCompteurs = null;
        }
    }

    function demarrerLaVeilleDesCompteurs(carte) {
        arreterLaVeilleDesCompteurs();
        veilleDesCompteurs = window.setInterval(function () {
            rafraichirLesCompteurs(carte);
        }, VEILLE_DES_COMPTEURS);
    }

    /*
     * La demande, marquee d'un jeton : une reponse tardive de l'ancien systeme n'ecrase jamais le
     * nouveau. Le serveur seul dit quels mouvements existent ; la reponse remplace tout.
     */
    function chargerLesFlottes(carte, galaxie, systeme) {
        if (typeof galaxyFleetsUrl === 'undefined' || !galaxyFleetsUrl || !window.jQuery) {
            return;
        }

        /*
         * **Invalider d abord, adopter la generation ensuite.** Le droit est inconnu tant que la
         * reponse n est pas la : on masque des maintenant, ce qui perime du meme geste toute
         * demande plus ancienne encore en vol. La demande qui part prend la generation qui en
         * resulte — l inverse ferait qu elle se perimerait elle-meme.
         */
        invaliderLaSurveillance(carte);

        var generation = generationDuContexte;

        window.jQuery.getJSON(galaxyFleetsUrl, { galaxy: galaxie, system: systeme })
            .done(function (reponse) {
                if (generation !== generationDuContexte || !reponse || !reponse.success) {
                    return;
                }

                mettreAJourLesCompteurs(reponse.counters);
                decalageHorloge = Number(reponse.server_now) * 1000 - Date.now();
                mouvements = Array.isArray(reponse.movements) ? reponse.movements : [];
                patrouilles = Array.isArray(reponse.patrols) ? reponse.patrols : [];
                /*
                 * **Remplacement, jamais fusion.** Le serveur rend la liste complete de ce que le
                 * joueur a le droit de voir a cet instant ; une liste vide retire donc ce qui etait
                 * affiche. Le jeton, plus haut, garantit qu une reponse plus ancienne ne repasse
                 * jamais par ici — sans quoi elle reintroduirait ce qu une revocation vient d oter.
                 */
                contactsDeSurveillance = Array.isArray(reponse.surveillance) ? reponse.surveillance : [];
                dessinerLesMouvements(carte, galaxie, systeme);
                dessinerLesPatrouilles(carte, galaxie, systeme);
                dessinerLaSurveillance(carte);
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

    var connexionPerdue = false;

    /*
     * Une connexion qui tombe puis revient, sans que l onglet ait bouge.
     *
     * **Ce cas ne produit aucun `visibilitychange`.** Un onglet reste visible pendant une coupure
     * de reseau, et la resynchronisation au retour au premier plan ne le couvre donc pas. Or c est
     * exactement pendant une coupure qu une revocation peut passer inapercue : le canal ne porte
     * plus rien, et l ecran garde ce qu il avait.
     *
     * Deux moments, deux gestes. **A la perte**, on masque : tant que le canal est mort, plus rien
     * ne peut confirmer que ces contacts restent autorises, et un renseignement inverifiable ne
     * s affiche pas. **Au retour**, on redemande : c est le serveur qui dit ce qui subsiste, jamais
     * la memoire de l onglet.
     *
     * Deux sources, parce qu aucune n est complete : l evenement `online` du navigateur voit la
     * carte reseau, la connexion du diffuseur voit le canal lui-meme — une coupure serveur ne
     * touche pas la premiere.
     */
    function surveillerLaConnexion(carte) {
        var reprendre = function () {
            if (!connexionPerdue) {
                return;
            }

            connexionPerdue = false;

            if (carte.gtSysteme) {
                chargerLesFlottes(carte, carte.gtSysteme.galaxie, carte.gtSysteme.systeme);
            }
        };

        var perdre = function () {
            connexionPerdue = true;
            invaliderLaSurveillance(carte);
        };

        window.addEventListener('online', reprendre);
        window.addEventListener('offline', perdre);

        var connexion = window.Echo && window.Echo.connector && window.Echo.connector.pusher
            ? window.Echo.connector.pusher.connection
            : null;

        if (!connexion || typeof connexion.bind !== 'function') {
            return;
        }

        connexion.bind('connected', reprendre);

        ['unavailable', 'disconnected', 'failed'].forEach(function (etat) {
            connexion.bind(etat, perdre);
        });
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
                } else {
                    rafraichirLesCompteurs(carte);
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
        patrouilles = [];
        arreterLAnimation();
        annulerLesFenetres();
        chargerLesFlottes(carte, galaxie, systeme);
        ecouterLeSysteme(carte, galaxie, systeme);
        ecouterLeJoueur(carte);
        surveillerLaConnexion(carte);
        demarrerLaVeilleDesCompteurs(carte);
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

        patrouilles.forEach(function (p) {
            if (p._marqueur && p.segment) {
                p._bouts = extremites(p.segment, carte.gtSysteme.galaxie, carte.gtSysteme.systeme);
            }
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
            arreterLaVeilleDesCompteurs();

            return;
        }

        animer();

        if (carte && carte.gtSysteme) {
            demarrerLesOrbites(carte);
            demarrerLaVeilleDesCompteurs(carte);
            rafraichirLesCompteurs(carte);

            /*
             * **Un onglet qui revient ne sait plus ce qu il a le droit de voir.** Il a pu manquer
             * une revocation pendant qu il dormait : reprendre l animation sans redemander aurait
             * laisse a l ecran des renseignements devenus interdits, indefiniment. La demande
             * masque d abord, puis repeint ce que le serveur autorise encore.
             */
            chargerLesFlottes(carte, carte.gtSysteme.galaxie, carte.gtSysteme.systeme);
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
    /*
     * Rouvre l'ordre que le redessin a interrompu, dans le systeme desormais affiche.
     *
     * La patrouille n'est plus forcement dans la charge utile — c'est meme le cas courant : on a
     * change de systeme pour l'envoyer ailleurs. La fiche se rouvre donc sur la photographie que
     * l'ordre porte, et le devis qui suivra viendra du serveur comme toujours.
     */
    function restaurerLOrdre(carte) {
        var ordre = carte.gtOrdreARestaurer;

        carte.gtOrdreARestaurer = null;

        if (!ordre || !ordre.patrouille) {
            return;
        }

        var vivante = null;

        patrouilles.forEach(function (p) {
            if (Number(p.id) === Number(ordre.patrouille.id)) {
                vivante = p;
            }
        });

        choisirLaPatrouille(carte, vivante || ordre.patrouille, true);

        var f = carte.querySelector('.gtCard');

        if (!f) {
            return;
        }

        ordre.patrouille = vivante || ordre.patrouille;
        ordre.destination = null;
        ordre.devis = null;
        poserLOrdre(carte, f, ordre);
        commencerLeChoixDeDestination(carte, f);
        noteDOrdre(f, locaFiche('patrolOtherSystem', ''), false);
    }

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
        var ficheOuverte = carte.querySelector('.gtCard');

        carte.gtPatrouilleARestaurer = memeSysteme && ficheOuverte && !ficheOuverte.hidden && ficheOuverte.gtPatrouille && !ficheOuverte.gtOrdre
            ? Number(ficheOuverte.gtPatrouille.id)
            : null;

        /*
         * **Un ordre en attente de destination survit au changement de systeme.**
         *
         * C'est ce qui permet d'envoyer une patrouille ailleurs : on prend l'ordre ici, on navigue,
         * et on designe la-bas — la destination portera le systeme affiche au moment du choix. Sans
         * cela, changer de systeme annulait l'ordre et un envoi inter-systemes etait impossible
         * autrement qu'en saisissant des coordonnees.
         */
        carte.gtOrdreARestaurer = ficheOuverte && ficheOuverte.gtOrdre && ficheOuverte.gtOrdre.etape === 'destination'
            ? ficheOuverte.gtOrdre
            : null;

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

        poserLaLegende(carte);

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
        restaurerLOrdre(carte);
        demarrerLaCoucheFlottes(carte, Number(systeme.galaxy), Number(systeme.system));
        demarrerLesOrbites(carte);
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
