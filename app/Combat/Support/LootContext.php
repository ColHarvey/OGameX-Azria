<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Enums\HonorPolicy;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Exceptions\FalsifiedLootContext;
use OGame\Combat\Exceptions\UnknownFingerprintSchema;
use OGame\Combat\Policies\LootPolicyRegistry;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\Models\Resources;

/**
 * Les faits de pillage d'un combat, photographies une fois et jamais relus.
 *
 * ## Pourquoi le moteur ne les observe plus lui-meme
 *
 * Le moteur instantane calculait sa propre politique en interrogeant les modeles vivants au moment
 * ou il tournait. C'est juste tant que la bataille est instantanee : l'observation et le calcul se
 * suivent d'un cheveu.
 *
 * Un combat persistant dure. Pendant ce temps, la cible peut se connecter — elle
 * cesserait d'etre inactive —, un attaquant peut changer de classe, une recherche d'hyperespace peut
 * s'achever et grossir un fret. Si le moteur relisait ces donnees a la resolution, deux combats
 * partis du meme etat rendraient deux resultats differents, et le taux annonce dans le rapport ne
 * serait plus celui sous lequel les joueurs se sont engages.
 *
 * D'ou cette regle : **le moteur recoit ses faits, il ne decide pas quand les observer**.
 *
 * ## Pourquoi le constructeur est prive
 *
 * Le contexte porte a la fois les faits et ce qui en decoule — le taux, les versions. Un
 * constructeur public acceptant les deux independamment permettrait d'ecrire un contexte
 * contradictoire : 50 % de taux avec un fret entierement Decouvreur contre une cible inactive.
 * Personne ne le ferait expres ; une ligne de base modifiee, si.
 *
 * ## Comment une ancienne regle reste applicable
 *
 * Les versions ne sont pas comparees a « la version courante » : elles servent a **choisir
 * l'implementation** dans un registre. Un combat ouvert sous `cargo_weighted_v1` reste calculable
 * apres l'arrivee d'une v2, tant que la v1 figure au registre. Une version absente est refusee, sans
 * repli — appliquer une autre formule changerait le resultat d'un combat deja engage.
 */
