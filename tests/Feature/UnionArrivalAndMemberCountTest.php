<?php

namespace Tests\Feature;

use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\FleetUnionService;
use Tests\AccountTestCase;

/**
 * Ce qu'une union montre au joueur : combien ils sont, et quand la flotte se posera.
 *
 * ## Les deux defauts fermes ici
 *
 * L'overlay de federation ecrivait **`(1/5)` en toutes lettres**. Une union de quatre joueurs
 * affichait « 1/5 », et une union dont la colonne `max_players` aurait valu autre chose mentait
 * deux fois. Le nombre venait de nulle part : ni des membres, ni de la colonne.
 *
 * La page d'envoi, elle, annoncait l'arrivee que la flotte aurait **seule**. Rejoindre une union
 * change cette heure — la flotte est alignee sur l'union, ou l'union est retardee sur elle — et
 * au-dela d'une fenetre la jointure est **refusee**. Rien de tout cela n'etait dit : le joueur
 * configurait une flotte lente et decouvrait le refus a l'envoi.
 *
 * ## Ce que ces temoins ne prouvent pas
 *
 * Le calcul lui-meme vit dans le bundle JavaScript, et ce poste n'a ni Node ni navigateur. Ce qui
 * est etabli ici, c'est que **la page porte tout ce qu'il faut** : la ligne, les trois phrases, et
 * la fenetre de retard lue sur la constante du serveur plutot que recopiee. Que l'heure affichee
 * soit la bonne se verifie a l'ecran.
 */
class UnionArrivalAndMemberCountTest extends AccountTestCase
{
    /**
     * Le compteur lit les membres, pas une constante.
     *
     * Mutation : rendre `unionPlayerCount` a 1 — l'essai tombe. C'est exactement l'etat d'avant.
     */
    public function testTheOverlayCountsTheMembersItActuallyHas(): void
    {
        $union = $this->uneUnionDeDeuxJoueurs();

        $reponse = $this->get('/overlay/fleet/federation?fleet=' . $union['missionId']);

        $reponse->assertStatus(200);
        $reponse->assertSee('<span id="unionParticipantCount">2</span>', false);
    }

    /**
     * Le plafond vient de la colonne de l'union, pas du nombre 5.
     *
     * **C'est ce temoin qui tue la mutation la plus tentante** : reecrire `5` a la place de
     * `$unionMaxPlayers` passerait le temoin precedent sans encombre.
     */
    public function testTheCeilingComesFromTheUnionsOwnColumn(): void
    {
        $union = $this->uneUnionDeDeuxJoueurs();

        FleetUnion::where('id', $union['unionId'])->update(['max_players' => 3]);

        $reponse = $this->get('/overlay/fleet/federation?fleet=' . $union['missionId']);

        $reponse->assertStatus(200);
        $reponse->assertSee('<span id="unionParticipantCount">2</span>/3)', false);
        $reponse->assertDontSee('(1/5)', false);
    }

    /**
     * Un identifiant d'union pris dans l'adresse ne rend plus les membres d'une union etrangere.
     *
     * L'ancien code faisait `FleetUnion::find($request->input('union'))` **sans aucun controle** :
     * n'importe quel nombre rendait la liste des membres. L'union se lit desormais sur la mission,
     * deja verifiee comme appartenant au joueur.
     */
    public function testAUnionIdentifierFromTheUrlRevealsNobody(): void
    {
        $etrangere = $this->uneUnionEtrangere();
        $maMission = $this->uneMissionDAttaquePour($this->currentUserId);

        $reponse = $this->get('/overlay/fleet/federation?fleet=' . $maMission->id . '&union=' . $etrangere['unionId']);

        $reponse->assertStatus(200);
        $reponse->assertDontSee($etrangere['memberName'], false);
        $reponse->assertSee('<span id="unionParticipantCount">1</span>', false);
    }

    /**
     * La page publie la fenetre de retard **du serveur**, jamais une copie.
     *
     * Recopiee dans le JavaScript, la valeur aurait diverge en silence : le joueur aurait lu une
     * limite et le serveur en aurait applique une autre. L'attendu est construit depuis la
     * constante, donc la changer ne demande pas de toucher cet essai — la retirer de la vue, si.
     */
    public function testThePageAnnouncesTheServersOwnDelayWindow(): void
    {
        $reponse = $this->get('/fleet');

        $reponse->assertStatus(200);
        $reponse->assertSee(
            'var unionMaxDelayRatio = ' . FleetUnionService::MAX_DELAY_PERCENTAGE . ';',
            false
        );
    }

    /**
     * La ligne d'arrivee synchronisee existe, et ses trois phrases sont traduites.
     *
     * `#durationAKS` etait ecrit par le JavaScript et ne correspondait a **aucun element** : la
     * valeur n'atteignait personne. Les cles absentes se verraient ici, `__('t_ingame...')` rendant
     * alors la cle elle-meme.
     */
    public function testTheSynchronisedArrivalLineIsOnThePageWithItsThreeSentences(): void
    {
        $reponse = $this->get('/fleet');

        $reponse->assertStatus(200);
        $reponse->assertSee('id="unionSyncLine"', false);
        $reponse->assertSee('id="durationAKS"', false);

        foreach (['WAITING', 'DELAYING', 'TOO_LATE'] as $genre) {
            $reponse->assertSee('LOCA_FLEET_UNION_SYNC_' . $genre, false);
        }

        foreach (['waiting', 'delaying', 'too_late'] as $cle) {
            $reponse->assertDontSee('t_ingame.fleet.union_sync_note_' . $cle, false);
        }
    }

