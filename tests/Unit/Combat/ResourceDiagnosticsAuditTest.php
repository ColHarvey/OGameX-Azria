<?php

namespace Tests\Unit\Combat;

use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OGame\Combat\Enums\OperationKind;
use OGame\Combat\Exceptions\ContradictoryResourceDiagnostic;
use OGame\Combat\Exceptions\MismatchedOperationKey;
use OGame\Combat\Services\CombatResolutionOutcome;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\OperationKey;
use OGame\Combat\Support\ResourceDiagnostic;
use OGame\Combat\Support\ResourceDiagnosticsJournal;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Combat\Support\SealedResourceDiagnostics;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\UnitTestCase;

/**
 * L'audit des conversions de ressources : un seul journal, et rien de perdu.
 *
 * ## Les deux moments qu'il ne faut pas melanger
 *
 * Le moteur fige ses diagnostics dans le `BattleResult` : ils appartiennent au **calcul**. Le
 * service de resolution rend les siens separement : ils appartiennent a l'**application** du
 * resultat, qui aura lieu des heures plus tard dans le cycle persistant.
 *
 * Ecrire les seconds dans le premier ferait que le resultat relu ne serait plus celui qui a ete
 * ecrit — et un rejeu du calcul ne redonnerait plus le meme objet.
 */
class ResourceDiagnosticsAuditTest extends UnitTestCase
{
    /**
     * Rien a signaler : aucun journal du tout.
     */
    public function testNothingToReportWritesNothing(): void
    {
        $journal = Log::spy();

        ResourceDiagnosticsJournal::report(
            SealedResourceDiagnostics::seal(self::operation(), ResourceNormalizationDiagnostics::none())
        );

        $journal->shouldNotHaveReceived('warning');
    }

