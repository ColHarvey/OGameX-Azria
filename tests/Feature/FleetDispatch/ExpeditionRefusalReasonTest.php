<?php

namespace Tests\Feature\FleetDispatch;

use Illuminate\Support\Facades\DB;
use OGame\Enums\CharacterClass;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\FleetDispatchTestCase;

/**
 * La raison d'un refus d'expedition atteint le joueur.
 *
 * ## Le cas de support qui a revele le defaut
 *
 * Un joueur Explorateur — +2 expeditions — ne pouvait pas en lancer et ne comprenait pas pourquoi.
 * `ExpeditionMission::isMissionPossible()` refuse toute expedition sans Astrophysique >= 1, classe ou
 * non, et le dit. Mais `checkTarget()` ne gardait de chaque mission que son booleen : la raison
 * etait calculee puis jetee. Le joueur voyait « Expeditions : 0/2 » et un bouton gris muet.
 *
 * Ces temoins etablissent les trois faits : la raison voyage quand l'Astrophysique manque, la
 * classe Explorateur ne la fait pas disparaitre, et elle disparait des que la recherche est la.
 */
class ExpeditionRefusalReasonTest extends FleetDispatchTestCase
{
    protected int $missionType = 15;

    protected string $missionName = 'Expedition';

    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 5);
        $this->planetAddResources(new Resources(0, 0, 100000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * La reponse de check-target pour l'espace profond du systeme courant.
     *
     * @return array<string, mixed>
     */
    private function verdictPourLEspaceProfond(): array
    {
        $depart = $this->planetService->getPlanetCoordinates();
        $cible = new Coordinate($depart->galaxy, $depart->system, 16);

        /* Le formulaire nomme chaque unite `am<identifiant>`, comme le banc le fait en prive. */
        $champUnite = 'am' . ObjectService::getUnitObjectByMachineName('small_cargo')->id;

        return $this->post('/ajax/fleet/dispatch/check-target', [
            'galaxy' => $cible->galaxy,
            'system' => $cible->system,
            'position' => $cible->position,
            'type' => PlanetType::Planet->value,
            'mission' => 15,
            '_token' => csrf_token(),
            $champUnite => 1,
        ])->assertStatus(200)->json();
    }

    /**
     * @param array<string, mixed> $verdict
     */
    private function raisonAstrophysique(array $verdict): bool
    {
        foreach ($verdict['errors'] ?? [] as $erreur) {
            if (str_contains((string)($erreur['message'] ?? ''), __('Fleets cannot be sent to this target. You have to research Astrophysics first.'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sans Astrophysique, l'expedition est refusee **et la raison est dite**.
     */
    public function testWithoutAstrophysicsTheReasonReachesThePlayer(): void
    {
        $this->basicSetup();
        $this->playerSetResearchLevel('astrophysics', 0);

        $verdict = $this->verdictPourLEspaceProfond();

        $this->assertFalse($verdict['orders'][15] ?? true, 'An expedition is offered without Astrophysics.');
        $this->assertTrue($this->raisonAstrophysique($verdict), 'The expedition is refused but the reason is thrown away: the player sees a grey button and nothing else.');
    }

    /**
     * **La classe Explorateur ne remplace pas l'Astrophysique.** C'est exactement ce que le joueur
     * croyait : +2 emplacements, donc le droit. Le compteur monte, la porte reste fermee, et la
     * raison doit le dire.
     */
    public function testTheDiscovererClassDoesNotUnlockExpeditionsWithoutAstrophysics(): void
    {
        $this->basicSetup();
        $this->playerSetResearchLevel('astrophysics', 0);
        DB::table('users')->where('id', $this->currentUserId)->update(['character_class' => CharacterClass::DISCOVERER->value]);

        $verdict = $this->verdictPourLEspaceProfond();

        $this->assertFalse($verdict['orders'][15] ?? true, 'The Discoverer class unlocks expeditions without Astrophysics: that is a game rule change nobody decided.');
        $this->assertTrue($this->raisonAstrophysique($verdict), 'A Discoverer without Astrophysics is refused without being told why.');
    }

    /**
     * **Sur une planete, les refus restent muets.** Le correctif ne publie la raison que pour
     * l'expedition sur la position 16 ; une version qui publierait la raison de chaque mission
     * refusee inonderait le joueur — en mode vacances, dix fois le meme message. La mutation
     * « publier pour toute mission » survivait aux autres temoins : celui-ci la voit.
     */
    public function testOnAPlanetRefusedMissionsStaySilent(): void
    {
        $this->basicSetup();
        $cible = $this->getNearbyForeignPlanet()->getPlanetCoordinates();
        DB::table('users')->where('id', $this->currentUserId)->update(['vacation_mode' => 1]);

        $champUnite = 'am' . ObjectService::getUnitObjectByMachineName('small_cargo')->id;

        $verdict = $this->post('/ajax/fleet/dispatch/check-target', [
            'galaxy' => $cible->galaxy,
            'system' => $cible->system,
            'position' => $cible->position,
            'type' => PlanetType::Planet->value,
            'mission' => 3,
            '_token' => csrf_token(),
            $champUnite => 1,
        ])->assertStatus(200)->json();

        DB::table('users')->where('id', $this->currentUserId)->update(['vacation_mode' => 0]);

        $this->assertNotContains(true, array_values($verdict['orders'] ?? [true]), 'A mission is offered in vacation mode.');
        $this->assertSame([], $verdict['errors'] ?? ['?'], 'Refused missions on a planet now shout their reasons: the player would read the same vacation message once per mission type.');
    }

    /**
     * Avec Astrophysique 1, l'expedition est offerte et aucune raison de refus ne traine.
     */
    public function testWithAstrophysicsTheExpeditionIsOfferedAndNoReasonLingers(): void
    {
        $this->basicSetup();
        $this->playerSetResearchLevel('astrophysics', 1);

        $verdict = $this->verdictPourLEspaceProfond();

        $this->assertTrue($verdict['orders'][15] ?? false, 'An expedition is refused with Astrophysics 1.');
        $this->assertFalse($this->raisonAstrophysique($verdict), 'The Astrophysics reason is shown although the research is there.');
    }
}