    /**
     * Le bundle calcule l'arrivee synchronisee au lieu d'ecrire dans le vide.
     *
     * Lecture de source, faute de navigateur ici : le defaut etait **textuel**, une ecriture vers
     * un identifiant inexistant et une heure d'arrivee qui ignorait l'union.
     */
    public function testTheBundleSourceComputesTheSynchronisedArrival(): void
    {
        $source = (string)file_get_contents(base_path('resources/js/ingame/e7c74974620fa35b197315ebdbb8c2.js'));

        $this->assertStringNotContainsString(
            'formatTime(durationAKS)',
            $source,
            'The old write is still there: a remaining duration, sent to an element that does not exist.'
        );

        $this->assertStringContainsString(
            'LOCA_FLEET_UNION_SYNC_TOO_LATE',
            $source,
            'Nothing warns the player that the fleet is too slow to be accepted into the union.'
        );

        $this->assertStringContainsString(
            'this.unionMaxDelayRatio = cfg.unionMaxDelayRatio;',
            $source,
            'The delay window is not read from the page: it would have to be hardcoded here.'
        );
    }

    /**
     * Les deux refus annoncent la limite de l'union, plus un nombre grave dans la phrase.
     */
    public function testTheRefusalsQuoteTheUnionsOwnLimits(): void
    {
        $flottes = __('t_ingame.fleet.err_union_max_fleets', ['max' => 7]);
        $joueurs = __('t_ingame.fleet.err_union_max_players', ['max' => 3]);

        $this->assertStringContainsString('7', $flottes);
        $this->assertStringNotContainsString('16', $flottes);

        $this->assertStringContainsString('3', $joueurs);
        $this->assertStringNotContainsString('5', $joueurs);
    }

    /**
     * Une union de deux joueurs, dont celui qui est connecte, et la mission par laquelle il l'ouvre.
     *
     * La jointure passe par le service dans le jeu ; ici les deux inscriptions sont ecrites
     * directement, parce que ce qui est eprouve est **l'affichage** d'un etat que le jeu produit,
     * pas le chemin qui y mene.
     *
     * @return array{unionId: int, missionId: int}
     */
    private function uneUnionDeDeuxJoueurs(): array
    {
        $mienne = $this->uneMissionDAttaquePour($this->currentUserId);
        $union = $this->uneUnionAncreeSur($mienne);

        $voisin = User::factory()->create();
        $sienne = $this->uneMissionDAttaquePour((int)$voisin->id);
        $sienne->union_id = $union->id;
        $sienne->union_slot = 2;
        $sienne->mission_type = 2;
        $sienne->save();

        return ['unionId' => (int)$union->id, 'missionId' => (int)$mienne->id];
    }

    /**
     * Une union qui n'appartient pas au joueur connecte, et le nom d'un de ses membres.
     *
     * @return array{unionId: int, memberName: string}
     */
    private function uneUnionEtrangere(): array
    {
        // **La base persiste entre les essais d'une meme classe** : un pseudo fixe entre en
        // collision au deuxieme appel. Celui-ci reste reconnaissable et reste unique.
        $pseudo = 'TemoinDeFuite' . uniqid();
        $proprietaire = User::factory()->create(['username' => $pseudo]);
        $mission = $this->uneMissionDAttaquePour((int)$proprietaire->id);
        $union = $this->uneUnionAncreeSur($mission);

        return ['unionId' => (int)$union->id, 'memberName' => $pseudo];
    }

    /**
     * Une union posee sur la cible d'une mission, avec l'heure d'arrivee de celle-ci.
     */
    private function uneUnionAncreeSur(FleetMission $mission): FleetUnion
    {
        /** @var FleetUnion $union */
        $union = FleetUnion::create([
            'user_id' => $mission->user_id,
            'name' => 'UnionTemoin',
            'galaxy_to' => $mission->galaxy_to,
            'system_to' => $mission->system_to,
            'position_to' => $mission->position_to,
            'planet_type_to' => $mission->type_to,
            'time_arrival' => $mission->time_arrival,
            'max_fleets' => FleetUnion::DEFAULT_MAX_FLEETS,
            'max_players' => FleetUnion::DEFAULT_MAX_PLAYERS,
        ]);

        $mission->union_id = $union->id;
        $mission->union_slot = 1;
        $mission->mission_type = 2;
        $mission->save();

        return $union;
    }

    /**
     * Une attaque en vol, partant d'un corps du joueur donne.
     */
    private function uneMissionDAttaquePour(int $joueurId): FleetMission
    {
        $depart = Planet::where('user_id', $joueurId)->first();
        $departId = $depart !== null
            ? (int)$depart->id
            // **Jamais `Planet::factory()` avec des coordonnees ecrites a la main** : l'allocateur
            // d'inscription occupe les positions 4 a 12, et un montage fixe finit par entrer en
            // collision avec un corps qu'il a attribue. Le helper cherche une case libre.
            : $this->createPlanetAtSafeCoordinate($joueurId)->getPlanetId();

        /** @var FleetMission $mission */
        $mission = FleetMission::forceCreate([
            'user_id' => $joueurId,
            'planet_id_from' => $departId,
            'planet_id_to' => null,
            'mission_type' => 1,
            'time_departure' => time(),
            'time_arrival' => time() + 3600,
            'galaxy_to' => 4,
            'system_to' => 42,
            'position_to' => 7,
            'type_to' => 1,
            'light_fighter' => 10,
        ]);

        return $mission;
    }
}
