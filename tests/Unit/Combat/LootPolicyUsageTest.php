<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\NoLootReason;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\UnitTestCase;

/**
 * Qui a le droit de piller, et qui decide du taux : verifie sur le code lui-meme.
 *
 * ## Pourquoi un controle structurel
 *
 * Deux regles de ce chantier ne se voient dans aucun resultat de bataille :
 *
 * - le moteur ne doit plus observer les donnees vivantes pour fixer son taux ;
 * - chaque site de combat doit **declarer** son genre de contexte, et non en heriter un.
 *
 * Un docblock ne les fait pas respecter. Un nouveau site de combat ajoute dans six mois copierait
 * le voisin le plus proche, et un contexte de pillage ordinaire donnerait alors du butin a un
 * combat qui n'y a pas droit — sans qu'aucun test de comportement ne s'en apercoive, puisque les
 * missions concernees jettent aujourd'hui ce butin.
 */
class LootPolicyUsageTest extends UnitTestCase
{
    /**
     * Les sites de production qui construisent un moteur de combat, et le genre de contexte que
     * chacun doit fournir.
     *
     * `null` signifie : ce combat pille normalement, et photographie donc les faits vivants.
     *
     * @return array<string, NoLootReason|null>
     */
    private function knownConstructionSites(): array
    {
        return [
            // Une attaque pille : c'est le cas nominal, applique par CombatResolutionService.
            'app/GameMissions/AttackMission.php' => null,

            // Une destruction de lune pille aussi : le butin est preleve par deductResources().
            'app/GameMissions/MoonDestructionMission.php' => null,

            // Un contre-espionnage se bat sans rien emporter ; le rapport annonce deja zero.
            'app/GameMissions/EspionageMission.php' => NoLootReason::CounterEspionage,

            // Une rencontre d'expedition ne pille pas — et la planete PNJ est batie sur celle de
            // depart de l'attaquant, qu'un contexte ordinaire viderait.
            'app/GameMissions/ExpeditionMission.php' => NoLootReason::NpcEncounter,

            // L'outil de mesure ne prend rien nulle part.
            'app/Console/Commands/Test/TestBattleEnginePerformance.php' => NoLootReason::SyntheticBenchmark,
        ];
    }

    /**
     * Aucun site de combat n'echappe a l'inventaire.
     *
     * Si un nouveau fichier construit un moteur, ce test echoue tant que son genre de contexte n'a
     * pas ete choisi et inscrit ci-dessus.
     */
    public function testEveryConstructionSiteIsClassified(): void
    {
        $trouves = [];

        foreach ($this->productionFiles() as $chemin => $contenu) {
            if (preg_match('/new (?:Php|Rust)BattleEngine\(|new \$engineClass\(/', $contenu) === 1) {
                $trouves[] = $chemin;
            }
        }

        sort($trouves);
        $attendus = array_keys($this->knownConstructionSites());
        sort($attendus);

        $this->assertSame(
            $attendus,
            $trouves,
            'A battle engine is built somewhere that has not declared its loot context kind. '
            . 'Choose one — normal loot, or a named refusal — and record it in this test.'
        );
    }

    /**
     * Chaque site fournit bien le genre de contexte qui lui a ete attribue.
     */
    public function testEverySiteProvidesTheContextKindItWasGiven(): void
    {
        foreach ($this->knownConstructionSites() as $chemin => $refus) {
            $contenu = (string)file_get_contents(base_path($chemin));

            if ($refus === null) {
                // Un site pillard passe par la frontiere de mission, qui degrade proprement le
                // refus de domaine au lieu de laisser une exception boucler dans l ordonnanceur.
                $this->assertStringContainsString(
                    'LootContextForMission::lootingOrDegraded(',
                    $contenu,
                    "{$chemin} is supposed to allow looting, but never takes a live snapshot."
                );

                $this->assertStringNotContainsString(
                    'LiveLootContextFactory::withoutLoot(',
                    $contenu,
                    "{$chemin} is supposed to allow looting, yet refuses it."
                );

                continue;
            }

            $this->assertStringContainsString(
                'LiveLootContextFactory::withoutLoot(',
                $contenu,
                "{$chemin} must refuse looting explicitly."
            );

            $this->assertStringContainsString(
                'NoLootReason::' . $refus->name,
                $contenu,
                "{$chemin} must refuse looting with the reason « {$refus->value} », and say so explicitly."
            );

            $this->assertStringNotContainsString(
                'LootContextForMission::lootingOrDegraded(',
                $contenu,
                "{$chemin} must not grant looting: it would take resources it has no right to."
            );
        }
    }

    /**
     * Aucun moteur n'est construit sans son contexte.
     *
     * Le parametre est obligatoire, donc PHP l'imposerait de toute facon — mais l'erreur
     * n'apparaitrait qu'a l'execution du combat concerne, c'est-a-dire en jeu. Ce controle la fait
     * apparaitre a la premiere execution de la suite.
     */
    public function testNoEngineIsBuiltWithoutItsContext(): void
    {
        foreach ($this->knownConstructionSites() as $chemin => $ignore) {
            $contenu = (string)file_get_contents(base_path($chemin));

            $this->assertMatchesRegularExpression(
                '/\$lootContext/',
                $contenu,
                "{$chemin} builds a battle engine without passing a loot context."
            );
        }
    }

