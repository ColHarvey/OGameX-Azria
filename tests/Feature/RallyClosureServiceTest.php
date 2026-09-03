<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Exceptions\UnknownSnapshotProjection;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\SnapshotProjection;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatSnapshotInclusion;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * La fermeture du ralliement : la photographie se prend, une fois.
 *
 * ## Ce que ces essais protegent
 *
 * Le ralliement est une phase d'admission, pas un combat commence. A sa fermeture, les candidates
 * sont arbitrees et plus rien ne bouge. Trois choses doivent tenir :
 *
 *     fermer avant l'echeance exclurait des flottes qu'on avait promis d'attendre
 *     fermer deux fois ne doit rien faire de plus
 *     ce qui est admis vient des faits geles, pas du monde courant
 *
 * ## Ce qu'ils ne prouvent pas encore
 *
 * Ni les messages aux joueurs, ni la reservation de butin : ces deux-la viennent apres. Le dire evite
 * de croire la fermeture terminee.
 *
 * Les inclusions, elles, sont desormais prouvees — y compris qu'elles portent la projection **de
 * l instance** et non la version courante.
 */
class RallyClosureServiceTest extends TestCase
{
    private const int OPENING = 1_700_000_000;

    private CombatOpeningService $ouverture;

    private RallyClosureService $fermeture;

    /**
     * Le nombre de corps deja crees, pour en donner un different a chacun.
     */
    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ouverture = new CombatOpeningService();
        $this->fermeture = new RallyClosureService();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Fermer avant l'echeance ne ferme rien.
     *
     * L'echeance a ete calculee a l'ouverture, sur les flottes qui seraient admises. Fermer avant
     * elle exclurait celles qu'on avait promis d'attendre.
     */
    public function testClosingBeforeTheDeadlineDoesNothing(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 30, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $issue = $this->fermeture->close($combat->id, self::OPENING + 10);

        $this->assertFalse($issue->closed);
        $this->assertSame('trop tot', $issue->reason);

        $combat->refresh();
        $this->assertSame(CombatState::Rallying, $combat->status);
    }

