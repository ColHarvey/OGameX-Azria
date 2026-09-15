<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\MilitaryTallyPublisher;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryValue;
use OGame\Services\ObjectService;
use stdClass;

/**
 * Ce qu un banc des cumuls militaires fait toujours : activer la collecte a un instant, relire le registre par prefixe
 * de clef, evaluer des unites au poids du jeu, et rendre le registre tel qu il l a trouve.
 */
trait ReadsMilitaryTallies
{
    protected function activerLesCumulsDepuis(int $instant): void
    {
        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        DB::table('settings')->insert([
            'key' => MilitaryTallyRecorder::SINCE_KEY,
            'value' => (string)$instant,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }

    /**
     * Les prefixes de clef que l essai a lus : ce sont les siens, et il les efface en partant.
     *
     * @var list<string>
     */
    protected array $prefixesDesCumuls = [];

    /**
     * Rend le registre et les reglages tels que l essai les a trouves. **La base d un passage simple survit d un
     * passage a l autre** : un evenement laisse derriere soi, sous une clef fixe, ferait refuser la meme inscription
     * au passage suivant.
     */
    protected function desactiverLesCumuls(): void
    {
        DB::table('settings')->whereIn('key', [MilitaryTallyRecorder::SINCE_KEY, MilitaryTallyPublisher::PUBLISHED_KEY])->delete();

        foreach (array_unique($this->prefixesDesCumuls) as $prefixe) {
            DB::table('military_tally_events')->where('event_key', 'like', $prefixe . '%')->delete();
        }

        $this->prefixesDesCumuls = [];
    }

    /**
     * Efface ce qu un passage precedent aurait laisse sous un prefixe de clef fixe, avant de l ecrire de nouveau.
     */
    protected function effacerLesCumulsSous(string $prefixe): void
    {
        $this->prefixesDesCumuls[] = $prefixe;
        DB::table('military_tally_events')->where('event_key', 'like', $prefixe . '%')->delete();
    }

    /**
     * Les evenements du registre sous un prefixe, par clef croissante.
     *
     * @return array<string, stdClass>
     */
    protected function evenementsSous(string $prefixe): array
    {
        $this->prefixesDesCumuls[] = $prefixe;
        $lignes = [];

        foreach (DB::table('military_tally_events')->where('event_key', 'like', $prefixe . '%')->orderBy('event_key')->get() as $ligne) {
            $lignes[(string)$ligne->event_key] = $ligne;
        }

        return $lignes;
    }

    /**
     * La valeur militaire, en demi-unites, d unites nommees par leur nom de machine.
     *
     * @param array<string, int> $unites
     */
    protected function valeurMilitaireDe(array $unites): int
    {
        $collection = new UnitCollection();

        foreach ($unites as $nom => $nombre) {
            if ((int)$nombre > 0) {
                $collection->addUnit(ObjectService::getUnitObjectByMachineName((string)$nom), (int)$nombre);
            }
        }

        return MilitaryValue::ofUnits($collection);
    }

    /**
     * La somme, unite par unite, des pertes de chaque round d un rapport de bataille.
     *
     * @param array<int, array<string, mixed>> $rounds
     * @return array<string, int>
     */
    protected function pertesCumuleesDesRounds(array $rounds, string $champ): array
    {
        $total = [];

        foreach ($rounds as $round) {
            foreach ((array)($round[$champ] ?? []) as $nom => $nombre) {
                $total[(string)$nom] = ($total[(string)$nom] ?? 0) + (int)$nombre;
            }
        }

        return $total;
    }
}
