{{-- L enveloppe « fenetre » : le seul contenu, injecte par le module `alliance-profile.js` dans la fenetre
     superposee du jeu. Aucun script, aucun asset : le bundle est deja charge par la page qui a ouvert la fenetre,
     et les gestionnaires sont delegues sur le document une fois pour toutes. --}}
@include('ingame.alliance.profile.content')
