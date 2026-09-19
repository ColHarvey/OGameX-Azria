<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Enums\CharacterClass;
use OGame\History\ClassHistoryReader;
use OGame\Models\User;
use OGame\Services\AllianceService;
use PHPUnit\Framework\AssertionFailedError;
use Tests\FleetDispatchTestCase;
use Tests\RecordsClassHistory;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * **La garde du montage refuse un compte que la fermeture ne saurait pas geler, et dit pourquoi.**
 *
 * `FleetDispatchTestCase::requireAnAdmissibleHistoryFor()` est appelee par les quatre montages de combat durable
 * (ralliement a fenetre, combat engage, bataille groupee, destruction de lune) sur le proprietaire de la cible et
 * l attaquant. Ce temoin l eprouve directement, sur des comptes fabriques pour cela, sans laisser de flotte en vol :
 * les trois lectures du gel — classe personnelle, appartenance, classe de l alliance — chacune refusee avec la raison
 * du lecteur, et un compte propre accepte. Une mutation qui rendrait la garde muette passerait tous les montages
 * en vert tant qu aucun voisin ne fuit ; elle tombe ici.
 */
final class AdmissibleHistoryGuardTest extends FleetDispatchTestCase
{
    use DetachesFromAnyAlliance;
    use RecordsClassHistory;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /** @var list<int> */
    private array $comptes = [];

    /** @var list<int> */
    private array $alliances = [];

    protected function basicSetup(): void
    {
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        $this->dissolveTheBenchAlliances(...$this->alliances);
        $this->detachFromAnyAlliance(...$this->comptes);

        // Les scenarios de cette classe contredisent volontairement l historique : ils le remettent en accord.
        foreach ($this->comptes as $compte) {
            $this->leaveTheClassHistoryCoherentFor($compte);
            $this->leaveTheMembershipHistoryCoherentFor($compte);
        }

        parent::tearDown();
    }

    public function testACleanAccountPasses(): void
    {
        $compte = $this->unCompte();

        $this->requireAnAdmissibleHistoryFor($compte, $this->instant(), 'le compte propre');
        $this->addToAssertionCount(1);
    }

    /**
     * **Une naissance posterieure a l instant est ramenee par la garde elle-meme, et le compte passe.**
     *
     * C est un artefact d horloge du banc : les essais ne partagent pas la meme, et le voisin etranger est partage par
     * tout le processus (journal §166.2). La reparation vit dans la garde et non chez l appelant, parce que six
     * montages l appellent et qu un seul la faisait — la repartition en processus decidait lequel rencontrait le
     * probleme. Sans ce temoin, retirer la reparation ne casserait rien ici.
     */
    public function testALateBirthIsBroughtBackByTheGuardItself(): void
    {
        $compte = $this->unCompte();
        $instant = $this->instant();
        DB::table('character_class_history')->where('user_id', $compte)->update(['changed_at' => $instant + 3600]);
        DB::table('alliance_membership_history')->where('user_id', $compte)->update(['changed_at' => $instant + 3600]);
        $this->assertFalse(resolve(ClassHistoryReader::class)->personalClassAt($compte, $instant)->isKnown(), 'Premisse : ne apres l instant, le compte est illisible.');

        $this->requireAnAdmissibleHistoryFor($compte, $instant, 'le compte ne trop tard');

        $this->assertSame($instant - 1, (int)DB::table('character_class_history')->where('user_id', $compte)->value('changed_at'), 'La garde a ramene la naissance avant l instant.');
    }

    public function testAClassWrittenAloneIsRefusedWithTheReadersReason(): void
    {
        $compte = $this->unCompte();
        DB::table('users')->where('id', $compte)->update(['character_class' => CharacterClass::GENERAL->value]);

        $refus = $this->leRefusPour($compte, 'le proprietaire de la cible');

        $this->assertStringContainsString('Premisse du montage : le proprietaire de la cible (compte ' . $compte . ')', $refus);
        $this->assertStringContainsString('Classe personnelle : la classe personnelle du compte ' . $compte . ' : l historique finit sur « NULL », la colonne dit « 2 ». Un changement a ete ecrit sans sa ligne.', $refus);
    }

    public function testAMembershipWrittenAloneIsRefusedWithTheReadersReason(): void
    {
        [$membre, $alliance] = $this->unMembreDUneAlliance();
        DB::table('users')->where('id', $membre)->update(['alliance_id' => null]);

        $refus = $this->leRefusPour($membre, 'l attaquant');

        $this->assertStringContainsString('Premisse du montage : l attaquant (compte ' . $membre . ')', $refus);
        $this->assertStringContainsString('Appartenance : l appartenance du compte ' . $membre . ' : l historique finit sur « ' . $alliance . ' », la colonne dit « NULL ». Un changement a ete ecrit sans sa ligne.', $refus);
    }

    public function testAnAllianceClassWrittenAloneIsRefusedForItsMembers(): void
    {
        [$membre, $alliance] = $this->unMembreDUneAlliance();
        DB::table('alliances')->where('id', $alliance)->update(['alliance_class' => 'TRADERS']);

        $refus = $this->leRefusPour($membre, 'le proprietaire de la cible');

        $this->assertStringContainsString('Classe de l alliance : la classe de l alliance ' . $alliance . ' : l historique finit sur « NULL », la colonne dit « \'TRADERS\' ». Un changement a ete ecrit sans sa ligne.', $refus);
    }

    private function leRefusPour(int $compte, string $role): string
    {
        try {
            $this->requireAnAdmissibleHistoryFor($compte, $this->instant(), $role);
        } catch (AssertionFailedError $refus) {
            return $refus->getMessage();
        }

        $this->fail('La garde a accepte un compte que la fermeture ne saurait pas geler.');
    }

    /**
     * @return array{0: int, 1: int} Le membre entre par le jeu, l alliance.
     */
    private function unMembreDUneAlliance(): array
    {
        $service = resolve(AllianceService::class);
        $fondateur = $this->unCompte();
        $membre = $this->unCompte();

        $alliance = (int)$service->createAlliance($fondateur, 'GA' . substr((string)$fondateur, -4), 'Garde ' . $fondateur)->id;
        $this->alliances[] = $alliance;
        $service->acceptApplication((int)$service->applyToAlliance($membre, $alliance)->id, $fondateur);

        return [$membre, $alliance];
    }

    private function unCompte(): int
    {
        $compte = User::factory()->create(['username' => 'garde_' . Str::random(10)]);
        $this->comptes[] = (int)$compte->id;

        return (int)$compte->id;
    }

    /**
     * L instant d admission eprouve : juste apres l horloge du banc, ou chaque ligne est ecrite.
     */
    private function instant(): int
    {
        return (int)Date::now()->timestamp + 1;
    }
}
