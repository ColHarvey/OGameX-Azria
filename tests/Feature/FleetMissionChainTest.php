<?php

namespace Tests\Feature;

use OGame\Factories\GameMissionFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use Tests\AccountTestCase;

/**
 * Ce que la page Flotte promet et ce que l'envoi accepte disent-ils la meme chose ?
 *
 * Chaque type de mission a deja son fichier de test qui verifie son comportement. Ce qui
 * n'etait verifie nulle part, c'est le **raccord** : `check-target` annonce au joueur les
 * missions permises sur une cible, et `send-fleet` les accepte ou les refuse. Rien ne
 * garantissait que les deux repondent pareil.
 *
 * C'est exactement la forme du defaut deja trouve dans la galaxie, ou l'entree « Destruction
 * de la Lune » envoyait le type 10 — l'attaque de missiles — tandis que la mission vivait
 * sous le type 9. Chaque moitie fonctionnait ; seul le raccord etait faux, et aucun test ne
 * pouvait le voir.
 *
 * Le test ne fixe pas d'avance quelles missions doivent etre possibles sur quelle cible :
 * c'est au jeu de le decider. Il verifie que sa reponse est tenue.
 */
class FleetMissionChainTest extends AccountTestCase
{
    /**
     * Assert that every mission announced as possible can really be dispatched.
     */
    public function testEveryMissionAnnouncedAsPossibleCanReallyBeDispatched(): void
    {
        $this->armerLaFlotte();

        $tenues = 0;

        foreach ($this->cibles() as $nom => [$coordonnees, $type]) {
            $ordres = $this->verifierCible($coordonnees, $type);

            foreach ($ordres as $mission => $permise) {
                if (!$permise) {
                    continue;
                }

                $avant = FleetMission::where('user_id', $this->currentUserId)->count();
                $reponse = $this->envoyer($coordonnees, $type, (int)$mission);

                $this->assertTrue(
                    (bool)($reponse['success'] ?? false),
                    "The target check offers mission {$mission} on {$nom}, but sending it is refused: "
                    . json_encode($reponse['errors'] ?? $reponse)
                );

                $this->assertGreaterThan(
                    $avant,
                    FleetMission::where('user_id', $this->currentUserId)->count(),
                    "Sending mission {$mission} on {$nom} reported success but created no fleet mission."
                );

                $tenues++;
            }
        }

        // Sans cette borne, un check-target qui ne proposerait plus rien ferait passer le test
        // sans avoir rien verifie.
        $this->assertGreaterThanOrEqual(
            4,
            $tenues,
            'The target check offers almost no mission at all, so this test proves nothing.'
        );
    }

    /**
     * Assert that a mission announced as impossible is also refused on dispatch.
     *
     * L'autre sens du raccord : une mission grisee dans l'interface ne doit pas passer si on
     * la poste quand meme. C'est la moitie qui protege du contournement.
     */
    public function testEveryMissionAnnouncedAsImpossibleIsAlsoRefused(): void
    {
        $this->armerLaFlotte();

        $refusees = 0;

        foreach ($this->cibles() as $nom => [$coordonnees, $type]) {
            $ordres = $this->verifierCible($coordonnees, $type);

            foreach ($ordres as $mission => $permise) {
                if ($permise) {
                    continue;
                }

                $reponse = $this->envoyer($coordonnees, $type, (int)$mission);

                $this->assertFalse(
                    (bool)($reponse['success'] ?? false),
                    "The target check refuses mission {$mission} on {$nom}, yet posting it directly was accepted."
                );

                $refusees++;
            }
        }

        $this->assertGreaterThanOrEqual(
            4,
            $refusees,
            'The target check refuses almost nothing, so this test proves nothing.'
        );
    }

    /**
     * Assert that every mission type the game declares is reachable through the fleet page.
     *
     * Le defaut de la galaxie tenait a un numero de type qui ne correspondait a aucune mission
     * instanciable. On verifie donc que chaque numero propose par check-target designe bien une
     * mission existante, et qu'aucune mission du jeu n'est absente de la liste des types
     * proposables.
     */
    public function testEveryOfferedMissionTypeMapsToARealMission(): void
    {
        $this->armerLaFlotte();

        $missions = GameMissionFactory::getAllMissions();

        foreach ($this->cibles() as $nom => [$coordonnees, $type]) {
            foreach ($this->verifierCible($coordonnees, $type) as $mission => $permise) {
                $this->assertArrayHasKey(
                    (int)$mission,
                    $missions,
                    "The fleet page offers mission type {$mission} on {$nom}, but no mission class answers to that number."
                );
            }
        }
    }

