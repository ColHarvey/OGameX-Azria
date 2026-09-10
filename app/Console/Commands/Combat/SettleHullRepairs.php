<?php

namespace OGame\Console\Commands\Combat;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Hull\HullRepairService;
use OGame\Services\SettingsService;

/**
 * Rend au joueur les vaisseaux dont la reparation est terminee.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CETTE COMMANDE FAIT SI PEU
 *
 * La reparation est **progressive et interpolee dans le temps** (decision 5 du 10 septembre 2026) :
 * la part accomplie est une fonction pure de l horloge, calculee a la lecture. Rien n a donc besoin
 * d etre avance periodiquement — un joueur qui regarde son dock voit l avancement exact sans qu
 * aucun travailleur ne soit passe.
 *
 * Ce passage ne sert qu au **seul effet qui doit vraiment se produire une fois** : rendre les unites
 * quand l echeance est atteinte, c est-a-dire retirer leurs degats. Et meme celui-la se produirait
 * sans lui — un ordre echu que personne n a regle rend des unites intactes a la premiere lecture —
 * mais il resterait « en reparation » aux yeux du dock, qui refuserait un second ordre.
 *
 * **L heure vient de l horloge, jamais d une option.** Une option la portant permettrait de terminer
 * une reparation avant son terme en la donnant a l avance.
 *
 * **Modifier `routes/console.php` impose `ogamex restart ogamex-scheduler`** : le conteneur ne lit
 * le fichier qu a son demarrage.
 */
#[Description('Rendre les vaisseaux dont la reparation au chantier spatial est terminee')]
#[Signature('ogamex:coques:reparer')]
class SettleHullRepairs extends Command
{
    public function __construct(
        private readonly HullRepairService $reparations,
        private readonly SettingsService $settings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // **Rien ne tourne tant que l interrupteur est desarme.** Pas une table lue, pas une ligne
        // ecrite : c est l exigence du cahier des charges, et la placer ici plutot que dans le
        // service evite qu un passage planifie reveille le chantier sur un serveur qui ne l a pas
        // active.
        if (!$this->settings->hullDamageEnabled()) {
            return self::SUCCESS;
        }

        $regles = $this->reparations->settleDue((int)Date::now()->timestamp);

        if ($regles > 0) {
            $this->line('  ' . $regles . ' reparation(s) terminee(s).');
        }

        return self::SUCCESS;
    }
}
