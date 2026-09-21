/*
 * Les non-lus du chat : une verite serveur, une pastille, un bandeau, un son.
 *
 * ## Le principe
 *
 * Le serveur est la reference. Le navigateur peut **predire** — un message arrive, on demande l etat —,
 * jamais **decider** : toute valeur affichee vient d une photographie complete rendue par
 * `GET /ajax/chat/unread` (toutes les conversations qui portent un non-lu, et le total). Le total n est
 * jamais somme dans le DOM : un contact present dans deux listes (ami ET membre de l alliance) portait
 * deux badges, et `updateTotalNewChatCounter()` comptait son message deux fois (mesure du
 * 21 septembre 2026).
 *
 * ## Le protocole de lecture, et ce qu il garantit
 *
 * - **Une seule lecture d etat en vol.** Un besoin survenu pendant une lecture ou un marquage pose une
 *   actualisation **due**, il ne lance pas une seconde requete.
 * - **Une epoque.** Elle avance a chaque evenement qui rend l etat caduc (marquage envoye ou abouti,
 *   message recu, retour d onglet, reconnexion). Une photographie qui repond a une epoque passee est
 *   **abandonnee avant tout effet** — et pose l actualisation due : ce qui l a rendue caduque merite une
 *   lecture fraiche. Une reponse abandonnee ne fait donc jamais perdre l actualisation attendue.
 * - **Pendant les marquages locaux, les lectures sont suspendues** — jusqu a la fin de **tous** : un
 *   compteur, pas un drapeau, et la premiere reponse ne leve pas seule la suspension.
 * - **Un echec ne boucle pas** : la lecture reste due et repart apres un delai borne et croissant.
 * - **Ce qui n est pas garanti** : l epoque est locale a l onglet. Un changement fait ailleurs — autre
 *   onglet, autre appareil — se voit a la synchronisation suivante, pas avant. Une valeur brievement
 *   depassee est possible ; un compteur bloque ne l est pas, puisqu un onglet visible relit a cadence
 *   fixe (60 s le direct connecte, 20 s sinon) — cadence prevue quand navigateur et reseau fonctionnent,
 *   pas garantie absolue.
 *
 * ## Ce qui compte comme « lu »
 *
 * Seuls les messages **entres dans la zone affichee** d une conversation **ouverte, non reduite**, dans un
 * onglet **visible**, sont marques. Direct : l ensemble exact des identifiants vus (`seenIds`). Alliance :
 * le plus grand identifiant affiche (`seenUpToId`) — afficher un message marque aussi les precedents,
 * regle tranchee par Keven le 21 septembre 2026. Ouvrir Contacts, restaurer une conversation, fermer ou
 * laisser expirer un bandeau ne marquent rien.
 *
 * ## Le son, et sa section critique
 *
 * Lecture de l anneau des identifiants deja sonnes, controle, enregistrement et declenchement se font
 * **sous le meme verrou exclusif** (`navigator.locks`), et la visibilite est verifiee **apres** l obtention
 * du verrou : un onglet passe en arriere-plan pendant la file n agit pas. Limites, dites et gardees :
 * anneau borne, stockage indisponible, audio refuse, navigateur sans verrou. Et quoi qu il arrive, une
 * panne du son, du bandeau ou de l anneau n empeche ni l affichage d un message ni la pastille.
 *
 * Toutes les requetes passent par `$.ajax`, comme le reste du chat : le banc les retient et y repond lui-meme.
 */
var ogame = ogame || {};

