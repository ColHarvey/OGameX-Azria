<?php

namespace Tests\Support;

use OGame\Models\Lifeforms\LifeformDiscoveryOddsRevision;
use OGame\Models\Lifeforms\LifeformRuleRevision;

/**
 * Rend les **revisions datees** qu un essai fait ecrire en enregistrant les reglages du serveur.
 *
 * ## Le piege, mesure le 19 septembre 2026
 *
 * Enregistrer la page des reglages ecrit une revision des vitesses (`ServerSettingsController` ->
 * `LifeformRuleRevisions::recordIfChanged()`), avec les vitesses **vivantes du banc** — donc `economy_speed = 8`, la
 * valeur que `AccountTestCase::setUp()` pose pour tous les essais. Cette ligne survit a la classe qui l a provoquee.
 *
 * Or `LifeformRuleRevisions::at()` prefere **toute** revision au reglage vivant, et retombe meme sur la plus ancienne
 * quand aucune ne precede l instant demande. Une classe de formes de vie qui epingle `economy_speed = 1` et calcule son
 * attendu avec cette vitesse recevait alors une duree **huit fois plus courte** : `LifeformEffectWitnessesTest` a
 * rougi ainsi (5 940 au lieu de 47 520), et seulement quand la repartition en processus mettait une classe
 * d administration devant lui. Reproduit en huit secondes en enchainant les deux classes (journal §166).
 *
 * ## Ce que ce trait fait, et ne fait pas
 *
 * Il releve l identifiant le plus haut de chaque table avant l essai, et supprime apres coup **les lignes que l essai a
 * ajoutees**, jamais celles qu il a trouvees. Il ne touche pas aux reglages eux-memes : c est le travail de
 * [[PinsSettings]], et les deux vont ensemble — rendre la valeur sans rendre son historique laisse le monde a moitie
 * remis.
 */
trait ReturnsLifeformRuleRevisions
{
    private int $revisionsDeReglesAvant = 0;

    private int $revisionsDeCotesAvant = 0;

    protected function rememberLifeformRuleRevisions(): void
    {
        $this->revisionsDeReglesAvant = (int)LifeformRuleRevision::query()->max('id');
        $this->revisionsDeCotesAvant = (int)LifeformDiscoveryOddsRevision::query()->max('id');
    }

    protected function returnLifeformRuleRevisions(): void
    {
        LifeformRuleRevision::query()->where('id', '>', $this->revisionsDeReglesAvant)->delete();
        LifeformDiscoveryOddsRevision::query()->where('id', '>', $this->revisionsDeCotesAvant)->delete();
    }
}
