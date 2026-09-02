<?php

namespace Tests\Feature\Combat;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Services\SettingsService;
use Tests\UnitTestCase;

/**
 * Les tables du systeme de combats persistants tiennent-elles ce qu'elles doivent tenir ?
 *
 * Trois garanties valent d'etre verifiees ici plutot que decouvertes en production :
 *
 * 1. **Le modele de duree est ecrit avec le combat.** Sans cela, ajuster un reglage
 *    rallongerait ou raccourcirait une bataille deja engagee.
 * 2. **Les coordonnees survivent a la disparition du corps celeste.** Un identifiant seul ne
 *    dirait plus ou la bataille a eu lieu si la planete est detruite ou abandonnee.
 * 3. **Le systeme est inerte tant qu'il n'est pas active.** Les tables peuvent exister en
 *    production sans qu'aucun combat ne les utilise.
 */
class CombatInstanceSchemaTest extends UnitTestCase
{
    /**
     * Assert that a combat keeps the duration model that produced it.
     *
     * La garantie la plus importante de cette table. Le rythme et l'amortissement sont ecrits
     * avec le combat, pas relus a la resolution : un reglage change en cours de route ne doit
     * jamais deplacer la fin d'une bataille commencee.
     */
    public function testACombatKeepsTheDurationModelThatProducedIt(): void
    {
        $combat = CombatInstance::create($this->unCombat([
            'duration_seconds' => 7413,
            'duration_rate' => 2083.0,
            'duration_damping' => 3.0,
            'duration_minimum_seconds' => 5,
        ]));

        // Le reglage change apres coup, comme le ferait un administrateur.
        $reglages = resolve(SettingsService::class);
        $reglages->set('combat_duration_rate', 9999);
        $reglages->set('combat_duration_damping', 1);

        $relu = CombatInstance::find($combat->id);
        $this->assertNotNull($relu);

        $this->assertSame(7413, $relu->duration_seconds, 'The stored duration moved when a setting changed.');
        $this->assertSame(2083.0, $relu->duration_rate, 'The combat no longer remembers the pace it was computed with.');
        $this->assertSame(3.0, $relu->duration_damping, 'The combat no longer remembers the damping it was computed with.');

        $reglages->set('combat_duration_rate', 2083);
        $reglages->set('combat_duration_damping', 3);
    }

    /**
     * Assert that the coordinates outlive the celestial body.
     */
    public function testTheCoordinatesOutliveTheCelestialBody(): void
    {
        $combat = CombatInstance::create($this->unCombat(['target_planet_id' => null]));

        $relu = CombatInstance::find($combat->id);
        $this->assertNotNull($relu);

        $this->assertNull($relu->target_planet_id, 'The scenario is not conclusive: the planet id is still set.');
        $this->assertSame(2, $relu->galaxy, 'The galaxy was lost with the planet.');
        $this->assertSame(265, $relu->system, 'The system was lost with the planet.');
        $this->assertSame(8, $relu->position, 'The position was lost with the planet.');
    }

    /**
     * Assert that the state and cancellation cause are stored as their enums.
     */
    public function testTheStateAndCancellationCauseAreStoredAsTheirEnums(): void
    {
        $combat = CombatInstance::create($this->unCombat([
            'status' => CombatState::Cancelled,
            'cancellation_cause' => CombatCancellationCause::TargetDisappeared,
        ]));

        $relu = CombatInstance::find($combat->id);
        $this->assertNotNull($relu);

        $this->assertSame(CombatState::Cancelled, $relu->status, 'The state did not come back as an enum.');
        $this->assertSame(CombatCancellationCause::TargetDisappeared, $relu->cancellation_cause, 'The cancellation cause did not come back as an enum.');
        $this->assertFalse($relu->locksTargetBody(), 'A cancelled combat still locks the body.');
    }

    /**
     * Assert that the hidden result and the snapshot survive a round trip.
     *
     * Ils portent des structures imbriquees ; une colonne mal typee les rendrait sous forme de
     * chaine, et la resolution appliquerait n'importe quoi.
     */
    public function testTheHiddenResultAndSnapshotSurviveARoundTrip(): void
    {
        $resultat = ['loot' => ['metal' => 120_000], 'rounds' => [['hits' => 42]]];
        $calendrier = [['round' => 1, 'seconds' => 900], ['round' => 2, 'seconds' => 1200]];

        $combat = CombatInstance::create($this->unCombat([
            'battle_result' => $resultat,
            'round_schedule' => $calendrier,
            'battle_snapshot' => ['attacker' => ['light_fighter' => 200]],
        ]));

        $relu = CombatInstance::find($combat->id);
        $this->assertNotNull($relu);

        $this->assertSame($resultat, $relu->battle_result, 'The hidden result did not survive the round trip.');
        $this->assertSame($calendrier, $relu->round_schedule, 'The round schedule did not survive the round trip.');
        $this->assertSame(['attacker' => ['light_fighter' => 200]], $relu->battle_snapshot, 'The snapshot did not survive the round trip.');
    }

