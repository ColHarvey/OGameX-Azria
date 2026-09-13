<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * **L entrelacement qui faisait « le ralliement ne se ferme pas », rejoue a volonte.**
 *
 * ## Ce qui se passait, et qui n etait pas etabli
 *
 * Le symptome — « The rally did not close on arrival » — apparaissait sur des essais differents d un passage
 * parallele a l autre, et jamais seul ni en sequentiel. La premiere explication avancee designait un banc de
 * schema ; **elle etait fausse**, celui-la enveloppe chacun de ses essais dans une transaction qu il annule.
 * Les vrais ecrivains sont `CombatClassBonusOnShotsTest` et `SpatialOpeningStateTest` : tous deux posent une
 * ligne de `combat_instances` avec **`mission_id = 1` en dur**, en ralliement, **sans barriere**, et tous deux
 * descendent d `AccountTestCase`, qui n annule rien. La ligne survit dans la base du processus.
 *
 * Une base par processus, un compteur par base : la premiere mission envoyee recoit l identifiant 1. Quand
 * c etait celle d un essai de combat durable, sa recherche `where('mission_id', 1)->first()` ramenait **la
 * plus ancienne** ligne portant cet identifiant — celle du voisin, sans barriere. La fermeture rendait alors
 * « combat inconnu », **sans une ligne de journal** : c est une issue normale du service.
 *
 * ## Pourquoi cet essai plutot qu une note
 *
 * Un intermittent qu on relance jusqu au vert fait perdre l habitude de lire les echecs. Ici l entrelacement
 * est **pose**, pas attendu : deux leurres avant l ouverture, un troisieme apres, et la regle de lecture doit
 * survivre aux trois. Les deux gardes se tuent separement — le corps vise ecarte le voisin qui vise ailleurs,
 * le plus recent ecarte celui qui precede — et chaque essai ci-dessous en tue une.
 *
 * **Un leurre est invisible pour le jeu** : l ouverture rejoint un combat par sa **barriere**
 * (`CombatOpeningService::existingCombatOn()`), et un leurre n en a pas. Il ne trouble donc que la lecture du
 * banc, ce qui est exactement le defaut a fermer.
 */
final class ANeighbourRallyIsNeverMineTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');

        parent::tearDown();
    }

    /**
     * **Deux lignes de voisins ecrites avant la mienne ne deviennent pas la mienne.**
     *
     * C est l entrelacement exact du defaut : les leurres precedent l ouverture, donc ils portent des
     * identifiants plus petits, et une lecture qui prend « la premiere » tombe sur eux.
     */
    public function testANeighbourRallyWrittenBeforeMineIsNotMine(): void
    {
        [$ouvreuse, , $ouverture] = $this->aRallyAboutToOpen();

        // La forme de `SpatialOpeningStateTest` : aucun corps vise, un point de l espace.
        $espace = $this->unLeurre((int)$ouvreuse->id, null, PlanetType::SpatialPoint);

        // La forme de `CombatClassBonusOnShotsTest` : un corps vise reel — et, dans une base partagee par
        // les essais d un processus, ce corps peut etre **celui-ci**.
        $memeCorps = $this->unLeurre((int)$ouvreuse->id, (int)$ouvreuse->planet_id_to, PlanetType::Planet);

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);

        // **Qui est le combat de cet essai se lit sur la barriere**, pas sur la recherche qu on eprouve : une
        // premisse tiree de la lecture fautive ne dirait plus rien le jour ou cette lecture se casse. La
        // barriere est justement ce que les lignes des voisins n ont pas.
        $mien = (int)DB::table('celestial_body_combat_barriers')
            ->where('target_body_id', (int)$ouvreuse->planet_id_to)
            ->value('combat_instance_id');

        $this->assertGreaterThan(0, $mien, 'L arrivee n a pose aucune barriere : aucun combat ne s est ouvert.');
        $this->assertGreaterThan((int)$espace->id, $mien, 'Le leurre spatial ne precede pas le combat de cet essai : il ne mesurerait rien.');
        $this->assertGreaterThan((int)$memeCorps->id, $mien, 'Le leurre du meme corps ne precede pas le combat de cet essai : il ne mesurerait rien.');

        $this->assertSame($mien, (int)$combat->id, 'Le montage a pris le ralliement d un voisin au lieu du sien.');
    }

    /**
     * **Une ligne de voisin ecrite apres la mienne ne devient pas la mienne non plus.**
     *
     * Celle-la porte un identifiant **plus grand** : « le plus recent » ne suffit pas a l ecarter, seul le
     * corps vise le fait. Les deux gardes ont donc chacune leur essai.
     */
    public function testANeighbourRallyWrittenAfterMineIsNotMineEither(): void
    {
        [$ouvreuse, , $ouverture] = $this->aRallyAboutToOpen();

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);

        $ailleurs = $this->unLeurre((int)$ouvreuse->id, (int)$ouvreuse->planet_id_from, PlanetType::Planet);

        $this->assertNotSame((int)$ouvreuse->planet_id_to, (int)$ouvreuse->planet_id_from, 'Le leurre vise le meme corps que l attaque : il ne mesurerait rien.');
        $this->assertGreaterThan((int)$combat->id, (int)$ailleurs->id, 'Le leurre ne suit pas le combat de cet essai : « le plus recent » suffirait a l ecarter.');

        $retrouve = $this->theCombatOf((int)$ouvreuse->id, (int)$ouvreuse->planet_id_to);

        $this->assertNotNull($retrouve, 'La recherche ne retrouve plus le combat de cet essai.');
        $this->assertSame((int)$combat->id, (int)$retrouve->id, 'La recherche a pris la ligne d un voisin qui visait un autre corps.');
    }

    /**
     * Une ligne de `combat_instances` telle qu un banc voisin la laisse : en ralliement, sans barriere,
     * jamais fermee.
     */
    private function unLeurre(int $missionId, int|null $corps, PlanetType $type): CombatInstance
    {
        $leurre = new CombatInstance();

        $leurre->forceFill([
            'status' => CombatState::Rallying,
            'mission_id' => $missionId,
            'target_planet_id' => $corps,
            'target_type' => $type->value,
            'galaxy' => 2,
            'system' => 55,
            'position' => 0,
        ]);

        $leurre->save();

        return $leurre;
    }
}
