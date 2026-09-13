<?php

namespace OGame\Patrol\Combat;

use OGame\Combat\Services\PhotographedDefender;
use OGame\Combat\Services\PhotographedUniverse;
use OGame\Combat\Support\FrozenFact;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;

/**
 * L etat protege d un combat en espace libre : ce qui est vrai a l ouverture, et rien d autre.
 *
 * ------------------------------------------------------------------------------------
 * CE QU IL EST, ET CE QU IL N EST PAS
 *
 * C est **l etat de depart** de la bataille, pas son resultat. L avanceur partira de la pour
 * jouer le premier round, et les renforts admis entre deux rounds s ajouteront a l etat relu.
 * Rien ici ne decide de l issue.
 *
 * Il ecrit dans la meme colonne que l etat d ouverture d un corps (`opening_state`), et porte
 * un **genre** explicite : les deux documents ne decrivent pas le meme monde, et une relecture
 * qui confondrait les deux jouerait une bataille sur un effectif qui n est pas le sien. Le
 * genre est verifie a la relecture, pas suppose.
 *
 * ------------------------------------------------------------------------------------
 * LES REGLAGES SONT PHOTOGRAPHIES ICI AUSSI
 *
 * Pour la meme raison que sur un corps : ce que l administration changera pendant la bataille
 * ne doit toucher que les combats ouverts apres. Un taux de debris modifie au troisieme round
 * ne peut pas changer ce que les deux premiers ont produit.
 *
 * ------------------------------------------------------------------------------------
 * L EMPREINTE DIT QU IL N A PAS BOUGE
 *
 * Elle n est pas une securite contre un attaquant — personne n ecrit dans cette colonne depuis
 * le jeu. Elle sert au relecteur : un document reecrit par une migration, une reprise ou une
 * main humaine se constate au lieu d etre joue.
 */
final class SpatialOpeningState
{
    /**
     * La version du document. **Elle est propre a l espace libre** : la faire suivre celle des
     * corps ferait croire qu un numero commun decrit une forme commune.
     */
    public const int VERSION = 1;

    public function __construct(
        private FleetMissionService|null $fleetMissions = null,
        private PlayerServiceFactory|null $players = null,
        private SettingsService|null $settings = null,
    ) {
    }

    /**
     * Photographie la patrouille qui defend, et ecrit l etat sur l instance.
     *
     * @param FleetMission $defendingFleet La flotte posee de la patrouille : elle porte les unites.
     */
    public function capture(CombatInstance $combat, Patrol $patrol, FleetMission $defendingFleet, int $openedAt): void
    {
        $defense = $this->photograph($patrol, $defendingFleet);

        $etat = [
            'version' => self::VERSION,
            'kind' => 'spatial',
            'captured_at' => $openedAt,
            'defence' => $defense->toFrozenFacts(),
            'universe' => PhotographedUniverse::fromLiveSettings($this->settings())->toFrozenFacts(),
        ];

        $combat->opening_state = $etat;
        $combat->opening_state_fingerprint = self::fingerprintOf($etat);
        $combat->opening_captured_at = $openedAt;
        $combat->save();
    }

    /**
     * La defense gelee, relue depuis l instance — ou un refus.
     *
     * **Un combat spatial sans etat d ouverture n a pas de photographie**, et la reconstruire
     * depuis le monde vivant donnerait l effectif d aujourd hui, pas celui du premier round.
     */
    public function protectedDefenceOf(CombatInstance $combat): FrozenSpatialDefence
    {
        $etat = $combat->opening_state;

        if (!is_array($etat) || $etat === []) {
            throw new CorruptedSpatialDefence(
                'Combat ' . $combat->id . ' has no opening state: a free-space battle cannot be rebuilt '
                . 'from the living world, which no longer holds the roster of its first round.'
            );
        }

        if (FrozenFact::string($etat, 'kind') !== 'spatial') {
            throw new CorruptedSpatialDefence(
                'Combat ' . $combat->id . ' carries an opening state of another kind: a body photograph '
                . 'and a free-space one do not describe the same world.'
            );
        }

        $version = FrozenFact::int($etat, 'version');

        if ($version !== self::VERSION) {
            throw new CorruptedSpatialDefence(
                'Combat ' . $combat->id . ' carries a spatial opening state of version ' . $version
                . ', and this server reads version ' . self::VERSION . '.'
            );
        }

        return FrozenSpatialDefence::fromFrozenFacts(FrozenFact::array($etat, 'defence'));
    }

    /**
     * L etat lu tel qu il a ete ecrit — pour constater qu il n a pas bouge.
     *
     * @return array<string, mixed>
     */
    public function rawStateOf(CombatInstance $combat): array
    {
        $etat = $combat->opening_state;

        return is_array($etat) ? $etat : [];
    }

    /**
     * Ce que le monde vivant dit de cette patrouille, a cet instant.
     */
    private function photograph(Patrol $patrol, FleetMission $defendingFleet): FrozenSpatialDefence
    {
        $proprietaire = $this->players()->make((int)$patrol->user_id, true);

        return new FrozenSpatialDefence(
            (int)$patrol->id,
            (int)$patrol->user_id,
            (int)$defendingFleet->id,
            $this->fleetMissions()->getFleetUnits($defendingFleet),
            [
                'metal' => (int)$defendingFleet->metal,
                'crystal' => (int)$defendingFleet->crystal,
                'deuterium' => (int)$defendingFleet->deuterium,
            ],
            // **La reserve est arrondie vers le bas, et le dire evite une question.** Elle est
            // comptee en fractions pendant le stationnement (revue 121, R3) ; ce qui entre dans une
            // photographie est ce qui paie reellement un retour, donc des unites entieres.
            (int)floor((float)$patrol->fuel_reserve),
            new PhotographedDefender(
                $proprietaire->getResearchLevel('weapon_technology'),
                $proprietaire->getResearchLevel('shielding_technology'),
                $proprietaire->getResearchLevel('armor_technology'),
                // **Le bonus derive, pas la classe.** C est la valeur que le moteur applique aux
                // tirs ; photographier la classe laisserait un changement de classe pendant la
                // bataille changer des tirs deja joues.
                //
                // **Les deux classes, et non celle du personnage seule.** Cette ligne n interrogeait
                // que `CharacterClassService` : un defenseur d une alliance de Guerriers perdait son
                // niveau en espace libre, alors que la photographie d un corps le portait deja.
                $proprietaire->getCombatResearchBonusLevels(),
                // **Aucun chantier spatial en espace libre.** La part d epaves retombe sur le
                // plancher du jeu, que le moteur applique par `max(1, …)`.
                0,
            ),
            // **La classe, et pas seulement son bonus.** Le moteur demande a la classe si le
            // joueur est General — pour la manoeuvre de Hamill — et quel fret ses transporteurs
            // portent. Sans elle, un defenseur recharge perdrait ces capacites en silence.
            $proprietaire->getUser()->character_class,
        );
    }

    /**
     * @param array<string, mixed> $etat
     */
    private static function fingerprintOf(array $etat): string
    {
        return hash('sha256', json_encode($etat, JSON_THROW_ON_ERROR));
    }

    private function fleetMissions(): FleetMissionService
    {
        return $this->fleetMissions ??= resolve(FleetMissionService::class);
    }

    private function players(): PlayerServiceFactory
    {
        return $this->players ??= resolve(PlayerServiceFactory::class);
    }

    private function settings(): SettingsService
    {
        return $this->settings ??= resolve(SettingsService::class);
    }
}
