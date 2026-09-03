<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Allocation\FrozenLootPotential;
use OGame\Combat\Exceptions\CorruptedFrozenLootAmounts;
use OGame\Combat\Exceptions\CorruptedResourceAmount;
use OGame\Combat\Exceptions\MismatchedRuleVersionSet;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Le butin potentiel : fige une fois depuis l'issue du moteur, en entiers, et relu tel quel.
 *
 * ## La premiere moitie de la regle
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * Sans potentiel fige, l'attaquant prendrait la production arrivee pendant le combat. Le potentiel
 * ne lit donc jamais la planete vivante : il fige ce que le moteur a rendu depuis le contexte gele,
 * et c'est ce qui est relu au reglement.
 *
 * ## Ce que ces essais prouvent
 *
 * La conversion en entiers se fait une fois, sans tolerance au negatif, et ses diagnostics voyagent
 * a cote des montants. Les versions du resultat sont verifiees contre celles du combat, jamais
 * copiees. Ce qui est ecrit sur l'instance se relit a l'identique, y compris au-dela de deux
 * puissance cinquante-trois — et une colonne corrompue se distingue d'une colonne pas encore ecrite.
 */
class FrozenLootPotentialTest extends TestCase
{
    private const int OPENING = 1_700_000_000;

    /**
     * Quatre fois deux puissance cinquante-trois : exactement representable en flottant, au-dela de
     * la precision entiere.
     */
    private const int BEYOND_EXACT_FLOAT = 36_028_797_018_963_968;

    private int $bodies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * Le potentiel est l'issue du moteur, en entiers, avec ses versions et son empreinte.
     */
    public function testThePotentialIsFrozenFromTheEngineOutcomeInExactUnits(): void
    {
        $versions = FrozenCombatVersionSet::chosenAtOpening();
        $issue = $this->anOutcomeOf(1_000.0, 500.0, 200.0, $versions, 7_500, 'empreinte:abc');

        $potentiel = FrozenLootPotential::frozenFrom($issue, $versions);

        $this->assertSame(1_000, $potentiel->amounts->metal);
        $this->assertSame(500, $potentiel->amounts->crystal);
        $this->assertSame(200, $potentiel->amounts->deuterium);

        $this->assertSame(7_500, $potentiel->rateInBasisPoints);
        $this->assertSame($versions->lootAllocator, $potentiel->allocatorVersion);
        $this->assertSame($versions->lootPolicy, $potentiel->policyVersion);
        $this->assertSame('empreinte:abc', $potentiel->snapshotFingerprint);

        $this->assertFalse($potentiel->diagnostics->any(), 'A clean outcome produced conversion diagnostics.');
    }

    /**
     * Une fraction est arrondie vers le bas, comme tout fait gele.
     *
     * Le moteur rend des entiers par construction ; une fraction signalerait qu'il a change. La
     * frontiere des faits geles tranche vers le bas — on ne prend pas une fraction d'unite qui
     * n'existe pas — et c'est la regle deja arretee dans `ResourceBoundary`, pas une nouvelle.
     */
    public function testAFractionalOutcomeIsFlooredLikeAnyFrozenFact(): void
    {
        $versions = FrozenCombatVersionSet::chosenAtOpening();
        $issue = $this->anOutcomeOf(1_000.9, 500.1, 200.5, $versions);

        $potentiel = FrozenLootPotential::frozenFrom($issue, $versions);

        $this->assertSame(1_000, $potentiel->amounts->metal);
        $this->assertSame(500, $potentiel->amounts->crystal);
        $this->assertSame(200, $potentiel->amounts->deuterium);
    }

    /**
     * Un resultat negatif est refuse, sans la tolerance accordee aux soldes vivants.
     *
     * Un solde vivant peut porter un artefact de moins d'une unite sous zero, corrige a la
     * frontiere. Un resultat gele qui porterait un negatif affirmerait un fait que personne n'a
     * observe : il ne se corrige pas, il s'arrete.
     */
    public function testANegativeOutcomeIsRefusedWithoutTolerance(): void
    {
        $versions = FrozenCombatVersionSet::chosenAtOpening();
        $issue = $this->anOutcomeOf(1_000.0, -0.5, 200.0, $versions);

        $this->expectException(CorruptedResourceAmount::class);

        FrozenLootPotential::frozenFrom($issue, $versions);
    }

