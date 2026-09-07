<?php

namespace OGame\Observers;

use Illuminate\Support\Facades\DB;
use OGame\Events\GalaxySystemChanged;
use OGame\Models\DebrisField;

/**
 * Un champ de debris apparait, change ou disparait : le systeme l'annonce.
 *
 * Les debris sont publics — la Galaxie les montre a tout joueur qui regarde le systeme, avec leurs
 * quantites. L'annonce, elle, ne porte que les coordonnees : le navigateur redemande la
 * photographie, et c'est elle qui dit combien.
 *
 * `saved` couvre la creation et chaque mise a jour (une bataille qui alimente, un recyclage qui
 * preleve) ; `deleted` couvre un champ vide. Apres la validation, jamais dedans : un reglement de
 * combat peut etre annule, et un champ qui n'existera pas ne doit pas etre annonce.
 */
class DebrisFieldObserver
{
    public function saved(DebrisField $debris): void
    {
        $this->announce($debris, $debris->wasRecentlyCreated ? 'created' : 'updated');
    }

    public function deleted(DebrisField $debris): void
    {
        $this->announce($debris, 'deleted');
    }

    private function announce(DebrisField $debris, string $change): void
    {
        $galaxy = (int)$debris->galaxy;
        $system = (int)$debris->system;
        $position = (int)$debris->planet;

        if ($galaxy === 0 || $system === 0) {
            return;
        }

        DB::afterCommit(static function () use ($galaxy, $system, $position, $change): void {
            broadcast(new GalaxySystemChanged($galaxy, $system, $position, 'debris', $change));
        });
    }
}
