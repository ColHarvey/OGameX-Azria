<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Decisions\ArrivalResolver;
use OGame\Combat\Decisions\ArrivingAssets;
use OGame\Combat\Decisions\AssetRecoveryOutcome;
use OGame\Combat\Decisions\CombatDecisionMatrix;
use OGame\Combat\Decisions\CombatSituation;
use OGame\Combat\Decisions\DelegatedOutcomes;
use OGame\Combat\Decisions\FinalArrivalResolution;
use OGame\Combat\Decisions\RallyAdmissionOutcome;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Enums\InvariantCode;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\SnapshotObligation;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Exceptions\ArrivalOutsideMatrixDomain;
use OGame\Combat\Exceptions\ContradictoryDelegatedOutcome;
use OGame\Combat\Exceptions\ImpossibleCombatSituation;
use OGame\Combat\Exceptions\MissingDelegatedOutcome;
use OGame\Combat\Support\ReturnPlan;
use OGame\Models\Planet\Coordinate;
use Tests\UnitTestCase;
use Throwable;

/**
 * Le consommateur des 396 situations : quelqu'un recoit enfin ce que la matrice delegue.
 *
 * ## La reserve que ce fichier leve
 *
 * `CombatDecisionMatrixTest` l'ecrivait noir sur blanc :
 *
 * > Ces essais prouvent que la matrice delegue ; ils ne prouvent pas encore que quelqu'un recoit.
 *
 * Trois des quatre categories de cases ouvertes sont des directives — deleguer est une decision.
 * Mais une delegation ne vaut comme tranchee **que si son consommateur existe et traite
 * exhaustivement ses resultats** ; sans cela, elle n'est qu'un trou sous un autre nom.
 *
 * Ce balayage prend donc chacune des 396 situations, lui fournit chaque reponse que ses mecanismes
 * delegues peuvent rendre, et exige un resultat ferme. Trois issues seulement sont admises :
 *
 *     une FinalArrivalResolution                  -> la case est close
 *     ImpossibleCombatSituation                   -> elle ne peut pas se produire
 *     ArrivalOutsideMatrixDomain                  -> elle releve d'un autre mecanisme
 *
 * Et deux pannes, qui sont des preuves elles aussi : une reponse manquante et une reponse que la
 * question interdisait ne produisent **aucun** comportement.
 *
 * ## Pourquoi un recensement chiffre a la fin
 *
 * Les invariants disent ce qui ne doit jamais arriver ; ils ne disent pas si une case a change de
 * camp. Un recensement des issues, trie et fige, le dit — une case qui bascule deplace deux
 * nombres, et l'ecart se lit a la ligne pres.
 */
class ArrivalResolutionTest extends UnitTestCase
{
    /**
     * Le corps ou les actifs sans destination sont deposes, dans ces essais.
     */
    private const int RECOVERY_BODY = 42;

    /**
     * Le recensement des issues, pour les reponses canoniques.
     *
     * Produit par enumeration, puis relu. « admis » pour l'admission, « applique avant la
     * photographie » pour l'ordre causal : les reponses les plus favorables, celles qui laissent le
     * plus de cases se poser.
     *
     * @var array<string, int>
     */
    private const array CENSUS = [
        'allow_normally | no_combat_effect | hors photo' => 171,
        'allow_normally | no_combat_effect | photo : delivered_cargo' => 3,
        'allow_normally | no_combat_effect | photo : delivered_fleet, delivered_cargo' => 33,
        'defer_impact | rally_closed | hors photo' => 6,
        'join_attack | no_combat_effect | hors photo' => 27,
        'join_attack | no_combat_effect | photo : attacking_fleet' => 9,
        'join_defence | no_combat_effect | photo : defending_fleet' => 3,
        'land_outside_snapshot | own_fleet_coming_home | hors photo' => 66,
        'return_to_origin | position_no_longer_free | hors photo' => 9,
        'return_to_origin | rally_closed | hors photo' => 24,
        'return_to_origin | target_combat_locked | hors photo' => 9,
        'sont hors domaine' => 36,
    ];

