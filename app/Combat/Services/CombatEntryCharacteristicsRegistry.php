<?php

namespace OGame\Combat\Services;

use Closure;
use OGame\Combat\Causality\CausalEventOrder;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Enums\UnitCharacteristicsRule;
use OGame\Combat\Exceptions\MissingEntryCharacteristics;
use OGame\Combat\Exceptions\UnknownAdmissionHistory;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\EffectOrderKey;
use OGame\Combat\Support\FrozenCombatCharacteristics;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\History\ClassHistoryReader;
use OGame\History\HistoricValue;
use OGame\Models\CombatEntryCharacteristic;
use OGame\Models\CombatInstance;
use OGame\Models\ResearchQueue;
use OGame\Services\AllianceClassService;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;

/**
 * Le registre des caracteristiques de combat, prises a **l instant d admission** de chaque flotte.
 *
 * ## La decision qu il sert
 *
 * Keven, 12 et 13 septembre 2026 : une flotte qui entre dans un combat durable y apporte ses niveaux
 * d armes, de boucliers et de blindage **et** le bonus de ses classes — personnelle et d alliance — tels
 * qu ils etaient a son admission. Rien de ce qui arrive ensuite ne change ses tirs, et le moment ou un
 * travailleur la traite n y entre jamais.
 *
 * ## Qui ecrit, et a quel instant
 *
 * - `recordAtArrival()` : l arrivee physique d une attaque (`EntersADurableCombat`), d une Defense ACS
 *   (`AcsDefendMission`), ou d une vague que la cloture inscrit sans que son travailleur soit passe.
 *   L admission est un **evenement d arrivee** dans l ordre causal du combat.
 * - `recordAtOpening()` : un renfort deja pose quand le combat s ouvre. L admission est la **barriere
 *   d ouverture**.
 *
 * ## La garnison n a pas de ligne, et pourquoi
 *
 * Le corps vise n arrive nulle part : ce qu il apporte est la **photographie d ouverture**, dont les
 * niveaux de recherche suivent une regle propre, deja arretee — une recherche appartient au combat si elle
 * a ete engagee avant l ouverture et achevee avant la fermeture (`ClosureReconciliation`). Le geler a la
 * barriere lui retirerait ces relevements.
 *
 * Son **bonus de classe**, lui, n a pas d effet admissible : c est une decision, et la photographie le
 * prend a l instant ou un travailleur traite l ouverture — une classe d alliance choisie entre l arrivee de
 * l attaquante et ce passage y entrerait. `garrisonClassBonusAt()` le rend a l instant d ouverture, depuis
 * les memes historiques que les flottes, et la composition l assemble avec les niveaux photographies.
 *
 * ## L ordre de la meme seconde, repris et jamais reinvente
 *
 * - **Une recherche** est un evenement : elle se compare a l admission par `EffectOrderKey`, sous l ordre
 *   causal **gele du combat**. Sous la premiere version, une recherche achevee a la seconde d une arrivee
 *   la precede ; une barriere, elle, precede tout evenement de sa seconde.
 * - **Un changement de classe ou d appartenance** est une decision : l historique rend la derniere decision
 *   strictement anterieure (`DecisionOrder`).
 *
 * ## La premiere entree fait foi
 *
 * Une ligne existante n est jamais reecrite. Toutes les ecritures ont lieu sous les verrous du combat ; la
 * clef unique ferme ce que l ordre des verrous laisserait entrouvert.
 *
 * ## Inconnu est une anomalie, jamais une valeur
 *
 * Un historique qui ne sait pas ce que le participant avait a son admission leve `UnknownAdmissionHistory`.
 * Les portes d arrivee l attrapent et le journalisent sans rien ecrire — une exception y fermerait les pages
 * du joueur ; la cloture l attrape, revient en arriere et se suspend, et l avanceur compte l echec.
 *
 * ## Sous la premiere regle, rien ne s ecrit
 *
 * Un combat ouvert a la ligne de base des historiques ou avant compose ses unites comme avant. Lui ecrire
 * des caracteristiques gelees laisserait croire qu elles servent.
 */
