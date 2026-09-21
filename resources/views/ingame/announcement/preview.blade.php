{{--
    L apercu de la bulle : **le rendu du joueur, pas seulement son balisage**.

    ## Ce que la premiere version manquait

    Le controleur rendait le partiel seul. Un partiel n a ni gabarit ni feuille : la page sortait en Times New
    Roman, sans cadre, sans badge, sur fond blanc — et **les retours a la ligne disparaissaient**, puisque
    `white-space: pre-wrap` vit dans la feuille. L apercu montrait donc au redacteur un texte que le joueur
    n aurait jamais vu. Mesure au navigateur, 21 septembre 2026 : une seule feuille chargee, celle de la barre
    de debogage.

    ## Ce que cette page reproduit, et pourquoi chaque element compte

    La chaine de conteneurs de la vue generale, **mesuree** et non recopiee :

        body#overview.ogame  ->  #pageContent (990 px)  ->  #middle (670 px)  ->  #eventlistcomponent
                             ->  #inhalt  ->  la bulle (670 px)

    Ce n est pas une largeur qu on recopie — `width: 670px` serait faux le jour ou le gabarit change. Ce sont
    les memes conteneurs, donc la meme largeur par construction, et les memes regles qui s y appliquent.

    L identifiant et les classes du `body` comptent aussi : la feuille du jeu s y accroche.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('t_ingame.announcement.preview_title') }}</title>

    {{-- La meme feuille que le jeu, par le meme mecanisme : un chemin en dur pointerait un jour sur un
         fichier disparu, le manifeste de Vite ne peut pas. --}}
    @vite(['resources/css/ingame.css'])

    <style>
        /* Le fond de la zone de contenu du jeu, pour que le cadre de la bulle se lise comme en jeu. */
        body { background: #0a1520; margin: 0; padding: 24px 0; }
        .azria-apercu-note {
            max-width: 990px; margin: 0 auto 18px; padding: 10px 14px;
            border: 1px solid #5689aa; border-radius: 6px; background: #102839;
            color: #c7d9e7; font: 13px/1.5 Arial, sans-serif;
        }
        .azria-apercu-note strong { color: #e0effb; }
        #pageContent { margin: 0 auto; }
    </style>
</head>
<body id="overview" class="ogame lang-{{ app()->getLocale() }} default no-touch">

<p class="azria-apercu-note">
    <strong>{{ __('t_ingame.announcement.preview_title') }}</strong> —
    {{ __('t_ingame.announcement.preview_note') }}
</p>

{{-- Les conteneurs de la vue generale, dans le meme ordre : c est eux qui donnent la largeur. --}}
<div id="pageContent">
    <div id="middle">
        <div id="eventlistcomponent">
            <div id="inhalt">
                @include('ingame.announcement.bubble', ['announcement' => $announcement, 'preview' => true])
            </div>
        </div>
    </div>
</div>

</body>
</html>
