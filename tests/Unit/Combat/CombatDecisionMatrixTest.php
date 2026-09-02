<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Decisions\ArrivalDecision;
use OGame\Combat\Decisions\ArrivalVerdict;
use OGame\Combat\Decisions\ArrivingAssets;
use OGame\Combat\Decisions\CombatDecisionMatrix;
use OGame\Combat\Decisions\CombatSituation;
use OGame\Combat\Decisions\FinalCombatDecision;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\DecisionRequirement;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Enums\InvariantCode;
use OGame\Combat\Enums\OpenCellCategory;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\SnapshotObligation;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Exceptions\ImpossibleCombatSituation;
use OGame\Combat\Exceptions\NonFinalCombatReason;
use OGame\Combat\Support\ReturnPlan;
use OGame\Models\Planet\Coordinate;
use Tests\UnitTestCase;

/**
 * Les 396 cases : ce qu'elles decident, et ce qu'elles delegent.
 *
 * ## Pourquoi un manifeste et pas un compte
 *
 * Un compte ne prouve rien : une case pourrait se fermer pendant qu'une autre se rouvre, et le
 * total ne bougerait pas. Le manifeste fige les **identites exactes** avec leur categorie, trie ;
 * toute case qui change de camp se voit ligne a ligne.
 *
 * ## Trois des quatre categories sont des decisions fermees
 *
 * `NeedsRallyAdmission`, `NeedsCausalEligibility` et `StructurallyNotApplicable` sont des
 * directives : la matrice a tranche, et ce qu'elle a tranche est de deleguer a un mecanisme nomme.
 * Seule `MissingRule` designe un trou — et il doit rester vide.
 *
 * Une reserve qui vaut d'etre ecrite : une delegation ne compte comme tranchee **que si son
 * consommateur existe et traite exhaustivement ses resultats**. Ces essais prouvent que la matrice
 * delegue ; ils ne prouvent pas encore que quelqu'un recoit.
 */
class CombatDecisionMatrixTest extends UnitTestCase
{
    /**
     * Les identites exactes de toutes les cases qui ne rendent pas une action immediate.
     *
     * Recopier cette liste a la main serait une source d'erreurs ; elle a ete produite par
     * enumeration, puis relue. Elle est triee pour qu'un ecart se lise a la ligne pres.
     *
     * @var array<int, string>
     */
    private const array MANIFEST = [
        'NeedsCausalEligibility | missile / outbound / npc / rallying',
        'NeedsCausalEligibility | missile / outbound / player / rallying',
        'NeedsCausalEligibility | missile / outbound / system / rallying',
        'NeedsCausalEligibility | recycle / outbound / npc / active',
        'NeedsCausalEligibility | recycle / outbound / npc / rallying',
        'NeedsCausalEligibility | recycle / outbound / player / active',
        'NeedsCausalEligibility | recycle / outbound / player / rallying',
        'NeedsCausalEligibility | recycle / outbound / system / active',
        'NeedsCausalEligibility | recycle / outbound / system / rallying',
        'NeedsRallyAdmission | acs_attack / outbound / npc / rallying',
        'NeedsRallyAdmission | acs_attack / outbound / player / rallying',
        'NeedsRallyAdmission | acs_attack / outbound / system / rallying',
        'NeedsRallyAdmission | acs_defend / outbound / npc / rallying',
        'NeedsRallyAdmission | acs_defend / outbound / player / rallying',
        'NeedsRallyAdmission | acs_defend / outbound / system / rallying',
        'NeedsRallyAdmission | attack / outbound / npc / rallying',
        'NeedsRallyAdmission | attack / outbound / player / rallying',
        'NeedsRallyAdmission | attack / outbound / system / rallying',
        'NeedsRallyAdmission | moon_destruction / outbound / npc / rallying',
        'NeedsRallyAdmission | moon_destruction / outbound / player / rallying',
        'NeedsRallyAdmission | moon_destruction / outbound / system / rallying',
        'StructurallyNotApplicable | expedition / outbound / npc / active',
        'StructurallyNotApplicable | expedition / outbound / npc / aucun combat',
        'StructurallyNotApplicable | expedition / outbound / npc / cancelled',
        'StructurallyNotApplicable | expedition / outbound / npc / rallying',
        'StructurallyNotApplicable | expedition / outbound / npc / resolved',
        'StructurallyNotApplicable | expedition / outbound / npc / resolving',
        'StructurallyNotApplicable | expedition / outbound / player / active',
        'StructurallyNotApplicable | expedition / outbound / player / aucun combat',
        'StructurallyNotApplicable | expedition / outbound / player / cancelled',
        'StructurallyNotApplicable | expedition / outbound / player / rallying',
        'StructurallyNotApplicable | expedition / outbound / player / resolved',
        'StructurallyNotApplicable | expedition / outbound / player / resolving',
        'StructurallyNotApplicable | expedition / outbound / system / active',
        'StructurallyNotApplicable | expedition / outbound / system / aucun combat',
        'StructurallyNotApplicable | expedition / outbound / system / cancelled',
        'StructurallyNotApplicable | expedition / outbound / system / rallying',
        'StructurallyNotApplicable | expedition / outbound / system / resolved',
        'StructurallyNotApplicable | expedition / outbound / system / resolving',
        'StructurallyNotApplicable | missile / return / npc / active',
        'StructurallyNotApplicable | missile / return / npc / aucun combat',
        'StructurallyNotApplicable | missile / return / npc / cancelled',
        'StructurallyNotApplicable | missile / return / npc / rallying',
        'StructurallyNotApplicable | missile / return / npc / resolved',
        'StructurallyNotApplicable | missile / return / npc / resolving',
        'StructurallyNotApplicable | missile / return / player / active',
        'StructurallyNotApplicable | missile / return / player / aucun combat',
        'StructurallyNotApplicable | missile / return / player / cancelled',
        'StructurallyNotApplicable | missile / return / player / rallying',
        'StructurallyNotApplicable | missile / return / player / resolved',
        'StructurallyNotApplicable | missile / return / player / resolving',
        'StructurallyNotApplicable | missile / return / system / active',
        'StructurallyNotApplicable | missile / return / system / aucun combat',
        'StructurallyNotApplicable | missile / return / system / cancelled',
        'StructurallyNotApplicable | missile / return / system / rallying',
        'StructurallyNotApplicable | missile / return / system / resolved',
        'StructurallyNotApplicable | missile / return / system / resolving',
    ];