    /**
     * Chacune des 396 situations recoit une issue fermee, quelle que soit la reponse deleguee.
     *
     * Les reponses contradictoires sont exclues du balayage et eprouvees separement : elles n'ont
     * pas d'issue, et c'est la leur regle.
     */
    public function testEverySituationIsClosedWhateverTheMechanismsAnswer(): void
    {
        $examinees = 0;
        $fermees = 0;

        foreach (CombatSituation::all() as $situation) {
            $examinees++;

            if (!$situation->isPossible()) {
                $this->assertResolutionThrows(
                    ImpossibleCombatSituation::class,
                    $situation,
                    $this->anyAnswer(),
                    'A situation that cannot occur was resolved as if it were an ordinary arrival'
                );

                continue;
            }

            if ($situation->scope() === TargetScope::DeepSpace) {
                $this->assertResolutionThrows(
                    ArrivalOutsideMatrixDomain::class,
                    $situation,
                    $this->anyAnswer(),
                    'A deep-space arrival was resolved by the celestial-body consumer'
                );

                continue;
            }

            foreach ($this->everyAdmissibleAnswerFor($situation) as $description => $reponses) {
                $resolution = $this->resolve($situation, $reponses);

                $this->assertNotSame(
                    CombatReasonCode::Undecided,
                    $resolution->decision->reason,
                    'An undecided rule reached a player-facing result: ' . $situation->describe() . ' / ' . $description
                );

                $this->assertNotSame(
                    CombatMissionAction::DeferUntilResolved,
                    $resolution->decision->action,
                    'A deferral was published as a result: ' . $situation->describe() . ' / ' . $description
                );

                $fermees++;
            }
        }

        $this->assertSame(396, $examinees, 'The sweep no longer covers every situation.');
        $this->assertGreaterThan(400, $fermees, 'The sweep stopped exercising the delegated answers.');
    }

    /**
     * Une delegation sans reponse ne produit aucun comportement.
     *
     * **C'est la preuve qui compte le plus.** Une valeur par defaut — « admise », « hors
     * photographie » — ferait tourner le jeu sous une regle que personne n'a prononcee, et le
     * defaut resterait invisible jusqu'a ce qu'un joueur le paie.
     */
    public function testADelegationWithoutAnAnswerProducesNoBehaviour(): void
    {
        $reproches = [];

        foreach ($this->resolvableSituations() as $situation) {
            $mouvement = $this->movementOf($situation);

            $exigeUneAdmission = in_array($mouvement, [
                CombatMissionAction::SelectByAttackAdmission,
                CombatMissionAction::SelectByDefenceAdmission,
            ], true);

            // **Chaque mecanisme est mis en cause seul.** Retirer les deux reponses a la fois
            // laisserait le second masquer le premier : une admission qui se rabattrait
            // silencieusement sur « admise » passerait inapercue, puisque l'ordre causal se
            // plaindrait juste apres. La mutation correspondante avait effectivement survecu.
            if ($exigeUneAdmission) {
                $reproches[] = $this->missingMechanismOf(
                    $situation,
                    DelegatedOutcomes::ofCausalOrder(CausalAdmission::AppliedBeforeSnapshot)
                );

                continue;
            }

            if ($mouvement !== CombatMissionAction::SelectByEventOrder
                && $this->obligationOf($situation) !== SnapshotObligation::RequiresCausalDecision) {
                continue;
            }

            $reproches[] = $this->missingMechanismOf($situation, DelegatedOutcomes::none());
        }

        $comptes = array_count_values($reproches);
        ksort($comptes);

        $this->assertSame(
            [
                'ordre causal des evenements' => 48,
                'selecteur d admission' => 12,
            ],
            $comptes,
            'The mechanisms that must answer changed: check that no delegation lost its receiver.'
        );
    }

    /**
     * Une reponse que la question interdisait ne produit aucun comportement non plus.
     *
     * Un missile n'ouvre pas de combat, et il ne peut pas etre etranger a celui sur lequel on vient
     * de l'interroger. Les deux issues possibles — annuler, appliquer — modifient le jeu ; aucune
     * n'a ete decidee, donc aucune n'est prise.
     */
    public function testAnAnswerTheQuestionForbidsProducesNoBehaviourEither(): void
    {
        $contredites = 0;

        foreach ($this->resolvableSituations() as $situation) {
            if ($this->movementOf($situation) !== CombatMissionAction::SelectByEventOrder) {
                continue;
            }

            foreach ([CausalAdmission::FoundingInitiator, CausalAdmission::NotApplicable] as $impossible) {
                $contredites++;

                $this->assertResolutionThrows(
                    ContradictoryDelegatedOutcome::class,
                    $situation,
                    DelegatedOutcomes::ofCausalOrder($impossible),
                    'A contradictory answer was turned into a game rule'
                );
            }
        }

        $this->assertSame(24, $contredites, 'The event-order delegation changed shape.');
    }

