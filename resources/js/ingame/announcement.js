/**
 * La fermeture de la bulle d annonce.
 *
 * **Le navigateur ne decide rien.** Il envoie la version qu il affiche, et le serveur dit si la fermeture est
 * recevable — il relit le drapeau « masquable » de **cette version-la**, pas du brouillon courant. Un bouton
 * absent n est pas une protection.
 *
 * **Une fermeture ne peut pas masquer une publication plus recente.** Elle vise la version affichee ; si une
 * nouvelle est arrivee entre-temps, c est l ancienne qui est fermee, et la nouvelle s affichera au prochain
 * chargement. C est pour cela que la version voyage dans la requete au lieu d etre deduite par le serveur.
 */
var azAnnouncement = {
    /** Une fermeture est-elle en vol ? Deux clics n en envoient pas deux. */
    enVol: false,

    bulle: function () {
        return document.getElementById('azriaAnnouncement');
    },

    /**
     * Poser le gestionnaire. **Idempotent** : un second appel ne double pas l ecouteur.
     *
     * Le retrait vise un sous-espace de noms a lui : retirer tout `.azAnnouncement` d un element partage
     * emporterait le gestionnaire d un voisin.
     */
    init: function () {
        var a = azAnnouncement;

        $(document).off('click.azAnnouncementClose')
            .on('click.azAnnouncementClose', '.azria-announcement__close', function (e) {
                e.preventDefault();
                a.fermer(this);
            });
    },

    fermer: function (bouton) {
        var a = azAnnouncement;
        var bulle = a.bulle();
        if (!bulle || a.enVol) {
            return;
        }

        // L apercu de l administration emploie le meme rendu : sa croix ne doit rien ecrire.
        if (bulle.getAttribute('data-preview') === '1') {
            return;
        }

        var version = parseInt(bulle.getAttribute('data-version') || '0', 10);
        if (!(version > 0)) {
            return;
        }

        a.enVol = true;
        bouton.disabled = true;

        $.ajax({
            url: bouton.getAttribute('data-dismiss-url'),
            type: 'POST',
            dataType: 'json',
            data: { _token: bouton.getAttribute('data-token'), version: version },
            success: function () {
                a.enVol = false;
                // On retire la bulle du flux : les files remontent d elles-memes, sans rechargement.
                if (bulle.parentNode) {
                    bulle.parentNode.removeChild(bulle);
                }
            },
            error: function () {
                // **Le serveur a refuse, et on ne fait pas semblant.** La bulle reste affichee : la masquer
                // ici ferait croire a une fermeture que la base ne porte pas, et elle reviendrait au
                // rechargement suivant.
                a.enVol = false;
                bouton.disabled = false;
            }
        });
    }
};

function initAnnouncement() {
    azAnnouncement.init();
}