    /**
     * L'ancienne methode ne revient ni dans le moteur, ni dans le systeme de combat.
     *
     * Elle est conservee pour la compatibilite avec le depot amont, et son essai nominal reste
     * valable : elle decrit le bonus de classe. Mais elle ne consulte pas l'etat de la cible, et
     * ne doit plus servir a fixer un pillage.
     */
    public function testTheDeprecatedMethodNeverComesBackIntoTheCombatCode(): void
    {
        $fautifs = [];

        foreach ($this->productionFiles() as $chemin => $contenu) {
            if (!str_starts_with($chemin, 'app/GameMissions/BattleEngine/') && !str_starts_with($chemin, 'app/Combat/')) {
                continue;
            }

            if (str_contains($contenu, 'getInactiveLootPercentage')) {
                $fautifs[] = $chemin;
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            "getInactiveLootPercentage() is back in the combat code. It receives the attacker and never looks at "
            . "the target's inactivity: the loot rate comes from OGame\\Combat\\Support\\LootPolicy.\n  - "
            . implode("\n  - ", $fautifs)
        );
    }

    /**
     * La methode obsolete est bien marquee comme telle.
     */
    public function testTheDeprecatedMethodIsMarked(): void
    {
        $contenu = (string)file_get_contents(base_path('app/Services/CharacterClassService.php'));
        $avant = substr($contenu, 0, (int)strpos($contenu, 'public function getInactiveLootPercentage'));

        $this->assertStringContainsString(
            '@deprecated',
            substr($avant, -1_500),
            'getInactiveLootPercentage() has no @deprecated marker, so new code has no warning that it is a trap.'
        );
    }

    /**
     * Le nom du compte systeme n a qu une seule source.
     *
     * **C est un identifiant deguise en libelle.** Il decidait, en clair et en onze endroits, qui
     * est exclu des classements, qui ne peut pas etre attaque, et quel compte la generation de rangs
     * ignore. Une faute de frappe dans l un d eux se serait vue a l usage, jamais a la lecture — et
     * `ActorKindResolver` en aurait ajoute une douzieme.
     *
     * @return void
     */
    public function testTheSystemAccountNameHasASingleSource(): void
    {
        $fautifs = [];

        foreach ($this->productionFiles() as $chemin => $contenu) {
            if ($chemin === 'app/Models/User.php') {
                // La source unique elle-meme, qui doit bien porter la chaine.
                continue;
            }

            if (str_contains($contenu, "'Legor'")) {
                $fautifs[] = $chemin;
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            "The system account name is written by hand instead of coming from User::SYSTEM_ACCOUNT_USERNAME. "
            . "A typo in one of these would only show at runtime:
  - "
            . implode("
  - ", $fautifs)
        );
    }

    /**
     * Aucun calculateur n a de dependance de journalisation.
     *
     * **Une garde architecturale, pas une preuve de purete.** Elle constate qu aucune dependance
     * externe n a ete introduite ; le determinisme et l absence d etat residuel sont verifies par
     * les essais comportementaux, pas par un balayage de texte.
     *
     * Pourquoi elle compte : une seule resolution de combat traverse la distribution six fois. Un
     * journal pose dans un calculateur produirait six lignes pour une operation — et rendrait le
     * calcul non rejouable, puisque relire un resultat le reecrirait.
     */
    public function testNoCalculatorHasALoggingDependency(): void
    {
        $calculateurs = [
            'app/Combat/Support/ResourceBoundary.php',
            'app/Combat/Allocation/ExactLootAllocationV1.php',
            'app/Combat/Policies/CargoWeightedV1.php',
            'app/Combat/Policies/NpcBaseV1.php',
            'app/Combat/Policies/NoLootV1.php',
            'app/GameMissions/BattleEngine/Services/LootService.php',
            'app/GameMissions/BattleEngine/BattleEngine.php',
        ];

        foreach ($calculateurs as $chemin) {
            $source = (string)file_get_contents(base_path($chemin));

            foreach (['Log::', 'Illuminate\Support\Facades\Log'] as $interdit) {
                $this->assertStringNotContainsString(
                    $interdit,
                    $source,
                    "{$chemin} writes a journal. One resolution crosses the distribution six times: "
                    . 'the outermost orchestrator is the only place that may write, once.'
                );
            }
        }
    }

    /**
     * La journalisation appartient aux orchestrateurs, et ils la font.
     */
    public function testTheOrchestratorsOwnTheJournal(): void
    {
        foreach (['app/GameMissions/AttackMission.php', 'app/GameMissions/RecycleMission.php'] as $chemin) {
            $source = (string)file_get_contents(base_path($chemin));

            $this->assertStringContainsString(
                'ResourceDiagnosticsJournal::report(',
                $source,
                "{$chemin} never reports what its resource conversions met: a silent normalisation drift "
                . 'would only surface as a player complaint.'
            );

            $this->assertSame(
                1,
                substr_count($source, 'ResourceDiagnosticsJournal::report('),
                "{$chemin} reports more than once for a single operation."
            );
        }
    }

    /**
     * Tous les fichiers PHP de production, indexes par chemin relatif.
     *
     * **Le balayage s arrete a `app/`, et c est delibere.** Une migration deja appliquee doit rester
     * autonome et reproductible avec le code de son epoque : lui faire dependre d une constante
     * d aujourd hui la rendrait fausse le jour ou cette constante changerait, et un rejeu de
     * l historique ne produirait plus la meme base.
     *
     * `2024_05_17_213750_add_roles.php` ecrit donc encore le nom du compte systeme en clair, onze
     * fois, et doit continuer de le faire. Les seeders et les fixtures representant des donnees
     * anciennes relevent du meme raisonnement.
     *
     * @return array<string, string>
     */
    private function productionFiles(): array
    {
        $fichiers = [];
        $racine = base_path('app');

        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));

        foreach ($iterateur as $fichier) {
            if (!$fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
                continue;
            }

            $contenu = file_get_contents($fichier->getPathname());

            if (!is_string($contenu)) {
                continue;
            }

            $relatif = str_replace('\\', '/', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $fichier->getPathname()));
            $fichiers[$relatif] = $contenu;
        }

        return $fichiers;
    }
}