    /**
     * Une candidate refusee repart, ou est annulee, mais n'entre jamais dans la photographie.
     */
    public function testARefusedCandidateNeverReachesTheSnapshot(): void
    {
        $refusees = 0;

        foreach ($this->resolvableSituations() as $situation) {
            if (!in_array($this->movementOf($situation), [
                CombatMissionAction::SelectByAttackAdmission,
                CombatMissionAction::SelectByDefenceAdmission,
            ], true)) {
                continue;
            }

            $refusees++;

            $resolution = $this->resolve($situation, DelegatedOutcomes::ofAdmissionAndCausalOrder(
                RallyAdmissionOutcome::refused(CombatReasonCode::AllianceNotEligible),
                CausalAdmission::AppliedBeforeSnapshot
            ));

            $this->assertSame(
                CombatMissionAction::ReturnToOrigin,
                $resolution->decision->action,
                'A refused candidate did something other than turn back: ' . $situation->describe()
            );

            $this->assertSame(
                CombatReasonCode::AllianceNotEligible,
                $resolution->decision->reason,
                'The refusal reason was lost on the way to the player: ' . $situation->describe()
            );

            $this->assertFalse(
                $resolution->snapshot->included,
                'A refused candidate reached the snapshot: ' . $situation->describe()
            );
        }

        $this->assertSame(12, $refusees, 'The rally-admission delegation changed shape.');
    }

    /**
     * Une candidate admise entre dans la photographie et retient la fenetre.
     *
     * **Seule une candidate retenue par la selection prolonge le ralliement.** Un retour ou un
     * transport arrive a temps y figure sans jamais l'avoir fixe : la nuance est ce qui empeche une
     * flotte de maintenir la fenetre ouverte pour s'y inclure elle-meme.
     */
    public function testAnAdmittedCandidateEntersTheSnapshotAndHoldsTheWindow(): void
    {
        $admises = 0;
        $incidentes = 0;

        foreach ($this->resolvableSituations() as $situation) {
            $mouvement = $this->movementOf($situation);

            if (in_array($mouvement, [
                CombatMissionAction::SelectByAttackAdmission,
                CombatMissionAction::SelectByDefenceAdmission,
            ], true)) {
                $admises++;

                $resolution = $this->resolve($situation, DelegatedOutcomes::ofAdmissionAndCausalOrder(
                    RallyAdmissionOutcome::admitted(),
                    CausalAdmission::AppliedBeforeSnapshot
                ));

                $this->assertTrue(
                    $resolution->snapshot->included,
                    'An admitted candidate was left out of its own battle: ' . $situation->describe()
                );

                $this->assertTrue(
                    $resolution->snapshot->extendsRallyWindow(),
                    'An admitted candidate did not hold the window it was admitted into: ' . $situation->describe()
                );

                continue;
            }

            if ($this->obligationOf($situation) !== SnapshotObligation::RequiresCausalDecision) {
                continue;
            }

            $incidentes++;

            $resolution = $this->resolve(
                $situation,
                DelegatedOutcomes::ofCausalOrder(CausalAdmission::AppliedBeforeSnapshot)
            );

            $this->assertFalse(
                $resolution->snapshot->extendsRallyWindow(),
                'A passing arrival held the rally window open, and could then include itself: ' . $situation->describe()
            );
        }

        $this->assertSame(12, $admises);
        $this->assertGreaterThan(0, $incidentes, 'No incidental arrival was exercised during the rally.');
    }