    /**
     * Assert that participants keep the units they had at the snapshot.
     */
    public function testParticipantsKeepTheUnitsTheyHadAtTheSnapshot(): void
    {
        $combat = CombatInstance::create($this->unCombat([]));

        CombatParticipant::create([
            'combat_instance_id' => $combat->id,
            'player_id' => 1,
            'fleet_mission_id' => null,
            'participant_key' => CombatParticipantKey::forPlanet(1),
            'side' => CombatParticipant::SIDE_DEFENDER,
            'participant_type' => CombatParticipant::TYPE_PLANET_FLEET,
            'units_snapshot' => ['cruiser' => 80, 'rocket_launcher' => 400],
        ]);

        $relu = CombatInstance::find($combat->id);
        $this->assertNotNull($relu);

        $participants = $relu->participants;

        $this->assertCount(1, $participants, 'The participant is not attached to its combat.');
        $this->assertSame(['cruiser' => 80, 'rocket_launcher' => 400], $participants->first()?->units_snapshot);
    }

    /**
     * Assert that a participant without a combat is refused.
     *
     * Sans cle etrangere, un participant orphelin pourrait exister : une ligne qui ne dit plus
     * a quelle bataille elle a pris part. C'est pire que le probleme qu'on croyait eviter en
     * n'en mettant pas.
     */
    public function testAParticipantWithoutACombatIsRefused(): void
    {
        $this->expectException(QueryException::class);

        CombatParticipant::create([
            'combat_instance_id' => 999_999,
            'player_id' => 1,
            'participant_key' => CombatParticipantKey::forFleet(1),
            'side' => CombatParticipant::SIDE_ATTACKER,
            'participant_type' => CombatParticipant::TYPE_ATTACK_FLEET,
            'units_snapshot' => ['light_fighter' => 10],
        ]);
    }

    /**
     * Assert that deleting a combat that still has participants is refused.
     *
     * RESTRICT, ni cascade ni rien : supprimer un combat n'efface pas silencieusement qui y
     * etait. Une purge doit etre explicite et ordonnee — ou, mieux, marquer le combat comme
     * purge plutot que le supprimer.
     */
    public function testDeletingACombatThatStillHasParticipantsIsRefused(): void
    {
        $combat = CombatInstance::create($this->unCombat([]));

        CombatParticipant::create([
            'combat_instance_id' => $combat->id,
            'player_id' => 1,
            'fleet_mission_id' => 77,
            'participant_key' => CombatParticipantKey::forFleet(77),
            'side' => CombatParticipant::SIDE_ATTACKER,
            'participant_type' => CombatParticipant::TYPE_ATTACK_FLEET,
            'units_snapshot' => ['light_fighter' => 10],
        ]);

        $this->expectException(QueryException::class);

        $combat->delete();
    }

    /**
     * Assert that the same fleet cannot be registered twice in one combat.
     *
     * Un traitement rejoue l'inscrirait deux fois, et la resolution compterait ses vaisseaux en
     * double — donc ses pertes, son butin et son retour.
     */
    public function testTheSameFleetCannotBeRegisteredTwiceInOneCombat(): void
    {
        $combat = CombatInstance::create($this->unCombat([]));

        $inscrire = fn (): CombatParticipant => CombatParticipant::create([
            'combat_instance_id' => $combat->id,
            'player_id' => 1,
            'fleet_mission_id' => 42,
            'participant_key' => CombatParticipantKey::forFleet(42),
            'side' => CombatParticipant::SIDE_ATTACKER,
            'participant_type' => CombatParticipant::TYPE_ATTACK_FLEET,
            'units_snapshot' => ['light_fighter' => 10],
        ]);

        $inscrire();

        $this->expectException(QueryException::class);

        $inscrire();
    }

    /**
     * Assert that the stationary garrison cannot be registered twice either.
     *
     * C'etait le trou de la version precedente, et je l'avais decrit comme une fonctionnalite :
     * l'unicite portait sur `fleet_mission_id`, et la garnison n'a pas de mission. Or **plusieurs
     * valeurs nulles sont permises dans un index unique** — un traitement rejoue aurait donc pu
     * inscrire la garnison deux fois, et la resolution aurait compte ses vaisseaux, ses pertes et
     * ses defenses en double.
     *
     * L'identite passe desormais par `participant_key`, jamais nulle.
     */
    public function testTheStationaryGarrisonCannotBeRegisteredTwiceEither(): void
    {
        $combat = CombatInstance::create($this->unCombat([]));

        $inscrire = fn (): CombatParticipant => CombatParticipant::create([
            'combat_instance_id' => $combat->id,
            'player_id' => 1,
            'fleet_mission_id' => null,
            'participant_key' => CombatParticipantKey::forPlanet(567),
            'side' => CombatParticipant::SIDE_DEFENDER,
            'participant_type' => CombatParticipant::TYPE_PLANET_FLEET,
            'units_snapshot' => ['cruiser' => 80],
        ]);

        $inscrire();

        $this->expectException(QueryException::class);

        $inscrire();
    }

