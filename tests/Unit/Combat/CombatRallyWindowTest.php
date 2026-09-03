<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Support\CombatRallyWindow;
use Tests\UnitTestCase;

/**
 * L'echeance de la fenetre de ralliement : quand elle se ferme, et qui tombe dedans.
 *
 * Les essais d'admission — qui rejoint quel camp — ont ete portes vers la matrice et les deux
 * selecteurs, ou la decision vit desormais : voir `RallyArrivalCoverageTest`. Ne restent ici que
 * les regles de temps, et elles n'ont pas bouge.
 *
 * La regle etant pure — aucune base, aucune horloge, aucun joueur — l'echeance est eprouvee sur
 * deux cents tirages reproductibles **et** sur ses frontieres nommees une a une : un tirage
 * aleatoire, meme reproductible, ne dit pas quelles frontieres comptent.
 */
class CombatRallyWindowTest extends UnitTestCase
{
    /**
     * Une flotte isolee n'obtient pas soixante secondes de verrou.
     *
     * **La protection contre le harcelement economique.** Le corps celeste est verrouille des la
     * premiere arrivee : sans cette regle, un unique chasseur leger envoye en boucle
     * immobiliserait une minute les departs et les ressources d'une planete, indefiniment et
     * pour un cout derisoire. Aucune flotte admissible en vol, aucune attente : le combat
     * commence a l'instant meme.
     */
    public function testALoneFleetGetsNoRallyWindowAtAll(): void
    {
        $ouverture = 1_000_000;

        $this->assertSame(
            $ouverture,
            CombatRallyWindow::closesAt($ouverture, []),
            'A lone attacker still froze the target for a full minute, which turns a light fighter into a blockade tool.'
        );

        $this->assertFalse(
            CombatRallyWindow::admitsArrivalAt(CombatRallyWindow::closesAt($ouverture, []), $ouverture),
            'The window closed at the same instant it opened, yet still admitted an arrival.'
        );
    }

    /**
     * La fenetre s'arrete apres la derniere flotte attendue, sans attendre les soixante secondes.
     */
    public function testTheWindowStopsAfterTheLastExpectedFleet(): void
    {
        $ouverture = 1_000_000;

        // Trois vagues preparees, la derniere a dix-huit secondes.
        $fermeture = CombatRallyWindow::closesAt($ouverture, [$ouverture + 4, $ouverture + 18, $ouverture + 9]);

        $this->assertSame($ouverture + 19, $fermeture, 'The window did not close right after the last expected fleet.');

        $this->assertTrue(
            CombatRallyWindow::admitsArrivalAt($fermeture, $ouverture + 18),
            'The very fleet that set the deadline arrived too late for it.'
        );
    }

    /**
     * Soixante secondes restent le plafond, quelles que soient les arrivees attendues.
     */
    public function testSixtySecondsRemainsTheCeiling(): void
    {
        $ouverture = 1_000_000;

        $this->assertSame(60, CombatRallyWindow::WINDOW_SECONDS);

        // Une flotte attendue a cinquante-neuf secondes : la fenetre irait a soixante, ce qui est
        // le plafond. Une autre attendue bien au-dela ne compte pas du tout.
        $this->assertSame(
            $ouverture + 60,
            CombatRallyWindow::closesAt($ouverture, [$ouverture + 59, $ouverture + 4000]),
            'The ceiling of sixty seconds was exceeded.'
        );

        $this->assertSame(
            $ouverture,
            CombatRallyWindow::closesAt($ouverture, [$ouverture + 4000]),
            'A fleet arriving long after the ceiling still held the window open.'
        );
    }

