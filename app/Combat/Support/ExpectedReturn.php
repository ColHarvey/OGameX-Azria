<?php

namespace OGame\Combat\Support;

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
     * La phase economique sous laquelle un incident de cargaison est situe.
     */
    public const string PHASE = 'refused_fleet_return_projection';

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

        // **Les colonnes de l'aller doivent porter des entiers, et le refus vient avant tout.**
        // Une cargaison est posee entiere au depart de la flotte, et le carburant consomme aussi ;
        // rien ne les fait produire en vol. Une fraction dessus est une donnee abimee, et un
        // transtypage entier l'aurait perdue en silence des deux cotes de la comparaison.
        self::refuseAnyBrokenColumnOf($aller);

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
            // **Un retour orchestre nait non traite, quelle que soit l'heure.** Le drapeau etait
            // impose selon l'horloge, et cela avait deux defauts : il dependait de deux lectures —
            // ici avant l'appel, dans `startReturn()` apres l'insertion — donc un retour pose sur la
            // frontiere pouvait etre attendu a zero puis livre ; et a un, il ne prouvait que
            // lui-meme, un createur fautif pouvant le poser sans avoir rien credite. La livraison
            // appartient au travailleur canonique, apres le commit, par le chemin ordinaire.
            'processed' => 0,
            'processed_hold' => 0,
            'canceled' => 0,
            'wreck_field_data' => null,

            // **Ce que la flotte porte, passe par la frontiere economique canonique.** Le
            // transtypage entier qui vivait ici n'etait le plancher de personne : il tronquait sans
            // le dire, et la meme troncature dans `startReturn()` rendait la perte invisible a la
            // comparaison. La regle du demi-carburant reste ou elle est — `getResources()` en est le
            // seul auteur — et son demi restant est plancher **nomme**, par la meme frontiere que le
            // reste du pipeline economique.
            'metal' => self::wholeUnitsOf($aller, $ressources->metal->get(), 'metal'),
            'crystal' => self::wholeUnitsOf($aller, $ressources->crystal->get(), 'crystal'),
            'deuterium' => self::wholeUnitsOf($aller, $ressources->deuterium->get(), 'deuterium'),
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
     * Les colonnes economiques de l'aller, refusees si l'une ne porte pas un entier.
     *
     * ## Pourquoi la colonne, et pas la valeur calculee
     *
     * `getResources()` rend la cargaison **plus la moitie du carburant consomme** : c'est une regle
     * du jeu, presente en amont et appliquee a tous les genres de mission, et une consommation
     * impaire y produit legitimement un demi. Refuser ce demi-la refuserait un retour sur deux —
     * une flotte resterait posee sur le corps qu'elle doit quitter, pour une valeur que le jeu
     * fabrique exprès. Ce n'est donc pas le calcul qui est controle, mais **ce que la base porte** :
     * la cargaison et le carburant sont poses entiers au lancement de la flotte, et rien en vol ne
     * les fait avancer par fractions. Une fraction dessus n'a pas d'auteur legitime.
     *
     * @param FleetMission $aller
     * @return void
     */
    private static function refuseAnyBrokenColumnOf(FleetMission $aller): void
    {
        foreach (['metal', 'crystal', 'deuterium', 'deuterium_consumption'] as $colonne) {
            ResourceBoundary::wholeUnitsOfCarriedCargo(
                (float)($aller->{$colonne} ?? 0),
                $colonne,
                self::PHASE,
                'mission ' . $aller->id
            );
        }
    }

    /**
     * Une quantite de retour en unites entieres.
     *
     * La projection n'a pas de convertisseur a elle : elle demande a `ResourceBoundary` le meme
     * entier que le reste du pipeline economique, sous une phase qui situe l'incident. Le plancher
     * est ici celui du demi-carburant, et de lui seul : les colonnes qui alimentent le calcul ont
     * deja ete refusees si elles portaient une fraction.
     *
     * @param FleetMission $aller
     * @param float $montant
     * @param string $champ
     * @return int
     */
    private static function wholeUnitsOf(FleetMission $aller, float $montant, string $champ): int
    {
        return ResourceBoundary::wholeUnitsOfLivingStock(
            $montant,
            $champ,
            self::PHASE,
            'mission ' . $aller->id
        )->units;
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