    /**
     * La matrice couvre exactement 396 situations, sans trou ni doublon.
     */
    public function testTheMatrixCoversExactlyThreeHundredAndNinetySixSituations(): void
    {
        $situations = CombatSituation::all();

        $this->assertCount(396, $situations);

        $identites = [];

        foreach ($situations as $situation) {
            $identites[$situation->describe()] = true;
        }

        $this->assertCount(396, $identites, 'Two situations describe themselves identically.');
    }

    /**
     * Aucune case ne reste sans regle.
     *
     * C'est l'article « zero decision ouverte », rendu verifiable. Il ne dit pas que chaque case
     * rend une action : il dit qu'aucune ne rend une question.
     */
    public function testNoCellIsLeftWithoutARule(): void
    {
        $sansRegle = [];

        foreach (CombatSituation::all() as $situation) {
            $decision = $this->arrivalOf($situation);

            if ($decision->openCellCategory() === OpenCellCategory::MissingRule) {
                $sansRegle[] = (string)$decision->openQuestion();
            }
        }

        $this->assertSame(
            [],
            $sansRegle,
            "Cells were left without a rule. A matrix that answers « let it through » to a forgotten cell "
            . "turns a design hole into production behaviour.\n\n" . implode("\n", $sansRegle)
        );
    }

    /**
     * Le manifeste trie des cases qui delegent, identite par identite.
     */
    public function testTheSortedManifestOfDelegatedCells(): void
    {
        $manifeste = [];

        foreach (CombatSituation::all() as $situation) {
            $categorie = $this->arrivalOf($situation)->openCellCategory();

            if ($categorie !== null) {
                $manifeste[] = $categorie->name . ' | ' . $situation->describe();
            }
        }

        sort($manifeste);

        $this->assertSame(
            self::MANIFEST,
            $manifeste,
            'A cell changed category. One closing while another reopens would leave the count untouched: '
            . 'this is why the identities are pinned rather than the number.'
        );
    }

    /**
     * Un corps libre laisse passer tout ce qui ne dispute pas sa possession.
     */
    public function testAFreeBodyLetsThroughEverythingThatDoesNotDisputeIt(): void
    {
        foreach ([null, CombatState::Resolved, CombatState::Cancelled] as $etat) {
            foreach (CombatMissionKind::cases() as $mission) {
                foreach (FlightLeg::cases() as $etape) {
                    $situation = new CombatSituation($mission, $etape, ActorKind::Player, $etat);

                    if (!$situation->isPossible() || $situation->scope() === TargetScope::DeepSpace) {
                        continue;
                    }

                    $attendue = $etape === FlightLeg::Outbound && $mission->opensCombat()
                        ? CombatMissionAction::JoinAttack
                        : CombatMissionAction::AllowNormally;

                    $this->assertSame($attendue, $this->arrivalOf($situation)->action(), $situation->describe());
                }
            }
        }
    }

    /**
     * Un evenement differe pendant la resolution porte deja ce qu'on en fera.
     *
     * **La regression la plus couteuse que ces essais protegent.** Differer puis rejouer l'arrivee
     * telle quelle la ferait retomber sur un corps devenu libre : une attaque tardive y ouvrirait un
     * second combat, c'est-a-dire la file d'attente que le jeu refuse.
     */
    public function testADeferredEventAlreadyCarriesWhatWillBeDoneWithIt(): void
    {
        $examinees = 0;

        foreach (CombatSituation::all() as $situation) {
            if ($situation->targetState !== CombatState::Resolving || !$situation->isPossible()) {
                continue;
            }

            if ($situation->scope() === TargetScope::DeepSpace) {
                continue;
            }

            $examinees++;
            $decision = $this->arrivalOf($situation);

            $this->assertSame(CombatMissionAction::DeferUntilResolved, $decision->action(), $situation->describe());
            $this->assertSame(CombatReasonCode::ResolutionInProgress, $decision->reason());

            $suite = $decision->continuation();

            $this->assertNotNull($suite, 'A deferred arrival carries no continuation: ' . $situation->describe());

            // La continuation est celle d'un evenement **tardif**, jamais celle d'une arrivee sur un
            // corps libre. C'est la difference exacte entre « repartir » et « ouvrir un second combat ».
            $tardive = new CombatSituation(
                $situation->mission,
                $situation->leg,
                $situation->actor,
                CombatState::Active
            );

            $this->assertSame(
                $this->arrivalOf($tardive)->action(),
                $suite->action(),
                'The continuation is not the late-event decision: ' . $situation->describe()
            );
        }

        $this->assertGreaterThan(0, $examinees, 'No deferred cell was examined: this test would prove nothing.');
    }