    /**
     * La borne est fermee : une arrivee prevue pile a la fermeture est en retard.
     *
     * Une regle unique, et une seule, pour tous les workers. L'instant compare est **l'heure
     * planifiee de l'arrivee**, jamais celle du traitement : un worker en retard ne doit pas
     * refuser une flotte qui devait arriver a temps.
     */
    public function testAnArrivalExactlyAtClosingTimeIsLate(): void
    {
        $ouverture = 1_000_000;
        $fermeture = CombatRallyWindow::closesAt($ouverture, [$ouverture + 59]);

        $this->assertTrue(CombatRallyWindow::admitsArrivalAt($fermeture, $ouverture), 'The window was closed at the very instant it opened.');
        $this->assertTrue(CombatRallyWindow::admitsArrivalAt($fermeture, $fermeture - 1), 'The window closed one second early.');
        $this->assertFalse(CombatRallyWindow::admitsArrivalAt($fermeture, $fermeture), 'An arrival scheduled exactly at closing time was admitted.');
        $this->assertFalse(CombatRallyWindow::admitsArrivalAt($fermeture, $fermeture + 1), 'The window outlived its own deadline.');
    }

    /**
     * Une arrivee traitee en retard reste jugee sur son heure prevue.
     *
     * Le retard d'un worker est un fait du serveur, pas un choix du joueur. Une flotte qui devait
     * arriver a la trentieme seconde reste admissible meme si l'evenement n'est traite qu'une
     * heure plus tard.
     */
    public function testALateProcessedArrivalIsStillJudgedOnItsScheduledTime(): void
    {
        $ouverture = 1_000_000;
        $fermeture = CombatRallyWindow::closesAt($ouverture, [$ouverture + 45]);

        $this->assertTrue(
            CombatRallyWindow::admitsArrivalAt($fermeture, $ouverture + 30),
            'A fleet that was due on time was refused because its event was processed late.'
        );
    }

    /**
     * Une flotte attendue avant l'ouverture ne compte pas.
     *
     * Elle est deja arrivee — c'est elle qui a ouvert le combat, ou elle appartient a un combat
     * precedent. La faire entrer dans le calcul reviendrait a fermer la fenetre avant de
     * l'ouvrir.
     */
    public function testAFleetExpectedBeforeTheOpeningDoesNotCount(): void
    {
        $ouverture = 1_000_000;

        $this->assertSame(
            $ouverture,
            CombatRallyWindow::closesAt($ouverture, [$ouverture - 10]),
            'A fleet expected before the combat opened was taken into account.'
        );
    }

    /**
     * Le decompte affiche ne descend jamais sous zero.
     */
    public function testTheDisplayedCountdownNeverGoesBelowZero(): void
    {
        $ouverture = 1_000_000;
        $fermeture = CombatRallyWindow::closesAt($ouverture, [$ouverture + 59]);

        $this->assertSame(60, CombatRallyWindow::secondsRemaining($fermeture, $ouverture));
        $this->assertSame(42, CombatRallyWindow::secondsRemaining($fermeture, $ouverture + 18));
        $this->assertSame(0, CombatRallyWindow::secondsRemaining($fermeture, $fermeture));
        $this->assertSame(0, CombatRallyWindow::secondsRemaining($fermeture, $fermeture + 5000));
    }

