<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\CombatArrivalOutcome;
use OGame\Combat\Enums\CombatSide;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Support\CombatArrival;
use OGame\Combat\Support\CombatRallyWindow;
use Tests\UnitTestCase;

/**
 * La fenetre de ralliement : qui rejoint un combat, et qui rentre chez lui.
 *
 * La regle etant pure — aucune base, aucune horloge, aucun joueur — elle peut etre eprouvee sur
 * **toutes** ses combinaisons plutot que sur quelques cas choisis. C'est ce que fait le dernier
 * test de ce fichier.
 */
class CombatRallyWindowTest extends UnitTestCase
{
    /**
     * Une arrivee sur un corps celeste au repos ouvre la fenetre.
     */
    public function testAnArrivalOnAQuietBodyOpensTheWindow(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::OpensRally,
            CombatRallyWindow::decideArrival(null, false, $this->attacker()),
            'A fleet arriving where nothing is happening must open the rally window.'
        );
    }

    /**
     * Un combat termine ne retient plus rien : l'arrivee suivante ouvre une nouvelle fenetre.
     */
    public function testAFinishedCombatDoesNotHoldTheBody(): void
    {
        foreach ([CombatState::Resolved, CombatState::Cancelled] as $etat) {
            $this->assertSame(
                CombatArrivalOutcome::OpensRally,
                CombatRallyWindow::decideArrival($etat, false, $this->attacker()),
                "The state {$etat->value} still holds the body, so a later attack could never begin."
            );
        }
    }

    /**
     * Une vague du meme attaquant rejoint le combat : c'est la raison d'etre de la fenetre.
     */
    public function testAWaveFromTheSameAttackerJoinsTheRally(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::JoinsRally,
            $this->duringRally($this->attacker(belongsToInitiator: true)),
            'A second wave from the same attacker must fight in the same battle.'
        );
    }

    /**
     * Un allie de l'alliance attaquante rejoint le combat.
     */
    public function testAnAllyOfTheAttackingAllianceJoinsTheRally(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::JoinsRally,
            $this->duringRally($this->attacker(sharesInitiatorAlliance: true, initiatorHasAlliance: true)),
            'An ally of the attacking alliance must be able to join.'
        );
    }

    /**
     * Sans alliance chez l'attaquant initial, personne d'autre que lui ne rejoint.
     *
     * L'alliance est le seul titre d'un tiers. S'il n'y en a pas, il n'y a pas de titre — et un
     * allie declare d'un joueur sans alliance n'existe pas.
     */
    public function testWithNoAllianceNobodyButTheInitiatorJoins(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::RecalledToOrigin,
            $this->duringRally($this->attacker(sharesInitiatorAlliance: true, initiatorHasAlliance: false)),
            'Someone joined the battle of an attacker who belongs to no alliance at all.'
        );
    }

    /**
     * Un attaquant independant n'est jamais fait allie par accident.
     *
     * **C'est la regle la plus importante de ce fichier.** Deux ennemis qui visent la meme cible
     * n'ont pas decide de s'allier : mettre leurs flottes dans la meme bataille calculerait leurs
     * pertes ensemble et partagerait leur butin.
     */
    public function testAnIndependentAttackerNeverBecomesAnAllyByAccident(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::RecalledToOrigin,
            $this->duringRally($this->attacker(initiatorHasAlliance: true)),
            'An unrelated attacker joined the battle, and would share losses and loot with someone who never agreed to it.'
        );
    }

    /**
     * Personne ne vient preter main-forte a un pirate.
     */
    public function testNobodyReinforcesAPirate(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::RecalledToOrigin,
            $this->duringRally($this->attacker(
                sharesInitiatorAlliance: true,
                initiatorHasAlliance: true,
                targetIsNpcHeld: true
            )),
            'A player joined the side of a server-driven account.'
        );
    }

    /**
     * Un renfort defenseur arrive a temps participe toujours.
     */
    public function testADefenderReinforcementArrivingInTimeAlwaysJoins(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::JoinsRally,
            $this->duringRally(new CombatArrival(side: CombatSide::Defender)),
            'A defender reinforcement arriving before the window closes must take part.'
        );
    }

    /**
     * Une fois la fenetre fermee, une attaque fait demi-tour.
     *
     * Ni file d'attente ni second combat automatique : la flotte rentre par la mecanique
     * normale de rappel.
     */
    public function testOnceTheWindowIsClosedAnAttackTurnsBack(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::RecalledToOrigin,
            CombatRallyWindow::decideArrival(
                CombatState::Rallying,
                false,
                $this->attacker(belongsToInitiator: true)
            ),
            'An attack joined after the window closed, which would change a result already computed.'
        );
    }

    /**
     * Un retour ou un deploiement arrive apres la fermeture se pose, mais ne combat pas.
     *
     * Le renvoyer serait absurde : il rentre chez lui. C'est ce cas qui impose que les pertes
     * soient appliquees comme une difference sur la photo, jamais en remplacant le contenu du
     * corps celeste — sinon ces vaisseaux-la seraient effaces par la resolution.
     */
    public function testAReturningFleetLandsWithoutFighting(): void
    {
        foreach ([CombatState::Active, CombatState::Resolving] as $etat) {
            $this->assertSame(
                CombatArrivalOutcome::ArrivesWithoutJoining,
                CombatRallyWindow::decideArrival(
                    $etat,
                    false,
                    new CombatArrival(side: CombatSide::Defender, isReturningOrDeploying: true)
                ),
                "A returning fleet was turned away from its own planet during state {$etat->value}."
            );
        }
    }

    /**
     * Un combat en cours ou en resolution ne prend aucun combattant.
     */
    public function testARunningCombatTakesNoFighterIn(): void
    {
        foreach ([CombatState::Active, CombatState::Resolving] as $etat) {
            foreach (CombatSide::cases() as $camp) {
                $this->assertSame(
                    CombatArrivalOutcome::RecalledToOrigin,
                    CombatRallyWindow::decideArrival($etat, true, new CombatArrival(
                        side: $camp,
                        belongsToInitiator: true,
                        sharesInitiatorAlliance: true,
                        initiatorHasAlliance: true
                    )),
                    "A fleet joined a combat in state {$etat->value}, whose result is already frozen."
                );
            }
        }
    }

    /**
     * La seizieme flotte passe, la dix-septieme non.
     */
    public function testTheSixteenthFleetPassesAndTheSeventeenthDoesNot(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::JoinsRally,
            $this->duringRally($this->attacker(belongsToInitiator: true, fleetsAlreadyJoined: 15)),
            'The sixteenth fleet was refused, one too early.'
        );
    }

    /**
     * Cinq joueurs distincts au total, attaquant initial compris.
     *
     * Le compte inclut celui qui a ouvert le combat. Compter « l'initiateur plus cinq allies »
     * ferait six joueurs et contournerait la limite ACS du jeu.
     */
    public function testTheLimitIsFiveDistinctPlayersIncludingTheInitiator(): void
    {
        // A ouvre : un joueur. B, C et D rejoignent — le camp en compte quatre, il reste une
        // place.
        $this->assertSame(
            CombatArrivalOutcome::JoinsRally,
            $this->duringRally($this->ally(playersAlreadyJoined: 4)),
            'The fifth player was refused, one too early.'
        );

        // A, B, C, D et E : la limite est atteinte. F est refuse.
        $this->assertSame(
            CombatArrivalOutcome::RecalledToOrigin,
            $this->duringRally($this->ally(playersAlreadyJoined: 5)),
            'A sixth player joined the side, so the rally window works around the ACS limit.'
        );
    }

    /**
     * Un camp complet en joueurs accepte encore les vagues de ceux qui y sont deja.
     *
     * **La distinction que ce test protege** : la limite compte des joueurs, pas des flottes.
     * Une seconde vague n'amene personne de nouveau. La refuser interdirait a l'attaquant
     * initial d'envoyer la sienne des que cinq joueurs se battent — soit exactement ce que la
     * fenetre de ralliement existe pour permettre.
     */
    public function testAFullSideStillAcceptsWavesFromThoseAlreadyInIt(): void
    {
        $this->assertSame(
            CombatArrivalOutcome::JoinsRally,
            $this->duringRally($this->attacker(belongsToInitiator: true, playersAlreadyJoined: 5)),
            'The initiator could not send another of his own fleets because five players were already fighting.'
        );

        $this->assertSame(
            CombatArrivalOutcome::JoinsRally,
            $this->duringRally($this->ally(playersAlreadyJoined: 5, ownerAlreadyJoined: true)),
            'An ally already in the battle could not send a second wave.'
        );
    }

    /**
     * Le plafond de seize flottes ne connait aucune exception.
     *
     * Contrairement a la limite de joueurs, celle-ci s'applique meme a l'attaquant initial : ce
     * sont bien des flottes qu'elle compte.
     */
    public function testTheSixteenFleetCeilingHasNoException(): void
    {
        foreach ([true, false] as $estInitiateur) {
            $this->assertSame(
                CombatArrivalOutcome::RecalledToOrigin,
                $this->duringRally($this->attacker(
                    belongsToInitiator: $estInitiateur,
                    sharesInitiatorAlliance: true,
                    initiatorHasAlliance: true,
                    fleetsAlreadyJoined: 16
                )),
                'A seventeenth fleet joined the side.'
            );
        }
    }

    /**
     * La fenetre dure soixante secondes, et pas une de plus.
     */
    public function testTheWindowLastsExactlySixtySeconds(): void
    {
        $ouverture = 1_000_000;

        $this->assertSame(60, CombatRallyWindow::WINDOW_SECONDS);
        $this->assertSame($ouverture + 60, CombatRallyWindow::closesAt($ouverture));

        $this->assertTrue(CombatRallyWindow::isOpenAt($ouverture, $ouverture), 'The window is closed at the very instant it opens.');
        $this->assertTrue(CombatRallyWindow::isOpenAt($ouverture, $ouverture + 59), 'The window closed one second early.');
        $this->assertFalse(CombatRallyWindow::isOpenAt($ouverture, $ouverture + 60), 'The window is still open at its closing instant.');
        $this->assertFalse(CombatRallyWindow::isOpenAt($ouverture, $ouverture + 61), 'The window outlived its own deadline.');
    }

    /**
     * Le decompte affiche ne descend jamais sous zero.
     */
    public function testTheDisplayedCountdownNeverGoesBelowZero(): void
    {
        $ouverture = 1_000_000;

        $this->assertSame(60, CombatRallyWindow::secondsRemaining($ouverture, $ouverture));
        $this->assertSame(42, CombatRallyWindow::secondsRemaining($ouverture, $ouverture + 18));
        $this->assertSame(0, CombatRallyWindow::secondsRemaining($ouverture, $ouverture + 60));
        $this->assertSame(0, CombatRallyWindow::secondsRemaining($ouverture, $ouverture + 5000));
    }

    /**
     * Aucune combinaison ne rend une issue inattendue.
     *
     * Le balayage croise tout ce qui entre dans la decision : les six etats — les cinq du cycle
     * plus l'absence de combat —, la fenetre, le camp, et les six faits d'une arrivee. Les
     * combinaisons sont produites par un produit cartesien plutot que par des boucles imbriquees :
     * a neuf niveaux, l'indentation devient plus difficile a lire que la regle elle-meme, et une
     * accolade mal placee y passerait inapercue.
     *
     * Cinq invariants doivent tenir partout.
     */
    public function testNoCombinationProducesAnUnexpectedOutcome(): void
    {
        $axes = [
            'etat' => array_merge([null], CombatState::cases()),
            'fenetreOuverte' => [true, false],
            'camp' => CombatSide::cases(),
            'memeJoueur' => [true, false],
            'memeAlliance' => [true, false],
            'alliance' => [true, false],
            'retour' => [true, false],
            'dejaEngage' => [true, false],
            'joueursPresents' => [0, 5],
        ];

        $combinaisons = $this->cartesianProduct($axes);

        foreach ($combinaisons as $cas) {
            $arrivee = new CombatArrival(
                side: $cas['camp'],
                belongsToInitiator: $cas['memeJoueur'],
                sharesInitiatorAlliance: $cas['memeAlliance'],
                initiatorHasAlliance: $cas['alliance'],
                isReturningOrDeploying: $cas['retour'],
                ownerAlreadyJoined: $cas['dejaEngage'],
                playersAlreadyJoined: $cas['joueursPresents'],
            );

            $issue = CombatRallyWindow::decideArrival($cas['etat'], $cas['fenetreOuverte'], $arrivee);
            $ralliementOuvert = $cas['etat'] === CombatState::Rallying && $cas['fenetreOuverte'];
            $corpsLibre = $cas['etat'] === null || !$cas['etat']->locksTargetBody();

            if ($issue === CombatArrivalOutcome::JoinsRally) {
                $this->assertTrue($ralliementOuvert, 'A fleet joined a rally that was not open.');

                $this->assertTrue(
                    $cas['camp'] === CombatSide::Defender || $cas['memeJoueur'] || ($cas['alliance'] && $cas['memeAlliance']),
                    'An attacker with no claim joined the rally.'
                );

                // La limite de joueurs ne cede jamais a quelqu'un de nouveau.
                $this->assertFalse(
                    $arrivee->bringsANewPlayer() && $cas['joueursPresents'] >= CombatRallyWindow::MAX_PLAYERS_PER_SIDE,
                    'A new player joined a side that had already reached the ACS player limit.'
                );
            }

            if ($issue === CombatArrivalOutcome::OpensRally) {
                $this->assertTrue($corpsLibre, 'A new rally opened on a body already held by a combat.');
            }

            if ($issue === CombatArrivalOutcome::ArrivesWithoutJoining) {
                $this->assertTrue($cas['retour'], 'A fleet landed without fighting although it was neither returning nor deploying.');
                $this->assertFalse($ralliementOuvert, 'A returning fleet was set aside while the rally was still open.');
            }

            if ($corpsLibre) {
                $this->assertSame(
                    CombatArrivalOutcome::OpensRally,
                    $issue,
                    'An arrival on a free body did anything other than open a rally.'
                );
            }
        }

        $this->assertCount(1536, $combinaisons, 'The exhaustive sweep no longer covers every combination.');
    }

    /**
     * Toutes les combinaisons possibles des axes donnes.
     *
     * @param array<string, array<int, mixed>> $axes
     * @return array<int, array<string, mixed>>
     */
    private function cartesianProduct(array $axes): array
    {
        $combinaisons = [[]];

        foreach ($axes as $nom => $valeurs) {
            $etendues = [];

            foreach ($combinaisons as $partielle) {
                foreach ($valeurs as $valeur) {
                    $etendues[] = $partielle + [$nom => $valeur];
                }
            }

            $combinaisons = $etendues;
        }

        return $combinaisons;
    }

    /**
     * Une arrivee attaquante, avec les faits qu'on veut lui donner.
     */
    private function attacker(
        bool $belongsToInitiator = false,
        bool $sharesInitiatorAlliance = false,
        bool $initiatorHasAlliance = false,
        bool $targetIsNpcHeld = false,
        bool $ownerAlreadyJoined = false,
        int $playersAlreadyJoined = 0,
        int $fleetsAlreadyJoined = 0,
    ): CombatArrival {
        return new CombatArrival(
            side: CombatSide::Attacker,
            belongsToInitiator: $belongsToInitiator,
            sharesInitiatorAlliance: $sharesInitiatorAlliance,
            initiatorHasAlliance: $initiatorHasAlliance,
            targetIsNpcHeld: $targetIsNpcHeld,
            ownerAlreadyJoined: $ownerAlreadyJoined,
            playersAlreadyJoined: $playersAlreadyJoined,
            fleetsAlreadyJoined: $fleetsAlreadyJoined,
        );
    }

    /**
     * Une arrivee d'un allie de l'alliance attaquante.
     */
    private function ally(
        int $playersAlreadyJoined = 0,
        int $fleetsAlreadyJoined = 0,
        bool $ownerAlreadyJoined = false,
    ): CombatArrival {
        return $this->attacker(
            sharesInitiatorAlliance: true,
            initiatorHasAlliance: true,
            ownerAlreadyJoined: $ownerAlreadyJoined,
            playersAlreadyJoined: $playersAlreadyJoined,
            fleetsAlreadyJoined: $fleetsAlreadyJoined,
        );
    }

    /**
     * L'issue de cette arrivee, fenetre de ralliement ouverte.
     */
    private function duringRally(CombatArrival $arrival): CombatArrivalOutcome
    {
        return CombatRallyWindow::decideArrival(CombatState::Rallying, true, $arrival);
    }
}