ogame.chatUnread = {
    // ------------------------------------------------------------------ reglages
    REPRISE_MIN_MS: 2000,
    REPRISE_MAX_MS: 60000,
    VEILLE_CONNECTE_MS: 60000,
    VEILLE_DECONNECTE_MS: 20000,
    BANDEAU_MS: 5000,
    BANDEAUX_MAX: 3,
    ANNEAU_TAILLE: 200,
    VUS_TAILLE: 500,
    MARQUAGE_ATTENTE_MS: 700,
    SEEN_IDS_MAX: 200,

    // ------------------------------------------------------------------ etat
    urls: null,
    playerId: 0,
    allianceId: 0,
    loca: function (clef) {
        return (typeof chatLoca !== 'undefined' && chatLoca[clef] !== undefined) ? chatLoca[clef] : clef;
    },

    epoque: 0,
    lectureEnVol: false,
    actualisationDue: false,
    marquagesEnVol: 0,
    echecsConsecutifs: 0,
    minuterieReprise: null,
    minuterieVeille: null,
    etat: { total: 0, conversations: [] },
    totalPrecedent: 0,

    /** Les identifiants deja notifies dans cet onglet, bornes : ils ne servent qu a ne pas repeter. */
    vus: [],
    /** Ce qui attend d etre marque : par conversation, les identifiants entres dans la zone affichee. */
    aMarquer: {},
    minuterieMarquage: null,
    observateur: null,
    observateurDom: null,
    observes: null,
    bandeaux: {},

    // ------------------------------------------------------------------ demarrage
    /**
     * @param {{urls: {snapshot: string, seen: string}, playerId: number, allianceId: number|null}} options
     */
    init: function (options) {
        var u = ogame.chatUnread;
        if (!options || !options.urls || !options.urls.snapshot || !options.urls.seen) {
            return;
        }
        u.urls = options.urls;
        u.playerId = Number(options.playerId) || 0;
        u.allianceId = Number(options.allianceId) || 0;
        u.observes = (typeof WeakSet === 'function') ? new WeakSet() : null;

        u.garder(function () { u.poserLaPastille(); });
        u.garder(function () { u.surveillerLesConversations(); });
        u.garder(function () { u.ecouterLaVisibilite(); });
        u.garder(function () { u.ecouterLeDirect(); });
        u.armerLaVeille();
        u.demanderLecture();
    },

    /**
     * Une panne d un ornement — son, bandeau, anneau — ne doit jamais empecher le chat de fonctionner.
     */
    garder: function (action) {
        try {
            return action();
        } catch (e) {
            return undefined;
        }
    },

    // ------------------------------------------------------------------ la lecture d etat
    /**
     * Demander l etat. S il y a deja une lecture en vol ou un marquage en cours, l actualisation est **due**
     * et partira a la fin ; on ne lance jamais deux photographies dans la meme epoque.
     */
    demanderLecture: function () {
        var u = ogame.chatUnread;
        if (u.lectureEnVol || u.marquagesEnVol > 0 || u.minuterieReprise !== null) {
            u.actualisationDue = true;
            return;
        }
        u.lire();
    },

    lire: function () {
        var u = ogame.chatUnread;
        if (!u.urls) {
            return;
        }
        var epoque = u.epoque;
        u.lectureEnVol = true;
        u.actualisationDue = false;
        $.ajax({
            url: u.urls.snapshot,
            type: 'GET',
            dataType: 'json',
            cache: false,
            success: function (etat) {
                u.lectureEnVol = false;
                u.echecsConsecutifs = 0;
                if (epoque !== u.epoque || u.marquagesEnVol > 0) {
                    // **Abandonnee avant tout effet, et l actualisation reste due** : ce qui l a rendue
                    // caduque merite une lecture fraiche.
                    u.actualisationDue = true;
                    u.honorer();
                    return;
                }
                u.appliquer(etat);
                u.honorer();
            },
            error: function () {
                u.lectureEnVol = false;
                // La lecture reste due ; elle repart apres un delai borne, jamais en boucle serree.
                u.actualisationDue = true;
                u.planifierLaReprise();
            }
        });
    },

    /** A la fin de toute lecture ou de tout marquage : si une actualisation est due, exactement une lecture. */
    honorer: function () {
        var u = ogame.chatUnread;
        if (!u.actualisationDue || u.lectureEnVol || u.marquagesEnVol > 0 || u.minuterieReprise !== null) {
            return;
        }
        u.lire();
    },

    planifierLaReprise: function () {
        var u = ogame.chatUnread;
        if (u.minuterieReprise !== null) {
            return;
        }
        u.echecsConsecutifs += 1;
        var delai = Math.min(u.REPRISE_MAX_MS, u.REPRISE_MIN_MS * Math.pow(2, u.echecsConsecutifs - 1));
        u.minuterieReprise = setTimeout(function () {
            u.minuterieReprise = null;
            u.honorer();
        }, delai);
    },

    /**
     * Une photographie acceptee **remplace tout l etat** : une conversation absente porte zero. C est la
     * completude de la photographie qui l autorise, pas une borne d identifiant.
     */
    appliquer: function (etat) {
        var u = ogame.chatUnread;
        if (!etat || typeof etat !== 'object' || !Array.isArray(etat.conversations)) {
            return;
        }
        var total = 0;
        var conversations = [];
        $.each(etat.conversations, function (i, c) {
            if (!c || (c.kind !== 'direct' && c.kind !== 'alliance')) {
                return;
            }
            var n = Number(c.unread);
            if (!isFinite(n) || n < 0 || Math.floor(n) !== n) {
                return;
            }
            conversations.push(c);
            total += n;
        });
        u.totalPrecedent = u.etat.total;
        u.etat = { total: total, conversations: conversations };
        u.garder(function () { u.rendre(); });
    },

    // ------------------------------------------------------------------ les marquages
    /**
     * Un marquage ouvre une epoque et suspend les lectures **jusqu a la fin de tous** les marquages en vol.
     * Succes comme echec : la suspension ne tombe qu au dernier, et la lecture due part alors.
     */
    marquer: function (charge) {
        var u = ogame.chatUnread;
        if (!u.urls) {
            return;
        }
        u.epoque += 1;
        u.marquagesEnVol += 1;
        u.actualisationDue = true;
        var fin = function () {
            u.marquagesEnVol = Math.max(0, u.marquagesEnVol - 1);
            u.epoque += 1;
            if (u.marquagesEnVol === 0) {
                u.honorer();
            }
        };
        $.ajax({
            url: u.urls.seen,
            type: 'POST',
            dataType: 'json',
            data: charge,
            success: fin,
            error: fin
        });
    },

    // ------------------------------------------------------------------ ce qui a ete vu
    /**
     * Les messages qui entrent dans la zone affichee d une fenetre ouverte, non reduite, dans un onglet
     * visible. `IntersectionObserver` dit « entre dans la zone » ; sans lui, rien n est marque depuis la barre —
     * le serveur ne devine pas.
     */
    surveillerLesConversations: function () {
        var u = ogame.chatUnread;
        if (typeof IntersectionObserver !== 'function' || typeof MutationObserver !== 'function') {
            return;
        }
        var barre = document.getElementById('chatBar');
        if (!barre) {
            return;
        }
        u.observateur = new IntersectionObserver(function (entrees) {
            $.each(entrees, function (i, e) {
                if (e.isIntersecting) {
                    u.messageAffiche(e.target);
                }
            });
        }, { threshold: 0.6 });
        u.observateurDom = new MutationObserver(function () {
            u.observerLesMessages();
        });
        u.observateurDom.observe(barre, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
        u.observerLesMessages();
    },

    observerLesMessages: function () {
        var u = ogame.chatUnread;
        if (!u.observateur) {
            return;
        }
        $('#chatBar .chat_bar_list_item.open .chat_box .chat_msg[data-chat-id]').each(function () {
            if (u.observes && u.observes.has(this)) {
                return;
            }
            if (u.observes) {
                u.observes.add(this);
            }
            u.observateur.observe(this);
        });
    },

    /** Un message est entre dans la zone affichee : si sa fenetre et l onglet sont regardes, il est vu. */
    messageAffiche: function (element) {
        var u = ogame.chatUnread;
        if (document.visibilityState !== 'visible') {
            return;
        }
        var fenetre = $(element).closest('.chat_bar_list_item');
        if (!fenetre.length || !fenetre.hasClass('open')) {
            return;
        }
        var boite = fenetre.children('.chat_box');
        if (!boite.length || boite.css('display') === 'none') {
            return;
        }
        var id = parseInt($(element).attr('data-chat-id'), 10);
        if (!(id > 0)) {
            return;
        }
        var associationId = parseInt(fenetre.attr('data-associationid'), 10);
        var playerId = parseInt(fenetre.attr('data-playerid'), 10);
        var clef;
        if (associationId > 0) {
            clef = 'a:' + associationId;
        } else if (playerId > 0) {
            // Un message direct de moi n est jamais non lu ; seul un message recu compte.
            if ($(element).hasClass('odd')) {
                return;
            }
            clef = 'p:' + playerId;
        } else {
            return;
        }
        if (!u.aMarquer[clef]) {
            u.aMarquer[clef] = {};
        }
        u.aMarquer[clef][id] = true;
        if (u.minuterieMarquage === null) {
            u.minuterieMarquage = setTimeout(function () {
                u.minuterieMarquage = null;
                u.envoyerLesMarquages();
            }, u.MARQUAGE_ATTENTE_MS);
        }
    },

    envoyerLesMarquages: function () {
        var u = ogame.chatUnread;
        var lots = u.aMarquer;
        u.aMarquer = {};
        $.each(lots, function (clef, ids) {
            var liste = [];
            $.each(ids, function (id) {
                liste.push(parseInt(id, 10));
            });
            if (!liste.length) {
                return;
            }
            var genre = clef.charAt(0);
            var cible = parseInt(clef.substring(2), 10);
            if (genre === 'a') {
                u.marquer({ associationId: cible, seenUpToId: Math.max.apply(null, liste) });
                return;
            }
            liste.sort(function (a, b) { return a - b; });
            while (liste.length) {
                u.marquer({ playerId: cible, seenIds: liste.splice(0, u.SEEN_IDS_MAX) });
            }
        });
    },

    // ------------------------------------------------------------------ les declencheurs
    ecouterLaVisibilite: function () {
        var u = ogame.chatUnread;
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                u.epoque += 1;
                u.demanderLecture();
                u.garder(function () { u.observerLesMessages(); });
            }
            u.armerLaVeille();
        });
    },

    ecouterLeDirect: function () {
        var u = ogame.chatUnread;
        var connexion = u.connexionDuDirect();
        if (!connexion || typeof connexion.bind !== 'function') {
            return;
        }
        connexion.bind('connected', function () {
            u.epoque += 1;
            u.demanderLecture();
            u.armerLaVeille();
        });
        connexion.bind('disconnected', function () { u.armerLaVeille(); });
        connexion.bind('unavailable', function () { u.armerLaVeille(); });
    },

    connexionDuDirect: function () {
        try {
            return (window.Echo && window.Echo.connector && window.Echo.connector.pusher) ? window.Echo.connector.pusher.connection : null;
        } catch (e) {
            return null;
        }
    },

    directConnecte: function () {
        var c = ogame.chatUnread.connexionDuDirect();
        return !!(c && c.state === 'connected');
    },

    /** La veille : onglet visible seulement, 60 s le direct connecte, 20 s sinon. Un onglet masque ne sonde pas. */
    armerLaVeille: function () {
        var u = ogame.chatUnread;
        if (u.minuterieVeille !== null) {
            clearInterval(u.minuterieVeille);
            u.minuterieVeille = null;
        }
        if (document.visibilityState !== 'visible') {
            return;
        }
        var periode = u.directConnecte() ? u.VEILLE_CONNECTE_MS : u.VEILLE_DECONNECTE_MS;
        u.minuterieVeille = setInterval(function () {
            if (document.visibilityState === 'visible') {
                u.demanderLecture();
            }
        }, periode);
    },

    /**
     * Un message est arrive par le direct. L etat devient caduc — on le redemande — et, si ce message n est ni
     * de moi ni deja notifie, on le signale. Le bandeau et le son sont des ornements : gardes.
     */
    messageRecu: function (m) {
        var u = ogame.chatUnread;
        if (!m || !(m.id > 0)) {
            return;
        }
        u.epoque += 1;
        u.demanderLecture();
        u.garder(function () { u.observerLesMessages(); });
        if (Number(m.senderId) === u.playerId) {
            return;
        }
        if (u.dejaVu(m.id)) {
            return;
        }
        u.garder(function () { u.notifier(m); });
    },

    dejaVu: function (id) {
        var u = ogame.chatUnread;
        id = Number(id);
        if (u.vus.indexOf(id) !== -1) {
            return true;
        }
        u.vus.push(id);
        if (u.vus.length > u.VUS_TAILLE) {
            u.vus.splice(0, u.vus.length - u.VUS_TAILLE);
        }
        return false;
    },

    // ------------------------------------------------------------------ la pastille et les badges
    poserLaPastille: function () {
        var u = ogame.chatUnread;
        var bouton = $('#chatBarPlayerList .onlineCount');
        if (!bouton.length || bouton.find('.az-unread-pip').length) {
            return;
        }
        var pastille = $('<span class="az-unread-pip" hidden role="status"></span>');
        // Une enveloppe dessinee ici, pas une icone Lucide : les douze icones du chat sont figees avec leur
        // licence dans `public/img/chat-azria/`, et rien ne s y ajoute sans y etre fige de la meme facon.
        pastille.append('<svg class="az-svg" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M3 7l9 6 9-6"/></svg>');
        pastille.append('<span class="az-unread-count">0</span>');
        bouton.append(pastille);
        u.rendre();
    },

    /** Le libelle des non-lus, dans la langue du joueur. */
    libelle: function (n) {
        var u = ogame.chatUnread;
        if (n === 1) {
            return u.loca('UNREAD_ONE');
        }
        return u.loca('UNREAD_MANY').replace('#+#', String(n));
    },

    rendre: function () {
        var u = ogame.chatUnread;
        var total = u.etat.total;

        // La pastille de Contacts : absente a zero, un bref halo quand le nombre monte.
        var pastille = $('#chatBarPlayerList .az-unread-pip');
        if (pastille.length) {
            pastille.find('.az-unread-count').text(String(total));
            if (total > 0) {
                pastille.removeAttr('hidden').attr('title', u.libelle(total)).attr('aria-label', u.libelle(total));
                if (total > u.totalPrecedent) {
                    // Le halo en deux temps, sans `@keyframes` : l anneau apparait, puis se dissipe par transition,
                    // puis tout est retire — un bref eclat, jamais un clignotement permanent.
                    pastille.removeClass('az-unread-pip--halo az-unread-pip--halo-out');
                    setTimeout(function () { pastille.addClass('az-unread-pip--halo'); }, 0);
                    setTimeout(function () { pastille.addClass('az-unread-pip--halo-out'); }, 40);
                    setTimeout(function () { pastille.removeClass('az-unread-pip--halo az-unread-pip--halo-out'); }, 1200);
                }
            } else {
                pastille.attr('hidden', 'hidden').removeClass('az-unread-pip--halo').removeAttr('title').removeAttr('aria-label');
            }
        }

        // Le badge du haut lit la MEME verite : le nombre, et une infobulle qui dit la meme chose.
        var lien = $('a.comm_menu.chat');
        var badge = lien.find('.new_msg_count');
        if (badge.length) {
            badge.text(String(total)).attr('data-new-messages', String(total)).toggleClass('noMessage', total === 0);
            lien.attr('title', total > 0 ? u.libelle(total) : '');
        }

        // Les badges par conversation : tous ceux qui portent l identifiant, doublons compris — la valeur
        // est la meme partout, et le total n est plus somme dessus.
        var parJoueur = {};
        var parAlliance = {};
        $.each(u.etat.conversations, function (i, c) {
            if (c.kind === 'direct') {
                parJoueur[c.playerId] = c.unread;
            } else if (c.kind === 'alliance') {
                parAlliance[c.allianceId] = c.unread;
            }
        });
        $('#chatBar .new_msg_count[data-playerid]').each(function () {
            var n = parJoueur[$(this).attr('data-playerid')] || 0;
            $(this).text(String(n)).attr('data-new-messages', String(n)).toggleClass('noMessage', n === 0);
        });
        $('#chatBar .new_msg_count[data-associationid]').each(function () {
            var n = parAlliance[$(this).attr('data-associationid')] || 0;
            $(this).text(String(n)).attr('data-new-messages', String(n)).toggleClass('noMessage', n === 0);
        });
    },

    // ------------------------------------------------------------------ le bandeau
    /**
     * Un bandeau par expediteur, cinq secondes, sans contenu prive. Seul un onglet visible l affiche ; le
     * clic ouvre la conversation ; la croix ferme ; fermer ou expirer ne marque rien.
     */
    notifier: function (m) {
        var u = ogame.chatUnread;
        if (document.visibilityState === 'visible') {
            u.afficherLeBandeau(m);
        }
        u.sonner(m.id);
    },

    afficherLeBandeau: function (m) {
        var u = ogame.chatUnread;
        var barre = $('#chatBar');
        if (!barre.length) {
            return;
        }
        var pile = barre.children('.az-toast-stack');
        if (!pile.length) {
            pile = $('<div class="az-toast-stack" aria-live="polite"></div>');
            barre.append(pile);
        }
        var clef = (m.associationId > 0) ? 'a:' + m.associationId : 'p:' + m.senderId;
        var existant = u.bandeaux[clef];
        if (existant) {
            existant.compte += 1;
            existant.element.find('.az-toast-count').text('(' + existant.compte + ')').removeAttr('hidden');
            clearTimeout(existant.minuterie);
            existant.minuterie = setTimeout(function () { u.fermerLeBandeau(clef); }, u.BANDEAU_MS);
            return;
        }
        var clefs = Object.keys(u.bandeaux);
        if (clefs.length >= u.BANDEAUX_MAX) {
            u.fermerLeBandeau(clefs[0]);
        }
        var nom = String(m.senderName || '');
        var element = $('<div class="az-toast" role="status"></div>');
        var corps = $('<button type="button" class="az-toast-body"></button>');
        corps.append($('<span class="az-toast-name"></span>').text(nom));
        corps.append(document.createTextNode(' ' + u.loca('TOAST_SENT')));
        corps.append($('<span class="az-toast-count" hidden></span>'));
        corps.on('click', function () {
            u.fermerLeBandeau(clef);
            u.garder(function () { u.ouvrir(m); });
        });
        var croix = $('<button type="button" class="az-toast-close"></button>').attr('title', u.loca('TOAST_CLOSE')).attr('aria-label', u.loca('TOAST_CLOSE')).text('×');
        croix.on('click', function () { u.fermerLeBandeau(clef); });
        element.append(corps).append(croix);
        pile.append(element);
        u.bandeaux[clef] = {
            element: element,
            compte: 1,
            minuterie: setTimeout(function () { u.fermerLeBandeau(clef); }, u.BANDEAU_MS)
        };
    },

    fermerLeBandeau: function (clef) {
        var u = ogame.chatUnread;
        var b = u.bandeaux[clef];
        if (!b) {
            return;
        }
        clearTimeout(b.minuterie);
        b.element.remove();
        delete u.bandeaux[clef];
    },

    /** Ouvrir la conversation du bandeau, sans marquer : ce sont les messages affiches qui marqueront. */
    ouvrir: function (m) {
        if (!ogame.chat) {
            return;
        }
        if (m.associationId > 0 && typeof ogame.chat.loadChatLogWithAssociation === 'function') {
            ogame.chat.loadChatLogWithAssociation(Number(m.associationId), null, undefined, false);
            return;
        }
        if (typeof ogame.chat.loadChatLogWithPlayer === 'function') {
            ogame.chat.loadChatLogWithPlayer(Number(m.senderId), null, undefined, false);
        }
    },

    // ------------------------------------------------------------------ le son
    clefSon: function () {
        return 'az-chat-son:' + ogame.chatUnread.playerId;
    },

    sonActive: function () {
        var u = ogame.chatUnread;
        try {
            return localStorage.getItem(u.clefSon()) === '1';
        } catch (e) {
            return false;
        }
    },

    basculerLeSon: function (bouton) {
        var u = ogame.chatUnread;
        var actif = !u.sonActive();
        try {
            localStorage.setItem(u.clefSon(), actif ? '1' : '0');
        } catch (e) {
            // Sans stockage, la preference ne survit pas a la page : on le dit par l etat du bouton seulement.
        }
        // Le geste du joueur est l occasion de deverrouiller l audio : sans lui, le navigateur refuse.
        if (actif) {
            u.garder(function () { u.contexteAudio(true); });
        }
        // Le bouton clique est redessine lui-meme : il peut ne pas encore etre dans la page.
        u.rendreLeBoutonDuSon(bouton);
        return actif;
    },

    /** Le bouton du panneau des contacts : etat lu au rendu, jamais devine. */
    boutonDuSon: function () {
        var u = ogame.chatUnread;
        var bouton = $('<button type="button" class="az-icon az-sound"></button>');
        bouton.on('click', function (e) {
            e.stopPropagation();
            u.basculerLeSon(bouton);
        });
        u.rendreLeBoutonDuSon(bouton);
        return bouton;
    },

    rendreLeBoutonDuSon: function (bouton) {
        var u = ogame.chatUnread;
        bouton = bouton || $('#chatBar .az-sound');
        if (!bouton.length) {
            return;
        }
        var actif = u.sonActive();
        var libelle = u.loca(actif ? 'SOUND_ON' : 'SOUND_OFF');
        bouton.attr('aria-pressed', actif ? 'true' : 'false').attr('title', libelle).attr('aria-label', libelle);
        // Un haut-parleur dessine ici, pas une icone Lucide (voir `poserLaPastille`).
        bouton.html(actif
            ? '<svg class="az-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 9h3l4-3v12l-4-3H4z"/><path d="M15 9.5a3.5 3.5 0 0 1 0 5"/><path d="M17.5 7a7 7 0 0 1 0 10"/></svg>'
            : '<svg class="az-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 9h3l4-3v12l-4-3H4z"/><path d="M15 9l5 6"/><path d="M20 9l-5 6"/></svg>');
    },

    audio: null,
    contexteAudio: function (reprendre) {
        var u = ogame.chatUnread;
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (typeof Ctx !== 'function') {
            return null;
        }
        if (!u.audio) {
            u.audio = new Ctx();
        }
        if (reprendre && u.audio.state === 'suspended' && typeof u.audio.resume === 'function') {
            u.audio.resume().catch(function () {});
        }
        return u.audio;
    },

    /**
     * Sonner une fois pour ce message, tous onglets confondus.
     *
     * Tout est dans la section critique : la visibilite (verifiee APRES l obtention du verrou), la lecture de
     * l anneau, le controle, l enregistrement — ecrit AVANT de jouer, pour qu une panne fasse perdre un son
     * plutot que le doubler — et le declenchement. Sans `navigator.locks`, pas de section critique : le seul
     * onglet visible joue, et le dedoublonnage entre onglets est une limite, pas une garantie.
     */
    sonner: function (id) {
        var u = ogame.chatUnread;
        if (!u.sonActive()) {
            return;
        }
        var section = function () {
            if (document.visibilityState !== 'visible') {
                return;
            }
            var anneau = u.lireLAnneau();
            if (anneau.indexOf(Number(id)) !== -1) {
                return;
            }
            anneau.push(Number(id));
            if (anneau.length > u.ANNEAU_TAILLE) {
                anneau.splice(0, anneau.length - u.ANNEAU_TAILLE);
            }
            u.ecrireLAnneau(anneau);
            return u.jouerLeSon();
        };
        try {
            if (navigator.locks && typeof navigator.locks.request === 'function') {
                navigator.locks.request('azria-chat-son', { mode: 'exclusive' }, function () {
                    return section();
                }).catch(function () {});
            } else {
                section();
            }
        } catch (e) {
            // Rien : le son est un ornement.
        }
    },

    lireLAnneau: function () {
        try {
            var brut = localStorage.getItem('az-chat-sons');
            var liste = brut ? JSON.parse(brut) : [];
            return Array.isArray(liste) ? liste : [];
        } catch (e) {
            return [];
        }
    },

    ecrireLAnneau: function (liste) {
        try {
            localStorage.setItem('az-chat-sons', JSON.stringify(liste));
        } catch (e) {
            // Stockage indisponible : le dedoublonnage retombe sur la memoire de cet onglet (`vus`).
        }
    },

    /** Un bref son, sans fichier : deux notes courtes. `play`-like : la promesse se resout au demarrage. */
    jouerLeSon: function () {
        var u = ogame.chatUnread;
        return new Promise(function (resoudre) {
            try {
                var ctx = u.contexteAudio(false);
                if (!ctx || ctx.state !== 'running') {
                    resoudre();
                    return;
                }
                var t = ctx.currentTime;
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, t);
                osc.frequency.setValueAtTime(1175, t + 0.12);
                gain.gain.setValueAtTime(0.0001, t);
                gain.gain.exponentialRampToValueAtTime(0.06, t + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(t);
                osc.stop(t + 0.32);
                resoudre();
            } catch (e) {
                resoudre();
            }
        });
    }
};
