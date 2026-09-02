<?php

namespace OGame\Combat\Decisions;

use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Exceptions\NonFinalCombatReason;
use OGame\Combat\Support\ReturnPlan;

/**
 * Le seul type qu'un resultat destine a un joueur peut porter.
 *
 * ## Ce que le passage de type garantit
 *
 * `ArrivalDecision::reason()` leve deja quand la decision porte une exigence ou un code
 * d'invariant. C'est un garde-fou, et il agit **au moment de l'appel** : rien n'empeche un chemin
 * de serialiser l'action sans jamais demander la raison, et de publier ainsi
 * `select_by_attack_admission` dans un rapport.
 *
 * Ce type ferme cette porte-la. Une exigence ou un invariant ne franchit pas la fabrique, donc
 * n'atteint ni le journal, ni un message, ni un `FinalArrivalResolution` :
 *
 *     ArrivalDecision  -> mouvement, y compris ce qui delegue encore
 *     FinalCombatDecision -> ce qui peut etre montre, et rien d'autre
 *
 * ## Ce qu'il ne fait pas
 *
 * Il ne traduit pas. Le `CombatReasonCode` reste un code stable ; les ecrans et les traductions
 * s'en serviront plus tard, sans remettre de logique metier dans l'interface.
 */
final readonly class FinalCombatDecision
{
    /**
     * @param CombatMissionAction $action
     * @param CombatReasonCode $reason
     * @param ReturnPlan|null $returnPlan
     */
    private function __construct(
        public CombatMissionAction $action,
        public CombatReasonCode $reason,
        public ReturnPlan|null $returnPlan,
    ) {
    }

    /**
     * Le resultat final d'une decision, ou un refus.
     *
     * **Un report est refuse lui aussi.** Il n'est pas final : ce qui sera montre au joueur est sa
     * continuation, une fois la resolution close. Accepter le report ici publierait « resolution en
     * cours » comme s'il s'agissait de l'issue de sa mission.
     *
     * @param ArrivalDecision $decision
     * @return self
     */
    public static function of(ArrivalDecision $decision): self
    {
        if (!$decision->isResolved()) {
            throw new NonFinalCombatReason(
                'Une case sans regle n a pas de resultat a montrer : ' . (string)$decision->openQuestion()
            );
        }

        if (!$decision->isFinal()) {
            throw new NonFinalCombatReason(
                'Cette decision porte '
                . ($decision->requirement() !== null
                    ? 'l exigence « ' . $decision->requirement()->value . ' »'
                    : 'le code d invariant « ' . (string)$decision->invariant()?->value . ' »')
                . ' : la publier reviendrait a montrer un etat intermediaire du serveur comme une regle du jeu.'
            );
        }

        if ($decision->action() === CombatMissionAction::DeferUntilResolved) {
            throw new NonFinalCombatReason(
                'Un report n est pas un resultat : ce qui sera montre au joueur est sa continuation, une fois '
                . 'la resolution close.'
            );
        }

        return new self($decision->action(), $decision->reason(), $decision->returnPlan);
    }

    /**
     * Ce qu'un journal ou un message peut porter.
     *
     * @return array<string, string|int|null>
     */
    public function toPlayerFacingFacts(): array
    {
        return [
            'action' => $this->action->value,
            'reason' => $this->reason->value,
            'return_body_id' => $this->returnPlan?->planetId,
        ];
    }
}
