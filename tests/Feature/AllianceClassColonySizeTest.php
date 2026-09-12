<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Enums\AllianceClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Alliance;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * +5 % de cases sur une planete colonisee par un membre d une alliance de Chercheurs.
 *
 * ## Le nombre de cases est tire au hasard : la mesure fixe la graine
 *
 * `setupPlanetProperties()` tire `rand($min, $max)` dans la fourchette de la position visee. Deux
 * colonisations sans precaution donneraient deux nombres differents, et l ecart de 5 % serait noye.
 * Le banc pose donc **la meme graine** avant chaque colonisation et vise **la meme position** dans
 * deux systemes libres : la fourchette, la suite de tirages et donc l assiette sont identiques, et
 * le seul ecart restant est le bonus.
 *
 * ## La mesure se prend sur la colonne, pas sur l accesseur
 *
 * `getPlanetFieldMax()` ajoute les cases du terraformeur et de la base lunaire. Une colonie neuve
 * n en a aucune, mais lire `field_max` en base retire toute ambiguite sur ce qui est mesure.
 */
class AllianceClassColonySizeTest extends AccountTestCase
{
    /**
     * La graine des deux colonisations. Sa valeur n'a aucune importance ; son **unicite** en a.
     */
    private const int GRAINE = 20260912;

    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');

        parent::tearDown();
    }

    /**
     * **Une alliance de Chercheurs agrandit la colonie de 5 %.**
     */
    public function testAResearchersAllianceGivesFivePercentMoreFieldsOnAColonisedPlanet(): void
    {
        // La classe de personnage porte son propre multiplicateur de taille : l'ecarter laisse le
        // bonus d'alliance seul dans la mesure.
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => null]);

        [$premiere, $seconde] = $this->deuxCasesLibresALaMemePosition();

        $sansAlliance = $this->casesDUneColonieEn($premiere);

        $this->uneAllianceDeChercheurs();

        $avecAlliance = $this->casesDUneColonieEn($seconde);

        $this->assertGreaterThan($sansAlliance, $avecAlliance, 'La colonie d une alliance de Chercheurs n est pas plus grande.');
        $this->assertSame(
            (int)($sansAlliance * 1.05),
            $avecAlliance,
            'L agrandissement ne vaut pas 5 % : sans alliance ' . $sansAlliance . ' cases, avec ' . $avecAlliance . '.'
        );
    }

    /**
     * **Une alliance de Guerriers ne change pas la taille d une colonie.**
     *
     * Sans ce temoin, un bonus pose sur toutes les classes passerait le premier essai.
     */
    public function testAWarriorsAllianceLeavesTheColonyItsNaturalSize(): void
    {
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => null]);

        [$premiere, $seconde] = $this->deuxCasesLibresALaMemePosition();

        $sansAlliance = $this->casesDUneColonieEn($premiere);

        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);

        $this->assertSame(
            $sansAlliance,
            $this->casesDUneColonieEn($seconde),
            'Une alliance de Guerriers agrandit les colonies, ce que sa classe ne promet pas.'
        );
    }

    /**
     * Le nombre de cases d une colonie posee sur ces coordonnees, la graine etant fixee.
     */
    private function casesDUneColonieEn(Coordinate $ou): int
    {
        // **La graine est posee juste avant la creation.** `rand()` et `mt_rand()` partagent le meme
        // generateur depuis PHP 7.1 : une graine identique rend la meme suite, donc la meme assiette.
        srand(self::GRAINE);

        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $colonie = resolve(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($joueur, $ou);

        return (int)DB::table('planets')->where('id', $colonie->getPlanetId())->value('field_max');
    }

    /**
     * Deux coordonnees libres, **a la meme position**, dans deux systemes differents.
     *
     * La position decide de la fourchette de cases : deux positions differentes rendraient les deux
     * mesures incomparables, et l ecart de 5 % ne voudrait plus rien dire.
     *
     * @return array{0: Coordinate, 1: Coordinate}
     */
    private function deuxCasesLibresALaMemePosition(): array
    {
        $position = 8;
        $galaxie = $this->planetService->getPlanetCoordinates()->galaxy;
        $libres = [];

        for ($systeme = 1; $systeme <= 499 && count($libres) < 2; $systeme++) {
            $occupee = DB::table('planets')
                ->where('galaxy', $galaxie)
                ->where('system', $systeme)
                ->where('planet', $position)
                ->exists();

            if (!$occupee) {
                $libres[] = new Coordinate($galaxie, $systeme, $position);
            }
        }

        $this->assertCount(2, $libres, 'Aucune paire de cases libres a la position ' . $position . ' dans la galaxie ' . $galaxie . '.');

        return [$libres[0], $libres[1]];
    }

    private function uneAllianceDeChercheurs(): Alliance
    {
        return $this->uneAllianceDeClasse(AllianceClass::RESEARCHERS);
    }

    private function uneAllianceDeClasse(AllianceClass $classe): Alliance
    {
        $alliance = resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'TA' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Taille ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        $this->assertNotNull($alliance);

        // Une alliance fondee a l instant n'a pas les quatorze jours qui offrent le premier choix.
        DB::table('users')->where('id', $this->currentUserId)->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);

        resolve(AllianceClassService::class)->choose(
            User::query()->findOrFail($this->currentUserId),
            $alliance,
            $classe
        );

        return $alliance;
    }
}