    /**
     * L'echeance est la plus petite qui inclue la derniere flotte attendue.
     *
     * **Les deux bornes ne suffisaient pas.** « Fermeture apres la derniere arrivee » et
     * « fermeture au plus au plafond » garantissent la surete, pas la minimalite : un decalage de
     * deux pas de temps les respecte encore des qu'on est loin du plafond. Un tel decalage
     * verrouillerait la cible pour rien, et seul le hasard des donnees le ferait apparaitre.
     *
     * Le contrat est donc enonce exactement :
     *
     *     fermeture == derniere_arrivee_retenue + TICK_SECONDS
     *
     * et, sans candidate, `fermeture == ouverture`.
     *
     * Le tirage est **reproductible** : generateur local, graine fixe, graine rappelee dans le
     * message d'echec. Aucun generateur global n'est touche, pour qu'un test voisin ne se mette
     * pas a dependre de celui-ci.
     */
    public function testTheDeadlineIsTheSmallestOneThatIncludesTheLastExpectedFleet(): void
    {
        $ouverture = 1_000_000;
        $plafond = $ouverture + CombatRallyWindow::WINDOW_SECONDS;
        $graine = 20260902;
        $etat = $graine;

        // Generateur congruentiel local : reproductible, et sans effet sur mt_rand().
        $suivant = static function (int $borne) use (&$etat): int {
            $etat = (1103515245 * $etat + 12345) % 2147483648;

            return intdiv($etat, 65536) % $borne;
        };

        for ($tirage = 0; $tirage < 200; $tirage++) {
            $arrivees = [];

            for ($index = 0, $combien = $suivant(7); $index < $combien; $index++) {
                // Volontairement au-dela des bornes : des flottes deja arrivees, et d'autres
                // attendues bien apres le plafond.
                $arrivees[] = $ouverture - 30 + $suivant(151);
            }

            $fermeture = CombatRallyWindow::closesAt($ouverture, $arrivees);

            $retenues = array_filter(
                $arrivees,
                static fn (int $arrivee): bool => $arrivee >= $ouverture && $arrivee + CombatRallyWindow::TICK_SECONDS <= $plafond
            );

            $contexte = ' (graine ' . $graine . ', tirage ' . $tirage . ', arrivees ' . implode(',', $arrivees) . ')';

            if ($retenues === []) {
                $this->assertSame($ouverture, $fermeture, 'With no expected fleet the window must close the instant it opens.' . $contexte);

                continue;
            }

            $this->assertSame(
                max($retenues) + CombatRallyWindow::TICK_SECONDS,
                $fermeture,
                'The deadline is not the smallest one that includes the last expected fleet.' . $contexte
            );

            $this->assertLessThanOrEqual($plafond, $fermeture, 'The deadline exceeded the ceiling.' . $contexte);

            foreach ($retenues as $arrivee) {
                $this->assertTrue(
                    CombatRallyWindow::admitsArrivalAt($fermeture, $arrivee),
                    'A fleet counted when computing the deadline was then refused by it.' . $contexte
                );
            }
        }
    }

    /**
     * Les cas de frontiere, ecrits en clair et sans hasard.
     *
     * Un tirage aleatoire, meme reproductible, ne dit pas quelles frontieres comptent. Ces cas-ci
     * les nomment.
     */
    public function testTheBoundaryCasesAreSpeltOut(): void
    {
        $ouverture = 1_000_000;
        $tick = CombatRallyWindow::TICK_SECONDS;
        $plafond = $ouverture + CombatRallyWindow::WINDOW_SECONDS;

        $this->assertSame(1, $tick, 'The business precision is no longer one second, so every deadline below must be revisited.');

        $this->assertSame(
            $ouverture,
            CombatRallyWindow::closesAt($ouverture, []),
            'With nothing expected the window must close the instant it opens.'
        );

        $this->assertSame(
            $ouverture + $tick,
            CombatRallyWindow::closesAt($ouverture, [$ouverture]),
            'A fleet arriving at the very opening must close the window one tick later.'
        );

        $this->assertSame(
            $plafond,
            CombatRallyWindow::closesAt($ouverture, [$plafond - $tick]),
            'A fleet due one tick before the ceiling must be kept, closing the window exactly at the ceiling.'
        );

        $this->assertSame(
            $ouverture,
            CombatRallyWindow::closesAt($ouverture, [$plafond]),
            'A fleet due exactly at the ceiling must be excluded.'
        );

        // Plusieurs missions a la meme heure : ce sont des flottes distinctes, mais l'echeance ne
        // depend que de l'instant. Trois flottes a la meme seconde ferment la fenetre comme une.
        $this->assertSame(
            $ouverture + 12 + $tick,
            CombatRallyWindow::closesAt($ouverture, [$ouverture + 12, $ouverture + 12, $ouverture + 12]),
            'Several fleets sharing one arrival time moved the deadline.'
        );

        // Le meme evenement livre deux fois ne doit rien changer non plus.
        $uneFois = CombatRallyWindow::closesAt($ouverture, [$ouverture + 7, $ouverture + 30]);
        $deuxFois = CombatRallyWindow::closesAt($ouverture, [$ouverture + 7, $ouverture + 30, $ouverture + 30]);

        $this->assertSame($uneFois, $deuxFois, 'Delivering the same arrival twice changed the deadline.');
    }

