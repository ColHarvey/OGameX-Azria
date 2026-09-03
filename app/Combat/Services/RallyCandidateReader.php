<?php

namespace OGame\Combat\Services;

use OGame\Combat\Admission\CandidateMission;
use OGame\Combat\Admission\FrozenAllianceMembership;
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
 * ## L'alliance ne se reconstruit pas, elle se relit
 *
 * La regle est arretee : *un changement d'alliance apres l'ouverture ne change rien*. Ni
 * `users.alliance_id`, qui porte l'alliance d'aujourd'hui, ni `alliance_members.joined_at` ne
 * peuvent y repondre — **une sortie supprime la ligne**, et aucun filtre sur une date d'entree ne
 * ressuscite un membre parti.
 *
 * Une premiere version filtrait pourtant sur `joined_at <= ouverture`, et son commentaire affirmait
 * qu'ecarter un joueur parti « est juste, il est parti ». C'etait substituer un jugement a une
 * decision deja prise : un allie admissible a l'ouverture devenait inadmissible a la fermeture.
 *
 * Le lecteur ne reconstruit donc plus rien. Il recoit `FrozenAllianceMembership`, la photographie
 * prise a l'ouverture, et s'y tient — comme le reste du systeme se tient aux faits ecrits avec le
 * combat plutot qu'a un monde qui a change depuis.
 */
final class RallyCandidateReader
{
    /**
     * Les candidates d'un ralliement, dans un ordre deterministe.
     *
     * @param int $targetBodyId Le corps **exact** attaque. Une planete et sa lune partagent leurs
     *                          coordonnees : viser l'une n'est pas viser l'autre.
     * @param int $openedAt L'instant d'ouverture, fige.
     * @param FrozenAllianceMembership $membership Les appartenances **photographiees a l'ouverture**.
     *                                             Le lecteur ne les recalcule pas : l'historique
     *                                             n'existe pas, et une sortie effacerait la trace.
     * @param int $openerMissionId La mission qui a ouvert : elle est le groupe fondateur, pas une candidate.
     * @return array<int, CandidateMission>
     */
    public function read(
        int $targetBodyId,
        int $openedAt,
        FrozenAllianceMembership $membership,
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

        $candidates = [];

        foreach ($missions as $mission) {
            $candidates[] = new CandidateMission(
                $mission->id,
                $mission->user_id,
                $membership->allianceFor($mission->user_id),
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
                $mission->union_id,
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
}
