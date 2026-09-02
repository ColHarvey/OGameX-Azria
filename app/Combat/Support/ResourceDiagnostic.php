<?php

namespace OGame\Combat\Support;

/**
 * Un incident de conversion, avec de quoi le situer et le distinguer.
 *
 * ## Pourquoi une identite, et pourquoi elle n'est pas tiree au sort
 *
 * Deux incidents identiques en apparence — meme code, meme ressource, meme valeur — peuvent venir
 * de deux etapes differentes : le pillage de la cible et le plafonnement d'une cargaison de retour,
 * par exemple. Ce sont **deux occurrences reelles**, et les fusionner remplacerait six
 * avertissements par un seul, incomplet.
 *
 * Inversement, la meme occurrence propagee deux fois — le pipeline la rend, l'appelant l'agrege, la
 * mission la journalise — ne doit etre comptee qu'une fois.
 *
 * D'ou une identite **derivee**, jamais tiree : ni UUID, ni horloge, ni compteur global. Deux
 * executions des memes faits geles doivent produire exactement les memes identites, sans quoi le
 * calcul cesserait d'etre rejouable.
 *
 * ## L'ordinal vient d'une position metier
 *
 * La phase et la ressource situent l'incident dans le deroulement du combat, pas dans l'ordre
 * accidentel d'une boucle. Reordonner `['metal', 'crystal', 'deuterium']` ne change donc aucune
 * identite, tandis que deux incidents metier distincts restent distincts.
 */
final readonly class ResourceDiagnostic
{
    /**
     * @param string $code Ce qui s'est produit.
     * @param string $phase Le moment fonctionnel : pillage de la cible, collecte d'un Faucheur,
     *                      plafond d'un retour, recyclage.
     * @param string $subject La flotte ou le retour concerne, quand il y en a un.
     * @param string $resource La ressource concernee.
     * @param int $units La valeur canonique retenue. **Le flottant brut n'est pas conserve** : c'est
     *                   l'entier qui gouverne le combat.
     */
    public function __construct(
        public string $code,
        public string $phase,
        public string $subject,
        public string $resource,
        public int $units,
    ) {
    }

    /**
     * L'identite de cette occurrence, deterministe et stable.
     *
     * @return string
     */
    public function identity(): string
    {
        return $this->phase . '|' . $this->subject . '|' . $this->resource;
    }

    /**
     * Ce que l'identite promet : deux occurrences de meme identite doivent dire la meme chose.
     *
     * @param self $other
     * @return bool
     */
    public function saysTheSameAs(self $other): bool
    {
        return $this->code === $other->code && $this->units === $other->units;
    }
}
