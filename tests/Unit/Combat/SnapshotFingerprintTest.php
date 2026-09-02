<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Support\SnapshotFingerprint;
use Tests\UnitTestCase;

/**
 * L'empreinte des faits economiques : ce qu'elle doit voir, et ce qu'elle doit ignorer.
 *
 * ## La double exigence
 *
 * Elle doit **changer** quand un fait change — sinon elle ne detecte rien. Et elle doit **rester
 * identique** quand seule la presentation change — sinon elle refuserait des combats parfaitement
 * legitimes, et on finirait par la desactiver.
 *
 * Les essais ci-dessous prennent les deux cotes : une mutation par champ, et une permutation qui ne
 * doit rien changer.
 */
class SnapshotFingerprintTest extends UnitTestCase
{
    /**
     * Les faits de reference.
     *
     * @return array<string, mixed>
     */
    private function reference(): array
    {
        return [
            'observed_at' => 1_800_000_000,
            'policy_version' => 'cargo_weighted_v1',
            'allocator_version' => 'exact_loot_pipeline_v1',
            'rate_in_basis_points' => 6_250,
            'no_loot_because' => null,
            'target' => ['body_key' => 'corps-vise', 'owner_id' => 42],
            'target_is_inactive' => true,
            'discoverer_cargo' => 25_000,
            'total_cargo' => 50_000,
            'fleets' => [
                [
                    'fleet_mission_id' => 101,
                    'owner_id' => 7,
                    'actor_kind' => 'player',
                    'is_initiator' => true,
                    'is_discoverer' => true,
                    'free_cargo' => 25_000,
                    'units' => ['large_cargo' => 1, 'small_cargo' => 5],
                    'carried' => ['metal' => 100, 'crystal' => 0, 'deuterium' => 0],
                ],
                [
                    'fleet_mission_id' => 102,
                    'owner_id' => 8,
                    'actor_kind' => 'player',
                    'is_initiator' => false,
                    'is_discoverer' => false,
                    'free_cargo' => 25_000,
                    'units' => ['small_cargo' => 5],
                    'carried' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
                ],
            ],
        ];
    }

    /**
     * Chacun des faits que l'empreinte doit couvrir la fait changer.
     *
     * Un fait absent de cette liste serait un fait qu'on pourrait modifier sans que rien ne le
     * remarque — et c'est precisement ce que l'empreinte existe pour empecher.
     */
    public function testEveryEconomicFactChangesTheFingerprint(): void
    {
        $reference = SnapshotFingerprint::of($this->reference());

        $mutations = [
            'mission ajoutee' => function (array $f): array {
                $f['fleets'][] = $f['fleets'][1];
                $f['fleets'][2]['fleet_mission_id'] = 103;

                return $f;
            },
            'mission retiree' => function (array $f): array {
                array_pop($f['fleets']);

                return $f;
            },
            'mission remplacee' => function (array $f): array {
                $f['fleets'][1]['fleet_mission_id'] = 999;

                return $f;
            },
            'quantite d un vaisseau' => function (array $f): array {
                $f['fleets'][0]['units']['small_cargo'] = 6;

                return $f;
            },
            'type de vaisseau' => function (array $f): array {
                $f['fleets'][1]['units'] = ['large_cargo' => 5];

                return $f;
            },
            'cargaison transportee' => function (array $f): array {
                $f['fleets'][0]['carried']['metal'] = 101;

                return $f;
            },
            'fret libre gele' => function (array $f): array {
                $f['fleets'][0]['free_cargo'] = 25_001;

                return $f;
            },
            'genre d acteur' => function (array $f): array {
                $f['fleets'][1]['actor_kind'] = 'npc';

                return $f;
            },
            'role d initiateur' => function (array $f): array {
                $f['fleets'][0]['is_initiator'] = false;

                return $f;
            },
            'classe gelee' => function (array $f): array {
                $f['fleets'][1]['is_discoverer'] = true;

                return $f;
            },
            'cible' => function (array $f): array {
                $f['target']['body_key'] = 'un-autre-corps';

                return $f;
            },
            'proprietaire de la cible' => function (array $f): array {
                $f['target']['owner_id'] = 43;

                return $f;
            },
            'inactivite gelee' => function (array $f): array {
                $f['target_is_inactive'] = false;

                return $f;
            },
            'instant d observation' => function (array $f): array {
                $f['observed_at']++;

                return $f;
            },
            'version de politique' => function (array $f): array {
                $f['policy_version'] = 'npc_base_v1';

                return $f;
            },
            'version d allocateur' => function (array $f): array {
                $f['allocator_version'] = 'exact_loot_pipeline_v2';

                return $f;
            },
            'raison de non-pillage' => function (array $f): array {
                $f['no_loot_because'] = 'npc_encounter';

                return $f;
            },
            'taux' => function (array $f): array {
                $f['rate_in_basis_points'] = 5_000;

                return $f;
            },
            'fret Decouvreur' => function (array $f): array {
                $f['discoverer_cargo'] = 25_001;

                return $f;
            },
            'fret total' => function (array $f): array {
                $f['total_cargo'] = 50_001;

                return $f;
            },
        ];

        foreach ($mutations as $quoi => $mutation) {
            $this->assertNotSame(
                $reference,
                SnapshotFingerprint::of($mutation($this->reference())),
                "Changing « {$quoi} » left the fingerprint untouched: this fact could be altered unnoticed."
            );
        }
    }

    /**
     * L'ordre des cles d'un dictionnaire ne change rien.
     *
     * Deux photographies des memes faits, ecrites dans un ordre different, doivent se reconnaitre.
     */
    public function testTheOrderOfDictionaryKeysChangesNothing(): void
    {
        $reference = $this->reference();
        $permute = array_reverse($reference, true);
        $permute['target'] = array_reverse($permute['target'], true);
        $permute['fleets'][0] = array_reverse($permute['fleets'][0], true);

        $this->assertSame(SnapshotFingerprint::of($reference), SnapshotFingerprint::of($permute));
    }

    /**
     * Une liste ordonnee garde son ordre, et le changer change l'empreinte.
     *
     * **Un tri recursif generique aurait efface cette distinction** en transformant toute liste en
     * ensemble. C'est a l'appelant de trier ce qui doit l'etre — les flottes par identifiant — avant
     * de calculer l'empreinte ; ce que la forme canonique ne doit pas faire a sa place.
     */
    public function testAnOrderedListKeepsItsOrder(): void
    {
        $reference = $this->reference();
        $inverse = $reference;
        $inverse['fleets'] = array_reverse($inverse['fleets']);

        $this->assertNotSame(
            SnapshotFingerprint::of($reference),
            SnapshotFingerprint::of($inverse),
            'Reversing a business list left the fingerprint untouched, so a generic sort has flattened it.'
        );
    }

    /**
     * Un flottant economique est refuse, et non arrondi en silence.
     *
     * La conversion en unites entieres a lieu a la frontiere. Un flottant qui arrive jusqu'ici
     * signale une conversion oubliee — et `1.0` donnerait une autre empreinte que `1`.
     */
    public function testAFloatIsRefusedRatherThanRounded(): void
    {
        $faits = $this->reference();
        $faits['fleets'][0]['carried']['metal'] = 100.0;

        $this->expectException(InvalidArgumentException::class);

        SnapshotFingerprint::of($faits);
    }

    /**
     * L'empreinte est stable d'un appel a l'autre.
     */
    public function testTheFingerprintIsStable(): void
    {
        $this->assertSame(SnapshotFingerprint::of($this->reference()), SnapshotFingerprint::of($this->reference()));
    }
}
