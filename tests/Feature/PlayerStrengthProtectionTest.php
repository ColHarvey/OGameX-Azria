<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Protection\PlayerStrengthGuard;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * **La protection des debutants : un geant ne frappe pas un debutant, ni l inverse.**
 *
 * ## Ce que ce banc ferme
 *
 * `isNewbie()` et `isStrong()` etaient ecrites depuis l origine, seuils compris — moins de 20 % des
 * points de l autre, plus de 500 % des siens. Elles n etaient lues **qu a un seul endroit** : la
 * composition d une ligne de Galaxie, pour afficher une lettre de statut a cote du nom. Aucun refus
 * du serveur ne les consultait : un joueur a cinq cent mille points pouvait attaquer un debutant a
 * deux cents, et rien ne l en empechait.
 *
 * Trouve le 12 septembre 2026 en comparant, refus par refus, ce que refuse l attaque d une planete et
 * ce que refuse l attaque d une patrouille. **Comparer un objet a ses pairs, champ par champ.**
 *
 * ## Ce que ces temoins exigent, et pourquoi chacun
 *
 * Les deux sens, parce que la regle est symetrique. Le cas comparable, sans quoi « tout est refuse »
 * passerait. L interrupteur, parce qu une protection qu on ne peut pas desarmer est un piege. Et
 * l inactif, parce que le recolter est une mecanique du jeu que cette protection n a jamais eu pour
 * role d empecher.
 */
class PlayerStrengthProtectionTest extends AccountTestCase
{
    private const int FAIBLE = 200;

    private const int FORT = 500000;

    protected function tearDown(): void
    {
        foreach ($this->origine as $userId => $etat) {
            User::query()->whereKey($userId)->update(['time' => $etat['time']]);

            if ($etat['score'] === null) {
                Highscore::query()->where('player_id', $userId)->delete();
            } else {
                Highscore::query()->where('player_id', $userId)->update(['general' => $etat['score']]);
            }
        }

        $this->origine = [];

        resolve(SettingsService::class)->set('newbie_protection_enabled', 0);
        Date::setTestNow();

        parent::tearDown();
    }

    private function armer(): void
    {
        resolve(SettingsService::class)->set('newbie_protection_enabled', 1);
    }

    /**
     * Pose le score d un joueur, et le rend actif — l inactivite est jugee a part.
     */
    /** @var array<int, array{score: int|null, time: int}> l etat d origine de chaque joueur touche */
    private array $origine = [];

    private function poserLeScore(int $userId, int $score, bool $actif = true): void
    {
        /*
         * **Retenir avant d ecrire.** La base d un processus est partagee entre les classes : un
         * score ou une date d activite laisses en place changent ce que le banc voisin voit. Celui
         * de l affichage des cibles a rougi exactement ainsi, et ce n etait pas sa faute.
         */
        if (!array_key_exists($userId, $this->origine)) {
            $ligne = User::query()->whereKey($userId)->first();
            $this->origine[$userId] = [
                'score' => Highscore::query()->where('player_id', $userId)->value('general'),
                'time' => (int)($ligne->time ?? 0),
            ];
        }

        Highscore::query()->updateOrCreate(
            ['player_id' => $userId],
            ['general' => $score, 'economy' => $score, 'research' => 0, 'military' => 0]
        );

        User::query()->whereKey($userId)->update([
            'time' => $actif ? (int)Date::now()->timestamp : (int)Date::now()->subDays(30)->timestamp,
        ]);
    }

    /**
     * Un second joueur, avec son score. Rend son identifiant.
     */
    private function unAutreJoueur(int $score, bool $actif = true): int
    {
        $autre = User::query()->where('id', '!=', $this->currentUserId)->where('is_npc', 0)->firstOrFail();

        $this->poserLeScore((int)$autre->id, $score, $actif);

        return (int)$autre->id;
    }

    private function garde(): PlayerStrengthGuard
    {
        return resolve(PlayerStrengthGuard::class);
    }

    public function testUnGeantNePeutPasFrapperUnDebutant(): void
    {
        $this->armer();
        $this->poserLeScore($this->currentUserId, self::FORT);
        $cible = $this->unAutreJoueur(self::FAIBLE);

        $this->assertTrue(
            $this->garde()->forbids(CombatMissionKind::Attack, $this->currentUserId, $cible),
            'Un joueur a ' . self::FORT . ' points peut attaquer un debutant a ' . self::FAIBLE . '.'
        );
    }

    public function testUnDebutantNePeutPasFrapperUnGeant(): void
    {
        $this->armer();
        $this->poserLeScore($this->currentUserId, self::FAIBLE);
        $cible = $this->unAutreJoueur(self::FORT);

        $this->assertTrue(
            $this->garde()->forbids(CombatMissionKind::Attack, $this->currentUserId, $cible),
            'La protection ne joue que dans un sens : le debutant peut encore se jeter sur le geant.'
        );
    }

    /**
     * **Le cas comparable, et il compte autant que les deux autres.** Sans lui, une garde qui
     * refuserait tout passerait les deux temoins precedents.
     */
    public function testDeuxJoueursComparablesPeuventSAffronter(): void
    {
        $this->armer();
        $this->poserLeScore($this->currentUserId, 10000);
        $cible = $this->unAutreJoueur(12000);

        $this->assertFalse(
            $this->garde()->forbids(CombatMissionKind::Attack, $this->currentUserId, $cible),
            'Deux joueurs de force voisine ne peuvent plus s affronter : la protection refuse tout.'
        );
    }

