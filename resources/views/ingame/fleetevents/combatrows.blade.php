{{--
    Les cartes de combat seules, sans le tableau qui les entoure.

    C'est ce fragment que le navigateur redemande pour rafraichir les batailles sans toucher aux
    lignes de mouvement, ni fermer un detail ouvert, ni deplacer le defilement.
--}}
@foreach ($combatPanel['combats'] as $combat)
    @include ('ingame.fleetevents.combatrow', ['combat' => $combat])
@endforeach
