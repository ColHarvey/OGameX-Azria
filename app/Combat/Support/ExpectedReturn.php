<?php

namespace OGame\Combat\Support;

use Illuminate\Support\Facades\Schema;
use OGame\Models\Enums\PlanetType;
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
        // Le jeton qui reserve une mission pendant qu'un passage la traite. Il ne dit rien du
        // mouvement : il vit le temps d'un traitement et personne ne le lit apres. Un retour naissant
        // le porte a nul, comme toute mission neuve.
        'processing_claimed_at',
        // L etat des coques que la flotte rapporte (journal §118). Elle est **sans effet sur le
        // mouvement** — ni destination, ni instant, ni effectif n en dependent — et c est le critere
        // exact de cette liste.
        //
        // Elle n est pas pour autant sans importance, et la classer ici veut dire que **cette garde
        // ne verifie pas son transport** : c est un temoin dedie qui etablit qu un retour rapporte
        // les degats de son aller, pas la projection du mouvement. Le dire plutot que de laisser
        // croire a une couverture qui n existe pas.
        'damaged_hulls',
    ];

    /**
     * @param array<string, int|null> $imposees Colonne -> valeur attendue.
     * @param ResourceNormalizationDiagnostics $diagnostics Ce que la frontiere a signale en chemin.
     */
    private function __construct(
        public array $imposees,
        public ResourceNormalizationDiagnostics $diagnostics,
    ) {
    }

    /**
     * La projection, lue maintenant — sur l'aller tel qu'il est, l'ordre tel qu'il est donne.
     */
    public static function of(FleetMission $aller, ReturnOrder $ordre): self
    {
        $service = resolve(FleetMissionService::class);

        // **Ce que la frontiere signale se garde, au lieu d'etre jete.** Chaque conversion peut
        // rendre un diagnostic — une fortune au-dela de deux puissance cinquante-trois traverse
        // legitimement, mais elle doit se voir. Ne garder que l'entier faisait disparaitre
        // l'incident : le protocole acceptait le retour, et le journal unique de l'operation
        // n'apprenait rien.
        $diagnostics = ResourceNormalizationDiagnostics::none();

        // **Les colonnes de l'aller doivent porter des entiers, et le refus vient avant tout.**
        // Une cargaison est posee entiere au depart de la flotte, et le carburant consomme aussi ;
        // rien ne les fait produire en vol. Une fraction dessus est une donnee abimee, et un
        // transtypage entier l'aurait perdue en silence des deux cotes de la comparaison.
        $diagnostics = $diagnostics->mergedWith(self::diagnoseTheColumnsOf($aller));

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

            // La destination est celle que l'ordre impose — un corps, ou le point ou une patrouille
            // attend la flotte. **Une seule des deux formes s'ecrit**, l'autre reste vide.
            'planet_id_to' => $ordre->destination->landsOnAPoint() ? null : $ordre->destination->bodyIdOrFail(),
            'type_to' => $ordre->destination->landsOnAPoint()
                ? PlanetType::SpatialPoint->value
                : $ordre->destination->bodyTypeOrFail()->value,
            'galaxy_to' => $ordre->destination->coordinate->galaxy,
            'system_to' => $ordre->destination->coordinate->system,
            'position_to' => $ordre->destination->landsOnAPoint() ? 0 : $ordre->destination->coordinate->position,

            // Les heures : le depart impose, l'arrivee au bout de la duree de l'aller.
            'time_departure' => $ordre->departureAt,
            'time_arrival' => $ordre->departureAt + ReturnOrder::tripDurationOf($aller),

            // Rien qui recree un stationnement ou du carburant, rien qui rattache a quoi que ce soit.
            'time_holding' => null,
            'deuterium_consumption' => 0,
            'combat_instance_id' => null,
            'union_id' => null,
            'union_slot' => null,

            // **Les colonnes de segment de patrouille suivent la flotte** (journal §114). Le lien a la
            // patrouille se conserve : c est lui qui dit a qui rendre les vaisseaux. Les deux bouts
            // s inversent : le retour part de la ou l aller s est presente, son point spatial s il en
            // avait un. La cible patrouille, elle, ne suit jamais un retour.
            'patrol_id' => $aller->patrol_id === null ? null : (int)$aller->patrol_id,
            'target_patrol_id' => null,
            // **Un retour ne vise plus rien.** Le proprietaire gele de la cible appartient a l aller
            // — c est lui qui dit qui etait vise, et le verdict d arrivee le compare. Le laisser sur
            // le retour ferait croire qu une flotte qui rentre garde une cible.
            'target_patrol_owner_id' => null,
            'x_from' => $aller->x_to === null ? null : (int)$aller->x_to,
            'y_from' => $aller->y_to === null ? null : (int)$aller->y_to,

            // **Un retour orchestre se pose toujours sur un corps celeste**, jamais sur un point de
            // l espace : `ResolvedReturnDestination` en porte l identifiant et le genre. Sa fin de
            // segment n a donc pas de coordonnees de reference. Les recopier depuis le depart de
            // l aller faisait dire deux endroits a la meme ligne.
            'x_to' => null,
            'y_to' => null,
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
            'metal' => self::wholeUnitsOf($aller, $ressources->metal->get(), 'metal', $diagnostics),
            'crystal' => self::wholeUnitsOf($aller, $ressources->crystal->get(), 'crystal', $diagnostics),
            'deuterium' => self::wholeUnitsOf($aller, $ressources->deuterium->get(), 'deuterium', $diagnostics),
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

        return new self($imposees, $diagnostics);
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
     * Elle rend ce que la frontiere a signale, au lieu de le jeter : au-dela de deux puissance
     * cinquante-trois une colonne passe legitimement, mais l'incident doit remonter au journal.
     *
     * @param FleetMission $aller
     * @return ResourceNormalizationDiagnostics
     */
    private static function diagnoseTheColumnsOf(FleetMission $aller): ResourceNormalizationDiagnostics
    {
        $diagnostics = ResourceNormalizationDiagnostics::none();

        foreach (['metal', 'crystal', 'deuterium', 'deuterium_consumption'] as $colonne) {
            $diagnostics = $diagnostics->mergedWith(ResourceBoundary::wholeUnitsOfCarriedCargo(
                (float)($aller->{$colonne} ?? 0),
                $colonne,
                self::PHASE,
                'mission ' . $aller->id
            )->diagnostics);
        }

        return $diagnostics;
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
     * @param ResourceNormalizationDiagnostics $diagnostics Enrichi de ce que cette conversion signale.
     * @return int
     */
    private static function wholeUnitsOf(FleetMission $aller, float $montant, string $champ, ResourceNormalizationDiagnostics &$diagnostics): int
    {
        $normalise = ResourceBoundary::wholeUnitsOfLivingStock(
            $montant,
            $champ,
            self::PHASE,
            'mission ' . $aller->id
        );

        $diagnostics = $diagnostics->mergedWith($normalise->diagnostics);

        return $normalise->units;
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

    /**
     * Les colonnes dont la valeur peut etre negative.
     *
     * Toutes les autres portent un identifiant, un horodatage, un effectif ou une ressource : le
     * negatif y est une donnee abimee, et le refuser est la moitie de la garde. Les coordonnees de
     * reference d un segment de patrouille, elles, sont centrees sur l etoile — l orbite 2 vaut
     * x = -199 — et le signe y porte du sens. L exception est nommee, plutot que la garde affaiblie
     * partout.
     */
    private const array SIGNED = [
        'x_from',
        'y_from',
        'x_to',
        'y_to',
    ];

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

        $signee = in_array($colonne, self::SIGNED, true);

        if (!is_finite($nombre) || floor($nombre) !== $nombre || (!$signee && $nombre < 0)) {
            return $colonne . ' vaut ' . var_export($valeur, true) . ($signee
                ? ' : ni fini, ni entier'
                : ' : ni fini, ni positif, ni entier');
        }

        if ((int)$nombre !== $attendu) {
            return $colonne . ' vaut ' . (int)$nombre . ' au lieu de ' . $attendu;
        }

        return null;
    }
}
