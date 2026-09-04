<?php

namespace OGame\Combat\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;

/**
 * Le retour attendu d'une flotte refusee, gele avant que quiconque le cree.
 *
 * ## Pourquoi une projection fermee, gelee avant l'appel
 *
 * Le protocole comparait l'enfant cree a l'aller **relu apres** l'appel : une fermeture defectueuse
 * pouvait amputer l'aller, creer un enfant ampute, et passer. La projection se construit donc avant
 * toute creation, depuis l'aller et l'ordre tels qu'ils sont a cet instant, et le verificateur ne
 * relit plus rien.
 *
 * Et elle est **fermee** : chaque colonne de la table est soit imposee — comparee —, soit declaree
 * sans effet sur le mouvement. Une colonne ajoutee demain que personne n'a classee fait refuser le
 * retour, au lieu d'ouvrir un trou que la prochaine liste aurait oublie.
 *
 * ## Ce que les nombres doivent etre
 *
 * La base expose les ressources en flottant. `10.0` est le meme entier que `10` ; `10.9`, une valeur
 * negative ou non finie ne le sont pas, et une conversion entiere les aurait tronquees en silence.
 */
final readonly class ExpectedReturn
{
    /**
     * Les colonnes dont la valeur ne change rien au mouvement : identite technique, horodatages,
     * options de bataille que le retour ne rejoue pas.
     */
    private const array WITHOUT_EFFECT = [
        'id',
        'created_at',
        'updated_at',
        'target_priority',
        'retreat_after_defender_retreat',
    ];

    /**
     * @param array<string, int|null> $imposees Colonne -> valeur attendue.
     */
    private function __construct(public array $imposees)
    {
    }

    /**
     * La projection, lue maintenant — sur l'aller tel qu'il est, l'ordre tel qu'il est donne.
     */
    public static function of(FleetMission $aller, ReturnOrder $ordre): self
    {
        $service = resolve(FleetMissionService::class);
        $ressources = $service->getResources($aller);

        $imposees = [
            // Filiation et proprietaire.
            'parent_id' => (int)$aller->id,
            'user_id' => (int)$aller->user_id,
            'mission_type' => (int)$aller->mission_type,

            // L'origine du retour est la ou l'aller s'est presente.
            'planet_id_from' => $aller->planet_id_to === null ? null : (int)$aller->planet_id_to,
            'type_from' => (int)$aller->type_to,
            'galaxy_from' => $aller->galaxy_to === null ? null : (int)$aller->galaxy_to,
            'system_from' => $aller->system_to === null ? null : (int)$aller->system_to,
            'position_from' => $aller->position_to === null ? null : (int)$aller->position_to,

            // La destination est celle que l'ordre impose.
            'planet_id_to' => $ordre->destination->bodyId,
            'type_to' => $ordre->destination->type->value,
            'galaxy_to' => $ordre->destination->coordinate->galaxy,
            'system_to' => $ordre->destination->coordinate->system,
            'position_to' => $ordre->destination->coordinate->position,

            // Les heures : le depart impose, l'arrivee au bout de la duree de l'aller.
            'time_departure' => $ordre->departureAt,
            'time_arrival' => $ordre->departureAt + ReturnOrder::tripDurationOf($aller),

            // Rien qui recree un stationnement ou du carburant, rien qui rattache a quoi que ce soit.
            'time_holding' => null,
            'deuterium_consumption' => 0,
            'combat_instance_id' => null,
            'union_id' => null,
            'union_slot' => null,
            // **Un retour deja arrive a sa creation est traite aussitot** — c'est ce que fait
            // `startReturn()` pour tout retour dont l'arrivee est passee, et un travailleur en
            // retard de plusieurs heures en cree. Le drapeau est donc impose selon l'horloge, pas
            // laisse libre : libre, un retour marque traite sans etre arrive passerait.
            'processed' => $ordre->departureAt + ReturnOrder::tripDurationOf($aller) < (int)Date::now()->timestamp ? 1 : 0,
            'processed_hold' => 0,
            'canceled' => 0,
            'wreck_field_data' => null,

            // Ce que la flotte porte, tel que le service le definit.
            'metal' => (int)$ressources->metal->get(),
            'crystal' => (int)$ressources->crystal->get(),
            'deuterium' => (int)$ressources->deuterium->get(),
            'interplanetary_missile' => 0,
            'crawler' => 0,
        ];

        // **Les unites qui ont une colonne, et elles seules.** Le catalogue des vaisseaux compte
        // aussi le satellite solaire, qui ne vole pas et n'a pas de colonne ; l'imposer ferait
        // refuser tout retour pour une valeur qui n'existe nulle part.
        $colonnes = Schema::getColumnListing($aller->getTable());

        foreach (ObjectService::getShipObjects() as $vaisseau) {
            if (in_array($vaisseau->machine_name, $colonnes, true)) {
                $imposees[$vaisseau->machine_name] = 0;
            }
        }

        foreach ($service->getFleetUnits($aller)->units as $unite) {
            if (in_array($unite->unitObject->machine_name, $colonnes, true)) {
                $imposees[$unite->unitObject->machine_name] = (int)$unite->amount;
            }
        }

        return new self($imposees);
    }

    /**
     * Le premier ecart entre ce retour et la projection, ou `null` s'il est exactement celui attendu.
     */
    public function firstDifferenceWith(FleetMission $retour): string|null
    {
        foreach (Schema::getColumnListing($retour->getTable()) as $colonne) {
            if (in_array($colonne, self::WITHOUT_EFFECT, true)) {
                continue;
            }

            if (!array_key_exists($colonne, $this->imposees)) {
                return 'la colonne ' . $colonne . ' n est classee ni imposee ni sans effet';
            }
        }

        foreach ($this->imposees as $colonne => $attendu) {
            $ecart = self::differenceOn($colonne, $retour->getAttribute($colonne), $attendu);

            if ($ecart !== null) {
                return $ecart;
            }
        }

        return null;
    }

    private static function differenceOn(string $colonne, mixed $valeur, int|null $attendu): string|null
    {
        if ($attendu === null) {
            return $valeur === null ? null : $colonne . ' vaut ' . var_export($valeur, true) . ' au lieu d etre vide';
        }

        if ($valeur === null) {
            return $colonne . ' est vide au lieu de ' . $attendu;
        }

        if (is_bool($valeur)) {
            $valeur = $valeur ? 1 : 0;
        }

        if (!is_int($valeur) && !is_float($valeur) && !(is_string($valeur) && is_numeric($valeur))) {
            return $colonne . ' vaut ' . var_export($valeur, true) . ' au lieu de ' . $attendu;
        }

        $nombre = (float)$valeur;

        if (!is_finite($nombre) || $nombre < 0 || floor($nombre) !== $nombre) {
            return $colonne . ' vaut ' . var_export($valeur, true) . ' : ni fini, ni positif, ni entier';
        }

        if ((int)$nombre !== $attendu) {
            return $colonne . ' vaut ' . (int)$nombre . ' au lieu de ' . $attendu;
        }

        return null;
    }
}
