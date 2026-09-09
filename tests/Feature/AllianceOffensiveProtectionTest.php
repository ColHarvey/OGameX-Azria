<?php

namespace Tests\Feature;

use OGame\Combat\Enums\CombatMissionKind;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Aucune offensive entre membres d une meme alliance.
 *
 * ## La regle et sa source
 *
 * Plan approuve du 9 septembre 2026, section 2. Elle n existait pas : rien n empechait d attaquer
 * la planete d un membre de sa propre alliance. Ces temoins etablissent le refus au **lancement** ;
 * celui de l arrivee, sous la transaction ou l action devient effective, est la piece suivante et
 * n est pas couvert ici — le dire plutot que de laisser croire que la protection est entiere.
 *
 * ## Le monde est fabrique
 *
 * L alliance est creee par cet essai, les deux joueurs y sont inscrits, et tout est retire au
 * demontage : la base d un processus est partagee, et une alliance laissee derriere ferait dependre
 * un voisin de l ordre d execution.
 */
class AllianceOffensiveProtectionTest extends AccountTestCase
{
    private int|null $alliance = null;

    protected function tearDown(): void
    {
        if ($this->alliance !== null) {
            // Le lien vit sur `users` autant que dans `alliance_members` : les deux partent, sinon un
            // essai voisin heriterait d une appartenance que plus rien ne porte.
            \Illuminate\Support\Facades\DB::table('users')->where('alliance_id', $this->alliance)->update(['alliance_id' => null, 'alliance_left_at' => null]);
            AllianceMember::query()->where('alliance_id', $this->alliance)->delete();
            Alliance::query()->whereKey($this->alliance)->delete();
            $this->alliance = null;
        }

        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 0);