    /**
     * Assert that a garrison and a fleet can still coexist in the same combat.
     *
     * La protection ci-dessus ne doit pas empecher le cas normal : une flotte attaquante et la
     * garnison du defenseur sont deux participants distincts du meme combat.
     */
    public function testAGarrisonAndAFleetCanCoexistInTheSameCombat(): void
    {
        $combat = CombatInstance::create($this->unCombat([]));

        CombatParticipant::create([
            'combat_instance_id' => $combat->id,
            'player_id' => 1,
            'fleet_mission_id' => 88,
            'participant_key' => CombatParticipantKey::forFleet(88),
            'side' => CombatParticipant::SIDE_ATTACKER,
            'participant_type' => CombatParticipant::TYPE_ATTACK_FLEET,
            'units_snapshot' => ['light_fighter' => 200],
        ]);

        CombatParticipant::create([
            'combat_instance_id' => $combat->id,
            'player_id' => 2,
            'fleet_mission_id' => null,
            'participant_key' => CombatParticipantKey::forPlanet(567),
            'side' => CombatParticipant::SIDE_DEFENDER,
            'participant_type' => CombatParticipant::TYPE_PLANET_FLEET,
            'units_snapshot' => ['cruiser' => 80],
        ]);

        $this->assertSame(2, CombatParticipant::where('combat_instance_id', $combat->id)->count(), 'A fleet and a garrison can no longer take part in the same combat.');
    }

    /**
     * Assert that an active combat can be found by target and state in one indexed lookup.
     *
     * C'est la requete du verrou, appelee par chaque validation de depart : sans index, elle
     * balaierait la table a chaque envoi de flotte du serveur.
     */
    public function testAnActiveCombatCanBeFoundByTargetAndState(): void
    {
        $indexes = Schema::getIndexes('combat_instances');
        $colonnes = array_map(static fn (array $index): array => $index['columns'], $indexes);

        $this->assertContains(['target_planet_id', 'status'], $colonnes, 'There is no index to answer « is this body in combat? » by planet id.');
        $this->assertContains(['galaxy', 'system', 'position', 'status'], $colonnes, 'There is no index to answer « is this body in combat? » by coordinates.');
        $this->assertContains(['status', 'ends_at'], $colonnes, 'There is no index for the reconciliation task to find combats that should have ended.');
    }

    /**
     * Assert that the whole system is inert until it is switched on.
     *
     * Les tables peuvent exister en production sans qu'aucun combat ne les utilise : c'est ce
     * qui permet de deployer d'abord et d'activer ensuite, comme deux gestes distincts.
     */
    public function testTheWholeSystemIsInertUntilItIsSwitchedOn(): void
    {
        $reglages = resolve(SettingsService::class);

        $this->assertFalse(
            $reglages->combatDurationEnabled(),
            'Persistent combats are enabled by default: deploying the tables would change the game on its own.'
        );

        // Les autres reglages portent le modele retenu, pret a servir des l'activation.
        $this->assertSame(2083.0, $reglages->combatDurationRate());
        $this->assertSame(3.0, $reglages->combatDurationDamping());
        $this->assertSame(5, $reglages->combatDurationMinimumSeconds());
    }

    /**
     * Assert that the fleet missions table can point at a combat.
     */
    public function testTheFleetMissionsTableCanPointAtACombat(): void
    {
        $this->assertTrue(
            Schema::hasColumn('fleet_missions', 'combat_instance_id'),
            'A fleet mission cannot be linked to a combat, so an engaged fleet could be processed a second time.'
        );
    }

    /**
     * Build a combat row with sensible defaults.
     *
     * @param array<string, mixed> $remplace
     * @return array<string, mixed>
     */
    private function unCombat(array $remplace): array
    {
        return array_merge([
            'status' => CombatState::Rallying,
            'mission_id' => 1,
            'target_planet_id' => 1,
            'target_type' => 1,
            'galaxy' => 2,
            'system' => 265,
            'position' => 8,
            'duration_seconds' => 0,
            'duration_rate' => 2083.0,
            'duration_damping' => 3.0,
            'duration_minimum_seconds' => 5,
        ], $remplace);
    }
}
