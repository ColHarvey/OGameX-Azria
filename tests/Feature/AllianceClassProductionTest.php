<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Enums\AllianceClass;
use OGame\Enums\CharacterClass;
use OGame\Models\Alliance;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;

/**
 * Le bonus de production d une alliance de Commercants : +5 % sur les mines et sur l energie.
 *
 * ## Ce que ce banc mesure, et pourquoi il le mesure ainsi
 *
 * Pas la valeur rendue par le service — un essai qui demande 1,05 au service et verifie qu il rend
 * 1,05 ne prouve que lui-meme. Il mesure **la production de la planete**, avant et apres que
 * l alliance prenne sa classe : c est ce que le joueur recoit.
 *
 * ## Le cumul est la regle, et il est eprouve
 *
 * Un Collecteur dans une alliance de Commercants gagne **les deux** bonus, comme sur OGame officiel.
 * Verser l un dans l autre serait invisible a un essai qui n eprouverait qu une classe a la fois :
 * celui-ci monte les deux ensemble et exige les deux lignes separement.
 */
class AllianceClassProductionTest extends AccountTestCase
{
    private function uneAllianceDeCommercants(): Alliance
    {
        $alliance = resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'PR' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Commercants ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        $this->assertNotNull($alliance);

        DB::table('users')->where('id', $this->currentUserId)->update(['dark_matter' => AllianceClass::PRICE_IN_DARK_MATTER]);
        resolve(AllianceClassService::class)->choose(User::query()->findOrFail($this->currentUserId), $alliance, AllianceClass::TRADERS);

        return $alliance;
    }

    /**
     * Des mines qui produisent, et de quoi les alimenter : sans production, le juste et le faux
     * valent tous les deux zero.
     */
    private function desMinesQuiTournent(): void
    {
        $this->planetSetObjectLevel('metal_mine', 20);
        $this->planetSetObjectLevel('crystal_mine', 18);
        $this->planetSetObjectLevel('deuterium_synthesizer', 15);
        $this->planetSetObjectLevel('solar_plant', 25);
        $this->get('/overview')->assertStatus(200);
        $this->relireLaPlanete();
    }

    /**
     * L index de production de la mine de metal, tel que le jeu le calcule.
     *
     * C est `getObjectProductionIndex()` qui pose les deux services de classe sur l objet de
     * production : passer par lui, c est mesurer le chemin du jeu.
     */
    private function indexDeLaMineDeMetal(): \OGame\Models\ProductionIndex
    {
        return $this->indexDe('metal_mine');
    }

    /**
     * L index de production d un batiment, tel que le jeu le calcule.
     */
    private function indexDe(string $batiment): \OGame\Models\ProductionIndex
    {
        $objet = ObjectService::getObjectByMachineName($batiment);

        return $this->planetService->getObjectProductionIndex($objet, $this->planetService->getObjectLevel($batiment));
    }

    /**
     * **Relire le service de planete, et non seulement la ligne.**
     *
     * `reloadPlanet()` rafraichit la ligne de la planete, pas le joueur que le service porte : une
     * alliance creee apres le montage resterait invisible a `getObjectProductionIndex()`, qui lit
     * `users.alliance_id` a travers ce joueur-la.
     */
    private function relireLaPlanete(): void
    {
        $relu = resolve(\OGame\Factories\PlanetServiceFactory::class)->make($this->planetService->getPlanetId(), true);

        $this->assertNotNull($relu, 'La planete du banc a disparu.');

        $this->planetService = $relu;
    }

