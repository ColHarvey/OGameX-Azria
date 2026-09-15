<?php

namespace OGame\Military;

/**
 * Les chemins qui créditent les cumuls militaires, et le témoin d'effet qui prouve que chacun crédite réellement.
 *
 * ## Déclaré raccordé ne suffit pas
 *
 * Une source n'est raccordée que si elle nomme son **témoin d'effet** : un essai de la suite qui fait passer le chemin
 * réel et exige l'événement inscrit. Trois gardes tiennent ce nom pour une preuve :
 *
 * 1. `MilitaryTallySourcesTest` exige que chaque témoin nommé existe, soit un essai public, et que la liste des sources
 *    soit exactement celle-ci — une source ne disparaît pas en silence ;
 * 2. la suite complète, en intégration continue, exige que ce témoin passe ;
 * 3. la batterie de mutations exige qu'il **tombe** quand le crédit est retiré de son chemin.
 *
 * `ogamex:military:demarrer-cumuls` refuse tant qu'une source n'a pas de témoin, et les nomme. Une source sans témoin
 * vaut `null`.
 */
final class MilitaryTallySources
{
    /**
     * Chaque source, et son témoin d'effet — `Classe::méthode` —, ou `null` tant qu'elle n'est pas raccordée.
     *
     * @var array<string, string|null>
     */
    public const array WITNESSES = [
        'construction' => 'Tests\\Feature\\MilitaryTalliesBuildTest::testEachDeliveredSliceIsOneEventAndTheSlicesCoverTheProgressExactly',
        'demi-temps' => 'Tests\\Feature\\MilitaryTalliesBuildTest::testTheHalvingCreditsWhatItDeliversAndTheProgressionResumesWithoutRecounting',
        'bataille' => 'Tests\\Feature\\MilitaryTalliesBattleTest::testADurableGroupBattleCreditsEachRankedParticipantOnceFromItsRounds',
        'manoeuvre-de-hamill' => 'Tests\\Feature\\MilitaryTalliesHamillTest::testADurableBattleUnderTheManoeuvreCreditsTheStarOnceToTheGeneralConsulted',
        'missile' => 'Tests\\Feature\\MilitaryTalliesMissileTest::testAStrikeCreditsTheInterceptionToTheDefenderAndTheDestroyedDefencesToBoth',
        'destruction-de-lune' => 'Tests\\Feature\\MilitaryTalliesMoonDestructionTest::testTheDeathstarsLostInACatastrophicAttemptAreLostWithoutADestroyerCredited',
        'expedition' => 'Tests\\Feature\\MilitaryTalliesExpeditionTest::testAnExpeditionBattleCreditsThePlayerWithWhatHeLostAndWhatHeShotDown',
        'contre-espionnage' => 'Tests\\Feature\\MilitaryTalliesEspionageTest::testACounterEspionageBattleCreditsTheSpyAndTheDefenderSymmetrically',
        'espace-libre' => 'Tests\\Feature\\MilitaryTalliesSpatialTest::testABattleInFreeSpaceCreditsBothPlayersLikeAnyBattle',
    ];

    /**
     * @param array<string, string|null>|null $witnesses Les témoins, remplacés seulement par un essai qui éprouve la
     *        commande d'activation elle-même.
     */
    public function __construct(private array|null $witnesses = null)
    {
    }

    /**
     * @return array<string, string|null>
     */
    public function witnesses(): array
    {
        return $this->witnesses ?? self::WITNESSES;
    }

    /**
     * Les sources qui n'ont pas encore de témoin d'effet.
     *
     * @return list<string>
     */
    public function unwired(): array
    {
        $manquantes = [];

        foreach ($this->witnesses() as $source => $temoin) {
            if ($temoin === null || trim($temoin) === '') {
                $manquantes[] = $source;
            }
        }

        return $manquantes;
    }
}