    /**
     * Retirer la candidate qui fixait l'echeance raccourcit la fenetre.
     */
    public function testWithdrawingTheFleetThatSetTheDeadlineShortensTheWindow(): void
    {
        $ouverture = 1_000_000;
        $avant = CombatRallyWindow::closesAt($ouverture, [$ouverture + 8, $ouverture + 40]);

        $this->assertSame($ouverture + 41, $avant);

        $apres = CombatRallyWindow::closesAfterWithdrawal($ouverture, [$ouverture + 8], $avant);

        $this->assertSame($ouverture + 9, $apres, 'The window kept waiting for a fleet that will never come.');
    }

    /**
     * Retirer une candidate qui ne fixait pas l'echeance ne change rien.
     */
    public function testWithdrawingAnyOtherCandidateChangesNothing(): void
    {
        $ouverture = 1_000_000;
        $avant = CombatRallyWindow::closesAt($ouverture, [$ouverture + 8, $ouverture + 40]);

        $this->assertSame(
            $avant,
            CombatRallyWindow::closesAfterWithdrawal($ouverture, [$ouverture + 40], $avant),
            'Withdrawing a fleet that was not the last one moved the deadline.'
        );
    }

    /**
     * Retirer la derniere candidate ferme le ralliement sur-le-champ.
     */
    public function testWithdrawingTheLastCandidateClosesTheRallyAtOnce(): void
    {
        $ouverture = 1_000_000;
        $avant = CombatRallyWindow::closesAt($ouverture, [$ouverture + 30]);

        $this->assertSame(
            $ouverture,
            CombatRallyWindow::closesAfterWithdrawal($ouverture, [], $avant),
            'With every candidate withdrawn the rally must close immediately.'
        );
    }

    /**
     * L'echeance ne se rallonge jamais, quoi qu'on lui presente.
     *
     * **C'est la garantie, pas une precaution.** Un rappel suivi d'un nouveau lancement, ou une
     * candidate reintroduite par un evenement rejoue, rallongerait sinon une fenetre deja
     * ouverte — et rendrait au harcelement ce que la fenetre dynamique lui a retire.
     *
     * Le test presente donc volontairement des candidates plus tardives que l'echeance en cours,
     * ce qui ne devrait jamais arriver, et verifie que la fenetre ne bouge pas.
     */
    public function testTheDeadlineNeverGrowsBackWhateverItIsHanded(): void
    {
        $ouverture = 1_000_000;
        $courante = CombatRallyWindow::closesAt($ouverture, [$ouverture + 10]);

        $this->assertSame($ouverture + 11, $courante);

        foreach ([[$ouverture + 50], [$ouverture + 10, $ouverture + 59], [$ouverture + 59]] as $restantes) {
            $this->assertLessThanOrEqual(
                $courante,
                CombatRallyWindow::closesAfterWithdrawal($ouverture, $restantes, $courante),
                'A withdrawal lengthened a window that was already open.'
            );
        }
    }

    /**
     * Le meme retrait applique deux fois donne le meme resultat.
     *
     * Un evenement de rappel livre deux fois ne doit pas raccourcir la fenetre une seconde fois,
     * ni la deplacer d'un pas de temps supplementaire.
     */
    public function testApplyingTheSameWithdrawalTwiceIsIdempotent(): void
    {
        $ouverture = 1_000_000;
        $avant = CombatRallyWindow::closesAt($ouverture, [$ouverture + 8, $ouverture + 40]);

        $unePremiereFois = CombatRallyWindow::closesAfterWithdrawal($ouverture, [$ouverture + 8], $avant);
        $uneSecondeFois = CombatRallyWindow::closesAfterWithdrawal($ouverture, [$ouverture + 8], $unePremiereFois);

        $this->assertSame($unePremiereFois, $uneSecondeFois, 'Replaying the same withdrawal moved the deadline again.');
    }
}