final readonly class LootContext
{
    /**
     * @param LootPolicy $policy La regle applicable et ses faits.
     * @param int $rateInBasisPoints Le taux qui en decoule, conserve pour l'audit.
     * @param string $policyVersion La regle de taux sous laquelle ce combat a ete ouvert.
     * @param string $allocatorVersion La regle de repartition, de bout en bout.
     * @param bool $targetIsInactive Fait : l'inactivite de la cible a la photographie.
     * @param int $discovererCargo Fait : le fret libre appartenant a des Decouvreurs.
     * @param int $totalCargo Fait : le fret libre offensif total.
     * @param int $observedAt Fait : l'instant de la photographie, en secondes UTC.
     * @param string $snapshotFingerprint L'empreinte des faits economiques.
     * @param NoLootReason|null $noLootBecause Le refus nomme, si ce combat ne pille pas.
     * @param array<string, mixed> $snapshot Les faits economiques qui ont produit l'empreinte.
     */
    private function __construct(
        public LootPolicy $policy,
        public int $rateInBasisPoints,
        public string $policyVersion,
        public string $allocatorVersion,
        public bool $targetIsInactive,
        public int $discovererCargo,
        public int $totalCargo,
        public int $observedAt,
        public string $snapshotFingerprint,
        public NoLootReason|null $noLootBecause,
        public array $snapshot,
    ) {
    }

    /**
     * Un contexte bati sur des faits qui viennent d'etre observes.
     *
     * @param LootPolicy $policy La regle choisie par le selecteur, avec ses faits.
     * @param array<int, AttackerFleetSnapshot> $fleets Les flottes photographiees.
     * @param array<string, mixed> $target Les faits de la cible : cle du corps, proprietaire.
     * @param int $observedAt
     * @param string $allocatorVersion
     * @param LootPolicyRegistry|null $registry
     * @return self
     */
    public static function fromObservedFacts(
        LootPolicy $policy,
        array $fleets,
        array $target,
        int $observedAt,
        string $allocatorVersion,
        LootPolicyRegistry|null $registry = null,
        Resources|null $protectedResources = null,
    ): self {
        $taux = $policy->maximumRateInBasisPoints($registry);
        $snapshot = self::snapshotOf($policy, $fleets, $target, $observedAt, $allocatorVersion, $taux, $protectedResources);

        return new self(
            $policy,
            $taux,
            $policy->version,
            $allocatorVersion,
            $policy->targetIsInactive,
            $policy->cargo->discovererCargo,
            $policy->cargo->totalCargo,
            $observedAt,
            SnapshotFingerprint::of($snapshot),
            $policy->noLootBecause,
            $snapshot,
        );
    }

    /**
     * Le contexte reconstruit depuis les faits persistes a l'ouverture du combat.
     *
     * **Le taux est recalcule sous la regle persistee, jamais sous la regle courante.** Ce qui est
     * conserve sert a verifier, pas a decider : un taux stocke qui ne decoule pas de ses faits
     * signale une alteration, et le contexte est alors refuse plutot que rendu.
     *
     * @param array<string, mixed> $facts
     * @param LootPolicyRegistry|null $policyRegistry
     * @param LootAllocatorRegistry|null $allocatorRegistry
     * @return self
     */
    public static function fromFrozenFacts(
        array $facts,
        LootPolicyRegistry|null $policyRegistry = null,
        LootAllocatorRegistry|null $allocatorRegistry = null,
    ): self {
        $schema = self::intFact($facts, 'fingerprint_schema');

        if ($schema !== SnapshotFingerprint::SCHEMA) {
            throw UnknownFingerprintSchema::because($schema, SnapshotFingerprint::SCHEMA);
        }

        $policyRegistry ??= LootPolicyRegistry::default();
        $allocatorRegistry ??= LootAllocatorRegistry::default();

        $versionRegle = self::stringFact($facts, 'policy_version');
        $versionAllocateur = self::stringFact($facts, 'allocator_version');
        $tauxConserve = self::intFact($facts, 'rate_in_basis_points');
        $empreinte = self::stringFact($facts, 'snapshot_fingerprint');
        $snapshot = self::arrayFact($facts, 'snapshot');
        $refus = self::noLootFact($facts);

        // Les deux versions doivent designer des implementations connues. Un refus ici dit qu'une
        // regle a ete retiree alors que des combats s'en reclamaient encore.
        $regle = $policyRegistry->forVersion($versionRegle);
        $allocatorRegistry->forVersion($versionAllocateur);

        $politique = new LootPolicy(
            self::boolFact($facts, 'target_is_inactive'),
            new AttackerCargoShare(self::intFact($facts, 'discoverer_cargo'), self::intFact($facts, 'total_cargo')),
            // L honneur n a qu un seul etat aujourd hui, et il n est donc pas persiste. Le jour ou
            // ce systeme existera, il devra entrer dans les faits geles comme les autres : le
            // reconstruire par defaut reviendrait alors a inventer un fait.
            HonorPolicy::Disabled,
            $refus,
            $versionRegle,
        );

        $taux = $regle->rateInBasisPoints($politique);

        if ($tauxConserve !== $taux) {
            throw FalsifiedLootContext::becauseTheRateDoesNotMatchTheFacts($tauxConserve, $taux);
        }

        $recalculee = SnapshotFingerprint::of($snapshot);

        if ($recalculee !== $empreinte) {
            throw FalsifiedLootContext::becauseItDoesNotBindToTheseFleets($recalculee, $empreinte);
        }

        return new self(
            $politique,
            $taux,
            $versionRegle,
            $versionAllocateur,
            $politique->targetIsInactive,
            $politique->cargo->discovererCargo,
            $politique->cargo->totalCargo,
            self::intFact($facts, 'observed_at'),
            $empreinte,
            $refus,
            $snapshot,
        );
    }

    /**
     * Les faits a persister avec le combat.
     *
     * @return array<string, mixed>
     */
    public function toFrozenFacts(): array
    {
        return [
            'fingerprint_schema' => SnapshotFingerprint::SCHEMA,
            'policy_version' => $this->policyVersion,
            'allocator_version' => $this->allocatorVersion,
            'rate_in_basis_points' => $this->rateInBasisPoints,
            'target_is_inactive' => $this->targetIsInactive,
            'discoverer_cargo' => $this->discovererCargo,
            'total_cargo' => $this->totalCargo,
            'observed_at' => $this->observedAt,
            'snapshot_fingerprint' => $this->snapshotFingerprint,
            'no_loot_because' => $this->noLootBecause?->value,
            'snapshot' => $this->snapshot,
        ];
    }

    /**
     * S'assure que ce contexte a bien ete photographie pour cette composition.
     *
     * **Seuls les faits structurels sont confrontes** — identifiants, proprietaires, vaisseaux,
     * cargaison, fret libre, cible. La classe et l'inactivite viennent du contexte : les recalculer
     * ici reviendrait a relire les modeles vivants, c'est-a-dire a defaire le gel.
     *
     * @param array<int, AttackerFleet> $fleets Les flottes que le moteur tient en main.
     * @param string $targetBodyKey
     * @return void
     */
    public function ensureItBindsTo(array $fleets, string $targetBodyKey): void
    {
        $attendus = [];

        foreach ($fleets as $flotte) {
            $attendus[$flotte->fleetMissionId] = AttackerFleetSnapshot::structuralFactsOfFleet($flotte);
        }

        ksort($attendus);

        $conserves = [];

        foreach ($this->snapshot['fleets'] ?? [] as $faits) {
            if (!is_array($faits)) {
                continue;
            }

            $conserves[(int)($faits['fleet_mission_id'] ?? 0)] = AttackerFleetSnapshot::structuralPartOf($faits);
        }

        ksort($conserves);

        if ($attendus !== $conserves) {
            throw FalsifiedLootContext::becauseItDoesNotBindToTheseFleets(
                'composition presente : ' . implode(', ', array_keys($attendus)),
                'composition photographiee : ' . implode(', ', array_keys($conserves))
            );
        }

        $cible = $this->snapshot['target']['body_key'] ?? null;

        if ($cible !== $targetBodyKey) {
            throw FalsifiedLootContext::becauseItDoesNotBindToTheseFleets(
                (string)$targetBodyKey,
                is_string($cible) ? $cible : 'cible inconnue'
            );
        }
    }

    /**
     * Si ce combat ne donne droit a aucun pillage.
     */
    /**
     * La reserve que ce combat protege, ou `null` s'il lit le stock vivant.
     *
     * Un combat durable photographie l'etat du corps a son ouverture et n'y ajoute que les livraisons
     * admissibles : c'est cette reserve que le butin plafonne, jamais ce que le monde a fait depuis.
     * Un combat instantane n'a pas de fenetre — il n'y a rien entre sa decision et son effet — et rend
     * `null` : le moteur lit alors le corps, comme il l'a toujours fait.
     */
    public function protectedResources(): Resources|null
    {
        $reserve = $this->snapshot['protected_resources'] ?? null;
        if (!is_array($reserve)) {
            return null;
        }

        return new Resources(
            FrozenFact::int($reserve, 'metal'),
            FrozenFact::int($reserve, 'crystal'),
            FrozenFact::int($reserve, 'deuterium'),
            0,
        );
    }

    public function grantsNoLoot(): bool
    {
        return $this->noLootBecause !== null;
    }

    /**
     * Les faits economiques qui entrent dans l'empreinte.
     *
     * @param LootPolicy $policy
     * @param array<int, AttackerFleetSnapshot> $fleets
     * @param array<string, mixed> $target
     * @param int $observedAt
     * @param string $allocatorVersion
     * @param int $rateInBasisPoints
     * @return array<string, mixed>
     */
    private static function snapshotOf(
        LootPolicy $policy,
        array $fleets,
        array $target,
        int $observedAt,
        string $allocatorVersion,
        int $rateInBasisPoints,
        Resources|null $protectedResources = null,
    ): array {
        $classees = $fleets;

        // Les flottes sont triees par identifiant : leur ordre d'arrivee n'est pas un fait.
        usort($classees, static fn (AttackerFleetSnapshot $a, AttackerFleetSnapshot $b): int
            => $a->fleetMissionId <=> $b->fleetMissionId);

        return [
            'observed_at' => $observedAt,
            'policy_version' => $policy->version,
            'allocator_version' => $allocatorVersion,
            'rate_in_basis_points' => $rateInBasisPoints,
            'no_loot_because' => $policy->noLootBecause?->value,
            'target' => $target,
            'target_is_inactive' => $policy->targetIsInactive,
            'discoverer_cargo' => $policy->cargo->discovererCargo,
            'total_cargo' => $policy->cargo->totalCargo,
            // **La reserve que le combat protege**, quand une photographie l'a determinee : l'etat du
            // corps a l'ouverture, augmente des seules livraisons admissibles. Absente pour un combat
            // instantane, qui n'a pas de fenetre et lit le stock vivant. Elle vit dans le snapshot,
            // donc dans l'empreinte : un rejeu la retrouve, et une falsification se voit.
            'protected_resources' => $protectedResources === null ? null : [
                'metal' => (int)$protectedResources->metal->get(),
                'crystal' => (int)$protectedResources->crystal->get(),
                'deuterium' => (int)$protectedResources->deuterium->get(),
            ],
            'fleets' => array_map(static fn (AttackerFleetSnapshot $f): array => $f->toArray(), $classees),
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @param string $field
     * @return int
     */
    private static function intFact(array $facts, string $field): int
    {
        $valeur = $facts[$field] ?? null;

        if (!is_int($valeur)) {
            throw FalsifiedLootContext::becauseTheFieldIsMissingOrMalformed($field);
        }

        return $valeur;
    }

    /**
     * @param array<string, mixed> $facts
     * @param string $field
     * @return string
     */
    private static function stringFact(array $facts, string $field): string
    {
        $valeur = $facts[$field] ?? null;

        if (!is_string($valeur)) {
            throw FalsifiedLootContext::becauseTheFieldIsMissingOrMalformed($field);
        }

        return $valeur;
    }

    /**
     * @param array<string, mixed> $facts
     * @param string $field
     * @return bool
     */
    private static function boolFact(array $facts, string $field): bool
    {
        $valeur = $facts[$field] ?? null;

        if (!is_bool($valeur)) {
            throw FalsifiedLootContext::becauseTheFieldIsMissingOrMalformed($field);
        }

        return $valeur;
    }

    /**
     * @param array<string, mixed> $facts
     * @param string $field
     * @return array<string, mixed>
     */
    private static function arrayFact(array $facts, string $field): array
    {
        $valeur = $facts[$field] ?? null;

        if (!is_array($valeur)) {
            throw FalsifiedLootContext::becauseTheFieldIsMissingOrMalformed($field);
        }

        return $valeur;
    }

    /**
     * @param array<string, mixed> $facts
     * @return NoLootReason|null
     */
    private static function noLootFact(array $facts): NoLootReason|null
    {
        if (!array_key_exists('no_loot_because', $facts)) {
            throw FalsifiedLootContext::becauseTheFieldIsMissingOrMalformed('no_loot_because');
        }

        $valeur = $facts['no_loot_because'];

        if ($valeur === null) {
            return null;
        }

        if (!is_string($valeur)) {
            throw FalsifiedLootContext::becauseTheFieldIsMissingOrMalformed('no_loot_because');
        }

        $refus = NoLootReason::tryFrom($valeur);

        if ($refus === null) {
            throw new InvalidArgumentException(
                'Le refus de pillage « ' . $valeur . ' » n existe pas : une raison inconnue ne peut pas etre '
                . 'interpretee, et la deviner reviendrait a choisir un droit de pillage au hasard.'
            );
        }

        return $refus;
    }
}