        parent::tearDown();
    }

    private function armer(): void
    {
        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 1);
    }

    /**
     * Inscrit les deux joueurs dans une meme alliance, creee pour cet essai.
     */
    private function uneAllianceCommune(int $autre): void
    {
        /*
         * **Le vrai chemin, pas une ligne ecrite a la main.** Un temoin qui fabrique lui-meme ce
         * qu il verifie ne protege pas le constructeur reel : l appartenance vit sur `users`, pas
         * seulement dans `alliance_members`, et une insertion directe laisserait le lien a moitie
         * pose sans que rien ne le dise.
         */
        $service = resolve(AllianceService::class);

        $alliance = $service->createAlliance(
            $this->currentUserId,
            'E' . substr((string)$this->currentUserId, -3) . substr((string)$autre, -3),
            'Essai ' . $this->currentUserId . '-' . $autre
        );

        $this->alliance = (int)$alliance->id;

        $candidature = $service->applyToAlliance($autre, $this->alliance);
        $service->acceptApplication((int)$candidature->id, $this->currentUserId);

        $this->assertTrue(
            $service->arePlayersInSameAlliance($this->currentUserId, $autre),
            'The two players are not in the same alliance: nothing would be proved.'
        );
    }

    /**
     * Un joueur etranger, avec une planete.
     *
     * @return array{int, \OGame\Services\PlanetService}
     */
    private function unEtranger(): array
    {
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();

        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        return [$proprietaire->getId(), $etrangere];
    }

    /**
     * Le genre decide de l offensive, et la correspondance est exhaustive.
     */
    public function testOnlyTheOffensiveKindsAreGuarded(): void
    {
        $offensifs = [
            CombatMissionKind::Attack,
            CombatMissionKind::AcsAttack,
            CombatMissionKind::MoonDestruction,
            CombatMissionKind::Missile,
        ];

        foreach (CombatMissionKind::cases() as $genre) {
            $this->assertSame(
                in_array($genre, $offensifs, true),
                $genre->isOffensiveAgainstAnotherPlayer(),
                'Le genre ' . $genre->value . ' n est pas classe comme le plan le dit.'
            );
        }
    }

    /**
     * Le decideur refuse une offensive contre un membre, et rien d autre.
     */
    public function testTheGuardRefusesAnOffensiveAgainstAMemberAndNothingElse(): void
    {
        $this->armer();
        [$etranger] = $this->unEtranger();
        $this->uneAllianceCommune($etranger);

        $garde = resolve(\OGame\Alliance\AllianceOffensiveGuard::class);

        $this->assertTrue($garde->forbids(CombatMissionKind::Attack, $this->currentUserId, $etranger));
        $this->assertTrue($garde->forbids(CombatMissionKind::Missile, $this->currentUserId, $etranger));
        $this->assertTrue($garde->forbids(CombatMissionKind::MoonDestruction, $this->currentUserId, $etranger));

        // L espionnage n est pas nomme par le plan : il reste permis entre membres.
        $this->assertFalse($garde->forbids(CombatMissionKind::Espionage, $this->currentUserId, $etranger));
        $this->assertFalse($garde->forbids(CombatMissionKind::Transport, $this->currentUserId, $etranger));

        // Un bien sans proprietaire n appartient a aucune alliance.
        $this->assertFalse($garde->forbids(CombatMissionKind::Attack, $this->currentUserId, null));

        // Se viser soi-meme releve d une autre regle, et repondre « votre alliance » serait faux.
        $this->assertFalse($garde->forbids(CombatMissionKind::Attack, $this->currentUserId, $this->currentUserId));
    }

    /**
     * Hors alliance commune, rien ne change.
     */
    public function testAPlayerOutsideTheAllianceIsStillAttackable(): void
    {
        $this->armer();
        [$etranger] = $this->unEtranger();

        $this->assertFalse(
            resolve(\OGame\Alliance\AllianceOffensiveGuard::class)->forbids(CombatMissionKind::Attack, $this->currentUserId, $etranger),
            'A player who shares no alliance was protected.'
        );
    }

    /**
     * **L interrupteur eteint, la regle n existe pas.** C est ce qui garantit qu armer le chantier
     * est une decision, et que le jeu se comporte exactement comme avant tant qu elle n est pas
     * prise.
     */
    public function testTheRuleDoesNotExistWhileTheSwitchIsOff(): void
    {
        [$etranger] = $this->unEtranger();
        $this->uneAllianceCommune($etranger);

        $this->assertFalse(
            resolve(\OGame\Alliance\AllianceOffensiveGuard::class)->forbids(CombatMissionKind::Attack, $this->currentUserId, $etranger),
            'The rule applied although its switch is off.'
        );
    }

    /**
     * Le lancement d une attaque contre la planete d un membre est refuse, avec sa raison.
     */
    public function testAnAttackAgainstAMemberIsRefusedAtLaunch(): void
    {
        $this->armer();
        [$etranger, $cible] = $this->unEtranger();
        $this->uneAllianceCommune($etranger);

        $mission = resolve(\OGame\GameMissions\AttackMission::class);
        $depart = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->first();

        $this->assertNotNull($depart);

        $verdict = $mission->isMissionPossible(
            $depart,
            $cible->getPlanetCoordinates(),
            $cible->getPlanetType(),
            $this->uneFlotte()
        );

        $this->assertFalse($verdict->possible, 'An attack against an alliance member was accepted at launch.');
        $this->assertSame(__('t_ingame.alliance.refusal_offensive_against_a_member'), $verdict->error);
    }

    /**
     * La meme attaque, hors alliance commune, reste possible.
     */
    public function testTheSameAttackStaysPossibleOutsideTheAlliance(): void
    {
        $this->armer();
        [, $cible] = $this->unEtranger();

        $mission = resolve(\OGame\GameMissions\AttackMission::class);
        $depart = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->first();

        $this->assertNotNull($depart);

        $verdict = $mission->isMissionPossible(
            $depart,
            $cible->getPlanetCoordinates(),
            $cible->getPlanetType(),
            $this->uneFlotte()
        );

        $this->assertTrue($verdict->possible, 'An ordinary attack was refused: the guard is too wide. ' . (string)$verdict->error);
    }

    private function uneFlotte(): \OGame\GameObjects\Models\Units\UnitCollection
    {
        $flotte = new \OGame\GameObjects\Models\Units\UnitCollection();
        $flotte->addUnit(\OGame\Services\ObjectService::getUnitObjectByMachineName('light_fighter'), 1);

        return $flotte;
    }
}
