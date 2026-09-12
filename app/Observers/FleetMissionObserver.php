<?php

namespace OGame\Observers;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Events\FleetMovementChanged;
use OGame\Models\FleetMission;
use OGame\Models\Planet;

/**
 * L'annonce d'un mouvement nait de l'ecriture, pas de l'appelant.
 *
 * ## Pourquoi un observateur, et non une annonce par chemin
 *
 * Une mission s'ecrit a plus d'endroits qu'on ne les enumere de tete : l'envoi, l'arrivee,
 * l'annulation, le retour cree par `startReturn()`, le retour d'une flotte refusee, l'expiration,
 * le rappel d'une flotte engagee. Chacun finit par un `save()` sur le modele. Poser l'annonce dans
 * l'observateur du modele ferme la classe : tout ce qui ecrit une mission l'annonce, y compris ce
 * qui sera ecrit demain. `MessageObserver` a fait le meme choix pour le meme motif.
 *
 * ## A qui, et apres quoi
 *
 * Aux deux joueurs que la boite d'evenements sert deja : l'expediteur, et le proprietaire du corps
 * vise. Personne d'autre — un tiers n'a pas de canal ou entendre ce mouvement.
 *
 * Apres la validation, jamais dedans : une mission nait dans une transaction, et une transaction
 * peut etre annulee. `afterCommit` rend l'annonce a la validation la plus exterieure, et ne fait
 * rien si elle echoue.
 *
 * ## Ce qu'une mise a jour annonce, et ce qu'elle tait
 *
 * Seules les colonnes qui changent ce que la carte montre declenchent une annonce : arrivee,
 * depart, traitement, annulation, corps vise. Un compteur interne qui bouge (`processed_hold`,
 * un lien de combat) ne dit rien au navigateur — annoncer pour rien lui ferait redemander pour rien.
 */
class FleetMissionObserver
{
    /**
     * Les colonnes dont le changement se voit sur la carte.
     *
     * @var array<int, string>
     */
    private const array COLONNES_VISIBLES = [
        'processed',
        'canceled',
        'time_departure',
        'time_arrival',
        'planet_id_to',
        'galaxy_to',
        'system_to',
        'position_to',
        'mission_type',
    ];

    public function created(FleetMission $mission): void
    {
        $this->announce($mission);
    }

    public function updated(FleetMission $mission): void
    {
        if (!$mission->wasChanged(self::COLONNES_VISIBLES)) {
            return;
        }

        $this->announce($mission);
    }

    private function announce(FleetMission $mission): void
    {
        $destinataires = [(int)$mission->user_id];

        if ($mission->planet_id_to !== null) {
            $cible = (int)Planet::where('id', $mission->planet_id_to)->value('user_id');

            if ($cible !== 0 && !in_array($cible, $destinataires, true)) {
                $destinataires[] = $cible;
            }
        }

        $charge = [
            'id' => (int)$mission->id,
            'galaxy_from' => (int)$mission->galaxy_from,
            'system_from' => (int)$mission->system_from,
            'galaxy_to' => (int)$mission->galaxy_to,
            'system_to' => (int)$mission->system_to,
        ];

        /*
         * **Ceux qui observent la patrouille apprennent son ordre a l instant — et rien d autre.**
         * Un joueur dont le reseau tient un contact visible sur cette patrouille verrait sinon sa
         * carte ignorer le depart jusqu a la veille suivante. Decision de Keven, 12 septembre 2026 :
         * tout ce qui se passe sur la carte en temps reel.
         *
         * Mais l annonce du proprietaire porte la destination reelle et l identifiant de la mission :
         * livree telle quelle a l observateur, elle lui aurait dit ou part la patrouille — ce que la
         * projection lui refuse (`outside`) — et nomme une mission etrangere. Relecture du lot,
         * constat bloquant. L observateur recoit donc une annonce **reduite** : le seul systeme ou
         * il tient le contact, des deux cotes, et aucun identifiant. Sa carte y redemande ce qu il
         * a le droit de voir ; c est tout ce que l annonce doit permettre.
         *
         * Le systeme est celui de la planete observatrice, qui doit exister encore et lui
         * appartenir — le meme predicat que la lecture du palier. Un observateur dont le corps a
         * ete detruit ou cede n est plus prevenu.
         */
        $observateurs = [];

        if ($mission->patrol_id !== null) {
            $observateurs = DB::table('surveillance_contacts')
                ->join('planets', 'planets.id', '=', 'surveillance_contacts.observer_planet_id')
                ->whereColumn('planets.user_id', 'surveillance_contacts.observer_user_id')
                ->where(static function ($requete): void {
                    $requete->whereNull('planets.destroyed')->orWhere('planets.destroyed', 0);
                })
                ->where('surveillance_contacts.patrol_id', (int)$mission->patrol_id)
                ->whereNull('surveillance_contacts.revoked_at')
                ->where('surveillance_contacts.visible_from', '<=', Date::now()->timestamp)
                ->distinct()
                ->get(['surveillance_contacts.observer_user_id', 'planets.galaxy', 'planets.system'])
                ->map(static fn (object $ligne): array => [(int)$ligne->observer_user_id, (int)$ligne->galaxy, (int)$ligne->system])
                ->filter(static fn (array $o): bool => $o[0] !== 0 && !in_array($o[0], $destinataires, true))
                ->values()
                ->all();
        }

        DB::afterCommit(static function () use ($destinataires, $observateurs, $charge): void {
            foreach ($destinataires as $joueur) {
                if ($joueur === 0) {
                    continue;
                }

                broadcast(new FleetMovementChanged(
                    $joueur,
                    $charge['id'],
                    $charge['galaxy_from'],
                    $charge['system_from'],
                    $charge['galaxy_to'],
                    $charge['system_to'],
                ));
            }

            foreach ($observateurs as [$observateur, $galaxie, $systeme]) {
                broadcast(new FleetMovementChanged($observateur, 0, $galaxie, $systeme, $galaxie, $systeme));
            }
        });
    }
}
