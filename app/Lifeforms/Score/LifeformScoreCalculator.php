<?php

namespace OGame\Lifeforms\Score;

use Illuminate\Support\Facades\Log;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Models\Resources;

/**
 * Les ressources investies dans les formes de vie d un corps, dont se deduisent les points de classement.
 *
 * ## La regle officielle, etablie le 20 septembre 2026
 *
 * OGame ne verse pas ces investissements dans l Economie ni dans la Recherche classiques : il tient deux
 * categories a part, `Lifeform Economy` (les batiments) et `Lifeform Technology` (les technologies), dont la somme
 * forme `Lifeform` — et ce total-la entre dans le classement General. Deux preuves independantes, conservees au
 * journal (§173) :
 *
 * - le forum officiel allemand, ou un membre du conseil corrige « Die Lebensform-Gebaude sind nicht bei den
 *   Okonomiepunkten dabei, sondern bei den Lebensformpunkten », correction **enterinee par un administrateur de
 *   jeu** qui clot le fil ;
 * - l API publique du serveur `en1`, sur un meme joueur : type 9 = 37 413 544 349, type 10 = 35 989 655 471, et
 *   type 8 = 73 403 207 171 — soit la somme des deux a 7 351 pres. Et `Total − (Economie + Recherche + Militaire)`
 *   vaut 73 310 730 400, le total Formes de vie a 0,13 % pres, l ecart s expliquant par un releve militaire pris
 *   280 s plus tard.
 *
 * La conversion en points est celle du jeu entier : `floor(somme des ressources / 1000)`. Elle se fait chez
 * l appelant, sur la somme de toutes les planetes, pour qu un reste de moins de mille ressources par planete ne
 * soit pas perdu autant de fois qu il y a de corps.
 *
 * ## DEUX REGLES NE SONT PAS TRANCHEES, et ce fichier ne les tranche pas en silence
 *
 * 1. **Les reductions de cout.** Ce calcul emploie le cout **nominal** du catalogue, sans aucune reduction — la
 *    meme convention que les batiments classiques d Azria, qui comptent eux aussi le cout du catalogue et non ce
 *    que le joueur a paye. Ce n est pas une decision : c est l absence de decision, rendue visible.
 *    `LifeformScoreOpenRules::COST_REDUCTIONS` la nomme, et un temoin l epingle comme provisoire.
 * 2. **Une technologie dépossédée.** Un reset de palier met `object_id` a `null` mais **conserve les niveaux**, et
 *    une technologie reprise retrouve son niveau. Ce calcul compte donc **tous les niveaux enregistres**, poses ou
 *    non : les ressources ont ete depensees. Meme statut — provisoire, nomme, epingle.
 *
 * Les deux se mesurent sur un vrai compte OGame ; le protocole est sur le bureau de Keven. Tant que les mesures ne
 * sont pas faites, personne ne doit pouvoir changer ces deux comportements sans faire tomber un temoin qui dit
 * explicitement qu il s agissait d un provisoire.
 */
final class LifeformScoreCalculator
{
    public function __construct(private LifeformLevels $levels)
    {
    }

    /**
     * Les ressources investies dans les batiments de formes de vie d un corps.
     */
    public function buildingResourcesOf(int $planetId): Resources
    {
        return $this->cumulative($this->levels->buildingLevelsOf($planetId), $planetId);
    }

    /**
     * Les ressources investies dans les technologies de formes de vie d un corps.
     *
     * **Non tranche** : tous les niveaux enregistres comptent, qu un emplacement porte encore la technologie ou
     * non. Voir `LifeformScoreOpenRules::DISPOSSESSED_TECHNOLOGY`.
     */
    public function technologyResourcesOf(int $planetId): Resources
    {
        return $this->cumulative($this->levels->technologyLevelsOf($planetId), $planetId);
    }

    /**
     * Le cout cumule de tous les niveaux jusqu a celui atteint, objet par objet.
     *
     * @param array<int, int> $niveaux Identifiant d objet => niveau atteint.
     */
    private function cumulative(array $niveaux, int $planetId = 0): Resources
    {
        $total = new Resources(0, 0, 0, 0);

        foreach ($niveaux as $objectId => $niveau) {
            $objectId = (int)$objectId;
            $niveau = (int)$niveau;

            // Un identifiant que le catalogue ne connait pas — une cle retiree, une donnee ancienne — ne vaut
            // aucun point plutot que de faire tomber toute la page du classement.
            if ($niveau < 1 || !LifeformCatalogue::has($objectId)) {
                continue;
            }

            $objet = LifeformCatalogue::byId($objectId);

            for ($palier = 1; $palier <= $niveau; $palier++) {
                try {
                    // **Cout nominal, reduction nulle** : voir `LifeformScoreOpenRules::COST_REDUCTIONS`.
                    $total->add(LifeformFormulas::cost($objet, $palier, LifeformScoreOpenRules::SCORING_REDUCTION));
                } catch (LifeformRefused $refus) {
                    // **Le classement ne ferme jamais une page.** Un palier dont le cout ne se represente plus
                    // ne peut plus etre acquis : la garde de `LifeformFormulas::cost()` le refuse avant toute
                    // ecriture. Un niveau pareil ne peut donc venir que d une ecriture directe en base — une
                    // intervention d administration, ou un banc. On arrete de compter CET objet et on le dit
                    // au journal : ce n est pas un plafonnement silencieux, c est un fait rapporte.
                    Log::warning('Classement des formes de vie : cout non representable, objet ignore a partir de ce palier.', [
                        'planet_id' => $planetId,
                        'object_id' => $objectId,
                        'palier' => $palier,
                        'niveau_enregistre' => $niveau,
                        'refus' => $refus->getMessage(),
                    ]);
                    break;
                }
            }
        }

        return $total;
    }
}