    /**
     * Un missile deja applique n'est ni rejoue, ni compte une seconde fois.
     *
     * Ses degats sont dans les defenses que la photographie lira. L'ancienne confusion entre « deja
     * reflete » et « admissible » les aurait retranches deux fois.
     */
    public function testAMissileAlreadyReflectedIsNeitherReplayedNorCountedTwice(): void
    {
        $situation = $this->aMissileDuringTheRally();

        $rejoue = $this->resolve(
            $situation,
            DelegatedOutcomes::ofCausalOrder(CausalAdmission::AppliedBeforeSnapshot)
        );

        $deja = $this->resolve(
            $situation,
            DelegatedOutcomes::ofCausalOrder(CausalAdmission::AlreadyInOpeningState)
        );

        $this->assertFalse(
            $rejoue->decision->action === CombatMissionAction::DeferUntilResolved,
            'An applicable missile was deferred instead of applied.'
        );

        $this->assertSame(
            CausalAdmission::AlreadyInOpeningState,
            $deja->causalAdmission,
            'The resolution lost the very fact that tells a replay from a first application.'
        );

        // **Le fait qui empeche le double comptage.** Les deux issues portent la meme action et la
        // meme raison ; seul ce drapeau dit a l appelant de ne pas appliquer l impact une seconde
        // fois. Sans lui, un missile deja tombe retrancherait les memes defenses deux fois.
        $this->assertFalse($rejoue->alreadyApplied, 'A missile that had never landed was treated as already applied.');
        $this->assertTrue(
            $deja->alreadyApplied,
            'A missile already reflected in the opening state was indistinguishable from one still to apply.'
        );

        foreach ([$rejoue, $deja] as $resolution) {
            $this->assertFalse(
                $resolution->snapshot->included,
                'A missile declared its damage a second time, on top of the defences the snapshot already reads.'
            );
        }
    }

    /**
     * Un missile engage apres l'ouverture est annule et signale, jamais applique en silence.
     */
    public function testAMissileCreatedAfterTheLockIsCancelledAndFlagged(): void
    {
        $resolution = $this->resolve(
            $this->aMissileDuringTheRally(),
            DelegatedOutcomes::ofCausalOrder(CausalAdmission::OutsideSnapshot)
        );

        $this->assertSame(CombatMissionAction::CancelWithoutImpact, $resolution->decision->action);
        $this->assertSame(CombatReasonCode::TargetCombatLocked, $resolution->decision->reason);

        $this->assertSame(
            InvariantCode::EffectCreatedAfterTheLock,
            $resolution->alert,
            'A race that should not have been possible passed without leaving a trace.'
        );
    }

    /**
     * Une flotte chargee sans destination est recuperee, jamais supprimee en silence.
     *
     * Deux chemins y menent, et il fallait les deux : la matrice quand le plan de retour est vide,
     * et le refus d'admission d'une attaque partie d'une lune detruite pendant son vol.
     */
    public function testALoadedFleetWithNowhereToGoIsRecoveredOnBothPaths(): void
    {
        $parLaMatrice = $this->resolve(
            new CombatSituation(
                CombatMissionKind::Transport,
                FlightLeg::Return,
                ActorKind::Player,
                CombatState::Active
            ),
            DelegatedOutcomes::ofAssetRecovery(AssetRecoveryOutcome::recoveredInto(self::RECOVERY_BODY)),
            $this->anImpossibleReturn()
        );

        $this->assertSame(self::RECOVERY_BODY, $parLaMatrice->recoveredIntoBodyId);
        $this->assertSame(CombatMissionAction::CancelWithoutImpact, $parLaMatrice->decision->action);

        $parLeRefus = $this->resolve(
            new CombatSituation(
                CombatMissionKind::Attack,
                FlightLeg::Outbound,
                ActorKind::Player,
                CombatState::Rallying
            ),
            DelegatedOutcomes::ofAdmissionAndAssetRecovery(
                RallyAdmissionOutcome::refused(CombatReasonCode::AllianceNotEligible),
                AssetRecoveryOutcome::recoveredInto(self::RECOVERY_BODY)
            ),
            $this->anImpossibleReturn()
        );

        $this->assertSame(
            self::RECOVERY_BODY,
            $parLeRefus->recoveredIntoBodyId,
            'A refused attack whose origin had been destroyed was deleted with its cargo.'
        );

        $this->assertSame(
            CombatReasonCode::AllianceNotEligible,
            $parLeRefus->decision->reason,
            'The player was told he had no destination, when what happened is that he was refused.'
        );
    }