    /**
     * Une attaque tardive n'ouvre jamais un second combat.
     *
     * Enonce separement de l'essai precedent, parce que c'est la regle de jeu et non le mecanisme :
     * elle doit rester lisible meme si la mecanique de continuation change.
     */
    public function testALateAttackNeverOpensASecondCombat(): void
    {
        foreach ([CombatState::Active, CombatState::Resolving] as $etat) {
            foreach ([CombatMissionKind::Attack, CombatMissionKind::AcsAttack, CombatMissionKind::MoonDestruction] as $mission) {
                $decision = $this->arrivalOf(
                    new CombatSituation($mission, FlightLeg::Outbound, ActorKind::Player, $etat)
                );

                $effective = $decision->continuation() ?? $decision;

                $this->assertNotSame(
                    CombatMissionAction::JoinAttack,
                    $effective->action(),
                    'A late ' . $mission->value . ' during ' . $etat->value . ' would open a second combat.'
                );

                $this->assertSame(CombatMissionAction::ReturnToOrigin, $effective->action());
                $this->assertSame(CombatReasonCode::RallyClosed, $effective->reason());
            }
        }
    }

    /**
     * Pendant le ralliement : ce qui est admis, ce qui rentre, ce qui delegue.
     */
    public function testWhatHappensWhileTheWindowIsOpen(): void
    {
        $attendus = [
            'attaque' => [CombatMissionKind::Attack, FlightLeg::Outbound, CombatMissionAction::SelectByAttackAdmission],
            'attaque groupee' => [CombatMissionKind::AcsAttack, FlightLeg::Outbound, CombatMissionAction::SelectByAttackAdmission],
            'destruction de lune' => [CombatMissionKind::MoonDestruction, FlightLeg::Outbound, CombatMissionAction::SelectByAttackAdmission],

            // Le camp defenseur a ses propres budgets, donc son propre selecteur.
            'defense groupee' => [CombatMissionKind::AcsDefend, FlightLeg::Outbound, CombatMissionAction::SelectByDefenceAdmission],

            // La livraison entre avant la photographie : reservable et pillable. Les transporteurs
            // repartent et ne deviennent jamais defenseurs.
            'transport' => [CombatMissionKind::Transport, FlightLeg::Outbound, CombatMissionAction::AllowNormally],

            // Flotte et cargaison rejoignent l'etat global de la cible, sans prolonger la fenetre.
            'deploiement' => [CombatMissionKind::Deployment, FlightLeg::Outbound, CombatMissionAction::AllowNormally],

            // Retour intact : ni espionnage, ni contre-espionnage, ni rapport.
            'espionnage' => [CombatMissionKind::Espionage, FlightLeg::Outbound, CombatMissionAction::ReturnToOrigin],

            // Seul l'ordre des evenements distingue l'impact legitime de l'anomalie.
            'missile' => [CombatMissionKind::Missile, FlightLeg::Outbound, CombatMissionAction::SelectByEventOrder],

            // Le champ de debris n'herite d'aucun verrou, mais l'ordre temporel s'impose.
            'recyclage' => [CombatMissionKind::Recycle, FlightLeg::Outbound, CombatMissionAction::SelectByEventOrder],

            // La position a cesse d'etre libre : la colonisation echoue et la flotte revient.
            'colonisation' => [CombatMissionKind::Colonisation, FlightLeg::Outbound, CombatMissionAction::ReturnToOrigin],

            // Tout vrai retour ayant une destination valide atterrit. Son appartenance a la
            // photographie se decide ailleurs, et il ne prolonge jamais la fenetre.
            'retour' => [CombatMissionKind::Attack, FlightLeg::Return, CombatMissionAction::AllowNormally],
            'retour d expedition' => [CombatMissionKind::Expedition, FlightLeg::Return, CombatMissionAction::AllowNormally],
        ];

        foreach ($attendus as $quoi => [$mission, $etape, $action]) {
            $situation = new CombatSituation($mission, $etape, ActorKind::Player, CombatState::Rallying);

            $this->assertSame(
                $action,
                $this->arrivalOf($situation)->action(),
                "The rule for « {$quoi} » during the rally window changed."
            );
        }
    }

