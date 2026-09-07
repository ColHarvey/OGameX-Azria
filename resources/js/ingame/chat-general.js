/**
 * Le chat general : une salle ou tout le serveur se parle, en direct.
 *
 * ## Ce que ce module est, et ce qu'il n'est pas
 *
 * Il ne touche pas a la machinerie des conversations (`chat.js`) : celle-ci gere des discussions
 * privees et d'alliance, avec leurs bulles, leurs compteurs de non-lus et leur visibilite. Le
 * general n'a rien de tout cela — **une seule salle, pas de destinataire**, et le jeu a deja un
 * systeme de messages prives par ailleurs.
 *
 * ## Un seul gabarit
 *
 * Les messages de l'historique et ceux qui arrivent en direct passent par la meme fonction. C'est
 * volontaire : rendre l'historique cote serveur et les arrivees ici, ce serait deux gabarits pour
 * une meme ligne, et ce depot a deja paye ce defaut — un nom d'unite arrivait en anglais en direct
 * et en francais au rechargement.
 *
 * Les decorations sont celles du classement, avec les memes classes : tag d'alliance, nom, badge
 * d'administrateur, points d'honneur colores selon leur signe.
 *
 * ## Ce qui se degrade proprement
 *
 * Sans `window.Echo`, l'historique s'affiche quand meme et l'envoi fonctionne ; seul le direct
 * manque, et la page le dit. Le module n'emporte personne : le bundle est une concatenation, une
 * exception non rattrapee ici arreterait les fichiers suivants.
 */
