/**
 * Laravel Echo initialization for real-time chat via Laravel Reverb.
 *
 * This file sets up the WebSocket connection using Laravel Echo with the
 * Reverb (Pusher-compatible) broadcaster. It must be loaded before chat.js.
 *
 * The variables reverbAppKey, reverbHost, reverbPort, and reverbScheme
 * are expected to be set in the main Blade layout before this script loads.
 *
 * ## Ce fichier construisait un objet de module, pas un client
 *
 * `echo.iife.js` n'expose pas la classe : il rend un **espace de noms** —
 * `{ Channel, Connector, EventFormatter, default: Echo, __esModule: true }` — et la classe vit
 * sous `.default`. Le `var Echo = ...` de ce fichier concatene devient donc `window.Echo`, et le
 * `new Echo({...})` qui suivait levait « Echo is not a constructor ».
 *
 * Le bundle etant une **simple concatenation**, cette exception non rattrapee arretait le script
 * a cette ligne : `chat.js`, `combat.js` et la pastille de courrier — les trois fichiers suivants —
 * ne s'executaient jamais. Le temps reel n'a donc jamais fonctionne dans le navigateur ; le chat
 * n'avait aucun secours, et le combat retombait sur son sondage de dix secondes, ce qui donnait
 * l'illusion que le canal direct marchait.
 *
 * Deux corrections, et chacune ferme une moitie du defaut :
 *
 * 1. **Le constructeur est resolu** au lieu d'etre suppose. La forme `.default` et la forme
 *    directe sont acceptees : une version future qui exporterait la classe elle-meme continuerait
 *    de fonctionner.
 * 2. **Rien ne s'echappe d'ici.** Un echec de construction laisse `window.Echo` absent, ce que les
 *    trois modules savent lire — ils se degradent en silence. Une exception, elle, les emportait.
 */
(function () {
    // Only initialize if Reverb config variables are available
    if (typeof reverbAppKey === 'undefined' || !reverbAppKey) {
        return;
    }

    /*
     * La classe, quelle que soit la forme sous laquelle la bibliotheque l'expose.
     *
     * On ne lit pas `window.Echo` : a cet instant il porte encore l'espace de noms pose par le
     * `var Echo` du fichier precedent, et c'est precisement ce qu'on cherche a demeler.
     */
    var Constructeur = null;

    if (typeof Echo === 'function') {
        Constructeur = Echo;
    } else if (Echo && typeof Echo.default === 'function') {
        Constructeur = Echo.default;
    }

    if (Constructeur === null) {
        if (window.console && window.console.error) {
            window.console.error('Echo : aucune classe utilisable dans la bibliotheque chargee ; le temps reel est desactive.');
        }

        return;
    }

    try {
        window.Echo = new Constructeur({
            broadcaster: 'reverb',
            key: reverbAppKey,
            wsHost: reverbHost,
            wsPort: reverbPort,
            wssPort: reverbPort,
            forceTLS: reverbScheme === 'https',
            enabledTransports: ['ws', 'wss'],
        });
        /*
         * **`toOthers()` n'exclut personne sans cet en-tete.**
         *
         * Le serveur diffuse les messages de chat par `broadcast(...)->toOthers()`, dont le
         * seul moyen de reconnaitre l'expediteur est l'identifiant de socket que la requete
         * porte. Il n'etait envoye nulle part : l'auteur d'un message d'alliance le recevait
         * donc en retour, en double de la copie que son propre envoi affiche.
         *
         * En `beforeSend` et non dans `headers` : l'identifiant n'existe qu'une fois la
         * connexion etablie, et il change a chaque reconnexion. Quatre autres appels a
         * `ajaxSetup` posent `headers` ; une cle distincte les laisse tranquilles.
         */
        if (window.jQuery) {
            window.jQuery.ajaxSetup({
                beforeSend: function (requete) {
                    if (!window.Echo || typeof window.Echo.socketId !== 'function') {
                        return;
                    }

                    var socket = window.Echo.socketId();

                    if (socket) {
                        requete.setRequestHeader('X-Socket-ID', socket);
                    }
                },
            });
        }
    } catch (e) {
        // **L'echec ne doit emporter personne.** Ce fichier est concatene avant chat.js, combat.js
        // et la pastille de courrier : une exception qui sort d'ici arrete le script et les prive
        // tous les trois, y compris de leurs secours. Sans `window.Echo`, ils se taisent, ce qui
        // est exactement le comportement voulu.
        if (window.console && window.console.error) {
            window.console.error('Echo : connexion impossible ; le temps reel est desactive.', e);
        }
    }
})();
