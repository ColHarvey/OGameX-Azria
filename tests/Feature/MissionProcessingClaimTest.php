<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\MissionUpdateOutcome;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;

/**
 * Une mission ne se traite qu'une fois, meme si deux appelants arrivent ensemble.
 *
 * ## Le defaut que ces temoins ferment
 *
 * `updateMission()` se gardait par un `if ($mission->processed) return;`. C'est un « je lis, puis
 * j'agis » : entre cette lecture et l'ecriture que fera le gestionnaire, un second appelant peut
 * lire le meme zero. Le planificateur et le chargement de page d'un joueur tournent en parallele —
 * les deux passaient, et la meme arrivee etait livree deux fois.
 *
 * **Le defaut s'est vu en production** : un joueur a recu deux fois le message « Retour d'une
 * flotte », avec ses vaisseaux et sa cargaison credites deux fois. Depuis que le reglement credite
 * l'honneur, les points doublaient avec eux.
 *
 * ## Ce que ces essais prouvent, et ce qu'ils ne prouvent pas
 *
 * Ils etablissent le **contrat** du jeton : une mission reservee n'est pas traitee, une reservation
 * perimee se reprend, et le jeton ne survit pas au passage. C'est ce qu'on peut observer sans
 * concurrence reelle.
 *
 * Ils ne prouvent pas la **course**. Deux processus qui reservent a la meme milliseconde, cela se
 * mesure sur MariaDB : `lockForUpdate()` ne compile a rien sous SQLite, et le nombre de lignes
 * qu'un `UPDATE` rend depend du moteur. C'est le role de
 * `tests/MariaDb/MissionClaimRaceTest.php`, que la CI seule execute.
 */
class MissionProcessingClaimTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->planetAddResources(new Resources(0, 0, 100000, 0));
        $this->planetAddUnit('small_cargo', 5);
    }

    /**
     * Sans reservation en cours, la mission se traite normalement.
     *
     * Ce temoin porte l'autre moitie de la preuve : sans lui, un jeton qui bloquerait *tout*
     * passerait les essais suivants sans que rien ne le dise.
     */
    public function testAnUnclaimedMissionIsProcessed(): void
    {
        $mission = $this->anArrivedTransport();

        $issue = resolve(FleetMissionService::class)->updateMission($mission);

        $this->assertSame(1, (int)FleetMission::query()->whereKey($mission->id)->value('processed'), 'An unclaimed mission was not processed at all.');
        $this->assertSame(MissionUpdateOutcome::Applied, $issue, 'The door did not say it had applied the mission.');
    }

    /**
     * Une mission qu'un autre passage tient n'est pas traitee.
     */
    public function testAMissionClaimedByAnotherPassIsLeftAlone(): void
    {
        $mission = $this->anArrivedTransport();

        // Le jeton d'un autre processus, pose il y a un instant.
        FleetMission::query()->whereKey($mission->id)->update(['processing_claimed_at' => Date::now()]);

        $issue = resolve(FleetMissionService::class)->updateMission($mission);

        $this->assertSame(
            0,
            (int)FleetMission::query()->whereKey($mission->id)->value('processed'),
            'A mission held by another pass was processed anyway: the arrival would be delivered twice.'
        );

        /*
         * **L issue nomme le cas, et c est neuf.** La porte ne rendait rien : une fermeture de
         * ralliement qui avait besoin de cet effet en concluait que la barriere n avait pas ete vue,
         * et accusait le mauvais coupable. Un rouge du bac MariaDB l a montre le 9 septembre 2026.
         */
        $this->assertSame(MissionUpdateOutcome::ClaimedElsewhere, $issue, 'The door did not say the mission was held by another worker.');
    }

    /**
     * **Deja traitee n est pas tenue ailleurs**, et la reservation echoue pareillement pour les deux.
     *
     * `claimForProcessing()` exige `processed = 0` : sans cette distinction, une fermeture verrait
     * « tenue ailleurs » la ou l effet est en realite fait, et se retirerait pour rien.
     */
    public function testAnAlreadyProcessedMissionIsToldApartFromAHeldOne(): void
    {
        $mission = $this->anArrivedTransport();

        $this->assertSame(MissionUpdateOutcome::Applied, resolve(FleetMissionService::class)->updateMission($mission));

        $this->assertSame(
            MissionUpdateOutcome::AlreadyProcessed,
            resolve(FleetMissionService::class)->updateMission($mission->refresh()),
            'A mission that is simply done was reported as held by someone else.'
        );
    }

    /**
     * L echeance pas atteinte se dit elle aussi, plutot que de se taire.
     */
    public function testAMissionThatIsNotDueYetSaysSo(): void
    {
        $mission = $this->anArrivedTransport();

        FleetMission::query()->whereKey($mission->id)->update(['time_arrival' => (int)Date::now()->timestamp + 3600]);

        $this->assertSame(
            MissionUpdateOutcome::NotDueYet,
            resolve(FleetMissionService::class)->updateMission($mission->refresh()),
            'A mission that has not arrived yet was reported as something else.'
        );
    }

    /**
     * Une mission effacee entre-temps ne se confond avec rien.
     */
    public function testAVanishedMissionSaysSo(): void
    {
        $mission = $this->anArrivedTransport();
        $copie = $mission->replicate();
        $copie->id = $mission->id;

        FleetMission::query()->whereKey($mission->id)->delete();

        $this->assertSame(
            MissionUpdateOutcome::Vanished,
            resolve(FleetMissionService::class)->updateMission($copie),
            'A mission that no longer exists was reported as something else.'
        );
    }

    /**
     * Une reservation abandonnee se reprend.
     *
     * Un processus tue ne rend pas son jeton. Sans echeance, la flotte resterait en l'air pour
     * toujours — un blocage pire que le doublon qu'on ferme.
     */
    public function testAnAbandonedClaimIsTakenOverAfterItsDeadline(): void
    {
        $mission = $this->anArrivedTransport();

        // Six minutes : au-dela du delai de reprise de cinq minutes.
        FleetMission::query()->whereKey($mission->id)->update(['processing_claimed_at' => Date::now()->subMinutes(6)]);

        resolve(FleetMissionService::class)->updateMission($mission);

        $this->assertSame(
            1,
            (int)FleetMission::query()->whereKey($mission->id)->value('processed'),
            'An abandoned claim was never taken over: the fleet would stay in flight forever.'
        );
    }

    /**
     * Le jeton ne survit pas au passage.
     *
     * Il ne doit rester ni apres un succes — `processed` protege desormais —, ni apres un passage
     * qui n'a rien traite : sinon la mission attendrait l'echeance pour rien.
     */
    public function testTheClaimIsHandedBackAfterThePass(): void
    {
        $mission = $this->anArrivedTransport();

        resolve(FleetMissionService::class)->updateMission($mission);

        $this->assertNull(
            FleetMission::query()->whereKey($mission->id)->value('processing_claimed_at'),
            'The claim outlived the pass that took it.'
        );
    }

    /**
     * Un transport vers un corps etranger, deja arrive.
     *
     * Le transport est le genre le plus simple qui livre quelque chose : il ne passe par aucune
     * porte de combat et n'a besoin d'aucun adversaire vivant.
     */
    private function anArrivedTransport(): FleetMission
    {
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 1);

        $mission = resolve(FleetMissionService::class)->createNewFromPlanet(
            $this->planetService,
            $this->getNearbyForeignPlanet()->getPlanetCoordinates(),
            PlanetType::Planet,
            3,
            $units,
            new Resources(100, 0, 0, 0),
            10
        );

        // L'essai amene l'heure a l'arrivee au lieu de la supposer passee : une mission qui n'est
        // pas encore arrivee sort de `updateMission()` bien avant la reservation, et les quatre
        // temoins passeraient sans rien mesurer.
        $this->travelTo(Date::createFromTimestamp($mission->time_arrival + 1));

        return $mission;
    }
}