final class CombatEntryCharacteristicsRegistry
{
    public function __construct(
        private PlayerServiceFactory|null $players = null,
        private ClassHistoryReader|null $history = null,
        private CausalEventOrderRegistry|null $orders = null,
    ) {
    }

    /**
     * Inscrit ce qu une flotte admise **a son arrivee physique** apporte a ses tirs.
     *
     * @throws UnknownAdmissionHistory
     */
    public function recordAtArrival(CombatInstance $combat, int $fleetMissionId, int $playerId, int $arrivalAt): void
    {
        $this->record(
            $combat,
            $fleetMissionId,
            $playerId,
            $arrivalAt,
            static fn (CausalEventOrder $ordre): EffectOrderKey => EffectOrderKey::forEvent($arrivalAt, CombatEventType::FleetArrival, $fleetMissionId, $ordre)
        );
    }

    /**
     * Inscrit ce qu un renfort deja pose apporte a ses tirs, **a l ouverture** qui le retient.
     *
     * @throws UnknownAdmissionHistory
     */
    public function recordAtOpening(CombatInstance $combat, int $fleetMissionId, int $playerId, int $openedAt): void
    {
        $this->record(
            $combat,
            $fleetMissionId,
            $playerId,
            $openedAt,
            static fn (CausalEventOrder $ordre): EffectOrderKey => EffectOrderKey::barrierAt($openedAt, $ordre)
        );
    }

    /**
     * Ce que cette flotte apporte a ses tirs, ou un refus.
     */
    public function of(CombatInstance $combat, int $fleetMissionId): FrozenCombatCharacteristics
    {
        $ligne = CombatEntryCharacteristic::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', CombatParticipantKey::forFleet($fleetMissionId))
            ->first();

        if (!$ligne instanceof CombatEntryCharacteristic) {
            throw new MissingEntryCharacteristics(
                'La flotte ' . $fleetMissionId . ' du combat ' . $combat->id . ' n a pas de caracteristiques '
                . 'gelees a son entree : ses tirs ne se composent pas depuis le joueur vivant.'
            );
        }

        return FrozenCombatCharacteristics::fromStorage($ligne->getAttributes());
    }

    /**
     * Le bonus de combat des classes de la garnison, **a l instant d ouverture**.
     *
     * La garnison est admise a l ouverture : c est la que ses classes se lisent, et l historique repond la
     * meme chose quel que soit le moment ou on l interroge — il ne se reecrit pas.
     *
     * @throws UnknownAdmissionHistory
     */
    public function garrisonClassBonusAt(CombatInstance $combat, int $ownerId, int $openedAt): int
    {
        return $this->classBonusAt('La garnison du combat ' . $combat->id, $ownerId, $openedAt);
    }

    /**
     * @param Closure(CausalEventOrder): EffectOrderKey $admission
     *
     * @throws UnknownAdmissionHistory
     */
    private function record(CombatInstance $combat, int $fleetMissionId, int $playerId, int $instant, Closure $admission): void
    {
        if (UnitCharacteristicsRule::fromInstance($combat) !== UnitCharacteristicsRule::FrozenAtEntry) {
            return;
        }

        $cle = CombatParticipantKey::forFleet($fleetMissionId);

        $dejaInscrite = CombatEntryCharacteristic::query()
            ->where('combat_instance_id', $combat->id)
            ->where('participant_key', $cle)
            ->exists();

        if ($dejaInscrite) {
            return;
        }

        // **L ordre du combat, relu depuis sa version persistee** : jamais l ordre courant pris au vol.
        $ordre = $this->orders()->forVersion((string)$combat->causal_order_version);
        $cleDAdmission = $admission($ordre);

        // Le compte relu a neuf porte les niveaux **appliques** ; l historique des files les ramene a
        // l instant d admission.
        $compte = $this->players()->make($playerId, true);

        $faits = new FrozenCombatCharacteristics(
            $this->researchLevelAt($compte->getResearchLevel('weapon_technology'), $playerId, 'weapon_technology', $cleDAdmission, $ordre),
            $this->researchLevelAt($compte->getResearchLevel('shielding_technology'), $playerId, 'shielding_technology', $cleDAdmission, $ordre),
            $this->researchLevelAt($compte->getResearchLevel('armor_technology'), $playerId, 'armor_technology', $cleDAdmission, $ordre),
            $this->classBonusAt('La flotte ' . $fleetMissionId . ' du combat ' . $combat->id, $playerId, $instant),
        );

        CombatEntryCharacteristic::query()->create([
            'combat_instance_id' => $combat->id,
            'fleet_mission_id' => $fleetMissionId,
            'participant_key' => $cle,
            'player_id' => $playerId,
            ...$faits->toStorage(),
            'entered_at' => $instant,
        ]);
    }

