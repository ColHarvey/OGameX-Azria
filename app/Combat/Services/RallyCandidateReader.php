<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Admission\CandidateMission;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Support\ActorKindResolver;
use OGame\Combat\Support\CombatRallyWindow;
use OGame\Models\FleetMission;
use OGame\Models\User;

/**
 * Les candidates au ralliement, relues sous verrou.
 *
 * ## Le job ne transporte aucun fait
 *
 * Un message de file ne porte qu'un identifiant. C'est ce service qui relit les lignes, au moment de
 * la fermeture et sous le verrou. Laisser le job transporter une alliance, une heure d'arrivee ou un
 * genre de mission reviendrait a faire confiance a une photographie prise par l'emetteur — et un
 * message rejoue appliquerait alors des faits perimes.
 *
 * ## Ce service ne decide rien
 *
 * Il ne fait que **traduire des lignes en faits**. Qui rejoint, qui repart, et pourquoi, sont
 * l'affaire des deux selecteurs. La separation compte : elle laisse la regle d'admission verifiable
 * sans univers, sans joueur et sans horloge, et elle empeche une regle de se dedoubler entre la
 * lecture et l'arbitrage.
 *
 * Il ne filtre donc que sur ce qui **ne peut pas etre juge plus tard** : le corps vise, et la
 * fenetre temporelle. Une candidate rappelee, une candidate lancee trop tard, une candidate d'un
 * joueur etranger sont toutes rendues — avec les faits qui permettront de les refuser **en le
 * disant**. Les ecarter ici les ferait disparaitre sans que personne ne sache pourquoi.
 *
 * ## L'alliance a l'ouverture, et pourquoi ce n'est pas `users.alliance_id`
 *
 * `users.alliance_id` porte l'alliance **d'aujourd'hui**. La regle veut celle de l'ouverture : un
 * joueur qui change d'alliance pendant le ralliement ne doit ni y entrer ni en sortir.
 *
 * Il n'existe pas d'historique des appartenances — une sortie supprime la ligne. Mais
 * `alliance_members.joined_at` suffit pour la question posee :
 *
 *     membre de l'alliance qui gouverne, et inscrit avant l'ouverture
 *
 * Un joueur parti n'a plus de ligne, donc il est ecarte — ce qui est juste, il est parti. Un joueur
 * arrive apres l'ouverture porte un `joined_at` posterieur, donc il est ecarte aussi. La question
 * est repondue exactement, avec des donnees qui existent.
 */
final class RallyCandidateReader
{
    /**
     * Les candidates d'un ralliement, dans un ordre deterministe.
     *
     * @param int $targetBodyId Le corps **exact** attaque. Une planete et sa lune partagent leurs
     *                          coordonnees : viser l'une n'est pas viser l'autre.
     * @param int $openedAt L'instant d'ouverture, fige.
     * @param int|null $governingAllianceId L'alliance qui gouverne le combat, figee a l'ouverture.
     * @param int $openerMissionId La mission qui a ouvert : elle est le groupe fondateur, pas une candidate.
     * @return array<int, CandidateMission>
     */
    public function read(
        int $targetBodyId,
        int $openedAt,
        int|null $governingAllianceId,
        int $openerMissionId,
    ): array {
        $plafond = $openedAt + CombatRallyWindow::WINDOW_SECONDS;

        /** @var array<int, FleetMission> $missions */
        $missions = FleetMission::query()
            ->where('planet_id_to', $targetBodyId)
            ->where('id', '!=', $openerMissionId)
            ->where('time_arrival', '>=', $openedAt)
            ->where('time_arrival', '<', $plafond)
            ->orderBy('time_arrival')
            ->orderBy('id')
            ->get()
            ->all();

        if ($missions === []) {
            return [];
        }

        $proprietaires = array_values(array_unique(array_map(
            static fn (FleetMission $mission): int => $mission->user_id,
            $missions
        )));

        $acteurs = $this->actorKindsOf($proprietaires);
        $allies = $this->membersOfAllianceBefore($governingAllianceId, $proprietaires, $openedAt);

        $candidates = [];

        foreach ($missions as $mission) {
            $candidates[] = new CandidateMission(
                $mission->id,
                $mission->user_id,
                isset($allies[$mission->user_id]) ? $governingAllianceId : null,
                $acteurs[$mission->user_id] ?? ActorKind::Player,
                CombatMissionKind::fromMissionType($mission->mission_type),
                // Une mission de suivi porte l'identifiant de celle qu'elle prolonge : c'est le
                // retour. L'aller n'en a pas.
                $mission->parent_id === null ? FlightLeg::Outbound : FlightLeg::Return,
                $targetBodyId,
                $mission->time_arrival,
                // **Strictement avant l'ouverture.** L'engagement est le lancement, et une egalite
                // avec une barriere compte pour « apres », ici comme partout ailleurs.
                $mission->time_departure < $openedAt,
                $mission->canceled === 1,
            );
        }

        return $candidates;
    }

    /**
     * Le genre d'acteur de chaque proprietaire.
     *
     * Une seule requete : un `ActorKindResolver::of()` par mission ferait seize lectures pour un
     * ralliement plein, sous le verrou.
     *
     * @param array<int, int> $userIds
     * @return array<int, ActorKind>
     */
    private function actorKindsOf(array $userIds): array
    {
        $genres = [];

        foreach (User::query()->whereIn('id', $userIds)->get() as $utilisateur) {
            $genres[$utilisateur->id] = ActorKindResolver::of($utilisateur);
        }

        return $genres;
    }

    /**
     * Les proprietaires membres de l'alliance qui gouverne **avant** l'ouverture.
     *
     * @param int|null $allianceId
     * @param array<int, int> $userIds
     * @param int $openedAt
     * @return array<int, true>
     */
    private function membersOfAllianceBefore(int|null $allianceId, array $userIds, int $openedAt): array
    {
        if ($allianceId === null) {
            // Sans alliance qui gouverne, personne d'autre que le createur ne rejoint : le
            // selecteur le dira, et il n'y a rien a lire.
            return [];
        }

        $membres = DB::table('alliance_members')
            ->where('alliance_id', $allianceId)
            ->whereIn('user_id', $userIds)
            ->where('joined_at', '<=', date('Y-m-d H:i:s', $openedAt))
            ->pluck('user_id');

        $trouves = [];

        foreach ($membres as $userId) {
            $trouves[(int)$userId] = true;
        }

        return $trouves;
    }
}
