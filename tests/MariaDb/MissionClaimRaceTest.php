<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use PHPUnit\Framework\Attributes\Group;
use Tests\FleetDispatchTestCase;

/**
 * Deux travailleurs traitent la meme arrivee : une seule livraison.
 *
 * ## La course, et pourquoi elle appartient au bac
 *
 * `Tests\Feature\MissionProcessingClaimTest` etablit le **contrat** du jeton — une mission reservee
 * n'est pas traitee, une reservation perimee se reprend, le jeton ne survit pas au passage. Il ne
 * peut rien dire de la course elle-meme : sous SQLite il n'y a ni seconde connexion, ni verrou de
 * ligne, et le juste comme le faux y coincident.
 *
 * Ici deux processus reels appellent `updateMission()` sur la meme mission a la meme seconde. C'est
 * la situation exacte qui s'est produite en production : le planificateur et le chargement de page
 * d'un joueur, et un message « Retour d'une flotte » recu deux fois — avec les vaisseaux et la
 * cargaison credites deux fois.
 *
 * ## Ce que la reservation exige du moteur
 *
 * Une seule ecriture conditionnelle pose le jeton et verifie qu'il etait libre. Le compte de lignes
 * qu'elle rend est la decision, et **ce compte depend du moteur** : MariaDB rend les lignes
 * changees, SQLite les lignes trouvees. Ici les deux coincident — un horodatage remplace toujours un
 * nul — mais la regle du depot est nette : toute decision prise sur un nombre de lignes ecrites se
 * prouve sur MariaDB.
 */
#[Group('mariadb')]
final class MissionClaimRaceTest extends FleetDispatchTestCase
{
    use RunsInParallelProcesses;

    protected int $missionType = 3;

    protected string $missionName = 'Transport';

    private const int CARGO_METAL = 500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 5);
        $this->planetAddResources(new Resources(self::CARGO_METAL, 0, 100000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * Une arrivee prise par deux travailleurs ne livre qu'une fois.
     */
    public function testTwoWorkersProcessingTheSameArrivalDeliverItOnce(): void
    {
        // **`basicSetup()` n'est appelee par personne** : le socle la declare abstraite, et chaque
        // essai l'invoque lui-meme — les essais de ralliement le font depuis leur trait. Sans cet
        // appel, la planete n'a ni cargo ni metal, et l'envoi echoue avant toute course.
        $this->basicSetup();

        // L'essai etablit ce qu'il exige au lieu de le supposer : sans fret ni cargaison, la course
        // ne pourrait rien livrer, et son silence ressemblerait a une reussite.
        $this->assertGreaterThan(0, $this->planetService->getObjectAmount('small_cargo'), 'The planet has no cargo ship: nothing could be sent.');
        $this->assertGreaterThanOrEqual(self::CARGO_METAL, (int)$this->planetService->metal()->get(), 'The planet cannot afford the cargo the race is supposed to deliver.');

        $cible = $this->getNearbyForeignPlanet();
        $cibleId = $cible->getPlanetId();

        $vaisseaux = new UnitCollection();
        $vaisseaux->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 1);

        $mission = resolve(FleetMissionService::class)->createNewFromPlanet(
            $this->planetService,
            $cible->getPlanetCoordinates(),
            PlanetType::Planet,
            $this->missionType,
            $vaisseaux,
            new Resources(self::CARGO_METAL, 0, 0, 0),
            10
        );

        $avant = (int)DB::table('planets')->where('id', $cibleId)->value('metal');
        $this->travelTo(Date::createFromTimestamp($mission->time_arrival + 1));

        $issues = $this->inParallel(2, static function (int $rang) use ($mission): string {
            resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($mission->id));

            return 'passe';
        });

        // **Aucun des deux ne doit echouer.** Le perdant de la reservation repart sans rien faire ;
        // s'il levait, la course serait fermee par une panne et non par une regle.
        $this->assertSame(['passe', 'passe'], $issues, 'A worker failed instead of finding the mission already claimed.');

        $this->assertSame(
            1,
            (int)DB::table('fleet_missions')->where('id', $mission->id)->value('processed'),
            'The arrival was not processed at all.'
        );

        // **La preuve tient sur le stock, pas sur le nombre de messages.** Un compte de messages
        // dirait qu'un joueur a ete prevenu deux fois ; c'est le metal livre deux fois qui fabrique
        // des ressources a partir de rien.
        $this->assertSame(
            $avant + self::CARGO_METAL,
            (int)DB::table('planets')->where('id', $cibleId)->value('metal'),
            'The cargo was delivered twice, or never: two workers processed the same arrival.'
        );

        $this->assertNull(
            DB::table('fleet_missions')->where('id', $mission->id)->value('processing_claimed_at'),
            'The claim outlived the race: the mission would wait for its deadline before anything could touch it again.'
        );
    }
}
