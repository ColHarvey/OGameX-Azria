<?php

namespace OGame\Combat\Services;

use OGame\Combat\Enums\UnitCharacteristicsRule;
use OGame\Combat\Exceptions\MissingEntryCharacteristics;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\FrozenCombatCharacteristics;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\CombatEntryCharacteristic;
use OGame\Models\CombatInstance;
use OGame\Models\ResearchQueue;
use OGame\Services\ObjectService;

/**
 * Le registre des caracteristiques de combat, prises a l entree de chaque flotte.
 *
 * ## La decision qu il sert
 *
 * Keven, 12 septembre 2026 : le gel se fait **a l arrivee de chaque flotte**. Une flotte qui entre dans
 * un combat durable y apporte ses niveaux d armes, de boucliers et de blindage et le bonus de ses
 * classes **de cet instant** ; une recherche achevee, une classe achetee ou une alliance quittee ensuite
 * ne change plus ses tirs.
 *
 * ## Qui ecrit, et quand
 *
 * Les trois portes d entree : l arrivee d une attaque (`EntersADurableCombat`), celle d une Defense ACS
 * sur un corps en ralliement (`AcsDefendMission`), et les renforts deja poses a l ouverture
 * (`CombatOpeningService`). Puis **la cloture**, pour toute flotte admise que personne n a encore vue :
 * une vague arrivee avant l echeance peut etre inscrite sans que son travailleur soit passe.
 *
 * ## L instant d admission, jamais celui du traitement
 *
 * Chaque ecrivain passe **l instant que les regles fixent** : l arrivee physique, ou l ouverture pour un
 * renfort deja pose — y compris la cloture, qui date une vague de son arrivee. Le moment ou une page ou
 * un travailleur traite la flotte n entre pas dans ce qui s inscrit (revue de Codex, 12 septembre 2026).
 *
 * L ecart n est pas theorique. Le middleware du jeu acheve **d abord** les recherches du joueur, **puis**
 * traite ses missions : une page chargee apres qu une recherche s est achevee — elle-meme achevee apres
 * l arrivee — lirait sur le compte un niveau que la flotte n avait pas. Les trois niveaux sont donc
 * **ramenes a l instant d admission** par l historique des files de recherche.
 *
 * ## Ce que l historique ne dit pas : la classe
 *
 * Aucune table ne garde la classe d un joueur ni celle de son alliance a un instant passe
 * (`character_class_changed_at` et `alliance_class_selected_at` ne gardent que le dernier changement,
 * pas la valeur d avant). Le bonus de classe est donc lu **a l ecriture**. C est une limite, et elle est
 * nommee ici plutot que comblee par une supposition.
 *
 * ## La premiere entree fait foi
 *
 * Une ligne existante n est jamais reecrite, et une seconde observation n est pas une contradiction :
 * c est le monde qui a change depuis l entree, precisement ce que le gel ignore. Toutes les ecritures ont
 * lieu sous les verrous du combat (porte des mouvements, transaction d ouverture ou de cloture) ; la clef
 * unique ferme ce que l ordre des verrous laisserait entrouvert.
 *
 * ## Sous la premiere regle, rien ne s ecrit
 *
 * Un combat ouvert avant cette decision compose ses unites comme avant. Lui ecrire des caracteristiques
 * gelees laisserait croire qu elles servent.
 */
final class CombatEntryCharacteristicsRegistry
{
    public function __construct(
        private PlayerServiceFactory|null $players = null,
    ) {
    }

    /**
     * Inscrit ce que cette flotte apporte a ses tirs, si rien ne l est encore.
     */
    public function recordAtEntry(CombatInstance $combat, int $fleetMissionId, int $playerId, int $enteredAt): void
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

        // **Le joueur relu a neuf**, puis ramene a l instant d admission : une instance gardee par la
        // fabrique porterait un service de classes d avant, et le compte porte peut-etre deja une
        // recherche achevee apres cet instant.
        $vivant = FrozenCombatCharacteristics::ofLivePlayer($this->players()->make($playerId, true));

        $faits = new FrozenCombatCharacteristics(
            $this->researchLevelAt($vivant->weaponLevel, $playerId, 'weapon_technology', $enteredAt),
            $this->researchLevelAt($vivant->shieldLevel, $playerId, 'shielding_technology', $enteredAt),
            $this->researchLevelAt($vivant->armorLevel, $playerId, 'armor_technology', $enteredAt),
            $vivant->classCombatBonus,
        );

        CombatEntryCharacteristic::query()->create([
            'combat_instance_id' => $combat->id,
            'fleet_mission_id' => $fleetMissionId,
            'participant_key' => $cle,
            'player_id' => $playerId,
            ...$faits->toStorage(),
            'entered_at' => $enteredAt,
        ]);
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
     * Le niveau de cette recherche **a l instant d admission**, depuis le niveau que le compte porte.
     *
     * Les files de recherche du joueur, toutes planetes confondues, commencees et non annulees, disent ce
     * que le compte ne dit pas :
     *
     * - une recherche **appliquee** mais achevee a cet instant ou apres n y etait pas : le niveau redescend
     *   sous sa cible ;
     * - une recherche achevee **avant** et **pas encore appliquee** y etait : le niveau monte a sa cible.
     *
     * L egalite vaut « apres », comme pour toutes les barrieres du combat. Un niveau pose hors des files
     * (administration, raccourci de developpement) n a pas d historique : il est pris tel que le compte le
     * porte.
     */
    private function researchLevelAt(int $porte, int $playerId, string $machineName, int $instant): int
    {
        $lignes = ResearchQueue::query()
            ->join('planets', 'planets.id', '=', 'research_queues.planet_id')
            ->where('planets.user_id', $playerId)
            ->where('research_queues.object_id', ObjectService::getResearchObjectByMachineName($machineName)->id)
            ->where('research_queues.building', 1)
            ->where('research_queues.canceled', 0)
            ->get(['research_queues.object_level_target', 'research_queues.time_end', 'research_queues.processed']);

        $niveau = $porte;

        foreach ($lignes as $ligne) {
            $cible = (int)$ligne->object_level_target;
            $fin = (int)$ligne->time_end;
            $appliquee = (int)$ligne->processed === 1;

            if ($appliquee && $fin >= $instant) {
                $niveau = min($niveau, $cible - 1);
            } elseif (!$appliquee && $fin < $instant) {
                $niveau = max($niveau, $cible);
            }
        }

        return max(0, $niveau);
    }

    private function players(): PlayerServiceFactory
    {
        return $this->players ??= resolve(PlayerServiceFactory::class);
    }
}