    /**
     * Le recensement des issues, fige.
     *
     * Les invariants ci-dessus disent ce qui ne doit jamais arriver. Celui-ci dit ce qui arrive :
     * une case qui change de camp deplace deux nombres, et l'ecart se lit a la ligne pres.
     */
    public function testTheFrozenCensusOfEveryOutcome(): void
    {
        $recensement = ['sont hors domaine' => 0];

        foreach (CombatSituation::all() as $situation) {
            if (!$situation->isPossible() || $situation->scope() === TargetScope::DeepSpace) {
                $recensement['sont hors domaine']++;

                continue;
            }

            $resolution = $this->resolve($situation, $this->canonicalAnswers());
            $empreinte = $this->fingerprintOf($resolution);

            $recensement[$empreinte] = ($recensement[$empreinte] ?? 0) + 1;
        }

        ksort($recensement);

        $this->assertSame(self::CENSUS, $recensement, 'The census of outcomes changed.');
        $this->assertSame(396, array_sum($recensement), 'The census no longer covers every situation.');
    }

    /**
     * Le mecanisme qui s'est plaint de ne pas avoir de reponse.
     *
     * Echoue si la resolution aboutit : une delegation sans reponse ne doit produire aucun
     * comportement, pas meme un comportement plausible.
     */
    private function missingMechanismOf(CombatSituation $situation, DelegatedOutcomes $outcomes): string
    {
        try {
            $this->resolve($situation, $outcomes);
        } catch (MissingDelegatedOutcome $manquante) {
            return $manquante->mechanism;
        }

        $this->fail(
            'A delegated case was resolved although its mechanism had not answered: ' . $situation->describe()
        );
    }

    /**
     * Les situations que le consommateur peut reellement resoudre.
     *
     * @return array<int, CombatSituation>
     */
    private function resolvableSituations(): array
    {
        return array_values(array_filter(
            CombatSituation::all(),
            static fn (CombatSituation $s): bool => $s->isPossible() && $s->scope() !== TargetScope::DeepSpace
        ));
    }

    /**
     * Toutes les reponses admissibles pour cette situation, contradictions exclues.
     *
     * @param CombatSituation $situation
     * @return array<string, DelegatedOutcomes>
     */
    private function everyAdmissibleAnswerFor(CombatSituation $situation): array
    {
        $mouvement = $this->movementOf($situation);
        $obligation = $this->obligationOf($situation);

        $admissions = in_array($mouvement, [
            CombatMissionAction::SelectByAttackAdmission,
            CombatMissionAction::SelectByDefenceAdmission,
        ], true)
            ? [
                'admise' => RallyAdmissionOutcome::admitted(),
                'refusee' => RallyAdmissionOutcome::refused(CombatReasonCode::AllianceNotEligible),
            ]
            : ['sans admission' => null];

        $causales = [];

        if ($mouvement === CombatMissionAction::SelectByEventOrder) {
            // Les deux reponses contradictoires sont eprouvees ailleurs : ici, seules celles qui ont
            // une issue.
            $causales = [
                'appliquee avant' => CausalAdmission::AppliedBeforeSnapshot,
                'deja refletee' => CausalAdmission::AlreadyInOpeningState,
                'hors photo' => CausalAdmission::OutsideSnapshot,
            ];
        } elseif ($obligation === SnapshotObligation::RequiresCausalDecision) {
            foreach (CausalAdmission::cases() as $cas) {
                $causales[$cas->value] = $cas;
            }
        } else {
            $causales = ['sans ordre causal' => null];
        }

        $reponses = [];

        foreach ($admissions as $nomAdmission => $admission) {
            foreach ($causales as $nomCausal => $causal) {
                $reponses[$nomAdmission . ' / ' . $nomCausal] = $this->outcomesOf($admission, $causal);
            }
        }

        return $reponses;
    }

    /**
     * Les reponses canoniques : admise, et appliquee avant la photographie.
     */
    private function canonicalAnswers(): DelegatedOutcomes
    {
        return $this->outcomesOf(RallyAdmissionOutcome::admitted(), CausalAdmission::AppliedBeforeSnapshot);
    }