    /**
     * Plusieurs diagnostics de plusieurs sources : exactement un journal, et il les contient tous.
     *
     * **C'est le test qui compte.** Verifier separement « le moteur ecrit une fois » puis « la
     * resolution ecrit une fois » laisserait passer deux journaux quand les deux sont combines.
     */
    public function testSeveralSourcesProduceExactlyOneJournalContainingEverything(): void
    {
        // Ce que le moteur a fige : la conversion du stock de la cible.
        $duCalcul = ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, 'target_loot', '', 'metal', 9007199254740992)
        );

        // Ce que l'application a rencontre : trois etapes distinctes.
        $deLApplication = ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, CombatResolutionOutcome::PHASE_ATTACKER_REAPER, '', 'metal', 9007199254740992)
        )->mergedWith(ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, CombatResolutionOutcome::PHASE_DEFENDER_REAPER, '', 'metal', 9007199254740992)
        ))->mergedWith(ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::NEGATIVE_ARTIFACT_NORMALIZED, CombatResolutionOutcome::PHASE_RETURN_CAP, CombatParticipantKey::forFleet(101), 'crystal', 0)
        ));

        $ensemble = $duCalcul->mergedWith($deLApplication);

        // Quatre occurrences, dont trois de meme code et meme ressource venues d'etapes differentes.
        $this->assertSame(4, $ensemble->count(), 'Occurrences from different steps were merged into one.');

        $capture = [];
        Log::listen(function ($message) use (&$capture): void {
            $capture[] = $message;
        });

        ResourceDiagnosticsJournal::report(SealedResourceDiagnostics::seal(self::operation(), $ensemble));

        $this->assertCount(1, $capture, 'One operation must leave exactly one trace.');
        $this->assertSame('warning', $capture[0]->level);

        // Et le contenu est le multiensemble complet, comptes et provenances compris.
        $this->assertSame(
            [
                ResourceNormalizationDiagnostics::NEGATIVE_ARTIFACT_NORMALIZED => [
                    'crystal' => [
                        'occurrenceCount' => 1,
                        'units' => [0],
                        'provenances' => ['return_cap:' . CombatParticipantKey::forFleet(101)],
                    ],
                ],
                ResourceNormalizationDiagnostics::PRECISION_DEGRADED => [
                    'metal' => [
                        'occurrenceCount' => 3,
                        'units' => [9007199254740992, 9007199254740992, 9007199254740992],
                        'provenances' => ['attacker_reaper', 'defender_reaper', 'target_loot'],
                    ],
                ],
            ],
            $capture[0]->context['diagnostics'],
            'The journal lost occurrences: three separate incidents were reported as fewer.'
        );
    }

    /**
     * Deux incidents identiques a des etapes differentes restent deux occurrences.
     *
     * Meme code, meme ressource, meme valeur — mais l'un vient du pillage de la cible et l'autre du
     * plafonnement d'un retour. Les fondre remplacerait deux avertissements par un seul, incomplet.
     */
    public function testIdenticalIncidentsAtDifferentStepsStayDistinct(): void
    {
        $premier = ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, 'target_loot', '', 'metal', 42)
        );
        $second = ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, 'return_cap', '', 'metal', 42)
        );

        $this->assertSame(2, $premier->mergedWith($second)->count());
        $this->assertSame(
            2,
            $premier->mergedWith($second)->groupedByCode()[ResourceNormalizationDiagnostics::PRECISION_DEGRADED]['metal']['occurrenceCount']
        );
    }

    /**
     * La meme occurrence propagee deux fois n'est comptee qu'une fois.
     *
     * Le pipeline la rend, l'appelant l'agrege, la mission la journalise : elle traverse plusieurs
     * mains sans devenir plusieurs incidents.
     */
    public function testTheSameOccurrencePropagatedTwiceCountsOnce(): void
    {
        $occurrence = ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, 'target_loot', '', 'metal', 42)
        );

        $this->assertSame(1, $occurrence->mergedWith($occurrence)->count());
    }

    /**
     * Une meme identite portant deux contenus differents est une violation d'invariant.
     *
     * Garder l'un des deux effacerait un incident reel ; les garder tous deux sous une meme identite
     * rendrait la deduplication arbitraire.
     */
    public function testTheSameIdentityWithTwoContentsIsRefused(): void
    {
        $premier = ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, 'target_loot', '', 'metal', 42)
        );
        $contradictoire = ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, 'target_loot', '', 'metal', 43)
        );

        $this->expectException(ContradictoryResourceDiagnostic::class);

        $premier->mergedWith($contradictoire);
    }

    /**
     * La fusion est associative et ne depend pas de l'ordre.
     *
     * L'ordre dans lequel les etapes remontent leurs diagnostics est un accident d'implementation ;
     * le journal final ne doit pas en dependre.
     */
    public function testTheMergeIsAssociativeAndOrderIndependent(): void
    {
        $a = ResourceNormalizationDiagnostics::of(new ResourceDiagnostic('c', 'phase_a', '', 'metal', 1));
        $b = ResourceNormalizationDiagnostics::of(new ResourceDiagnostic('c', 'phase_b', '', 'crystal', 2));
        $c = ResourceNormalizationDiagnostics::of(new ResourceDiagnostic('c', 'phase_c', '', 'deuterium', 3));

        $gauche = $a->mergedWith($b)->mergedWith($c);
        $droite = $a->mergedWith($b->mergedWith($c));
        $inverse = $c->mergedWith($b)->mergedWith($a);

        $this->assertEquals($gauche->occurrences, $droite->occurrences, 'The merge is not associative.');
        $this->assertEquals($gauche->occurrences, $inverse->occurrences, 'The merge depends on the order of its operands.');
        $this->assertSame(array_keys($gauche->occurrences), array_keys($inverse->occurrences), 'The order of the result is not stable.');
    }

    /**
     * Deux flottes distinctes, meme phase, meme ressource, meme contenu : deux occurrences.
     *
     * ## Le defaut que ce test ferme
     *
     * `phase|subject|resource` ne suffit que si le sujet porte une identite metier **stable et
     * unique**. Sans lui, deux retours plafonnes dans `return_cap` sur le metal, avec exactement le
     * meme diagnostic, porteraient l identite `return_cap||metal` et fusionneraient en une seule
     * occurrence — la moitie de l incident disparaitrait du journal.
     */
    public function testTwoDistinctFleetsKeepTwoOccurrences(): void
    {
        $premiere = ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            CombatResolutionOutcome::PHASE_RETURN_CAP,
            CombatParticipantKey::forFleet(101),
            'metal',
            42
        ));

        $seconde = ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            CombatResolutionOutcome::PHASE_RETURN_CAP,
            CombatParticipantKey::forFleet(102),
            'metal',
            42
        ));

        $ensemble = $premiere->mergedWith($seconde);

        $this->assertSame(2, $ensemble->count(), 'Two distinct fleets were merged into one occurrence.');
        $this->assertSame(
            2,
            $ensemble->groupedByCode()[ResourceNormalizationDiagnostics::PRECISION_DEGRADED]['metal']['occurrenceCount']
        );

        // Rejouer les memes faits redonne les memes identites : aucune part d aleatoire.
        $this->assertSame(array_keys($ensemble->occurrences), array_keys($premiere->mergedWith($seconde)->occurrences));
    }

    /**
     * La projection echoue sur une valeur qu elle ne sait pas figer, en la nommant.
     *
     * Les objets ordinaires sont parcourus propriete par propriete — c est complet, pas un resume.
     * Ce qu elle refuse, c est ce qui n a **pas d etat comparable** : une fermeture, dont figer le
     * contenu donnerait un tableau vide identique avant et apres, masquant exactement les mutations
     * recherchees.
     */
    public function testTheSnapshotRefusesAValueItCannotFreeze(): void
    {
        $resultat = new BattleResult();
        $resultat->wreckField = ['inconnu' => static fn (): int => 1];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wreckField\.inconnu/');

        self::snapshotOf($resultat);
    }

    /**
     * L'instantané d'un `BattleResult` couvre toutes ses proprietes.
     *
     * ## Pourquoi par reflexion, et pas par une liste ecrite a la main
     *
     * `BattleResult` porte quarante proprietes publiques, `AttackerFleetResult` dix. Une liste ecrite
     * a la main se perimerait au premier ajout — et ce chantier en a ajoute sept. Le champ oublie
     * serait alors mutable sans que rien ne le remarque, et le test resterait vert.
     *
     * La projection enumere donc, et echoue sur un type qu'elle ne sait pas representer : ajouter un
     * champ d'un genre nouveau oblige a decider comment le figer.
     */
    public function testTheSnapshotCoversEveryPropertyOfTheResult(): void
    {
        $resultat = new BattleResult();
        $resultat->attackerFleetResults = [new AttackerFleetResult(101, 7, new UnitCollection())];

        $couvertes = array_keys(self::snapshotOf($resultat));
        $attendues = [];

        foreach ((new ReflectionClass(BattleResult::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $propriete) {
            if (!$propriete->isStatic()) {
                $attendues[] = $propriete->getName();
            }
        }

        sort($couvertes);
        sort($attendues);

        $this->assertSame(
            $attendues,
            $couvertes,
            'A property of BattleResult is not represented in the snapshot: it could be mutated unnoticed.'
        );
    }

    /**
     * Deux operations distinctes ne fusionnent pas, meme avec les memes incidents.
     *
     * ## Ce que l enveloppe rend impossible
     *
     * `attacker_reaper||metal` designe le meme incident dans deux combats differents : l identite
     * locale ne suffit pas. L identite pleinement qualifiee est la paire **cle d operation +
     * identite locale**, et deux enveloppes de cles differentes refusent de fusionner.
     *
     * Le refus est structurel : sceller apres avoir fusionne aurait deja detruit l information qui
     * permet de le constater.
     */
    public function testTwoDistinctOperationsNeverMerge(): void
    {
        $incident = ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
            ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
            CombatResolutionOutcome::PHASE_ATTACKER_REAPER,
            '',
            'metal',
            42
        ));

        $attaque = SealedResourceDiagnostics::seal(OperationKey::rehydrate(OperationKind::ImmediateAttack, 7), $incident);
        $autreAttaque = SealedResourceDiagnostics::seal(OperationKey::rehydrate(OperationKind::ImmediateAttack, 8), $incident);

        // Les identites pleinement qualifiees different, alors que les incidents sont identiques.
        $this->assertNotSame($attaque->qualifiedIdentities(), $autreAttaque->qualifiedIdentities());

        $this->expectException(MismatchedOperationKey::class);

        $attaque->mergedWith($autreAttaque);
    }

    /**
     * Deux genres d operation portant le meme nombre restent distincts.
     *
     * La mission de flotte 42 et l instance de combat 42 viennent de tables differentes : sans le
     * genre dans la cle, elles partageraient une identite.
     */
    public function testTwoKindsSharingANumberStayDistinct(): void
    {
        $attaque = OperationKey::rehydrate(OperationKind::ImmediateAttack, 42);
        $lune = OperationKey::rehydrate(OperationKind::MoonDestruction, 42);

        $this->assertFalse($attaque->equals($lune), 'Two operation kinds with the same number were confused.');
        $this->assertNotSame($attaque->asString(), $lune->asString());
    }

    /**
     * La meme operation rejouee donne les memes identites completes.
     *
     * Aucune part d aleatoire, d horloge ni de compteur : rejouer les memes faits geles doit rendre
     * exactement les memes identites, sans quoi le calcul cesserait d etre rejouable.
     */
    public function testReplayingAnOperationGivesTheSameQualifiedIdentities(): void
    {
        $construire = static fn (): SealedResourceDiagnostics => SealedResourceDiagnostics::seal(
            OperationKey::rehydrate(OperationKind::ImmediateAttack, 7),
            ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
                ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
                CombatResolutionOutcome::PHASE_RETURN_CAP,
                CombatParticipantKey::forFleet(101),
                'metal',
                42
            ))
        );

        $this->assertSame($construire()->qualifiedIdentities(), $construire()->qualifiedIdentities());
        $this->assertSame(
            ['immediate_attack:7|return_cap|fleet:101|metal'],
            $construire()->qualifiedIdentities()
        );
    }

    /**
     * Deux enveloppes de la meme operation fusionnent.
     */
    public function testTwoEnvelopesOfTheSameOperationMerge(): void
    {
        $cle = OperationKey::rehydrate(OperationKind::ImmediateAttack, 7);

        $duCalcul = SealedResourceDiagnostics::seal($cle, ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, 'target_loot', '', 'metal', 42)
        ));
        $deLApplication = SealedResourceDiagnostics::seal($cle, ResourceNormalizationDiagnostics::of(
            new ResourceDiagnostic(ResourceNormalizationDiagnostics::PRECISION_DEGRADED, CombatResolutionOutcome::PHASE_DEFENDER_REAPER, '', 'metal', 42)
        ));

        $this->assertSame(2, $duCalcul->mergedWith($deLApplication)->diagnostics->count());
    }

    /**
     * Une cle sans identifiant persiste est refusee.
     *
     * Un identifiant absent ferait porter a plusieurs operations la meme cle, et leurs diagnostics
     * fusionneraient — c est exactement ce que l enveloppe existe pour empecher.
     */
    public function testAKeyWithoutAPersistedIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OperationKey::rehydrate(OperationKind::ImmediateAttack, 0);
    }

    /**
     * La cle d operation de reference de ces essais.
     *
     * @return OperationKey
     */
    private static function operation(): OperationKey
    {
        return OperationKey::rehydrate(OperationKind::ImmediateAttack, 7);
    }

    /**
     * Une projection profonde d'un resultat de bataille.
     *
     * **Independante de l'objet.** `$avant = $resultat` copierait la reference : les deux variables
     * designeraient le meme objet, et une mutation interne laisserait le test vert.
     *
     * @param BattleResult $resultat
     * @return array<string, mixed>
     */
    public static function snapshotOf(BattleResult $resultat): array
    {
        $projection = [];

        foreach ((new ReflectionClass($resultat))->getProperties(ReflectionProperty::IS_PUBLIC) as $propriete) {
            if ($propriete->isStatic()) {
                continue;
            }

            $nom = $propriete->getName();
            $projection[$nom] = $propriete->isInitialized($resultat)
                ? self::project($propriete->getValue($resultat), $nom)
                : '(non initialisee)';
        }

        return $projection;
    }

    /**
     * La forme figee d'une valeur.
     *
     * @param mixed $valeur
     * @param string $chemin
     * @return mixed
     */
    private static function project(mixed $valeur, string $chemin): mixed
    {
        if ($valeur === null || is_scalar($valeur)) {
            return $valeur;
        }

        if (is_array($valeur)) {
            $projete = [];

            foreach ($valeur as $cle => $element) {
                $projete[$cle] = self::project($element, $chemin . '.' . $cle);
            }

            return $projete;
        }

        if ($valeur instanceof UnitCollection) {
            return $valeur->toArray();
        }

        if ($valeur instanceof \OGame\Models\Resources) {
            return [$valeur->metal->get(), $valeur->crystal->get(), $valeur->deuterium->get(), $valeur->energy->get()];
        }

        if ($valeur instanceof ResourceNormalizationDiagnostics) {
            return $valeur->groupedByCode();
        }

        if ($valeur instanceof Closure) {
            // Une fermeture n a pas d etat observable : la figer donnerait un tableau vide, et une
            // mutation de ce qu elle capture passerait inapercue.
            throw new RuntimeException(
                'La projection ne sait pas figer « ' . $chemin . ' » : une fermeture n a pas d etat comparable.'
            );
        }

        if (is_object($valeur)) {
            // **Un parcours complet, pas un resume.** Tout autre objet est fige propriete publique
            // par propriete publique, recursivement : c est deterministe, exhaustif, et une mutation
            // interne s y voit. Un marqueur identique avant et apres masquerait exactement ce que cet
            // instantane doit detecter.
            if (substr_count($chemin, '.') > 12) {
                throw new RuntimeException(
                    'La projection s enfonce trop loin sous « ' . $chemin . ' » : le graphe est probablement cyclique.'
                );
            }

            $sous = [];

            foreach ((new ReflectionClass($valeur))->getProperties(ReflectionProperty::IS_PUBLIC) as $propriete) {
                if ($propriete->isStatic()) {
                    continue;
                }

                $sous[$propriete->getName()] = $propriete->isInitialized($valeur)
                    ? self::project($propriete->getValue($valeur), $chemin . '.' . $propriete->getName())
                    : '(non initialisee)';
            }

            ksort($sous);

            return $sous;
        }

        throw new RuntimeException(
            'La projection ne sait pas figer « ' . $chemin . ' » (type ' . get_debug_type($valeur) . ').'
        );
    }
}
