<?php

namespace OGame\Combat\Services;

use LogicException;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\LootContextForMission;
use OGame\GameMissions\BattleEngine\BattleEngineFactory;
use OGame\Models\CombatInstance;
use OGame\Services\SettingsService;

/**
 * L'engagement : la bataille est calculee une fois, a la cloture, et figee avec sa duree.
 *
 * ## Pourquoi a la cloture, et dans sa transaction
 *
 * Le moteur tire au sort. Calculer la bataille a l'echeance ne redonnerait jamais le meme resultat
 * qu'un calcul fait avant, et le rapport, la duree et le butin regle doivent venir du **meme**
 * calcul. Il a donc lieu au moment ou les rangs sont figes — la cloture du ralliement — sous les
 * memes verrous, et son resultat part avec la photographie : si la cloture est annulee, il n'y a
 * ni participants, ni resultat ; si elle passe, les deux existent.
 *
 * ## Ce qui est fige ici
 *
 * Le resultat lui-meme, par `BattleResultCodec` ; la duree, avec les trois parametres qui l'ont
 * calculee (rythme, plancher, amortissement) — un recalibrage ulterieur ne change pas un combat en
 * cours ; le calendrier des rounds ; et l'echeance, comptee depuis l'instant de cloture, jamais
 * depuis l'horloge du travailleur qui l'execute.
 *
 * ## Ce qui n'est pas decide ici
 *
 * Le butin. Le resultat porte un potentiel ; ce qui sera reellement pris se decide a l'echeance,
 * sur ce qui reste (`CombatSettlementService`). Et les flottes sont celles que la cloture vient
 * d'inscrire, telles qu'elles sont a cet instant : les vaisseaux construits pendant le combat n'y
 * participent pas, par decision de jeu.
 */
final class CombatEngagementService
{
    public function __construct(
        private CombatRosterReader $roster = new CombatRosterReader(),
        private SettingsService|null $settings = null,
        private CombatDurationEstimator|null $estimator = null,
        private LootAllocatorRegistry|null $allocators = null,
    ) {
    }

    /**
     * Calcule la bataille et l'ecrit sur l'instance, sans la sauver : l'appelant tient la transaction.
     *
     * @param CombatInstance $combat Sous verrou, ses participants deja inscrits.
     * @param int $startsAt L'echeance du ralliement : le combat commence la, et sa fin se compte de la.
     */
    public function engage(CombatInstance $combat, int $startsAt): CombatEngagement
    {
        // **Un combat deja engage garde son resultat.** Une cloture rejouee — un travail relivre,
        // un combat remis en ralliement pour reprendre son travail — retrouve la bataille calculee
        // la premiere fois et ne la recalcule pas : le moteur tire au sort, et deux calculs ne
        // donneraient pas le meme resultat. C'est le meme principe que les inclusions : creer, ou
        // relire ce qui existe.
        if ($combat->battle_result !== null) {
            return $this->alreadyEngaged($combat);
        }

        $effectif = $this->roster->forCombat($combat);

        // **Sous les versions du combat**, pas les courantes : un combat ouvert sous V1 se calcule
        // sous V1, meme si une V2 est devenue courante entre l'ouverture et la cloture.
        $versions = FrozenCombatVersionSet::fromInstance($combat);
        $allocation = FrozenLootAllocation::fromFrozenSet($versions, $this->allocators);

        // Le nom est celui des quatre autres sites de combat, et une garde structurelle le lit :
        // un moteur construit sans contexte n'echouerait qu'en jeu, au combat concerne.
        $lootContext = LootContextForMission::lootingOrDegraded(
            $effectif->attackers,
            $effectif->target,
            'attack',
            $combat->mission_id,
            $allocation
        );

        $moteur = BattleEngineFactory::configured(
            $this->settings(),
            $effectif->attackers,
            $effectif->target,
            $effectif->defenders,
            $lootContext
        );
        $moteur->setRetreatAfterDefenderRetreat((bool)$effectif->initiator->retreat_after_defender_retreat);

        $resultat = $moteur->simulateBattle();
        $resultat->attackerPlanetId = (int)$effectif->initiator->planet_id_from;

        $rythme = CombatDurationEstimator::DEFAULT_RATE;
        $plancher = CombatDurationEstimator::DEFAULT_MINIMUM_SECONDS;
        $amortissement = CombatDurationEstimator::DEFAULT_DAMPING;
        $estimation = $this->estimator()->estimate($resultat, $rythme, $plancher, $amortissement);

        $calendrier = [];
        foreach ($estimation->rounds as $round) {
            $calendrier[] = ['round' => $round->number, 'seconds' => $round->seconds, 'work' => $round->work];
        }

        $combat->battle_result = BattleResultCodec::toStorage($resultat);
        $combat->duration_seconds = $estimation->seconds;
        $combat->duration_rate = $rythme;
        $combat->duration_damping = $amortissement;
        $combat->duration_minimum_seconds = $plancher;
        $combat->duration_implausible = $estimation->implausible;
        $combat->round_schedule = $calendrier;
        $combat->ends_at = $startsAt + $estimation->seconds;

        return new CombatEngagement($estimation->seconds, $startsAt + $estimation->seconds, $estimation->implausible, count($estimation->rounds));
    }

    /**
     * L'engagement deja ecrit, relu depuis ses colonnes — ou un refus si elles se contredisent.
     */
    private function alreadyEngaged(CombatInstance $combat): CombatEngagement
    {
        $duree = $combat->duration_seconds;
        $echeance = $combat->ends_at;
        $calendrier = $combat->round_schedule;

        if (!is_int($duree) || !is_int($echeance) || !is_array($calendrier)) {
            throw new LogicException('Le combat ' . $combat->id . ' porte un resultat sans duree, sans echeance ou sans calendrier : il a ete engage a moitie.');
        }

        return new CombatEngagement($duree, $echeance, (bool)$combat->duration_implausible, count($calendrier));
    }

    private function settings(): SettingsService
    {
        return $this->settings ??= resolve(SettingsService::class);
    }

    private function estimator(): CombatDurationEstimator
    {
        return $this->estimator ??= new CombatDurationEstimator();
    }
}