    /**
     * Apres la photographie : les tardifs repartent, les vaisseaux du proprietaire se posent.
     */
    public function testWhatHappensOnceTheSnapshotIsTaken(): void
    {
        $attendus = [
            'attaque en retard' => [CombatMissionKind::Attack, FlightLeg::Outbound, CombatMissionAction::ReturnToOrigin],
            'attaque groupee en retard' => [CombatMissionKind::AcsAttack, FlightLeg::Outbound, CombatMissionAction::ReturnToOrigin],
            'destruction de lune en retard' => [CombatMissionKind::MoonDestruction, FlightLeg::Outbound, CombatMissionAction::ReturnToOrigin],

            // Elle ne doit jamais stationner immunisee au-dessus d'un combat en cours.
            'defense groupee en retard' => [CombatMissionKind::AcsDefend, FlightLeg::Outbound, CombatMissionAction::ReturnToOrigin],

            'espionnage' => [CombatMissionKind::Espionage, FlightLeg::Outbound, CombatMissionAction::ReturnToOrigin],
            'colonisation' => [CombatMissionKind::Colonisation, FlightLeg::Outbound, CombatMissionAction::ReturnToOrigin],

            // Livraison normale, mais hors butin et hors reservation de ce combat.
            'transport' => [CombatMissionKind::Transport, FlightLeg::Outbound, CombatMissionAction::AllowNormally],

            'retour personnel' => [CombatMissionKind::Attack, FlightLeg::Return, CombatMissionAction::LandOutsideSnapshot],
            'transport qui rentre' => [CombatMissionKind::Transport, FlightLeg::Return, CombatMissionAction::LandOutsideSnapshot],
            'deploiement' => [CombatMissionKind::Deployment, FlightLeg::Outbound, CombatMissionAction::LandOutsideSnapshot],

            'missile' => [CombatMissionKind::Missile, FlightLeg::Outbound, CombatMissionAction::DeferImpact],
            'recyclage' => [CombatMissionKind::Recycle, FlightLeg::Outbound, CombatMissionAction::SelectByEventOrder],
        ];

        foreach ($attendus as $quoi => [$mission, $etape, $action]) {
            $situation = new CombatSituation($mission, $etape, ActorKind::Player, CombatState::Active);

            $this->assertSame(
                $action,
                $this->arrivalOf($situation)->action(),
                "The rule for « {$quoi} » after closure changed."
            );
        }
    }

    /**
     * Une egalite de coordonnees ne fait heriter d'aucun verrou.
     *
     * Un champ de debris et une position de colonisation peuvent partager les coordonnees d'une
     * planete assiegee. Aucun des deux ne doit recevoir une action qui n'a de sens que pour un corps
     * celeste verrouille : se poser hors photographie, ou differer un impact.
     */
    public function testCoordinateEqualityGrantsNoLock(): void
    {
        $actionsDeCorpsVerrouille = [
            CombatMissionAction::LandOutsideSnapshot,
            CombatMissionAction::DeferImpact,
            CombatMissionAction::JoinAttack,
            CombatMissionAction::JoinDefence,
        ];

        foreach (CombatSituation::all() as $situation) {
            if ($situation->scope() === TargetScope::CelestialBody || !$situation->isPossible()) {
                continue;
            }

            if ($situation->scope() === TargetScope::DeepSpace) {
                $this->assertSame(
                    CombatMissionAction::OutsideMatrixDomain,
                    $this->arrivalOf($situation)->action(),
                    'Deep space was treated as a celestial body: ' . $situation->describe()
                );

                continue;
            }

            $decision = $this->arrivalOf($situation);
            $effective = $decision->continuation() ?? $decision;

            $this->assertNotContains(
                $effective->action(),
                $actionsDeCorpsVerrouille,
                'A non-celestial target inherited a celestial body lock: ' . $situation->describe()
            );
        }
    }

    /**
     * Les situations impossibles se rangent dans une enumeration et levent sur un chemin vivant.
     */
    public function testImpossibleSituationsAreFiledButNeverLived(): void
    {
        $impossibles = [
            'missile qui rentre' => new CombatSituation(
                CombatMissionKind::Missile,
                FlightLeg::Return,
                ActorKind::Player,
                null
            ),
            'expedition qui rencontre un combat' => new CombatSituation(
                CombatMissionKind::Expedition,
                FlightLeg::Outbound,
                ActorKind::Player,
                CombatState::Active
            ),
        ];

        foreach ($impossibles as $quoi => $situation) {
            $this->assertFalse($situation->isPossible(), "« {$quoi} » was declared possible.");

            $this->assertSame(
                OpenCellCategory::StructurallyNotApplicable,
                $this->arrivalOf($situation)->openCellCategory(),
                "« {$quoi} » was not filed as structurally not applicable."
            );
        }

        // Sur un chemin vivant, la meme situation revele un defaut en amont : elle leve.
        $this->expectException(ImpossibleCombatSituation::class);

        $impossibles['missile qui rentre']->ensureItCanOccur();
    }

    /**
     * Un retour d'expedition releve pleinement du verrou.
     *
     * L'aller vise l'espace profond, mais la flotte rentre sur un corps celeste — qui peut etre
     * assiege. Faire dependre la portee du seul genre de mission ferait sortir ces retours du
     * domaine du verrou alors qu'ils y sont.
     */
    public function testAnExpeditionComingHomeIsFullyUnderTheLock(): void
    {
        $retour = new CombatSituation(
            CombatMissionKind::Expedition,
            FlightLeg::Return,
            ActorKind::Player,
            CombatState::Active
        );

        $this->assertSame(TargetScope::CelestialBody, $retour->scope());
        $this->assertSame(CombatMissionAction::LandOutsideSnapshot, $this->arrivalOf($retour)->action());
    }