    /**
     * **Le bonus arrive dans la production de la planete**, et vaut exactement 5 % de l assiette.
     */
    public function testATradersAllianceRaisesMineProductionByFivePercent(): void
    {
        $this->desMinesQuiTournent();

        $avant = [
            'metal' => $this->planetService->getMetalProductionPerHour(),
            'crystal' => $this->planetService->getCrystalProductionPerHour(),
            'deuterium' => $this->planetService->getDeuteriumProductionPerHour(),
        ];

        $this->assertGreaterThan(0, $avant['metal'], 'La premisse tombe : la planete ne produit pas de metal.');
        $this->assertGreaterThan(0, $avant['crystal'], 'La premisse tombe : la planete ne produit pas de cristal.');

        /*
         * L assiette du bonus, telle que le moteur la definit : la production de CHAQUE mine plus sa
         * case de planete. Juger le cristal sur l index de la mine de metal comparerait deux choses
         * differentes — la mine de metal ne produit pas de cristal.
         */
        $assiette = [];

        foreach (['metal' => 'metal_mine', 'crystal' => 'crystal_mine', 'deuterium' => 'deuterium_synthesizer'] as $ressource => $batiment) {
            $index = $this->indexDe($batiment);
            $assiette[$ressource] = $index->mine->{$ressource}->get() + $index->planet_slot->{$ressource}->get();
            $this->assertGreaterThan(0, $assiette[$ressource], $ressource . ' : la premisse tombe, l assiette du bonus est nulle.');
        }

        $this->uneAllianceDeCommercants();

        $this->get('/overview')->assertStatus(200);
        $this->relireLaPlanete();

        foreach (['metal', 'crystal', 'deuterium'] as $nom) {
            $attendu = $avant[$nom] + floor($assiette[$nom] * 0.05);
            $obtenu = $this->planetService->{'get' . ucfirst($nom) . 'ProductionPerHour'}();

            $this->assertEqualsWithDelta($attendu, $obtenu, 1.0, $nom . ' : le bonus de l alliance de Commercants n arrive pas dans la production (avant ' . $avant[$nom] . ', apres ' . $obtenu . ').');
            $this->assertGreaterThan($avant[$nom], $obtenu, $nom . ' : la production n a pas augmente du tout.');
        }
    }

    /**
     * Le bonus a **sa propre ligne** dans l index : il ne se verse pas dans celle de la classe.
     */
    public function testTheBonusHasItsOwnLineAndDoesNotTouchTheCharacterClassOne(): void
    {
        $this->desMinesQuiTournent();
        $this->uneAllianceDeCommercants();

        $this->get('/overview')->assertStatus(200);
        $this->relireLaPlanete();

        $index = $this->indexDeLaMineDeMetal();

        $this->assertGreaterThan(0, $index->alliance_class->metal->get(), 'La ligne « classe d alliance » de l index est vide : le bonus n y est pas compte.');
        $this->assertSame(0.0, (float)$index->character_class->metal->get(), 'Le bonus d alliance a ete verse dans la ligne de la classe de personnage.');
    }

