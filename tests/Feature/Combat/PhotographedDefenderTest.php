<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\CombatInstance;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Les technologies de combat du defenseur sont celles de la photographie, pas celles du joueur vivant.
 *
 * ## La regle, et le defaut qu'elle ferme
 *
 * Une recherche appartient au combat si elle a ete **engagee avant l'ouverture** et **achevee avant la
 * fermeture**. Engagee apres l'ouverture, elle ne lui appartient pas : un defenseur ne renforce pas une
 * defense deja engagee en lancant une recherche pendant le ralliement. Tant que le moteur lisait le
 * joueur vivant, il suffisait qu'une page soit visitee pour que le niveau monte dans la bataille.
 *
 * Comme pour les files d'unites, l'effet admissible est **compte** sans etre applique : le gestionnaire
 * de la file de recherche traite tout ce qui est echu, et l'appeler drainerait l'inadmissible.
 */
final class PhotographedDefenderTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /**
     * Les files du proprietaire, telles qu elles etaient aux deux instants qui decident.
     *
     * **Un releve fait au seul moment de l echec ne suffit pas.** Une file presente a
     * l ouverture peut avoir ete traitee ou supprimee avant qu on regarde : « aucune file » a
     * la fin n ecarterait donc pas la piste qu elle designe. Les deux photographies sont prises
     * en cours de route et ne sont rendues que si une assertion tombe.
     *
     * @var array<string, string>
     */
    private array $photographiesDesFiles = [];

    private const int NIVEAU_OUVERTURE = 3;

    private const int NIVEAU_ADMISSIBLE = 4;

    private const int NIVEAU_INADMISSIBLE = 9;

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

    public function testAnEligibleResearchRaisesTheFrozenLevelAndAnIneligibleOneDoesNot(): void
    {
        [$combat, $cible, $ouverture, $proprietaire] = $this->anOpenRallyAgainstAResearcher();

        // Engagee avant l'ouverture, achevee dans la fenetre : elle appartient au combat.
        $admissible = $this->aResearchQueue($cible, 'weapon_technology', self::NIVEAU_ADMISSIBLE, $ouverture - 100, $ouverture + 5);
        // Engagee apres l'ouverture : elle ne lui appartient pas, meme achevee avant la fermeture.
        $inadmissible = $this->aResearchQueue($cible, 'shielding_technology', self::NIVEAU_INADMISSIBLE, $ouverture + 1, $ouverture + 6);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->photographierLesFiles($proprietaire, 'avant fermeture');
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $bonus = $this->classBonusOf($proprietaire);
        $this->assertSame(self::NIVEAU_ADMISSIBLE + $bonus, $this->frozenLevel($combat, 'weapon'), 'The eligible research did not raise the level the battle used.' . $this->diagnosticDuGel($cible, $proprietaire, $ouverture));
        $this->assertSame(self::NIVEAU_OUVERTURE + $bonus, $this->frozenLevel($combat, 'shield'), 'A research engaged after the opening strengthened a defence already engaged.' . $this->diagnosticDuGel($cible, $proprietaire, $ouverture));

        // Ni l'une ni l'autre n'a ete appliquee : la fermeture ne draine pas la file de recherche.
        $this->assertSame(0, (int)DB::table('research_queues')->where('id', $admissible)->value('processed'));
        $this->assertSame(0, (int)DB::table('research_queues')->where('id', $inadmissible)->value('processed'));
    }

    /**
     * Le temoin inverse : le joueur vivant donnerait un autre niveau. Sans lui, l'essai passerait
     * meme si le moteur lisait encore le joueur.
     */
    public function testTheLivingPlayerWouldGiveADifferentLevel(): void
    {
        [$combat, $cible, $ouverture, $proprietaire] = $this->anOpenRallyAgainstAResearcher();

        // Le monde termine la recherche pendant le ralliement, sur une decision posterieure.
        resolve(PlayerServiceFactory::class)->make($proprietaire, true)->setResearchLevel('weapon_technology', self::NIVEAU_INADMISSIBLE);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->photographierLesFiles($proprietaire, 'avant fermeture');
        (new RallyClosureService())->close($combat->id, $fermeture);

        $bonus = $this->classBonusOf($proprietaire);
        $this->assertSame(self::NIVEAU_OUVERTURE + $bonus, $this->frozenLevel($combat, 'weapon'), 'The battle used the level the world reached during the rally.' . $this->diagnosticDuGel($cible, $proprietaire, $ouverture));
        $this->assertNotSame(self::NIVEAU_INADMISSIBLE + $bonus, $this->frozenLevel($combat, 'weapon'), 'The living player and the photograph agree here: this test would pass without the photograph.' . $this->diagnosticDuGel($cible, $proprietaire, $ouverture));
    }

    /**
     * Le second ordre : le monde **termine** la recherche admissible pendant le ralliement.
     *
     * Le joueur charge une page, sa file est traitee, son niveau monte. La fermeture trouve alors
     * une completion deja appliquee : elle doit donner le meme niveau que si la completion attendait
     * encore, ni plus — un niveau atteint n'est pas atteint deux fois — ni moins.
     */
    public function testAResearchTheWorldFinishedDuringTheRallyGivesTheSameLevelAsOneStillPending(): void
    {
        [$combat, $cible, $ouverture, $proprietaire] = $this->anOpenRallyAgainstAResearcher();
        $admissible = $this->aResearchQueue($cible, 'weapon_technology', self::NIVEAU_ADMISSIBLE, $ouverture - 100, $ouverture + 5);

        // Le monde passe : la recherche est appliquee au joueur, la file marquee traitee.
        resolve(PlayerServiceFactory::class)->make($proprietaire, true)->setResearchLevel('weapon_technology', self::NIVEAU_ADMISSIBLE);
        DB::table('research_queues')->where('id', $admissible)->update(['processed' => 1]);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->photographierLesFiles($proprietaire, 'avant fermeture');
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $this->assertSame(self::NIVEAU_ADMISSIBLE + $this->classBonusOf($proprietaire), $this->frozenLevel($combat, 'weapon'), 'A research the world finished during the rally did not give the level a pending one gives.' . $this->diagnosticDuGel($cible, $proprietaire, $ouverture));
    }

    /**
     * Une recherche achevee **avant l'ouverture** est deja dans l'etat d'ouverture : sa completion,
     * encore lisible dans la file, ne releve pas le niveau une seconde fois. Sans recu, c'est le
     * plafonnement — un niveau atteint, jamais additionne — qui tient cette garantie.
     */
    public function testAResearchFinishedBeforeTheOpeningIsNotCountedTwice(): void
    {
        [$combat, $cible, $ouverture, $proprietaire] = $this->anOpenRallyAgainstAResearcher();

        // Achevee et appliquee avant l'ouverture : le joueur est deja au niveau, la file est traitee,
        // et l'etat d'ouverture est capture sur ce joueur-la.
        $anterieure = $this->aResearchQueue($cible, 'weapon_technology', self::NIVEAU_ADMISSIBLE, $ouverture - 200, $ouverture - 10);
        DB::table('research_queues')->where('id', $anterieure)->update(['processed' => 1]);
        resolve(PlayerServiceFactory::class)->make($proprietaire, true)->setResearchLevel('weapon_technology', self::NIVEAU_ADMISSIBLE);
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);
        $this->assertSame(self::NIVEAU_ADMISSIBLE, OpeningStateRecorder::openingDefenderOf($combat)->weaponLevel, 'The opening did not capture the level already reached.');

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->photographierLesFiles($proprietaire, 'avant fermeture');
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $this->assertSame(self::NIVEAU_ADMISSIBLE + $this->classBonusOf($proprietaire), $this->frozenLevel($combat, 'weapon'), 'A research finished before the opening raised the level a second time, or was lost.' . $this->diagnosticDuGel($cible, $proprietaire, $ouverture));
    }

    /**
     * @return array{0: CombatInstance, 1: int, 2: int, 3: int}
     */
    private function anOpenRallyAgainstAResearcher(): array
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $proprietaire = (int)DB::table('planets')->where('id', $cible)->value('user_id');

        /*
         * **Le montage etablit sa condition : aucune file de recherche sur AUCUN corps du proprietaire.**
         *
         * La cible est la planete propre partagee du processus, et chaque essai de cette classe y
         * insere des files par `aResearchQueue()`. Une file laissee par l'essai precedent reste
         * « engagee avant l'ouverture, achevee avant la fermeture » pour le suivant — dont
         * l'horloge a avance — et monte le niveau gele a 4 la ou l'ouverture vaut 3.
         *
         * **La portee du nettoyage doit etre celle de la lecture qu'il neutralise.** Vider la seule
         * cible ne suffisait pas : `CausalEventReader::bodiesOf()` consulte **tous** les corps du
         * proprietaire, et ce proprietaire en possede au moins deux par construction —
         * `getNearbyForeignCleanPlanet()` cree une planete supplementaire au proprietaire de la
         * planete etrangere partagee. Une file laissee sur cet autre corps echappait au nettoyage et
         * entrait dans la photographie.
         *
         * Mecanisme reproduit, non deduit : file parasite sur l'autre corps → niveau gele 4 ;
         * meme montage avec ce nettoyage elargi → 3. On corrige le banc, jamais la lecture : le jeu
         * a raison de consulter tous les corps d'un joueur, une recherche n'appartenant pas a une
         * planete.
         */
        DB::table('research_queues')
            ->whereIn('planet_id', DB::table('planets')->where('user_id', $proprietaire)->pluck('id')->all())
            ->delete();
        $joueur = resolve(PlayerServiceFactory::class)->make($proprietaire, true);
        foreach (['weapon_technology', 'shielding_technology'] as $technologie) {
            $joueur->setResearchLevel($technologie, self::NIVEAU_OUVERTURE);
        }

        // L'etat d'ouverture doit refleter ces niveaux : il est capture a l'ouverture, donc on le
        // recapture ici, comme si le combat venait de s'ouvrir sur ce joueur.
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);
        $this->photographierLesFiles($proprietaire, 'a l ouverture');

        return [$combat, $cible, $ouverture, $proprietaire];
    }

    /**
     * Les files de recherche de ce proprietaire, sur tous ses corps, en une ligne.
     */
    private function filesDe(int $proprietaire): string
    {
        $corps = DB::table('planets')->where('user_id', $proprietaire)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $identifiant): int => (int)$identifiant)->all();

        $lignes = [];

        foreach (DB::table('research_queues')->whereIn('planet_id', $corps)->orderBy('id')->get() as $file) {
            $lignes[] = sprintf(
                'file#%d corps=%d objet=%d niveau=%d du %d au %d traite=%d annule=%d',
                (int)$file->id,
                (int)$file->planet_id,
                (int)$file->object_id,
                (int)$file->object_level_target,
                (int)$file->time_start,
                (int)$file->time_end,
                (int)$file->processed,
                (int)$file->canceled
            );
        }

        return $lignes === [] ? 'aucune file' : implode(' ; ', $lignes);
    }

    /**
     * Garde l etat des files sous ce nom, pour le rendre si une assertion tombe.
     */
    private function photographierLesFiles(int $proprietaire, string $instant): void
    {
        $this->photographiesDesFiles[$instant] = $this->filesDe($proprietaire);
    }

    /**
     * Ce qu il faut voir pour comparer un echec reel au mecanisme reproduit.
     *
     * Le niveau gele de cette classe a rougi une fois en integration continue — 4 la ou
     * l ouverture vaut 3 — sans qu on puisse dire si la cause etait celle qu une experience a
     * su reproduire : une file de recherche laissee sur un autre corps du proprietaire. La
     * difference entre les deux ne se devine pas, elle se lit. Un prochain rouge portera donc le
     * proprietaire reellement selectionne, tous ses corps, et chaque file avec ses dates.
     *
     * **Construite a chaque assertion, reussie ou non**, parce qu elle est le troisieme argument
     * de l assertion : PHP l evalue avant que PHPUnit ne compare. Casser une assertion prouve que
     * le message s affiche, jamais qu il est absent quand elle passe. Le cout est de quelques
     * requetes par assertion dans cette seule classe, et il est assume ; ce qui ne l est pas,
     * c est de le decrire autrement qu il n est.
     */
    private function diagnosticDuGel(int $cible, int $proprietaire, int $ouverture): string
    {
        $corps = DB::table('planets')->where('user_id', $proprietaire)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $identifiant): int => (int)$identifiant)->all();

        $photographies = [];

        foreach ($this->photographiesDesFiles as $instant => $etat) {
            $photographies[] = $instant . ' -> ' . $etat;
        }

        $photographies[] = 'a l echec -> ' . $this->filesDe($proprietaire);

        return sprintf(
            ' | proprietaire=%d cible=%d corps=[%s] ouverture=%d bonus=%d | %s',
            $proprietaire,
            $cible,
            implode(',', $corps),
            $ouverture,
            $this->classBonusOf($proprietaire),
            implode(' | ', $photographies)
        );
    }

    private function classBonusOf(int $playerId): int
    {
        return resolve(CharacterClassService::class)
            ->getAdditionalCombatResearchLevels(resolve(PlayerServiceFactory::class)->make($playerId, true)->getUser());
    }

    private function frozenLevel(CombatInstance $combat, string $which): int
    {
        $combat->refresh();
        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        return $which === 'weapon' ? $resultat->defenderWeaponLevel : $resultat->defenderShieldLevel;
    }

    private function aResearchQueue(int $planetId, string $machineName, int $level, int $start, int $end): int
    {
        return (int)DB::table('research_queues')->insertGetId([
            'planet_id' => $planetId,
            'object_id' => ObjectService::getResearchObjectByMachineName($machineName)->id,
            'object_level_target' => $level,
            'time_duration' => max(1, $end - $start),
            'time_start' => $start,
            'time_end' => $end,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'building' => 0,
            'processed' => 0,
        ]);
    }
}
