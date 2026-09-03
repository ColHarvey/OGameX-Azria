<?php

namespace OGame\Combat\Services;

use OGame\Combat\Support\ResourceNormalizationDiagnostics;

/**
 * Ce que l'application d'un resultat de bataille a produit, distinct du resultat lui-meme.
 *
 * ## Pourquoi ne pas ecrire dans le `BattleResult`
 *
 * `BattleResult` represente le resultat **calcule et fige a la photographie**. Dans le cycle
 * persistant, il sera serialise a l'ouverture du combat, puis relu des minutes ou des heures plus
 * tard pour etre applique.
 *
 * Y accumuler ce que l'application rencontre melangerait deux instants : le resultat relu ne serait
 * plus celui qui a ete ecrit, et un rejeu du calcul ne redonnerait plus le meme objet. Les
 * diagnostics du **calcul** restent donc dans le resultat fige ; ceux de l'**application** —
 * collectes de Faucheurs, plafonds de cargaison de retour — voyagent ici.
 *
 * L'orchestrateur exterieur fusionne les deux et ecrit un seul audit pour l'operation. Aujourd'hui
 * c'est la mission ; dans le cycle persistant, ce sera le service qui resout l'instance arrivee a
 * echeance, apres la transaction reussie ou par la boite d'envoi.
 */
final readonly class CombatResolutionOutcome
{
    /**
     * Le plafonnement d'une cargaison de retour.
     */
    public const string PHASE_RETURN_CAP = 'return_cap';

    /**
     * Le plafonnement final d'une cargaison de retour, sur l'autre chemin.
     */
    public const string PHASE_RETURN_CAP_FINAL = 'return_cap_final';

    /**
     * La collecte de debris par les Faucheurs attaquants.
     */
    public const string PHASE_ATTACKER_REAPER = 'attacker_reaper';

    /**
     * Le plafonnement de cette collecte par la place restante dans les soutes.
     */
    public const string PHASE_ATTACKER_REAPER_ROOM = 'attacker_reaper_room';

    /**
     * La collecte de debris par les Faucheurs defenseurs.
     */
    public const string PHASE_DEFENDER_REAPER = 'defender_reaper';

    /**
     * @param ResourceNormalizationDiagnostics $diagnostics Ce que l'application a rencontre.
     */
    /**
     * @param ResourceNormalizationDiagnostics $diagnostics Ce que l application a rencontre.
     * @param int $battleReportId Le rapport ecrit : le combat durable l accroche a son instance.
     */
    public function __construct(
        public ResourceNormalizationDiagnostics $diagnostics,
        public int $battleReportId,
    ) {
    }
}
