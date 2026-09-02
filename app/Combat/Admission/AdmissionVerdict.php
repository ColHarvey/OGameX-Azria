<?php

namespace OGame\Combat\Admission;

/**
 * Ce qu'un camp a decide de ses candidates, et l'heure qu'il propose pour la fermeture.
 *
 * ## Chaque camp propose, le coordinateur dispose
 *
 * Les deux selecteurs sont separes — politiques differentes, budgets differents — mais **la
 * fermeture est commune**. Chacun rend ses admissions, ses exclusions, et les arrivees de ses
 * candidates admises ; c'est `CombatRallyWindow::closesAt()` — la regle qui existait deja — qui en
 * tire une echeance unique.
 *
 * ## Seules les admises prolongent
 *
 * Une candidate refusee ne repousse jamais la fermeture. La laisser le faire donnerait a un joueur
 * etranger — ou a une flotte au-dela des budgets — le pouvoir de retarder un combat auquel il ne
 * participe pas.
 */
final readonly class AdmissionVerdict
{
    /**
     * @param int $openedAt L'instant d'ouverture, en secondes.
     * @param AdmissionBudget $budget Les plafonds appliques, figes avec le combat.
     * @param array<int, GroupAdmission> $admissions Toutes les candidates, avec leur issue.
     */
    public function __construct(
        public int $openedAt,
        public AdmissionBudget $budget,
        public array $admissions,
    ) {
    }

    /**
     * Les groupes admis, dans l'ordre ou ils ont ete traites.
     *
     * @return array<int, AttackCandidateGroup>
     */
    public function admitted(): array
    {
        $admis = [];

        foreach ($this->admissions as $admission) {
            if ($admission->admitted) {
                $admis[] = $admission->group;
            }
        }

        return $admis;
    }

    /**
     * Les groupes refuses, avec leur raison.
     *
     * @return array<int, GroupAdmission>
     */
    public function refused(): array
    {
        $refuses = [];

        foreach ($this->admissions as $admission) {
            if (!$admission->admitted) {
                $refuses[] = $admission;
            }
        }

        return $refuses;
    }

    /**
     * La derniere arrivee parmi les candidates **admises**, ou `null` s'il n'y en a aucune.
     *
     * C'est ce que ce camp propose pour prolonger la fenetre. Aucune candidate admise signifie que
     * ce camp ne demande aucun delai : si l'autre non plus, fermeture et ouverture coincident.
     */
    public function latestAdmittedArrival(): int|null
    {
        $dernier = null;

        foreach ($this->admitted() as $groupe) {
            $arrivee = $groupe->scheduledArrivalAt();

            if ($dernier === null || $arrivee > $dernier) {
                $dernier = $arrivee;
            }
        }

        return $dernier;
    }

    /**
     * Le verdict, sous une forme lisible dans un message d'essai.
     *
     * @return array<int, string>
     */
    public function describe(): array
    {
        return array_map(
            static fn (GroupAdmission $admission): string => $admission->describe(),
            $this->admissions
        );
    }
}
