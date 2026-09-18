{{-- Le message d une action (espece choisie, technologie placee, vol lance...) passe par la boite du jeu, `fadeBox`,
     qui s affiche puis s efface : un texte brut au-dessus de l en-tete deformait toute la page (capture de Keven,
     journal §155.26). jQuery est charge avant le contenu ; @json, jamais {{ }}, dans un script. --}}
@if (session('status'))
    <script type="text/javascript">
        $(function () {
            fadeBox(@json(session('status')), false);
        });
    </script>
@endif