    /**
     * Au-dela de deux puissance cinquante-trois, la degradation est dite, pas tue.
     *
     * Le moteur rend des flottants : a cette hauteur, la valeur qu'il rend est deja privee de sa
     * derniere unite avant meme d'arriver ici. Le gel ne peut pas la recuperer — mais il ne doit
     * pas non plus la faire passer pour exacte. Le diagnostic voyage **a cote** des montants, qui
     * restent des entiers.
     */
    public function testAnOutcomeBeyondExactFloatPrecisionIsFrozenAndTheDegradationIsSaid(): void
    {
        $versions = FrozenCombatVersionSet::chosenAtOpening();
        $issue = $this->anOutcomeOf((float)self::BEYOND_EXACT_FLOAT, 0.0, 0.0, $versions);

        $potentiel = FrozenLootPotential::frozenFrom($issue, $versions);

        $this->assertSame(self::BEYOND_EXACT_FLOAT, $potentiel->amounts->metal);

        $this->assertTrue($potentiel->diagnostics->any(), 'A degraded conversion went unreported.');
        $this->assertArrayHasKey(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            $potentiel->diagnostics->groupedByCode(),
            'The degradation was reported under another code.'
        );
    }

    /**
     * Un resultat calcule sous d'autres versions que le combat n'est pas le resultat de ce combat.
     *
     * Le figer ferait regler une bataille sous une regle qu'elle n'a jamais connue. La comparaison
     * leve plutot que de rendre `false` : il n'y a pas de branche juste apres un desaccord.
     */
    public function testAnOutcomeComputedUnderOtherVersionsIsRefused(): void
    {
        $versions = FrozenCombatVersionSet::chosenAtOpening();
        $issue = $this->anOutcomeOf(1_000.0, 500.0, 200.0, $versions);
        $issue->lootAllocatorVersion = 'un_autre_allocateur';

        $this->expectException(MismatchedRuleVersionSet::class);

        FrozenLootPotential::frozenFrom($issue, $versions);
    }

    /**
     * Le potentiel ecrit sur l'instance se relit a l'identique, au-dela de deux puissance
     * cinquante-trois compris.
     *
     * **C'est l'essai qui justifie les colonnes `bigint`.** Une colonne flottante rendrait ici un
     * montant different de celui qui a ete ecrit, et le reglement debiterait autre chose que ce qui
     * etait dû.
     */
    public function testThePotentialSurvivesPersistenceOnTheInstance(): void
    {
        $combat = $this->anOpenedCombat();
        $versions = FrozenCombatVersionSet::fromInstance($combat);

        // **Aucune composante a zero.** Un zero laisserait passer une colonne oubliee : ecrire 0 a la
        // place du deuterium rendrait exactement ce que l essai attendait.
        $issue = $this->anOutcomeOf((float)self::BEYOND_EXACT_FLOAT, 500.0, 200.0, $versions, 5_000, 'empreinte:xyz');
        $potentiel = FrozenLootPotential::frozenFrom($issue, $versions);

        $combat->fill($potentiel->toColumns(self::OPENING + 600));
        $combat->save();

        $relu = FrozenLootPotential::fromInstance(CombatInstance::query()->findOrFail($combat->id));

        $this->assertNotNull($relu, 'A frozen potential read back as not frozen.');
        $this->assertTrue($relu->amounts->equals($potentiel->amounts), 'The potential changed between write and read.');
        $this->assertSame(self::BEYOND_EXACT_FLOAT, $relu->amounts->metal);
        $this->assertSame(500, $relu->amounts->crystal);
        $this->assertSame(200, $relu->amounts->deuterium);
        $this->assertSame(5_000, $relu->rateInBasisPoints);
        $this->assertSame($versions->lootAllocator, $relu->allocatorVersion);
        $this->assertSame($versions->lootPolicy, $relu->policyVersion);
        $this->assertSame('empreinte:xyz', $relu->snapshotFingerprint);
    }

    /**
     * Une instance dont le potentiel n'est pas fige se lit comme telle, pas comme corrompue.
     *
     * Un combat non resolu est un etat normal. Le confondre avec une corruption ferait traiter
     * chaque combat en cours comme un incident.
     */
    public function testAnUnfrozenInstanceReadsAsNullAndNotAsCorrupted(): void
    {
        $combat = $this->anOpenedCombat();

        $this->assertNull(FrozenLootPotential::fromInstance($combat));
    }

