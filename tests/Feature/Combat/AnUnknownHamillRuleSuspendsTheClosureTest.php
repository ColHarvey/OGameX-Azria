<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\Combat\Services\RallyClosureService;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * **Une regle de manoeuvre que ce code ne connait pas suspend la fermeture — elle ne choisit rien.**
 *
 * ## Pourquoi ce temoin, alors que la porte de relecture est deja eprouvee
 *
 * `HamillManoeuvreRule::fromInstance()` refuse une colonne vide, un nom inconnu ou une valeur qui n est pas
 * une chaine, et un essai unitaire le prouve. Cela etablit **le refus**, pas ce qu il devient : l ouverture
 * d un combat appelle la fermeture **dans la requete du joueur** (`CombatOpeningService`), et une exception
 * qui remonte jusque-la ferme toutes ses pages. Un refus qui casse le jeu n est pas un refus propre.
 *
 * La fermeture rend donc une issue **suspendue**, comme pour un historique d admission inconnu : rien n est
 * ecrit, le ralliement reste ouvert, l avanceur compte l echec et met le combat de cote apres cinq.
 *
 * ## D ou peut venir une version inconnue
 *
 * D une base plus recente que le code : un retour en arriere livre pendant qu un combat porte deja la version
 * suivante. C est exactement la fenetre de deploiement, et c est la raison d etre de ce garde.
 */
final class AnUnknownHamillRuleSuspendsTheClosureTest extends FleetDispatchTestCase
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
     * **La fermeture se suspend, et rien n est ecrit.**
     */
    public function testAnUnknownRuleSuspendsTheClosureInsteadOfChoosingOne(): void
    {
        $combat = $this->unRalliementPortant('v9');

        $issue = (new RallyClosureService())->close((int)$combat->id, $this->instantDeFermeture);

        $this->assertFalse($issue->closed, 'Le ralliement s est ferme sous une regle que ce code ne connait pas.');
        $this->assertTrue($issue->suspended, 'La fermeture n a pas ete comptee comme une anomalie : l avanceur ne la mettrait jamais de cote.');
        $this->assertStringContainsString('v9', $issue->reason, 'L issue ne nomme pas la regle refusee.');

        $relu = CombatInstance::query()->findOrFail($combat->id);

        $this->assertSame(CombatState::Rallying, $relu->status, 'Le combat a change d etat malgre le refus.');
        $this->assertNull($relu->battle_result, 'Une bataille a ete calculee sous une regle inconnue.');
    }

    /**
     * **Le meme montage, avec une regle connue, se ferme.**
     *
     * Sans lui, le precedent passerait aussi bien si ce montage ne se fermait jamais.
     */
    public function testTheSameRallyClosesUnderAKnownRule(): void
    {
        $combat = $this->unRalliementPortant(HamillManoeuvreRule::current()->value);

        $issue = (new RallyClosureService())->close((int)$combat->id, $this->instantDeFermeture);

        $this->assertTrue($issue->closed, 'Le ralliement ne s est pas ferme sous la regle courante : le refus precedent ne prouverait rien.');
        $this->assertFalse($issue->suspended, 'Une fermeture reussie a ete comptee comme une anomalie.');
        $this->assertNotNull(CombatInstance::query()->findOrFail($combat->id)->battle_result, 'Aucune bataille n a ete calculee.');
    }

    private int $instantDeFermeture = 0;

    /**
     * Un ralliement ouvert, sa seconde vague arrivee, et la version de regle qu on lui donne.
     */
    private function unRalliementPortant(string $version): CombatInstance
    {
        [$ouvreuse, , $ouverture] = $this->aRallyAboutToOpen();

        $combat = $this->theOpeningProcessedAt($ouvreuse, $ouverture);

        DB::table('combat_instances')->where('id', $combat->id)->update(['hamill_rule_version' => $version]);

        $vague = FleetMission::query()
            ->where('user_id', $this->currentUserId)
            ->where('mission_type', 1)
            ->where('processed', 0)
            ->whereKeyNot($combat->mission_id)
            ->orderByDesc('id')
            ->first();

        $this->assertInstanceOf(FleetMission::class, $vague, 'La seconde vague manque.');

        $this->instantDeFermeture = (int)$vague->time_arrival + 30;
        $this->travelTo(Date::createFromTimestamp($this->instantDeFermeture));

        return $combat;
    }
}
