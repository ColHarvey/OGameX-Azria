<?php

namespace Tests\Support;

use OGame\Models\Setting;
use OGame\Services\SettingsService;
use ReflectionProperty;

/**
 * Pose les reglages qu un essai exige, et rend le monde exactement comme il l a trouve.
 *
 * ## Les deux moities, et aucune n est facultative
 *
 * **Poser** : « un essai pose l interrupteur qu il suppose ». Les reglages vivent en base, la base
 * d un processus est partagee par des dizaines de classes, et supposer une valeur par defaut revient
 * a dependre de ses voisins. `BattleReferenceVectorsTest` l a appris en rougissant dans la suite
 * alors qu il passait en isolement.
 *
 * **Rendre** : sinon l essai fait a ses voisins ce que ses voisins lui ont fait. C est la moitie
 * qu on oublie, et elle compte autant. Le retour vit dans `tearDown()`, donc il s execute **meme
 * quand l essai echoue** — le cas ou l oubli coute le plus cher : un rouge suivi de rouges qui n ont
 * rien a voir avec lui.
 *
 * ## Rendre l existence, pas seulement la valeur
 *
 * Une premiere version laissait a sa valeur par defaut un reglage qui n existait pas, en se disant
 * que la valeur **effective** etait la meme. **Elle ne l est que pour l instant**, et Codex a nomme
 * le piege : une ligne ecrite explicitement survit a un changement du defaut. Le depot l a paye sur
 * le delai d alliance — le defaut est passe de trois a sept jours, et toute ligne posee a trois
 * aurait continue de dire trois.
 *
 * Un essai qui laisse derriere lui une ligne que personne n avait ecrite fabrique donc une bombe a
 * retardement : le jour ou le defaut change, le reglage ne suit pas, et rien ne dit pourquoi. Ce
 * trait releve donc **l existence et la valeur**, et rend les deux.
 *
 * ## Le nettoyage est reserve aux essais
 *
 * Le jeu n a pas de fonction de suppression d un reglage, et il n en recoit pas une pour les besoins
 * du banc : la ligne est retiree ici, par le modele, et le cache du service — un singleton, avec un
 * tableau prive rempli une seule fois — est vide pour que la lecture suivante reparte de la table.
 * Sans cela le service continuerait de servir une valeur que la base ne porte plus, ce qui serait un
 * mensonge de plus au lieu d un de moins.
 */
trait PinsSettings
{
    /**
     * Ce que valait chaque reglage avant qu on y touche, `null` quand la ligne n existait pas.
     *
     * @var array<string, string|null>
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
                $ligne = Setting::query()->where('key', $clef)->value('value');

                $this->reglagesAvant[$clef] = $ligne === null ? null : (string)$ligne;
            }

            $reglages->set($clef, $valeur);
        }
    }

    /**
     * Rend les reglages poses — leur valeur, et leur absence. Sans effet si rien n a ete pose.
     */
    protected function restorePinnedSettings(): void
    {
        if ($this->reglagesAvant === []) {
            return;
        }

        $reglages = resolve(SettingsService::class);

        foreach ($this->reglagesAvant as $clef => $valeur) {
            if ($valeur === null) {
                Setting::query()->where('key', $clef)->delete();

                continue;
            }

            $reglages->set($clef, $valeur);
        }

        $this->reglagesAvant = [];

        $this->forgetTheSettingsCache($reglages);
    }

    /**
     * Vide le cache du service pour que la lecture suivante reparte de la table.
     *
     * **Le tableau entier, pas une clef.** Le service ne recharge que lorsqu il est vide
     * (`loadFromDatabase()` derriere un `empty()`) : retirer une seule entree le laisserait croire
     * qu il connait deja tout, et la ligne effacee continuerait d etre servie.
     */
    private function forgetTheSettingsCache(SettingsService $reglages): void
    {
        (new ReflectionProperty(SettingsService::class, 'settings'))->setValue($reglages, []);
    }
}