    /**
     * Un taux corrompu se refuse aussi, pas seulement les montants.
     *
     * ## La mutation qui a rendu cet essai necessaire
     *
     * Retirer le controle du taux a la relecture a **survecu** : les essais ne corrompaient que les
     * montants. Un taux negatif ou absent aurait donc ete relu tel quel, et le rapport d'audit
     * aurait affirme un pourcentage que personne n'a calcule.
     */
    public function testACorruptedRateIsRefusedAtReading(): void
    {
        $combat = $this->anOpenedCombat();
        $versions = FrozenCombatVersionSet::fromInstance($combat);

        $potentiel = FrozenLootPotential::frozenFrom($this->anOutcomeOf(1_000.0, 500.0, 200.0, $versions), $versions);
        $combat->fill($potentiel->toColumns(self::OPENING + 600));
        $combat->save();

        DB::table('combat_instances')->where('id', $combat->id)->update(['potential_loot_rate_in_basis_points' => null]);

        $this->expectException(CorruptedFrozenLootAmounts::class);

        FrozenLootPotential::fromInstance(CombatInstance::query()->findOrFail($combat->id));
    }

    /**
     * Une colonne corrompue se refuse a la relecture, elle ne s'interprete pas.
     */
    public function testACorruptedColumnIsRefusedAtReading(): void
    {
        $combat = $this->anOpenedCombat();
        $versions = FrozenCombatVersionSet::fromInstance($combat);

        $potentiel = FrozenLootPotential::frozenFrom($this->anOutcomeOf(1_000.0, 500.0, 200.0, $versions), $versions);
        $combat->fill($potentiel->toColumns(self::OPENING + 600));
        $combat->save();

        // Quelqu'un a ecrit un negatif la ou seul un montant non negatif a un sens.
        DB::table('combat_instances')->where('id', $combat->id)->update(['potential_loot_crystal' => -5]);

        $this->expectException(CorruptedFrozenLootAmounts::class);

        FrozenLootPotential::fromInstance(CombatInstance::query()->findOrFail($combat->id));
    }

    /**
     * Une issue de moteur reduite a ce que le gel regarde.
     */
    private function anOutcomeOf(
        float $metal,
        float $crystal,
        float $deuterium,
        FrozenCombatVersionSet $versions,
        int $rateInBasisPoints = 5_000,
        string $fingerprint = 'empreinte:essai',
    ): BattleResult {
        $issue = new BattleResult();
        $issue->loot = new Resources($metal, $crystal, $deuterium, 0);
        $issue->lootRateInBasisPoints = $rateInBasisPoints;
        $issue->lootAllocatorVersion = $versions->lootAllocator;
        $issue->lootPolicyVersion = $versions->lootPolicy;
        $issue->lootSnapshotFingerprint = $fingerprint;

        return $issue;
    }

    /**
     * Un combat ouvert par une flotte.
     */
    private function anOpenedCombat(): CombatInstance
    {
        $joueur = $this->aPlayer();
        $corps = $this->aPlanetOwnedBy($this->aPlayer())->id;

        $ouvreur = FleetMission::forceCreate([
            'user_id' => $joueur->id,
            // L'engagement, qui suit desormais une fenetre nulle, exige une planete d'origine :
            // le retour y revient, et le moteur la nomme.
            'planet_id_from' => $this->aPlanetIdOf($joueur),
            'type_from' => 1,
            'planet_id_to' => $corps,
            'mission_type' => 1,
            'time_departure' => self::OPENING - 600,
            'time_arrival' => self::OPENING,
            'galaxy_to' => 9,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);

        return (new CombatOpeningService())->openOrJoin($ouvreur, $corps, self::OPENING);
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
     * Une planete a des coordonnees libres, deterministes.
     */
    private function aPlanetOwnedBy(User $owner): Planet
    {
        $this->bodies++;

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 9,
            'system' => 300 + intdiv($this->bodies, 15),
            'planet' => ($this->bodies % 15) + 1,
        ]);
    }

    /**
     * La planete d'un joueur cree par ces fixtures.
     */
    private function aPlanetIdOf(User $owner): int
    {
        $id = Planet::query()->where('user_id', $owner->id)->value('id');

        if (!is_int($id)) {
            $this->fail('The player ' . $owner->id . ' owns no planet: no attack could leave from anywhere.');
        }

        return $id;
    }
}