    /**
     * Le genre d'acteur ne change aucune case **de la matrice de base**.
     *
     * La reserve compte. Le resultat final, lui, depend bien de l'acteur : un pirate n'est pas
     * renforcable, et un acteur sans destination doit etre annule plutot que renvoye. Ces
     * differences se produisent dans l'admission collective et dans `ReturnPlan`, pas ici.
     */
    public function testTheActorKindChangesNoCellOfTheBaseMatrix(): void
    {
        foreach (CombatMissionKind::cases() as $mission) {
            foreach (FlightLeg::cases() as $etape) {
                foreach ([null, ...CombatState::cases()] as $etat) {
                    $reference = null;

                    foreach (ActorKind::cases() as $acteur) {
                        $empreinte = $this->fingerprintOf(new CombatSituation($mission, $etape, $acteur, $etat));

                        $reference ??= $empreinte;

                        $this->assertSame(
                            $reference,
                            $empreinte,
                            'The actor kind changed the base matrix without a written rule: '
                            . $mission->value . ' / ' . $etape->value . ' / ' . ($etat->value ?? 'aucun combat')
                        );
                    }
                }
            }
        }
    }

    /**
     * Une flotte sans destination praticable disparait au lieu d'etre renvoyee nulle part.
     *
     * C'est la seule difference que le genre d'acteur produit reellement, et elle passe par le plan
     * de retour : c'est lui qui porte le fait, apres avoir epuise les recours ordonnes du jeu.
     */
    public function testAFleetWithNowhereToGoDisappearsInsteadOfBeingSentNowhere(): void
    {
        $situation = new CombatSituation(
            CombatMissionKind::Attack,
            FlightLeg::Outbound,
            ActorKind::Npc,
            CombatState::Active
        );

        $decision = (new CombatDecisionMatrix())->verdictOf(
            $situation,
            ReturnPlan::cannotReturn(CombatReasonCode::RallyClosed),
            ArrivingAssets::nothingToPreserve()
        )->movement;

        $this->assertSame(CombatMissionAction::CancelWithoutImpact, $decision->action());
        $this->assertNull($decision->returnPlan, 'A cancelled arrival was given a destination.');
    }

