<?php

namespace OGame\Combat\Decisions;

/**
 * Ce qu'une flotte qui arrive transporte, et qu'on ne peut pas faire disparaitre.
 *
 * ## Pourquoi la question doit etre posee
 *
 * Une flotte sans destination praticable ne peut pas se poser. Pour une operation systeme sans
 * flotte ni cargaison ni proprietaire — une frappe pirate jetable —, l'annuler sans impact est
 * exact. Pour une flotte de joueur chargee, c'est une **suppression silencieuse d'actifs**.
 *
 * Le cas ne devrait pas se produire : la planete mere garantit normalement une destination. C'est
 * precisement pourquoi il compte. S'il survient, c'est une corruption ou un etat administratif, et
 * la reponse est une mise en quarantaine avec alerte — jamais une regle de jeu ordinaire.
 */
final readonly class ArrivingAssets
{
    /**
     * @param bool $hasUnits Si la flotte porte des vaisseaux.
     * @param bool $hasCargo Si elle porte des ressources.
     */
    public function __construct(
        public bool $hasUnits,
        public bool $hasCargo,
    ) {
    }

    /**
     * Une operation pilotee par le serveur, sans rien a preserver.
     */
    public static function nothingToPreserve(): self
    {
        return new self(false, false);
    }

    /**
     * Une flotte qui porte des vaisseaux et une cargaison.
     */
    public static function fleetWithCargo(): self
    {
        return new self(true, true);
    }

    /**
     * S'il existe quelque chose que l'annulation ferait disparaitre.
     */
    public function arePreservable(): bool
    {
        return $this->hasUnits || $this->hasCargo;
    }
}