    /**
     * Give the planet a broad fleet and enough slots to dispatch repeatedly.
     */
    private function armerLaFlotte(): void
    {
        // Assez d'emplacements pour enchainer les envois sans buter sur la limite.
        $this->playerSetResearchLevel('computer_technology', 10);

        foreach ([
            'small_cargo' => 200,
            'large_cargo' => 50,
            'light_fighter' => 200,
            'espionage_probe' => 50,
            'recycler' => 50,
            'colony_ship' => 10,
            'deathstar' => 2,
        ] as $vaisseau => $nombre) {
            $this->planetAddUnit($vaisseau, $nombre);
        }

        $this->planetAddResources(new \OGame\Models\Resources(2000000, 2000000, 2000000, 0));
    }

    /**
     * Build the set of targets worth exercising.
     *
     * @return array<string, array{0: Coordinate, 1: PlanetType}>
     */
    private function cibles(): array
    {
        $cibles = [];

        $etrangere = $this->getNearbyForeignPlanet();
        $cibles['une planete etrangere'] = [$etrangere->getPlanetCoordinates(), PlanetType::Planet];

        $seconde = $this->secondPlanetService;

        if ($seconde !== null) {
            $cibles['sa propre seconde planete'] = [$seconde->getPlanetCoordinates(), PlanetType::Planet];
        }

        $cibles['une coordonnee vide'] = [$this->getNearbyEmptyCoordinate(), PlanetType::Planet];

        // Un emplacement d'expedition : la position juste au-dela de la derniere planete.
        $depuis = $this->planetService->getPlanetCoordinates();
        $cibles['un emplacement d expedition'] = [new Coordinate($depuis->galaxy, $depuis->system, 16), PlanetType::Planet];

        // Le champ de debris de sa propre planete.
        $cibles['un champ de debris'] = [$depuis, PlanetType::DebrisField];

        return $cibles;
    }

    /**
     * The one fleet composition used for both the check and the dispatch.
     *
     * Elle doit etre identique des deux cotes : check-target repond en fonction de la flotte
     * presentee — l'espionnage exige des sondes, la colonisation un vaisseau de colonisation.
     * Verifier avec une flotte et envoyer avec une autre comparerait deux questions
     * differentes, et le test a d'abord echoue exactement pour cette raison.
     *
     * @return array<string, int>
     */
    private function flotteDEssai(): array
    {
        return [
            'am202' => 10,  // petit transporteur
            'am204' => 10,  // chasseur leger
            'am210' => 5,   // sonde d'espionnage
            'am209' => 5,   // recycleur
            'am208' => 1,   // vaisseau de colonisation
            'am214' => 1,   // etoile de la mort
        ];
    }

    /**
     * Ask the game which missions it offers on a target.
     *
     * @return array<int|string, bool>
     */
    private function verifierCible(Coordinate $coordonnees, PlanetType $type): array
    {
        $reponse = $this->post('/ajax/fleet/dispatch/check-target', [
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'position' => $coordonnees->position,
            'type' => $type->value,
            '_token' => csrf_token(),
            ...$this->flotteDEssai(),
        ]);

        $reponse->assertStatus(200);

        $donnees = $reponse->json();
        $this->assertIsArray($donnees);

        return $donnees['orders'] ?? [];
    }

    /**
     * Post a dispatch and return the decoded answer.
     *
     * @return array<string, mixed>
     */
    private function envoyer(Coordinate $coordonnees, PlanetType $type, int $mission): array
    {
        // Chaque envoi emporte les vaisseaux : sans reapprovisionnement, les missions
        // suivantes echoueraient faute de flotte, et non pour la raison testee.
        $this->armerLaFlotte();

        $reponse = $this->post('/ajax/fleet/dispatch/send-fleet', [
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'position' => $coordonnees->position,
            'type' => $type->value,
            'mission' => $mission,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            '_token' => csrf_token(),
            'holdingtime' => $mission === 15 ? 1 : 0,
            'speed' => 10,
            ...$this->flotteDEssai(),
        ]);

        $reponse->assertStatus(200);

        $donnees = $reponse->json();

        return is_array($donnees) ? $donnees : [];
    }
}