    /**
     * Le niveau de cette recherche **a l admission**, depuis le niveau que le compte porte.
     *
     * Les files du joueur, toutes planetes confondues, commencees et non annulees, disent ce que le compte ne
     * dit pas. Chacune est un evenement `ResearchCompletion` date de sa fin, compare a l admission sous l ordre
     * causal du combat :
     *
     * - **appliquee** mais pas avant l admission : elle n y etait pas, le niveau redescend sous sa cible ;
     * - **pas encore appliquee** mais avant l admission : elle y etait, le niveau monte a sa cible.
     *
     * Un niveau pose hors des files (administration, raccourci de developpement) n a pas d historique : il est
     * pris tel que le compte le porte.
     */
    private function researchLevelAt(int $porte, int $playerId, string $machineName, EffectOrderKey $admission, CausalEventOrder $ordre): int
    {
        $lignes = ResearchQueue::query()
            ->join('planets', 'planets.id', '=', 'research_queues.planet_id')
            ->where('planets.user_id', $playerId)
            ->where('research_queues.object_id', ObjectService::getResearchObjectByMachineName($machineName)->id)
            ->where('research_queues.building', 1)
            ->where('research_queues.canceled', 0)
            ->get(['research_queues.id', 'research_queues.object_level_target', 'research_queues.time_end', 'research_queues.processed']);

        $niveau = $porte;

        foreach ($lignes as $ligne) {
            $cible = (int)$ligne->object_level_target;
            $appliquee = (int)$ligne->processed === 1;
            $precede = EffectOrderKey::forEvent((int)$ligne->time_end, CombatEventType::ResearchCompletion, (int)$ligne->id, $ordre)->isBefore($admission);

            if ($appliquee && !$precede) {
                $niveau = min($niveau, $cible - 1);
            } elseif (!$appliquee && $precede) {
                $niveau = max($niveau, $cible);
            }
        }

        return max(0, $niveau);
    }

    /**
     * Le bonus de combat des classes du joueur **a l admission** : classe personnelle, puis classe de
     * l alliance a laquelle il appartenait a cet instant.
     *
     * @throws UnknownAdmissionHistory
     */
    private function classBonusAt(string $quoi, int $playerId, int $instant): int
    {
        $histoire = $this->history();

        $personnelle = $this->known($histoire->personalClassAt($playerId, $instant), $quoi);
        $niveaux = resolve(CharacterClassService::class)->combatResearchLevelsOfClass(
            is_int($personnelle) ? CharacterClass::tryFrom($personnelle) : null
        );

        $alliance = $this->known($histoire->membershipAt($playerId, $instant), $quoi);

        if (!is_int($alliance)) {
            return $niveaux;
        }

        $classes = resolve(AllianceClassService::class);
        $nom = $this->known($histoire->allianceClassAt($alliance, $instant), $quoi);

        return $niveaux + $classes->combatResearchLevelsOfClass($classes->classFromStoredName($nom));
    }

    /**
     * @throws UnknownAdmissionHistory
     */
    private function known(HistoricValue $valeur, string $quoi): int|string|null
    {
        if (!$valeur->isKnown()) {
            throw new UnknownAdmissionHistory(
                $quoi . ' ne peut pas etre gelee a son admission : ' . $valeur->reason
            );
        }

        return $valeur->value();
    }

    private function players(): PlayerServiceFactory
    {
        return $this->players ??= resolve(PlayerServiceFactory::class);
    }

    private function history(): ClassHistoryReader
    {
        return $this->history ??= resolve(ClassHistoryReader::class);
    }

    private function orders(): CausalEventOrderRegistry
    {
        return $this->orders ??= CausalEventOrderRegistry::default();
    }
}
