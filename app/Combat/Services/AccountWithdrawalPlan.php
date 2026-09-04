<?php

namespace OGame\Combat\Services;

use OGame\Combat\Enums\CombatCancellationCause;

/**
 * Ce qu'un compte doit faire de ses combats avant de disparaitre — decide avant tout effet.
 *
 * ## Pourquoi un plan complet d'abord
 *
 * Le retrait annulait combat par combat, et ne decouvrait qu'en arrivant a sa ligne qu'un combat
 * retenait la suppression. Un compte engage dans deux combats — le premier annulable, le second
 * renforce ou en cours d'application — perdait donc le premier, et gardait le compte. Une partie de
 * ses batailles avait disparu pour rien, et personne ne pouvait la rendre.
 *
 * Le plan se construit entierement avant la premiere annulation : chaque combat recoit sa cause, ou
 * la raison qui retient tout. **Un seul empechement suffit a ne rien faire du tout.**
 *
 * ## Ce que l'inventaire seul ne voyait pas
 *
 * Un plan ne protege que les lignes deja la. Une flotte du compte **encore en vol** peut atteindre
 * le travailleur apres l'inventaire et ouvrir ou rejoindre un combat : ses missions seraient alors
 * effacees, ce combat garderait une initiatrice qui n'existe plus, et sa barriere tiendrait un corps
 * pour toujours. Elles retiennent donc la suppression jusqu'a ce qu'elles soient finales. L'ensemble
 * ne peut que retrecir, puisque le compte ne lance plus rien.
 *
 * ## Ce que le plan ne fait pas
 *
 * Il ne verrouille rien et n'ecrit rien. Il decrit. L'application vient apres, et c'est elle qui
 * prend les verrous de l'ordre global, combat par combat.
 */
final readonly class AccountWithdrawalPlan
{
    /**
     * @param array<int, CombatCancellationCause> $aAnnuler Identifiant de combat -> cause.
     * @param array<int, string> $empechements Identifiant de combat -> ce qui retient la suppression.
     * @param array<int, int> $flottesQuiPeuventEncoreEngager Missions en vol capables d'ouvrir ou de
     *        rejoindre un combat apres l'inventaire.
     */
    public function __construct(
        public array $aAnnuler,
        public array $empechements,
        public array $flottesQuiPeuventEncoreEngager = [],
    ) {
    }

    /**
     * Si quelque chose retient la suppression. Alors **rien** n'est annule.
     */
    public function deferred(): bool
    {
        return $this->empechements !== [] || $this->flottesQuiPeuventEncoreEngager !== [];
    }

    /**
     * Ce qui retient la suppression, en une phrase lisible par un administrateur.
     */
    public function reason(): string
    {
        $lignes = [];

        foreach ($this->empechements as $combat => $pourquoi) {
            $lignes[] = 'combat ' . $combat . ' : ' . $pourquoi;
        }

        if ($this->flottesQuiPeuventEncoreEngager !== []) {
            $lignes[] = 'les flottes ' . implode(', ', $this->flottesQuiPeuventEncoreEngager)
                . ' sont encore en vol et peuvent ouvrir ou rejoindre un combat';
        }

        return implode(' ; ', $lignes);
    }

    /**
     * Les combats qui retiennent la suppression, par identifiant croissant.
     *
     * @return array<int, int>
     */
    public function blockingCombatIds(): array
    {
        $identifiants = array_map(static fn (mixed $id): int => (int)$id, array_keys($this->empechements));
        sort($identifiants);

        return $identifiants;
    }
}
