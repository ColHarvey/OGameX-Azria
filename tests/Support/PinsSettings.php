<?php

namespace Tests\Support;

use OGame\Services\SettingsService;

/**
 * Pose les reglages qu un essai exige, et les rend au demontage.
 *
 * ## Les deux moities, et aucune n est facultative
 *
 * **Poser** : « un essai pose l interrupteur qu il suppose ». Les reglages vivent en base, la base
 * d un processus est partagee par des dizaines de classes, et supposer une valeur par defaut revient
 * a dependre de ses voisins. `BattleReferenceVectorsTest` l a appris en rougissant dans la suite
 * alors qu il passait en isolement.
 *
 * **Rendre** : sinon l essai fait a ses voisins ce que ses voisins lui ont fait. C est la moitie
 * qu on oublie, et elle compte autant — remarque de Codex, 9 septembre 2026.
 *
 * Le retour se fait dans `tearDown()`, donc **meme quand l essai echoue** : c est precisement le cas
 * ou l oubli coute le plus cher, un rouge suivi de rouges qui n ont rien a voir avec lui.
 *
 * ## Ce que « rendre » veut dire exactement
 *
 * La valeur **effective** d avant. Un reglage qui n existait pas est laisse a sa valeur par defaut
 * plutot que supprime — le service n offre pas de retrait, et un lecteur obtient la meme chose dans
 * les deux cas. Le dire ici plutot que de laisser croire que la ligne disparait.
 */
trait PinsSettings
{
    /**
     * Ce que valait chaque reglage avant qu on y touche.
     *
     * @var array<string, string>
     */
    private array $reglagesAvant = [];

    /**
     * @param array<string, int|string> $valeurs
     */
    protected function pinSettings(array $valeurs): void
    {
        $reglages = resolve(SettingsService::class);

        foreach ($valeurs as $clef => $valeur) {
            // La premiere pose fait foi : deux appels ne doivent pas enregistrer comme « avant »
            // ce que le premier vient d ecrire.
            if (!array_key_exists($clef, $this->reglagesAvant)) {
                $this->reglagesAvant[$clef] = $reglages->get($clef, '');
            }

            $reglages->set($clef, $valeur);
        }
    }

    /**
     * Rend les reglages poses. Sans effet si rien n a ete pose.
     */
    protected function restorePinnedSettings(): void
    {
        $reglages = resolve(SettingsService::class);

        foreach ($this->reglagesAvant as $clef => $valeur) {
            if ($valeur !== '') {
                $reglages->set($clef, $valeur);
            }
        }

        $this->reglagesAvant = [];
    }
}