    /**
     * **Les deux bonus se cumulent**, et chacun reste dans sa ligne.
     */
    public function testTheAllianceBonusAddsUpWithTheCharacterClassOne(): void
    {
        $this->desMinesQuiTournent();

        // Collecteur : +25 % sur les mines. L essai pose la classe, il ne l espere pas.
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => CharacterClass::COLLECTOR->value]);
        $this->get('/overview')->assertStatus(200);
        $this->relireLaPlanete();

        $index = $this->indexDeLaMineDeMetal();
        $classeSeule = $index->character_class->metal->get();

        $this->assertGreaterThan(0, $classeSeule, 'La premisse tombe : le Collecteur ne rapporte rien, le cumul ne serait pas observable.');

        $this->uneAllianceDeCommercants();
        $this->get('/overview')->assertStatus(200);
        $this->relireLaPlanete();

        $index = $this->indexDeLaMineDeMetal();

        $this->assertEqualsWithDelta($classeSeule, $index->character_class->metal->get(), 1.0, 'Prendre une classe d alliance a change le bonus de la classe de personnage.');
        $this->assertGreaterThan(0, $index->alliance_class->metal->get(), 'Le bonus d alliance est absent alors que la classe est prise.');

        // Le total porte bien les deux.
        $total = $index->total->metal->get();
        $this->assertGreaterThanOrEqual(
            $index->mine->metal->get() + $classeSeule + $index->alliance_class->metal->get(),
            $total,
            'Le total ne compte pas les deux bonus.'
        );
    }

    /**
     * Une alliance d une autre classe ne donne rien sur la production.
     */
    public function testAWarriorsAllianceChangesNothingForProduction(): void
    {
        $this->desMinesQuiTournent();

        $avant = $this->planetService->getMetalProductionPerHour();

        $alliance = resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'GU' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Guerriers ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );
        $this->assertNotNull($alliance);
        DB::table('users')->where('id', $this->currentUserId)->update(['dark_matter' => AllianceClass::PRICE_IN_DARK_MATTER]);
        resolve(AllianceClassService::class)->choose(User::query()->findOrFail($this->currentUserId), $alliance, AllianceClass::WARRIORS);

        $this->get('/overview')->assertStatus(200);
        $this->relireLaPlanete();

        $this->assertEqualsWithDelta($avant, $this->planetService->getMetalProductionPerHour(), 1.0, 'Une alliance de Guerriers a change la production de metal.');
        $this->assertSame(0.0, (float)$this->indexDeLaMineDeMetal()->alliance_class->metal->get());
    }

    /**
     * Sans alliance, rien ne change — c est l etat de tout le serveur aujourd hui.
     */
    public function testWithoutAnAllianceProductionIsUntouched(): void
    {
        $this->desMinesQuiTournent();

        $index = $this->indexDeLaMineDeMetal();

        $this->assertSame(0.0, (float)$index->alliance_class->metal->get());
        $this->assertSame(0.0, (float)$index->alliance_class->energy->get());
    }

    /**
     * La page des reglages de production montre la ligne, et le joueur y lit ce qu il gagne.
     */
    public function testTheSettingsPageShowsTheAllianceClassLine(): void
    {
        $this->desMinesQuiTournent();
        $this->uneAllianceDeCommercants();

        $page = (string)$this->get('/resources/settings')->assertStatus(200)->getContent();

        $this->assertStringContainsString(__('t_ingame.resource_settings.alliance_class'), $page, 'La page des reglages ne montre pas la ligne de la classe d alliance.');
        $this->assertStringContainsString(AllianceClass::TRADERS->getName(), $page, 'La page ne dit pas de quelle classe d alliance il s agit.');

        /*
         * **Le montant, pas seulement le libelle.** La page assemble son total en additionnant
         * l index de chaque batiment producteur ; si le cumul oubliait la ligne d alliance, le
         * libelle serait toujours la et la valeur resterait a zero. C est la valeur qui prouve.
         */
        $ligne = $this->ligneDeLaClasseDAlliance($page);

        $this->assertNotSame('', $ligne, 'La ligne de la classe d alliance est introuvable dans la page.');

        preg_match_all('/<span class="tooltipCustom[^"]*"[^>]*>\s*([\d.,]+)\s*<\/span>/', $ligne, $m);

        $valeurs = array_map(static fn (string $v): int => (int)str_replace(['.', ',', ' '], '', $v), $m[1]);

        if ($valeurs === []) {
            $this->fail('La ligne de la classe d alliance ne porte aucune valeur.');
        }

        $this->assertGreaterThan(0, max($valeurs), 'La ligne de la classe d alliance affiche zero partout : le cumul entre batiments est perdu.');
    }

    /**
     * Le bloc de ligne qui porte le libelle de la classe d alliance, ou une chaine vide.
     */
    private function ligneDeLaClasseDAlliance(string $page): string
    {
        $libelle = __('t_ingame.resource_settings.alliance_class');

        foreach (explode('<tr', $page) as $bloc) {
            if (str_contains($bloc, $libelle)) {
                return $bloc;
            }
        }

        return '';
    }
}
