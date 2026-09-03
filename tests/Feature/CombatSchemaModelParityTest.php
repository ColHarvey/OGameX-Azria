<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatEffectReceipt;
use OGame\Models\CombatInstance;
use OGame\Models\CombatLootReservation;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatSnapshotInclusion;
use Tests\TestCase;

/**
 * Chaque modele de combat connait exactement les colonnes de sa table.
 *
 * ## Le defaut que cette garde attrape
 *
 * Eloquent **ignore silencieusement** ce qui n'est pas assignable. Une colonne ajoutee au schema et
 * oubliee dans `Fillable` ne provoque aucune erreur : la valeur est simplement jetee a l'ecriture.
 *
 * Sur ces tables-la, c'est particulierement couteux. Les colonnes de faits geles existent pour
 * qu'un combat garde les regles sous lesquelles il a commence ; une seule oubliee, et le fait cense
 * etre fige ne l'est jamais. Le defaut n'apparaitrait qu'a la resolution, deux heures plus tard, sur
 * un combat dont personne ne peut plus reconstituer l'etat.
 *
 * L'inverse compte aussi : une colonne assignable qui n'existe plus dans la table fait lever a
 * l'ecriture, et seulement sur le chemin qui l'utilise.
 *
 * ## Pourquoi la comparaison est exacte
 *
 * Pas « toutes les colonnes du schema sont assignables », mais **les deux listes sont les memes**.
 * Une garde a sens unique laisserait passer la moitie des defauts, et c'est toujours celle qu'on
 * n'a pas ecrite qui se produit.
 *
 * `id`, `created_at` et `updated_at` sont exclus : Eloquent les gere lui-meme, et les rendre
 * assignables serait une faute distincte.
 */
class CombatSchemaModelParityTest extends TestCase
{
    /**
     * Les colonnes qu'Eloquent gere seul, et qui n'ont rien a faire dans `Fillable`.
     *
     * @var array<int, string>
     */
    private const array MANAGED_BY_ELOQUENT = ['id', 'created_at', 'updated_at'];

    /**
     * Chaque modele de combat declare exactement les colonnes de sa table.
     */
    public function testEveryCombatModelKnowsItsOwnColumns(): void
    {
        $ecarts = [];

        foreach ($this->combatModels() as $classe) {
            /** @var Model $modele */
            $modele = new $classe();

            $table = $modele->getTable();

            $this->assertTrue(
                Schema::hasTable($table),
                "The model {$classe} points at a table that does not exist: {$table}."
            );

            $colonnes = array_values(array_diff(
                Schema::getColumnListing($table),
                self::MANAGED_BY_ELOQUENT
            ));

            $assignables = $modele->getFillable();

            sort($colonnes);
            sort($assignables);

            if ($colonnes === $assignables) {
                continue;
            }

            $manquantes = array_values(array_diff($colonnes, $assignables));
            $inconnues = array_values(array_diff($assignables, $colonnes));

            $ecarts[] = $classe . ' — dans la table mais pas assignables : '
                . ($manquantes === [] ? 'aucune' : implode(', ', $manquantes))
                . ' | assignables mais absentes de la table : '
                . ($inconnues === [] ? 'aucune' : implode(', ', $inconnues));
        }

        $this->assertSame(
            [],
            $ecarts,
            "A combat model no longer matches its table. A column missing from Fillable is written "
            . "silently to nowhere, and a frozen fact that is never frozen only shows up at resolution.\n"
            . implode("\n", $ecarts)
        );
    }

    /**
     * Les modeles du systeme de combat persistant.
     *
     * @return array<int, class-string<Model>>
     */
    private function combatModels(): array
    {
        return [
            CombatInstance::class,
            CombatParticipant::class,
            CelestialBodyCombatBarrier::class,
            CombatEffectReceipt::class,
            CombatSnapshotInclusion::class,
            CombatLootReservation::class,
            CombatOutboxMessage::class,
        ];
    }
}
