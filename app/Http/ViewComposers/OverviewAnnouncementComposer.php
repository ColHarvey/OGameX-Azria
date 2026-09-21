<?php

namespace OGame\Http\ViewComposers;

use Illuminate\View\View;
use OGame\Models\User;
use OGame\Services\AnnouncementBubbleService;

/**
 * Donne a la vue generale la bulle d annonce que **ce compte** doit voir, ou rien.
 *
 * ## Pourquoi un composeur et non le controleur
 *
 * La vue generale est rendue par un controleur que cette fonctionnalite n a aucune raison de modifier. Le
 * composeur attache la donnee a la vue, et la vue decide de l afficher : le jour ou la bulle disparait, rien
 * a defaire ailleurs.
 *
 * ## Ce qu il ne decide pas
 *
 * Il ne dit pas si la bulle est active, ni si elle a ete fermee, ni quelle version est courante : tout cela
 * vit dans {@see AnnouncementBubbleService::visibleFor()}, un seul endroit. Un composeur qui referait ce
 * raisonnement serait un second moteur de decision, et les deux finiraient par diverger.
 */
class OverviewAnnouncementComposer
{
    public function __construct(private AnnouncementBubbleService $bubbles)
    {
    }

    public function compose(View $view): void
    {
        $identifiant = (int)auth()->id();
        $compte = $identifiant > 0 ? User::query()->find($identifiant) : null;

        $view->with('announcement', $this->bubbles->visibleFor($compte));
    }
}
