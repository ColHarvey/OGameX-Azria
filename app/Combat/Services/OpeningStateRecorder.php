<?php

namespace OGame\Combat\Services;

use OGame\Combat\Causality\AppliedEffectReceipt;
use OGame\Combat\Causality\OpeningProvenance;
use OGame\Combat\Causality\ProtectedOpeningState;
use OGame\Combat\Exceptions\MissingOpeningState;
use OGame\Combat\Support\FrozenFact;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * L'etat protege du corps a l'ouverture, ecrit dans la transaction qui ouvre le combat.
 *
 * ## Ce qui est capture
 *
 * Les ressources du corps — la reserve que le combat protege : la production qui suit l'ouverture
 * est libre, elle n'y entre pas — et **ce que cet etat reflete deja**, effet par effet : chaque
 * mission de flotte deja traitee vers ce corps, chaque construction, chaque recherche deja achevee
 * de son proprietaire, sous forme de recu (identite, genre, empreinte de l'effet). C'est la
 * provenance : a la fermeture, un evenement admissible par les deux barrieres mais deja reflete
 * ici entre dans la photographie **sans etre rejoue**.
 *
 * ## Pourquoi l'etat et sa provenance voyagent ensemble
 *
 * Un solde seul ne dit pas quels effets l'ont produit. Un transport livre hier satisfait les deux
 * barrieres ; sans son recu, le reconciliateur l'ajouterait une seconde fois. `ProtectedOpeningState`
 * n'a pas de fabrique qui donne l'un sans l'autre, et ce document n'en a pas non plus.
 *
 * ## L'effectif, capture ici aussi
 *
 * Les unites du corps — vaisseaux et defenses de combat, telles que la garnison les emploie — sont
 * photographiees au meme instant. La fermeture y ajoutera ce que les effets admissibles ont produit,
 * et le moteur se battra contre cet effectif : jamais contre ce que le corps porte au moment ou la
 * fermeture passe, qui dependrait de ce que le monde a fait pendant le ralliement.
 *
 * ## Le defenseur, capture aussi
 *
 * Les niveaux d'armes, de boucliers et de blindage du proprietaire, son bonus de classe au combat et
 * le niveau du chantier spatial : les quatre faits que la bataille consomme de lui. Une recherche
 * achevee pendant le ralliement sur une decision **posterieure** a l'ouverture ne renforce donc plus
 * une defense deja engagee.
 *
 * ## Les reglages d'univers, captures ici depuis la revue 89
 *
 * Les sept reglages que le moteur consomme (`PhotographedUniverse`) sont pris **a l'ouverture**, et
 * plus a la cloture : un ralliement dure, et un administrateur qui ajustait la part d'epaves ou le
 * seuil d'un champ changeait une bataille deja engagee. Les attaquants sont partis sous un univers ;
 * ils se battent sous celui-la.
 *
 * `FrozenCombatApplicationContext` continue de geler a la cloture ce dont **l'application** depend —
 * classes, chantiers, instant d'application. Les deux photographies ne se recouvrent pas : l'une dit
 * sous quelles regles la bataille se calcule, l'autre sous quelles regles son resultat s'ecrit.
 */
final class OpeningStateRecorder
{
    public const int VERSION = 5;

    public function __construct(
        private CausalEventReader $reader = new CausalEventReader(),
        private PlanetServiceFactory|null $planets = null,
    ) {
    }

    /**
     * Capture l'etat du corps et l'ecrit sur l'instance. A appeler dans la transaction d'ouverture.
     */
    public function capture(CombatInstance $combat, int $targetBodyId, int $openedAt): void
    {
        // **Le corps est tenu** le temps de la lecture : ce que l'on capture est ce qui est commis, et
        // rien ne peut s'y ecrire entre les ressources et la provenance.
        Planet::query()->whereKey($targetBodyId)->lockForUpdate()->first();

        $corps = $this->planets()->make($targetBodyId, true);
        if ($corps === null) {
            throw new RuntimeException('Le combat ' . $combat->id . ' s ouvre sur un corps ' . $targetBodyId . ' qui n existe pas.');
        }
        $proprietaire = (int)($corps->getPlayer()?->getId() ?? 0);
        $ressources = $corps->getResources();

        $recus = [];
        foreach ($this->reader->receiptsAlreadyApplied($targetBodyId, $proprietaire, (int)$combat->id, $openedAt) as $recu) {
            $recus[] = [
                'event_identity' => $recu->eventIdentity,
                'kind_version' => $recu->kindVersion,
                'effect_fingerprint' => $recu->effectFingerprint,
                'aggregate_id' => $recu->aggregateId,
                'applied_at' => $recu->appliedAt,
                'receipt_id' => $recu->receiptId,
            ];
        }

        $etat = [
            'version' => self::VERSION,
            'captured_at' => $openedAt,
            'target_body_id' => $targetBodyId,
            'owner_id' => $proprietaire,
            'resources' => [
                'metal' => ResourceBoundary::wholeUnitsOfLivingStock($ressources->metal->get(), 'metal', 'etat_d_ouverture')->units,
                'crystal' => ResourceBoundary::wholeUnitsOfLivingStock($ressources->crystal->get(), 'crystal', 'etat_d_ouverture')->units,
                'deuterium' => ResourceBoundary::wholeUnitsOfLivingStock($ressources->deuterium->get(), 'deuterium', 'etat_d_ouverture')->units,
            ],
            'units' => DefenderFleet::fromPlanet($corps)->units->toArray(),
            // Les antimissiles ne se battent pas dans la garnison, mais une salve admissible les consomme :
            // la fermeture projette chaque salve sur la photographie, et il lui faut ceux de l'ouverture.
            'interceptors' => $corps->getObjectAmount('anti_ballistic_missile'),
            'defender' => self::defenderFactsOf($corps),
            // **Les reglages sous lesquels cette bataille se calculera.** Lus une fois, ici, dans la
            // transaction d'ouverture : ce que l'administration changera pendant le ralliement ne
            // touchera que les combats ouverts apres.
            'universe' => PhotographedUniverse::fromLiveSettings(resolve(SettingsService::class))->toFrozenFacts(),
            'provenance' => $recus,
        ];

        $combat->opening_state = $etat;
        $combat->opening_state_fingerprint = self::fingerprintOf($etat);
        $combat->opening_captured_at = $openedAt;
        $combat->save();
    }

    /**
     * L'etat protege relu depuis l'instance, ou un refus : un combat sans etat d'ouverture n'a pas
     * de photographie, et la fermeture ne doit pas en inventer une depuis le monde vivant.
     */
    public static function protectedStateOf(CombatInstance $combat): ProtectedOpeningState
    {
        $etat = self::documentOf($combat);
        $recus = [];
        foreach (FrozenFact::listOfArrays($etat, 'provenance') as $recu) {
            $recus[] = new AppliedEffectReceipt(
                FrozenFact::string($recu, 'event_identity'),
                FrozenFact::string($recu, 'kind_version'),
                FrozenFact::string($recu, 'effect_fingerprint'),
                FrozenFact::int($recu, 'aggregate_id'),
                FrozenFact::int($recu, 'applied_at'),
                FrozenFact::string($recu, 'receipt_id'),
            );
        }

        return new ProtectedOpeningState(
            (int)$combat->id,
            FrozenFact::int($etat, 'target_body_id'),
            FrozenFact::int($etat, 'captured_at'),
            OpeningProvenance::ofReceipts($recus),
            (string)$combat->opening_state_fingerprint,
        );
    }

    /**
     * Les ressources du corps a l'ouverture : la reserve protegee, avant les livraisons admissibles.
     */
    public static function openingResourcesOf(CombatInstance $combat): Resources
    {
        $ressources = FrozenFact::array(self::documentOf($combat), 'resources');

        return new Resources(
            FrozenFact::int($ressources, 'metal'),
            FrozenFact::int($ressources, 'crystal'),
            FrozenFact::int($ressources, 'deuterium'),
            0,
        );
    }

    /**
     * Les faits du defenseur a l'ouverture, avant tout effet admissible.
     */
    public static function openingDefenderOf(CombatInstance $combat): PhotographedDefender
    {
        $document = self::documentOf($combat);
        $faits = $document['defender'] ?? null;

        if (!is_array($faits)) {
            throw new MissingOpeningState('L etat d ouverture du combat ' . $combat->id . ' ne porte pas les faits du defenseur : il a ete ouvert sous une version anterieure.');
        }

        return PhotographedDefender::fromFrozenFacts($faits);
    }

    /**
     * Les antimissiles que le corps portait a l'ouverture.
     */
    public static function openingInterceptorsOf(CombatInstance $combat): int
    {
        $document = self::documentOf($combat);
        if (!array_key_exists('interceptors', $document)) {
            throw new MissingOpeningState('L etat d ouverture du combat ' . $combat->id . ' ne porte pas les antimissiles : il a ete ouvert sous une version anterieure.');
        }

        return FrozenFact::int($document, 'interceptors');
    }

    /**
     * Les reglages d'univers sous lesquels ce combat s'est ouvert.
     *
     * Un combat ouvert sous une version anterieure n'en porte pas, et **on ne retombe pas sur les
     * reglages vivants** : ce serait rendre a l'administration le pouvoir que cette capture lui
     * retire, en silence et seulement pour les vieux combats.
     */
    public static function openingUniverseOf(CombatInstance $combat): PhotographedUniverse
    {
        $document = self::documentOf($combat);
        $faits = $document['universe'] ?? null;

        if (!is_array($faits)) {
            throw new MissingOpeningState('L etat d ouverture du combat ' . $combat->id . ' ne porte pas les reglages d univers : il a ete ouvert sous une version anterieure.');
        }

        return PhotographedUniverse::fromFrozenFacts($faits);
    }

    /**
     * @return array<string, int>
     */
    private static function defenderFactsOf(PlanetService $corps): array
    {
        $proprietaire = $corps->getPlayer();
        if ($proprietaire === null) {
            throw new RuntimeException('Le corps ' . $corps->getPlanetId() . ' n a pas de proprietaire a l ouverture du combat.');
        }

        // Le chantier spatial d'une lune est celui de sa planete : c'est la que le moteur le lit.
        $chantier = $corps->isMoon() ? $corps->planet() : $corps;

        return (new PhotographedDefender(
            $proprietaire->getResearchLevel('weapon_technology'),
            $proprietaire->getResearchLevel('shielding_technology'),
            $proprietaire->getResearchLevel('armor_technology'),
            resolve(CharacterClassService::class)->getAdditionalCombatResearchLevels($proprietaire->getUser()),
            $chantier->getObjectLevel('space_dock'),
        ))->toFrozenFacts();
    }

    /**
     * L'effectif du corps a l'ouverture : la garnison avant tout effet admissible.
     */
    public static function openingUnitsOf(CombatInstance $combat): UnitCollection
    {
        $unites = new UnitCollection();
        $document = self::documentOf($combat);
        $photographie = $document['units'] ?? null;

        // **Un combat ouvert avant que l'effectif soit photographie n'en a pas.** Il n'y en a aucun en
        // jeu — le systeme n'est pas deploye —, mais le dire vaut mieux que rendre une garnison vide,
        // qui ferait gagner tout attaquant sans que rien ne le signale.
        if (!is_array($photographie)) {
            throw new MissingOpeningState('L etat d ouverture du combat ' . $combat->id . ' ne porte pas d effectif : il a ete ouvert sous une version anterieure, et sa garnison ne peut pas etre reconstituee.');
        }

        foreach (array_keys($photographie) as $nom) {
            // Meme exigence qu'aux autres portes : une quantite est un entier, ou le document est abime.
            $quantite = FrozenFact::int($photographie, (string)$nom);
            if ($quantite > 0) {
                $unites->addUnit(ObjectService::getUnitObjectByMachineName((string)$nom), $quantite);
            }
        }

        return $unites;
    }

    /**
     * @return array<string, mixed>
     */
    private static function documentOf(CombatInstance $combat): array
    {
        $etat = $combat->opening_state;
        if (!is_array($etat)) {
            throw new MissingOpeningState('Le combat ' . $combat->id . ' n a pas d etat d ouverture : sa photographie ne peut pas se construire, et la lire dans le monde vivant serait la fausser.');
        }

        $empreinte = self::fingerprintOf($etat);
        if ($empreinte !== (string)$combat->opening_state_fingerprint) {
            throw new MissingOpeningState('L etat d ouverture du combat ' . $combat->id . ' ne porte plus son empreinte : il a ete modifie depuis sa capture.');
        }

        return $etat;
    }

    /**
     * @param array<string, mixed> $etat
     */
    private static function fingerprintOf(array $etat): string
    {
        return hash('sha256', (string)json_encode(self::canonical($etat), JSON_THROW_ON_ERROR));
    }

    /**
     * Les cles triees a chaque niveau : deux documents egaux ont la meme empreinte, quel que soit
     * l'ordre dans lequel ils ont ete ecrits ou relus.
     */
    private static function canonical(mixed $valeur): mixed
    {
        if (!is_array($valeur)) {
            return $valeur;
        }
        if (array_is_list($valeur)) {
            return array_map(static fn (mixed $v): mixed => self::canonical($v), $valeur);
        }
        ksort($valeur);

        return array_map(static fn (mixed $v): mixed => self::canonical($v), $valeur);
    }

    private function planets(): PlanetServiceFactory
    {
        return $this->planets ??= resolve(PlanetServiceFactory::class);
    }
}