    /**
     * Toutes les reponses a la fois : pour les cas ou l'on veut voir l'exception, pas la reponse.
     */
    private function anyAnswer(): DelegatedOutcomes
    {
        return $this->canonicalAnswers();
    }

    /**
     * Un jeu de reponses, avec la recuperation d'actifs toujours disponible.
     */
    private function outcomesOf(
        RallyAdmissionOutcome|null $admission,
        CausalAdmission|null $causal,
    ): DelegatedOutcomes {
        if ($admission !== null && $causal !== null) {
            return DelegatedOutcomes::ofAdmissionAndCausalOrder($admission, $causal);
        }

        if ($admission !== null) {
            return DelegatedOutcomes::ofRallyAdmission($admission);
        }

        if ($causal !== null) {
            return DelegatedOutcomes::ofCausalOrder($causal);
        }

        return DelegatedOutcomes::ofAssetRecovery(AssetRecoveryOutcome::recoveredInto(self::RECOVERY_BODY));
    }

    /**
     * L'issue d'une situation, sous une forme comparable.
     */
    private function fingerprintOf(FinalArrivalResolution $resolution): string
    {
        $photo = $resolution->snapshot->included
            ? 'photo : ' . implode(', ', array_map(
                static fn (SnapshotContribution $c): string => $c->value,
                $resolution->snapshot->contributions()
            ))
            : 'hors photo';

        return $resolution->decision->action->value
            . ' | ' . $resolution->decision->reason->value
            . ' | ' . $photo;
    }

    /**
     * Le mouvement que la matrice rend pour cette situation, continuation deroulee.
     */
    private function movementOf(CombatSituation $situation): CombatMissionAction
    {
        $mouvement = (new CombatDecisionMatrix())->verdictOf(
            $situation,
            $this->aPossibleReturn(),
            ArrivingAssets::fleetWithCargo()
        )->movement;

        return ($mouvement->continuation() ?? $mouvement)->action();
    }

    /**
     * L'obligation de photographie que la matrice attache a cette situation.
     */
    private function obligationOf(CombatSituation $situation): SnapshotObligation
    {
        return (new CombatDecisionMatrix())->verdictOf(
            $situation,
            $this->aPossibleReturn(),
            ArrivingAssets::fleetWithCargo()
        )->snapshot;
    }

    /**
     * Resout une situation, avec un plan de retour praticable par defaut.
     */
    private function resolve(
        CombatSituation $situation,
        DelegatedOutcomes $outcomes,
        ReturnPlan|null $returnPlan = null,
    ): FinalArrivalResolution {
        return (new ArrivalResolver())->resolve(
            $situation,
            $returnPlan ?? $this->aPossibleReturn(),
            ArrivingAssets::fleetWithCargo(),
            $outcomes
        );
    }

    /**
     * Verifie qu'une resolution leve bien l'exception attendue.
     *
     * @param class-string<Throwable> $expected
     */
    private function assertResolutionThrows(
        string $expected,
        CombatSituation $situation,
        DelegatedOutcomes $outcomes,
        string $message,
    ): void {
        try {
            $this->resolve($situation, $outcomes);
        } catch (Throwable $leve) {
            $this->assertInstanceOf(
                $expected,
                $leve,
                $message . ' : ' . $situation->describe() . ' (' . $leve::class . ')'
            );

            return;
        }

        $this->fail($message . ' : ' . $situation->describe());
    }

    /**
     * Un missile pendant le ralliement : la seule case ou l'ordre causal decide d'un impact.
     */
    private function aMissileDuringTheRally(): CombatSituation
    {
        return new CombatSituation(
            CombatMissionKind::Missile,
            FlightLeg::Outbound,
            ActorKind::Player,
            CombatState::Rallying
        );
    }

    /**
     * Un plan de retour praticable, vers la planete mere.
     */
    private function aPossibleReturn(): ReturnPlan
    {
        return ReturnPlan::toHomeworld(1, new Coordinate(1, 1, 1), 1);
    }

    /**
     * Un plan de retour epuise : le compte n'a plus aucun corps ou se poser.
     */
    private function anImpossibleReturn(): ReturnPlan
    {
        return ReturnPlan::cannotReturn(CombatReasonCode::NoReturnDestination);
    }
}