    /**
     * A l'echeance, la photographie se prend et les vagues admises deviennent participantes.
     */
    public function testAtTheDeadlineTheAdmittedWavesBecomeParticipants(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $vague = $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $issue = $this->fermeture->close($combat->id, self::OPENING + 19);

        $this->assertTrue($issue->closed, 'The rally did not close at its own deadline.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status);

        $cles = CombatParticipant::where('combat_instance_id', $combat->id)
            ->pluck('participant_key')
            ->all();

        $this->assertContains(
            CombatParticipantKey::forFleet($vague->id),
            $cles,
            'A wave admitted by the selector was not registered as a participant.'
        );
    }

    /**
     * Fermer deux fois ne fait rien de plus.
     *
     * Un message de file peut etre livre deux fois, un worker reprendre apres un redemarrage. La
     * seconde tentative doit constater et s'arreter, sans lever ni dupliquer.
     */
    public function testClosingTwiceDoesNothingMore(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $premiere = $this->fermeture->close($combat->id, self::OPENING + 19);
        $avant = CombatParticipant::where('combat_instance_id', $combat->id)->count();

        $seconde = $this->fermeture->close($combat->id, self::OPENING + 30);

        $this->assertTrue($premiere->closed);
        $this->assertFalse($seconde->closed, 'A second closure claimed to have closed an already closed rally.');
        $this->assertSame('deja fermee', $seconde->reason);

        $this->assertSame(
            $avant,
            CombatParticipant::where('combat_instance_id', $combat->id)->count(),
            'Closing twice registered the same participants a second time.'
        );
    }

    /**
     * Les budgets consommes sont ecrits avec la photographie.
     */
    public function testTheConsumedBudgetsAreWrittenWithTheSnapshot(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->fermeture->close($combat->id, self::OPENING + 19);

        $combat->refresh();

        $this->assertGreaterThan(
            0,
            $combat->fleets_admitted,
            'The closure wrote no fleet count: nothing would know how full the side is.'
        );

        $this->assertSame(
            1,
            $combat->players_admitted,
            'Several waves of one player were counted as several players.'
        );
    }

    /**
     * Les verrous se prennent dans l'ordre fixe par la migration de barriere.
     *
     * ## Le defaut que cet essai ferme
     *
     * L'ordre global est ecrit dans la migration : **corps, puis combat, puis union, puis missions
     * par identifiant trie**. La fermeture verrouillait l'instance en premier et la barriere ensuite,
     * pendant que son propre commentaire affirmait l'inverse.
     *
     * Le desaccord n'etait pas documentaire. Une jointure ou une resolution qui suivrait l'ordre
     * ecrit aurait attendu la barriere pendant que la fermeture attendait l'instance : deux
     * transactions, deux verrous, chacune tenant celui que l'autre demande.
     *
     * ## Ce que cet essai prouve, et ce qu'il ne prouve pas
     *
     * Il observe l'ordre reel des requetes, pas le texte du commentaire — c'est pour cela qu'il
     * ecoute la connexion au lieu de lire le source.
     *
     * **Il ne prouve pas l'absence d'interblocage.** SQLite ignore `for update` : seul MariaDB
     * pose de vrais verrous de ligne, et l'epreuve a deux connexions reste a faire. Ce qu'il
     * garantit, c'est que l'ordre d'acquisition ne repartira pas a l'envers sans que rien ne le
     * dise.
     */
    public function testTheLocksAreTakenInTheOrderTheBarrierMigrationFixes(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $tables = [];

        DB::listen(function (QueryExecuted $requete) use (&$tables): void {
            foreach (['celestial_body_combat_barriers', 'combat_instances', 'fleet_missions'] as $table) {
                if (str_contains($requete->sql, '"' . $table . '"') && !in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        });

        $this->fermeture->close($combat->id, self::OPENING + 1);

        $rangs = array_flip($tables);

        $this->assertArrayHasKey('celestial_body_combat_barriers', $rangs, 'The closure never touched the barrier.');
        $this->assertArrayHasKey('combat_instances', $rangs, 'The closure never touched the instance.');

        $this->assertLessThan(
            $rangs['combat_instances'],
            $rangs['celestial_body_combat_barriers'],
            'The instance is locked before the barrier: the reverse of the order the migration fixes.'
        );

        $this->assertLessThan(
            $rangs['fleet_missions'] ?? PHP_INT_MAX,
            $rangs['combat_instances'],
            'Candidate missions are read before the instance is held.'
        );
    }

    /**
     * Celle qui a ouvert le combat s'y bat.
     *
     * ## Le defaut que cet essai ferme
     *
     * Le selecteur ne rend pas le groupe fondateur dans son verdict, et il a raison : le fondateur
     * n'est pas admis, il ouvre — il n'y a rien a decider sur lui. La fermeture s'appuyait pourtant
     * sur ce verdict seul.
     *
     * **L'attaquant qui avait lance la bataille n'etait donc ni participant, ni dans la
     * photographie, ni compte dans les budgets consommes.** Un combat ouvert par une flotte unique
     * se serait ferme avec zero attaquant : le defenseur aurait gagne contre personne.
     *
     * Le defaut ne s'est vu qu'en ecrivant les inclusions, parce qu'un essai comptait enfin **tout
     * le monde** au lieu de chercher une vague nommee.
     */
    public function testTheFleetThatOpenedTheCombatFightsInIt(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $issue = $this->fermeture->close($combat->id, self::OPENING + 1);

        $this->assertTrue($issue->closed);

        $this->assertContains(
            CombatParticipantKey::forFleet($ouvreur->id),
            CombatParticipant::where('combat_instance_id', $combat->id)->pluck('participant_key')->all(),
            'The fleet that opened the combat was not registered as a participant.'
        );

        $combat->refresh();

        $this->assertSame(
            1,
            $combat->fleets_admitted,
            'The opening fleet was not counted in the consumed budgets.'
        );
        $this->assertSame(1, $combat->players_admitted);
    }

    /**
     * Chaque flotte admise entre une fois dans la photographie, avec ce qu'elle apporte.
     *
     * Une arrivee appliquee au monde mais absente de la photographie serait perdue pour la
     * bataille : ses vaisseaux ne combattraient pas alors qu'ils sont arrives.
     */
    public function testEveryAdmittedFleetEntersTheSnapshotOnce(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $vague = $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $this->fermeture->close($combat->id, self::OPENING + 19);

        $inclusions = CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->get();

        $this->assertSame(
            2,
            $inclusions->count(),
            'The admitted fleets did not each enter the snapshot exactly once.'
        );

        foreach ([$ouvreur, $vague] as $mission) {
            $ligne = $inclusions->firstWhere(
                'event_identity',
                CombatEventIdentity::forFleetArrival($mission->id)
            );

            $this->assertNotNull($ligne, 'An admitted fleet is missing from the snapshot.');
            $this->assertSame(SnapshotContribution::AttackingFleet, $ligne->contribution);
        }
    }

    /**
     * L'inclusion est ecrite sous la projection de l'instance, pas sous la version courante.
     *
     * L'unicite porte sur combat / evenement / version. Lire la constante courante deux heures
     * apres l'ouverture ferait entrer le meme evenement une seconde fois apres une bascule — et
     * l'unicite ne verrait rien, puisqu'elle separe justement les versions.
     */
    public function testTheInclusionIsWrittenUnderTheProjectionOfTheInstance(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->assertSame(
            SnapshotProjection::CURRENT,
            $combat->projection_version,
            'The opening did not freeze the projection version with the instance.'
        );

        $this->fermeture->close($combat->id, self::OPENING + 1);

        $this->assertSame(
            [$combat->projection_version],
            CombatSnapshotInclusion::where('combat_instance_id', $combat->id)
                ->pluck('projection_version')
                ->unique()
                ->values()
                ->all()
        );
    }

    /**
     * Une projection inconnue arrete la fermeture au lieu de deviner.
     *
     * Un combat ouvert sous une projection que ce code ne sait plus lire ne se ferme pas « au
     * mieux » : ses inclusions signifieraient autre chose que ce qu'elles disent.
     */
    public function testAnUnknownProjectionStopsTheClosure(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);
        $combat->projection_version = 'v-inconnue';
        $combat->save();

        $this->expectException(UnknownSnapshotProjection::class);

        $this->fermeture->close($combat->id, self::OPENING + 1);
    }

    /**
     * Fermer deux fois n'inclut pas deux fois.
     */
    public function testClosingTwiceDoesNotIncludeTwice(): void
    {
        $joueur = $this->aPlayer();
        $corps = $this->aBodyId();

        $ouvreur = $this->anAttackAt($corps, self::OPENING, $joueur);
        $this->anAttackAt($corps, self::OPENING + 18, $joueur);

        $combat = $this->ouverture->openOrJoin($ouvreur, $corps, self::OPENING);

        $this->fermeture->close($combat->id, self::OPENING + 19);
        $avant = CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->count();

        $this->fermeture->close($combat->id, self::OPENING + 30);

        $this->assertSame(
            $avant,
            CombatSnapshotInclusion::where('combat_instance_id', $combat->id)->count(),
            'A second closure added the same events to the snapshot again.'
        );
    }

    /**
     * Un combat inconnu ne fait pas lever la fermeture.
     */
    public function testAnUnknownCombatIsReportedRatherThanThrown(): void
    {
        $issue = $this->fermeture->close(999_999, self::OPENING);

        $this->assertFalse($issue->closed);
        $this->assertSame('combat introuvable', $issue->reason);
    }

    /**
     * Une attaque en vol vers ce corps.
     */
    private function anAttackAt(int $targetBodyId, int $arrivesAt, User|null $owner = null): FleetMission
    {
        $proprietaire = $owner ?? $this->aPlayer();

        return FleetMission::forceCreate([
            'user_id' => $proprietaire->id,
            'planet_id_to' => $targetBodyId,
            'mission_type' => 1,
            'time_departure' => self::OPENING - 600,
            'time_arrival' => $arrivesAt,
            'galaxy_to' => 6,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);
    }

    /**
     * Un joueur, avec une planete.
     */
    private function aPlayer(): User
    {
        $utilisateur = User::factory()->create();

        $this->aPlanetOwnedBy($utilisateur);

        return $utilisateur;
    }

    /**
     * Un corps celeste reel : `planet_id_to` porte une cle etrangere.
     */
    private function aBodyId(): int
    {
        return $this->aPlanetOwnedBy(User::factory()->create())->id;
    }

    /**
     * Une planete a des coordonnees libres, deterministes.
     */
    private function aPlanetOwnedBy(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 6,
            'system' => 500 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }
}