(function () {
    var listeSelecteur = '#generalChatList';
    var loca = typeof generalChatLoca === 'object' && generalChatLoca !== null ? generalChatLoca : {};
    var ignores = {};
    var affiches = {};

    /**
     * Le texte, rendu inoffensif.
     *
     * Le serveur echappe deja le contenu du message ; ce qu'il n'echappe pas, ce sont les noms et
     * les tags, qui viennent de la table des joueurs et arrivent bruts.
     */
    function echapper(valeur) {
        return String(valeur === null || valeur === undefined ? '' : valeur)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function deuxChiffres(nombre) {
        return nombre < 10 ? '0' + nombre : String(nombre);
    }

    function dateLisible(horodatage) {
        var d = new Date(horodatage * 1000);

        return deuxChiffres(d.getDate()) + '.' + deuxChiffres(d.getMonth() + 1) + '.' + d.getFullYear()
            + ' ' + deuxChiffres(d.getHours()) + ':' + deuxChiffres(d.getMinutes()) + ':' + deuxChiffres(d.getSeconds());
    }

    /**
     * Les marques d'un auteur, exactement celles du classement.
     */
    function decorations(auteur) {
        if (!auteur) {
            return '';
        }

        var morceaux = '';

        if (auteur.allianceTag) {
            morceaux += '<span class="ally-tag">[' + echapper(auteur.allianceTag) + ']</span> ';
        }

        morceaux += '<span class="playername' + (auteur.isAdmin ? ' status_abbr_admin' : '') + '">'
            + echapper(auteur.name) + '</span>';

        if (auteur.isAdmin) {
            var titre = echapper(loca.adminBadge || 'Administrator');
            morceaux += '<img src="/img/icons/badge-admin.png" width="55" height="14" class="badgeAdmin"'
                + ' alt="' + titre + '" title="' + titre + '">';
        }

        if (typeof auteur.honorPoints === 'number') {
            // La couleur suit le signe, comme partout ailleurs dans le jeu.
            var classe = auteur.honorPoints < 0 ? 'undermark' : (auteur.honorPoints > 0 ? 'middlemark' : '');
            morceaux += ' <span class="honorScore">(<span class="' + classe + '" title="'
                + echapper(loca.honourPoints || '') + '">' + echapper(auteur.honorPoints) + '</span>)</span>';
        }

        return morceaux;
    }

    /**
     * Une ligne de la salle. **Le seul endroit ou une ligne est fabriquee.**
     *
     * `contenu` arrive deja echappe du serveur ; les sauts de ligne deviennent des retours visibles.
     */
    function ligne(identifiant, auteur, contenu, horodatage, estDeMoi) {
        return '<li class="chat_msg' + (estDeMoi ? ' odd' : '') + '" data-chat-id="' + echapper(identifiant) + '">'
            + '<div class="msg_head">'
            + '<span class="msg_title blue_txt">' + decorations(auteur) + '</span>'
            + '<span class="msg_date fright">' + dateLisible(horodatage) + '</span>'
            + '</div>'
            + '<span class="msg_content">' + String(contenu).replace(/\n/g, '<br>') + '</span>'
            + '<div class="speechbubble_arrow"></div>'
            + '</li>';
    }

    function liste() {
        return jQuery(listeSelecteur);
    }

    function versLeBas() {
        var conteneur = liste().closest('.largeChatContainer');

        if (conteneur.length) {
            conteneur.scrollTop(conteneur[0].scrollHeight);
        }
    }

    function annoncer(texte) {
        var avis = jQuery('#generalChatNotice');

        if (!avis.length) {
            return;
        }

        if (!texte) {
            avis.hide().text('');

            return;
        }

        avis.text(texte).show();
    }

    /**
     * Ajoute une ligne si elle n'y est pas deja.
     *
     * **L'identite d'un message est son identifiant**, jamais son texte ni son instant : deux
     * joueurs peuvent ecrire la meme chose dans la meme seconde.
     */
    function ajouter(identifiant, auteur, contenu, horodatage, estDeMoi) {
        var cle = String(identifiant);

        if (affiches[cle]) {
            return;
        }

        affiches[cle] = true;

        liste().find('.js_generalChatPlaceholder').remove();
        liste().append(ligne(identifiant, auteur, contenu, horodatage, estDeMoi));
        versLeBas();
    }

    function moi() {
        return typeof playerId === 'undefined' ? 0 : parseInt(playerId, 10);
    }

    function chargerHistorique() {
        jQuery.ajax({
            url: '/chat/history',
            type: 'POST',
            dataType: 'json',
            data: { mode: 6 },
            success: function (reponse) {
                if (!reponse || !reponse.chatItems) {
                    return;
                }

                var ordre = reponse.chatItemsByDateAsc || [];

                for (var i = 0; i < ordre.length; i++) {
                    var item = reponse.chatItems[ordre[i]];

                    if (!item) {
                        continue;
                    }

                    ajouter(
                        item.chatID,
                        item.author,
                        item.chatContent,
                        item.date,
                        item.author && item.author.id === moi()
                    );
                }
            },
        });
    }

    function envoyer() {
        var zone = jQuery('#generalChatText');
        var texte = jQuery.trim(zone.val());

        if (texte === '') {
            return;
        }

        jQuery.ajax({
            url: '/chat/send',
            type: 'POST',
            dataType: 'json',
            data: { mode: 5, text: texte },
            success: function (reponse) {
                if (!reponse || reponse.status !== 'OK') {
                    if (reponse && reponse.status === 'TOO_MANY_MESSAGES') {
                        annoncer((loca.tooMany || '').replace(':seconds', reponse.retryAfter));
                    } else {
                        annoncer(loca.sendFailed || '');
                    }

                    return;
                }

                annoncer('');
                zone.val('');

                // **L'auteur n'est pas diffuse vers lui-meme** (`toOthers()`), donc sa propre ligne
                // est posee ici. Elle porte le meme identifiant que celle des autres : un rechargement
                // ne la dedoublera pas.
                ajouter(reponse.id, reponse.author, reponse.text, reponse.date, true);
            },
            error: function () {
                annoncer(loca.sendFailed || '');
            },
        });
    }

    function ecouter() {
        if (typeof window.Echo === 'undefined' || typeof window.Echo.private !== 'function') {
            annoncer(loca.disconnected || '');

            return;
        }

        window.Echo.private('chat.general')
            .listen('.ChatMessageSent', function (recu) {
                if (!recu || !recu.general) {
                    return;
                }

                // Le canal est unique : la diffusion ne peut pas filtrer par lecteur, donc c'est ici
                // que les auteurs ignores sont ecartes — depuis la meme liste que le serveur applique
                // a l'historique.
                if (recu.author && ignores[String(recu.author.id)]) {
                    return;
                }

                ajouter(recu.id, recu.author, recu.text, recu.date, false);
            });
    }

    function demarrer() {
        if (!jQuery(listeSelecteur).length) {
            return;
        }

        var liste = typeof generalChatIgnoredIds === 'undefined' ? [] : generalChatIgnoredIds;

        for (var i = 0; i < liste.length; i++) {
            ignores[String(liste[i])] = true;
        }

        jQuery('#generalChatSend').on('click', function () {
            envoyer();
        });

        jQuery('#generalChatText').on('keydown', function (evenement) {
            // Entree envoie, Maj+Entree passe a la ligne : la convention des chats du jeu.
            if (evenement.which === 13 && !evenement.shiftKey) {
                evenement.preventDefault();
                envoyer();
            }
        });

        chargerHistorique();
        ecouter();
    }

    // **La page doit exister.** Ce fichier est concatene dans un bundle charge par l'en-tete :
    // lance tout de suite, il ne trouverait ni la liste, ni `playerId`.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', demarrer);
    } else {
        demarrer();
    }
})();