    /**
     * **Desarmee, elle ne refuse rien.** Une protection qu on ne peut pas eteindre est un piege :
     * toute mecanique neuve de ce serveur s arme et se desarme.
     */
    public function testDesarmeeElleNeRefuseRien(): void
    {
        resolve(SettingsService::class)->set('newbie_protection_enabled', 0);
        $this->poserLeScore($this->currentUserId, self::FORT);
        $cible = $this->unAutreJoueur(self::FAIBLE);

        $this->assertFalse(
            $this->garde()->forbids(CombatMissionKind::Attack, $this->currentUserId, $cible),
            'La protection refuse alors qu elle est desarmee : impossible de l eteindre en urgence.'
        );
    }

    /**
     * **Un compte inactif perd sa protection** (demande de Keven, 12 septembre 2026).
     *
     * Recolter un inactif est une mecanique du jeu ; cette protection n a jamais eu pour role de
     * l empecher. La premisse est etablie avant : le meme couple, cible active, est bien refuse.
     */
    public function testUnCompteInactifNEstPlusProtege(): void
    {
        $this->armer();
        $this->poserLeScore($this->currentUserId, self::FORT);

        $cible = $this->unAutreJoueur(self::FAIBLE);

        $this->assertTrue(
            $this->garde()->forbids(CombatMissionKind::Attack, $this->currentUserId, $cible),
            'La premisse manque : ce debutant actif n est deja pas protege.'
        );

        // Le meme joueur, devenu inactif.
        $this->poserLeScore($cible, self::FAIBLE, false);

        $this->assertFalse(
            $this->garde()->forbids(CombatMissionKind::Attack, $this->currentUserId, $cible),
            'Un debutant inactif reste protege : il ne peut plus etre recolte.'
        );
    }

    /**
     * **Se viser soi-meme n est pas une affaire de force.**
     *
     * Le court-circuit qui l ecarte est **equivalent** : sa mutation survit a ce temoin, et c est
     * juste. Avec deux scores egaux les deux comparaisons sont fausses, donc l ecart de force
     * n a jamais pu interdire ce cas. Ce temoin epingle le comportement, pas la ligne.
     *
     * D autres regles refusent deja de se viser soi-meme, et
     * repondre « l ecart de puissance » ici serait faux.
     */
    public function testSeViserSoiMemeNEstPasUneAffaireDeForce(): void
    {
        $this->armer();
        $this->poserLeScore($this->currentUserId, self::FORT);

        $this->assertFalse(
            $this->garde()->forbids(CombatMissionKind::Attack, $this->currentUserId, $this->currentUserId),
            'La garde repond « ecart de puissance » a un joueur qui se vise lui-meme.'
        );
    }

    /**
     * **Les deux chemins d attaque posent les memes protections.**
     *
     * C est le temoin qui ferme la classe de defaut, pas seulement le defaut. Attaquer une planete
     * et attaquer une patrouille sont deux offensives d un joueur contre un autre : ce qui interdit
     * l une doit interdire l autre. La protection d alliance ne couvrait que la premiere — armee,
     * elle aurait ete contournable en visant la patrouille d un allie au lieu de sa planete.
     *
     * **Ce que ce temoin prouve, et ce qu il ne prouve pas.** Il etablit que les deux gardes sont
     * invoquees sur le chemin des patrouilles, et il rougira si une protection future n est posee
     * que d un cote. Il ne rejoue pas leur decision — celle-la est eprouvee par les temoins
     * ci-dessus, sur la garde elle-meme.
     */
    public function testLesDeuxCheminsDAttaquePosentLesMemesProtections(): void
    {
        $patrouille = (string)file_get_contents(app_path('Patrol/PatrolAttackEligibility.php'));
        $planete = (string)file_get_contents(app_path('GameMissions/AttackMission.php'));
        $commun = (string)file_get_contents(app_path('GameMissions/Abstracts/GameMission.php'));

        foreach (['AllianceOffensiveGuard', 'PlayerStrengthGuard'] as $garde) {
            $this->assertStringContainsString(
                $garde,
                $commun,
                $garde . ' is no longer applied to planet attacks.'
            );

            $this->assertStringContainsString(
                $garde,
                $patrouille,
                $garde . ' is not applied to patrol attacks: the protection can be bypassed by attacking the patrol instead of the planet.'
            );
        }

        // Les deux gardes sont bien posees sur l attaque de planete, au meme endroit.
        foreach (['checkAllianceProtection', 'checkStrengthProtection'] as $controle) {
            $this->assertStringContainsString(
                $controle . '($planet, $targetPlanet)',
                $planete,
                'Planet attacks no longer run ' . $controle . '.'
            );
        }

        // Et les deux refus que le joueur lira existent.
        foreach (['target_is_an_ally', 'target_strength_protected'] as $refus) {
            $this->assertStringContainsString(
                "'" . $refus . "'",
                $patrouille,
                'The patrol path no longer refuses with ' . $refus . '.'
            );
        }
    }

    /**
     * **L espionnage reste ouvert**, comme dans le jeu d origine : on peut toujours sonder un
     * debutant. La garde ne juge que les genres offensifs.
     */
    public function testLEspionnageResteOuvert(): void
    {
        $this->armer();
        $this->poserLeScore($this->currentUserId, self::FORT);
        $cible = $this->unAutreJoueur(self::FAIBLE);

        $this->assertFalse(
            $this->garde()->forbids(CombatMissionKind::Espionage, $this->currentUserId, $cible),
            'L espionnage d un debutant est refuse : la protection deborde sur un genre non offensif.'
        );

        // Et les quatre genres offensifs, eux, sont bien tous couverts.
        foreach ([CombatMissionKind::Attack, CombatMissionKind::AcsAttack, CombatMissionKind::Missile, CombatMissionKind::MoonDestruction] as $genre) {
            $this->assertTrue(
                $this->garde()->forbids($genre, $this->currentUserId, $cible),
                'Le genre ' . $genre->name . ' echappe a la protection.'
            );
        }
    }
}
