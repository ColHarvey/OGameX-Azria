<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Services\PhotographedUniverse;
use Tests\UnitTestCase;

/**
 * Tout reglage d'univers que le moteur lit doit etre photographie a l'ouverture.
 *
 * ## Pourquoi un controle structurel
 *
 * `PhotographedUniverse` nomme sept reglages. Rien, dans un resultat de bataille, ne dirait qu'un
 * huitieme a ete ajoute au moteur et oublie ici : la bataille se jouerait simplement sur la valeur
 * vivante, et un changement d'administration pendant un ralliement atteindrait un combat deja
 * engage. Le defaut serait rare, invisible, et impossible a reproduire.
 *
 * Ce controle lit le moteur lui-meme. Il tombe le jour ou quelqu'un ajoute une lecture de reglage
 * sans l'ajouter a la photographie — et il nomme celle qui manque.
 *
 * ## Ce qu'il ne fait pas
 *
 * Il ne verifie pas que la valeur photographiee est *employee* : c'est le travail des temoins de
 * comportement (`FrozenUniverseSettingsTest`). Il verifie que rien n'a ete oublie.
 */
class SettingsSeenByTheEngineTest extends UnitTestCase
{
    /**
     * Les fichiers du moteur partage, ou toute lecture de reglage doit passer par la photographie.
     *
     * @return array<int, string>
     */
    private function engineFiles(): array
    {
        return [
            'app/GameMissions/BattleEngine/BattleEngine.php',
            'app/GameMissions/BattleEngine/PhpBattleEngine.php',
            'app/GameMissions/BattleEngine/RustBattleEngine.php',
        ];
    }

    public function testEverySettingTheEngineReadsIsPhotographed(): void
    {
        $lues = [];
        foreach ($this->engineFiles() as $chemin) {
            $source = file_get_contents(base_path($chemin));
            $this->assertIsString($source, 'Engine file not readable: ' . $chemin);

            if (preg_match_all('/\$this->settings->([a-zA-Z]+)\(/', $source, $trouvailles) > 0) {
                foreach ($trouvailles[1] as $methode) {
                    $lues[$methode] = $chemin;
                }
            }
        }

        $this->assertNotSame([], $lues, 'No setting read was found in the engine: the pattern no longer matches, and this guard would pass on anything.');

        $photographiees = array_keys(get_object_vars(new PhotographedUniverse(0, 0, 0, 0, 0, 0, 0)));
        $oubliees = array_values(array_diff(array_keys($lues), $photographiees));

        $this->assertSame(
            [],
            $oubliees,
            'The engine reads settings that the opening photograph does not freeze: '
            . implode(', ', array_map(static fn (string $m): string => $m . ' (' . $lues[$m] . ')', $oubliees))
        );
    }

    /**
     * Le temoin inverse de la liste : chaque fait photographie sert vraiment au moteur.
     *
     * Une photographie qui porterait un reglage que personne ne lit ferait croire a une protection
     * qui n'existe pas — et c'est exactement le genre d'affirmation qu'un rapport reprend.
     */
    public function testEveryPhotographedSettingIsActuallyReadByTheEngine(): void
    {
        $source = '';
        foreach ($this->engineFiles() as $chemin) {
            $source .= (string)file_get_contents(base_path($chemin));
        }

        foreach (array_keys(get_object_vars(new PhotographedUniverse(0, 0, 0, 0, 0, 0, 0))) as $fait) {
            $this->assertStringContainsString(
                $fait . '(',
                $source,
                'The photograph freezes ' . $fait . ', which no engine reads: the protection is decorative.'
            );
        }
    }
}