    /**
     * Une continuation doit etre tranchee, et ne peut pas differer a son tour.
     */
    public function testAContinuationMustBeSettledAndMayNotDeferAgain(): void
    {
        try {
            ArrivalDecision::deferUntilResolved(ArrivalDecision::unresolved('REGLE : une question ouverte'));
            $this->fail('An unsettled continuation was accepted: the event would be postponed indefinitely.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);

        ArrivalDecision::deferUntilResolved(
            ArrivalDecision::deferUntilResolved(ArrivalDecision::completeNormally())
        );
    }

    /**
     * Le ralliement porte un seul nom, dans le domaine comme en base.
     *
     * La migration prend son defaut de `CombatState::Rallying->value` : il n'existe pas de second
     * nom — pas d'`en attente` cote base et de `Rallying` cote domaine — qu'une correspondance
     * devrait rapprocher.
     */
    public function testTheRallyStateHasASingleCanonicalName(): void
    {
        $this->assertSame('rallying', CombatState::Rallying->value);

        $noms = array_map(static fn (CombatState $etat): string => $etat->value, CombatState::cases());

        $this->assertNotContains('pending', $noms, 'A second name for the rally window appeared.');
        $this->assertSame($noms, array_unique($noms));
    }

    /**
     * Aucun code d'attente n'atteint une raison lisible par un joueur.
     *
     * ## Pourquoi les trois familles sont separees
     *
     * Un `CombatReasonCode` finit sous les yeux d'un joueur. « Admission en attente » ne dit rien a
     * personne, et l'ecrire dans un rapport reviendrait a publier un etat intermediaire du serveur.
     * Tant que les deux vivaient dans la meme enumeration, rien n'empechait mecaniquement l'un de
     * passer pour l'autre.
     */
    public function testNoWaitingCodeReachesAPlayerFacingReason(): void
    {
        foreach (CombatReasonCode::cases() as $code) {
            $this->assertStringNotContainsStringIgnoringCase(
                'pending',
                $code->value,
                'A waiting code came back into the player-facing enumeration: ' . $code->value
            );
        }

        $delegantes = 0;

        foreach (CombatSituation::all() as $situation) {
            $decision = $this->arrivalOf($situation);
            $categorie = $decision->openCellCategory();

            if ($categorie === null) {
                // Une action immediate porte une raison finale, et elle est servie sans lever.
                $this->assertTrue($decision->isFinal(), 'An immediate action is not final: ' . $situation->describe());
                $this->assertNotSame(CombatReasonCode::Undecided, $decision->reason());
                $this->assertNull($decision->requirement(), $situation->describe());
                $this->assertNull($decision->invariant(), $situation->describe());

                continue;
            }

            if ($categorie === OpenCellCategory::MissingRule) {
                continue;
            }

            $delegantes++;

            // Une exigence ou un invariant, jamais les deux, et jamais de raison joueur.
            $this->assertFalse($decision->isFinal(), $situation->describe());

            $porte = ($decision->requirement() !== null ? 1 : 0) + ($decision->invariant() !== null ? 1 : 0);
            $this->assertSame(1, $porte, 'A delegating cell carries neither or both: ' . $situation->describe());

            try {
                $decision->reason();
                $this->fail('A waiting decision served a player-facing reason: ' . $situation->describe());
            } catch (NonFinalCombatReason) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(57, $delegantes, 'The number of delegating cells changed.');
    }

    /**
     * Les deux familles internes couvrent exactement ce que la matrice produit.
     */
    public function testTheInternalFamiliesAreExhaustivelyUsed(): void
    {
        $exigences = [];
        $invariants = [];

        foreach (CombatSituation::all() as $situation) {
            $decision = $this->arrivalOf($situation);

            if ($decision->requirement() !== null) {
                $exigences[$decision->requirement()->value] = true;
            }

            if ($decision->invariant() !== null) {
                $invariants[$decision->invariant()->value] = true;
            }
        }

        $this->assertSame(
            [DecisionRequirement::RallyAdmission->value, DecisionRequirement::CausalOrder->value],
            array_keys($exigences)
        );

        $this->assertSame(
            [InvariantCode::SituationCannotOccur->value, InvariantCode::NotACelestialBodyTarget->value],
            array_keys($invariants)
        );
    }

    /**
     * Pendant le ralliement, aucune case ne prejuge de la photographie.
     *
     * ## Ce que ce controle empeche
     *
     * `LandOutsideSnapshot` **decide** l'appartenance a la photographie : il dit « cette flotte n'y
     * figure pas ». Avant la fermeture, cette question n'est pas tranchee — elle depend des deux
     * barrieres temporelles, et c'est `SnapshotDecision` qui l'applique.
     *
     * Une case classee « action immediate » qui rendrait `LandOutsideSnapshot` pendant `Rallying`
     * contournerait donc cette decision : un retour decide avant l'ouverture et prevu avant la
     * fermeture doit entrer dans la photographie avec sa cargaison, et c'est precisement ce qu'une
     * telle case lui refuserait.
     *
     * `AllowNormally` reste admis parce qu'il ne dit **rien** de la photographie : il decrit le
     * mouvement, et laisse la contribution a qui en a la charge.
     */
    public function testDuringTheRallyNoCellPreDecidesSnapshotMembership(): void
    {
        $examinees = 0;

        foreach (CombatSituation::all() as $situation) {
            if ($situation->targetState !== CombatState::Rallying || !$situation->isPossible()) {
                continue;
            }

            if ($situation->scope() === TargetScope::DeepSpace) {
                continue;
            }

            $examinees++;

            $this->assertNotSame(
                CombatMissionAction::LandOutsideSnapshot,
                $this->arrivalOf($situation)->action(),
                'A cell settled snapshot membership before the window closed: ' . $situation->describe()
            );
        }

        $this->assertGreaterThan(0, $examinees, 'No rally cell was examined: this test would prove nothing.');

        // Et l'inverse doit rester vrai : une fois la photographie prise, la question **est** tranchee,
        // et un retour se pose hors photographie. Sans ce controle, supprimer entierement
        // `LandOutsideSnapshot` laisserait l'essai ci-dessus vert.
        $this->assertSame(
            CombatMissionAction::LandOutsideSnapshot,
            $this->arrivalOf(new CombatSituation(
                CombatMissionKind::Transport,
                FlightLeg::Return,
                ActorKind::Player,
                CombatState::Active
            ))->action()
        );
    }

    /**
     * Toute arrivee qui se pose pendant le ralliement exige une decision causale.
     *
     * ## Une obligation, pas une interdiction
     *
     * Interdire `LandOutsideSnapshot` pendant le ralliement empechait le mauvais resultat. Cela
     * n'obligeait personne a en demander un bon : un appelant qui recevait `AllowNormally` pouvait
     * conclure que rien ne restait a decider, et inclure ou exclure la flotte de son propre chef.
     *
     * Cet essai est la preuve **positive** : chaque case qui depose quelque chose sur le corps
     * pendant que la photographie n'est pas prise porte `RequiresCausalDecision`.
     */
    public function testEveryArrivalThatLandsDuringTheRallyDemandsACausalDecision(): void
    {
        $posees = 0;

        foreach (CombatSituation::all() as $situation) {
            $verdict = $this->verdictOf($situation);

            if ($situation->targetState !== CombatState::Rallying || !$verdict->mayTouchTheSnapshot()) {
                continue;
            }

            if ($situation->scope() !== TargetScope::CelestialBody) {
                continue;
            }

            $posees++;

            $this->assertSame(
                SnapshotObligation::RequiresCausalDecision,
                $verdict->snapshot,
                'An effect entered the rally window without the causal decision being demanded: '
                . $situation->describe()
            );
        }

        $this->assertGreaterThan(0, $posees, 'No landing cell was examined: this test would prove nothing.');

        // **Un missile ne pose aucune flotte et modifie pourtant la photographie.** Il doit donc
        // exiger la decision causale au meme titre qu'une livraison. Limiter l'obligation aux
        // mouvements qui atterrissent le laisserait entrer sans qu'on ait rien demande.
        $missile = $this->verdictOf(new CombatSituation(
            CombatMissionKind::Missile,
            FlightLeg::Outbound,
            ActorKind::Player,
            CombatState::Rallying
        ));

        $this->assertFalse($missile->landsOnTheBody(), 'A missile is not supposed to land a fleet.');
        $this->assertSame(SnapshotObligation::RequiresCausalDecision, $missile->snapshot);

        // Tous les retours en font partie, et c'etait le cas incertain : leur mouvement est
        // `AllowNormally`, qui ne dit rien de la photographie.
        foreach (CombatMissionKind::cases() as $mission) {
            $retour = new CombatSituation($mission, FlightLeg::Return, ActorKind::Player, CombatState::Rallying);

            if (!$retour->isPossible()) {
                continue;
            }

            $verdict = $this->verdictOf($retour);

            $this->assertSame(CombatMissionAction::AllowNormally, $verdict->movement->action());
            $this->assertSame(SnapshotObligation::RequiresCausalDecision, $verdict->snapshot, $retour->describe());
        }
    }

    /**
     * Une fois la photographie prise, la question est tranchee et ne se redemande pas.
     */
    public function testOnceTheSnapshotIsTakenMembershipIsSettled(): void
    {
        $verdict = $this->verdictOf(new CombatSituation(
            CombatMissionKind::Transport,
            FlightLeg::Return,
            ActorKind::Player,
            CombatState::Active
        ));

        $this->assertSame(CombatMissionAction::LandOutsideSnapshot, $verdict->movement->action());
        $this->assertSame(SnapshotObligation::SettledOutsideSnapshot, $verdict->snapshot);
    }

    /**
     * Une arrivee qui ne se pose pas ne porte aucune obligation de photographie.
     *
     * Sans ce controle, une flotte renvoyee pourrait se voir attacher une contribution, et le
     * reconciliateur chercherait des vaisseaux la ou il n'y en a aucun.
     */
    public function testAnArrivalThatTouchesNothingCarriesNoSnapshotObligation(): void
    {
        $examinees = 0;

        foreach (CombatSituation::all() as $situation) {
            $verdict = $this->verdictOf($situation);

            if ($verdict->mayTouchTheSnapshot()) {
                continue;
            }

            $examinees++;

            $this->assertSame(
                SnapshotObligation::NotConcerned,
                $verdict->snapshot,
                'A departure was given a snapshot obligation: ' . $situation->describe()
            );
        }

        $this->assertGreaterThan(0, $examinees, 'No departure was examined: this test would prove nothing.');
    }

    /**
     * Une decision interne ne peut pas devenir un resultat montre a un joueur.
     *
     * `reason()` levait deja, mais **au moment de l'appel** : rien n'empechait un chemin de
     * serialiser l'action sans jamais demander la raison, et de publier ainsi
     * `select_by_attack_admission` dans un rapport. Le passage de type ferme cette porte.
     */
    public function testAnInternalDecisionCannotBecomeAPlayerFacingResult(): void
    {
        $refusees = 0;

        foreach (CombatSituation::all() as $situation) {
            $decision = $this->arrivalOf($situation);

            if ($decision->isFinal() && $decision->action() !== CombatMissionAction::DeferUntilResolved) {
                $finale = FinalCombatDecision::of($decision);

                $this->assertSame($decision->action()->value, $finale->toPlayerFacingFacts()['action']);
                $this->assertStringNotContainsStringIgnoringCase(
                    'pending',
                    (string)$finale->toPlayerFacingFacts()['reason']
                );

                continue;
            }

            $refusees++;

            try {
                FinalCombatDecision::of($decision);
                $this->fail('An internal decision was published as a result: ' . $situation->describe());
            } catch (NonFinalCombatReason) {
                $this->addToAssertionCount(1);
            }
        }

        // Les 57 delegations, plus les reports : un report n'est pas un resultat, ce qui sera montre
        // au joueur est sa continuation.
        $this->assertGreaterThan(57, $refusees, 'Deferred decisions were accepted as final results.');
    }

    /**
     * Un retour dont le plan resolu ne designe aucun corps est annule, jamais pose.
     *
     * `FlightLeg::Return` ne garantit pas un corps celeste : la lune d'origine peut avoir ete
     * detruite pendant le vol. C'est le plan resolu sous verrou qui porte le fait, apres avoir
     * epuise les recours ordonnes du jeu.
     */
    public function testAReturnWithNoResolvedDestinationIsCancelledRatherThanLanded(): void
    {
        $sansDestination = ReturnPlan::cannotReturn(CombatReasonCode::TargetCombatLocked);

        foreach ([null, CombatState::Rallying, CombatState::Active, CombatState::Resolving] as $etat) {
            $situation = new CombatSituation(
                CombatMissionKind::Expedition,
                FlightLeg::Return,
                ActorKind::Player,
                $etat
            );

            $this->assertSame(TargetScope::NoDestination, $situation->scopeFor($sansDestination));

            $verdict = (new CombatDecisionMatrix())->verdictOf(
                $situation,
                $sansDestination,
                ArrivingAssets::nothingToPreserve()
            );

            $this->assertSame(CombatMissionAction::CancelWithoutImpact, $verdict->movement->action());
            $this->assertSame(CombatReasonCode::NoReturnDestination, $verdict->movement->reason());
            $this->assertSame(SnapshotObligation::NotConcerned, $verdict->snapshot);
        }

        // Et le meme retour avec un plan praticable se pose bien.
        $this->assertSame(
            TargetScope::CelestialBody,
            (new CombatSituation(
                CombatMissionKind::Expedition,
                FlightLeg::Return,
                ActorKind::Player,
                CombatState::Active
            ))->scopeFor($this->aPossibleReturn())
        );
    }

    /**
     * Une flotte chargee sans destination n'est jamais supprimee en silence.
     *
     * ## Deux situations que rien ne distinguait
     *
     * Pour une operation systeme sans flotte ni cargaison, `CancelWithoutImpact` est exact : il n'y
     * a rien a preserver. Pour une flotte de joueur, la meme decision **supprime ses vaisseaux et
     * sa cargaison**.
     *
     * Le cas ne devrait pas se produire — la planete mere garantit normalement une destination. C'est
     * justement pourquoi il compte : s'il survient, c'est une corruption ou un etat administratif, et
     * la reponse est une quarantaine, pas une regle de jeu ordinaire.
     */
    public function testALoadedFleetWithNoDestinationIsNeverSilentlyDeleted(): void
    {
        $sansDestination = ReturnPlan::cannotReturn(CombatReasonCode::TargetCombatLocked);

        $situation = new CombatSituation(
            CombatMissionKind::Transport,
            FlightLeg::Return,
            ActorKind::Player,
            CombatState::Active
        );

        $matrice = new CombatDecisionMatrix();

        $charge = $matrice->verdictOf($situation, $sansDestination, ArrivingAssets::fleetWithCargo());
        $vide = $matrice->verdictOf($situation, $sansDestination, ArrivingAssets::nothingToPreserve());

        $this->assertSame(CombatMissionAction::RequiresAssetRecovery, $charge->movement->action());
        $this->assertSame(InvariantCode::AssetsWithoutDestination, $charge->movement->invariant());
        $this->assertSame(OpenCellCategory::NeedsAssetRecovery, $charge->movement->openCellCategory());

        $this->assertSame(CombatMissionAction::CancelWithoutImpact, $vide->movement->action());
        $this->assertSame(CombatReasonCode::NoReturnDestination, $vide->movement->reason());

        // Une mise en recuperation n'est pas un resultat a montrer : c'est une alerte.
        $this->expectException(NonFinalCombatReason::class);

        FinalCombatDecision::of($charge->movement);
    }

    /**
     * Chaque genre de mission declare ce qu'il peut projeter dans la photographie.
     *
     * ## Pourquoi ce parcours plutot qu'une confiance dans le `match`
     *
     * Le `match` est exhaustif : un genre nouveau leverait une `UnhandledMatchError`. Mais elle ne se
     * produirait qu'a l'execution du chemin concerne, peut-etre en production. Ce parcours la
     * declenche dans la suite d'essais, avant.
     */
    public function testEveryMissionKindDeclaresWhatItCanProject(): void
    {
        $sansProjection = [
            CombatMissionKind::Espionage,
            CombatMissionKind::Colonisation,
            CombatMissionKind::Recycle,
            CombatMissionKind::Expedition,
        ];

        foreach (CombatMissionKind::cases() as $mission) {
            $aller = new CombatSituation($mission, FlightLeg::Outbound, ActorKind::Player, CombatState::Rallying);
            $retour = new CombatSituation($mission, FlightLeg::Return, ActorKind::Player, CombatState::Rallying);

            $attendu = in_array($mission, $sansProjection, true) ? [] : 'quelque chose';

            if ($attendu === []) {
                $this->assertSame([], $aller->possibleProjections(), $mission->value);
            } else {
                $this->assertNotSame([], $aller->possibleProjections(), $mission->value);
            }

            // **Un retour ne depend pas du genre de son aller.** Une flotte qui rentre depose ses
            // vaisseaux et sa cargaison, qu'elle soit partie espionner ou recycler.
            $this->assertSame(
                [SnapshotContribution::DeliveredFleet, SnapshotContribution::DeliveredCargo],
                $retour->possibleProjections(),
                $mission->value
            );
        }

        // Et le transport n'apporte que sa cargaison : ses transporteurs ne deviennent jamais
        // defenseurs.
        $this->assertSame(
            [SnapshotContribution::DeliveredCargo],
            (new CombatSituation(
                CombatMissionKind::Transport,
                FlightLeg::Outbound,
                ActorKind::Player,
                CombatState::Rallying
            ))->possibleProjections()
        );
    }

    /**
     * La decision d'une situation, avec un plan de retour praticable.
     */
    private function arrivalOf(CombatSituation $situation): ArrivalDecision
    {
        return $this->verdictOf($situation)->movement;
    }

    /**
     * Le verdict complet d'une situation, avec un plan de retour praticable.
     */
    private function verdictOf(CombatSituation $situation): ArrivalVerdict
    {
        return (new CombatDecisionMatrix())->verdictOf(
            $situation,
            $this->aPossibleReturn(),
            ArrivingAssets::fleetWithCargo()
        );
    }

    /**
     * Ce qu'une case produit, sous une forme comparable.
     */
    private function fingerprintOf(CombatSituation $situation): string
    {
        $decision = $this->arrivalOf($situation);

        if (!$decision->isResolved()) {
            return 'ouverte : ' . (string)$decision->openQuestion();
        }

        $empreinte = $decision->action()->value . '|' . $this->outcomeOf($decision);
        $suite = $decision->continuation();

        if ($suite !== null) {
            $empreinte .= ' -> ' . $suite->action()->value . '|' . $this->outcomeOf($suite);
        }

        return $empreinte;
    }

    /**
     * Ce que porte une decision : une raison joueur, une exigence, ou un code d'invariant.
     */
    private function outcomeOf(ArrivalDecision $decision): string
    {
        if ($decision->isFinal()) {
            return $decision->reason()->value;
        }

        return (string)($decision->requirement()->value ?? $decision->invariant()?->value);
    }

    /**
     * Un plan de retour praticable, vers la planete mere.
     */
    private function aPossibleReturn(): ReturnPlan
    {
        return ReturnPlan::toHomeworld(1, new Coordinate(1, 1, 1), 1);
    }
}
