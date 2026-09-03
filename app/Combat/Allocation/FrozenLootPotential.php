<?php

namespace OGame\Combat\Allocation;

use OGame\Combat\Exceptions\CorruptedFrozenLootAmounts;
use OGame\Combat\Exceptions\MismatchedRuleVersionSet;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CombatInstance;

/**
 * Le butin potentiel d'un combat : fige une fois, depuis la photographie, apres l'issue du moteur.
 *
 * ## Ce que « potentiel » veut dire
 *
 * C'est ce que l'attaquant aurait pris si la cible avait encore tout ce qu'elle portait a la
 * photographie. Il comprend le taux gele, les ressources photographiees et la capacite libre des
 * attaquants survivants — trois faits que le moteur a deja combines dans `BattleResult::$loot`, a
 * partir du contexte gele qu'on lui a donne.
 *
 * Cette classe ne recalcule donc rien. Elle **fige** ce que le moteur a rendu : elle le convertit en
 * entiers exacts, verifie qu'il a bien ete calcule sous les versions du combat, et le rend
 * persistable. La production arrivee apres l'ouverture ne peut jamais l'augmenter, puisqu'il ne lit
 * jamais la planete vivante.
 *
 * ## Une seule fois
 *
 * Le potentiel se fige apres l'issue du moteur et se persiste avec le resultat et ses versions. Au
 * reglement, il se **relit** — jamais il ne se recalcule depuis un solde qui a bouge depuis. C'est
 * la premiere moitie de la regle `applique = min(potentiel, restant)` : sans potentiel fige,
 * l'attaquant prendrait la production du combat.
 *
 * ## La frontiere, et ce qui la traverse
 *
 * Le moteur rend des `Resources`, dont les composantes sont des flottants. Le passage aux entiers
 * se fait ici, **une seule fois**, par `ResourceBoundary::wholeUnitsOfFrozenFact()` : arrondi vers
 * le bas, aucune tolerance au negatif — un resultat gele qui porterait un negatif affirmerait un
 * fait que personne n'a observe — et refus au-dela de ce que la plateforme porte.
 *
 * Les diagnostics de cette conversion voyagent **a cote** des montants, jamais dedans. Une precision
 * degradee au-dela de deux puissance cinquante-trois se constate et s'audite ; elle ne se glisse pas
 * dans le domaine exact sous la forme d'un `double` normalise.
 */
final readonly class FrozenLootPotential
{
    /**
     * Le moment fonctionnel de la conversion, pour situer un diagnostic.
     */
    public const string PHASE = 'potential_loot';

    /**
     * @param ExactLootAmounts $amounts Ce que l'attaquant aurait pris, en unites entieres.
     * @param int $rateInBasisPoints Le taux gele qui a produit ces montants, pour l'audit.
     * @param string $allocatorVersion L'allocateur sous lequel le moteur a calcule.
     * @param string $policyVersion La politique de taux sous laquelle il a calcule.
     * @param string $snapshotFingerprint L'empreinte de la photographie de butin.
     * @param ResourceNormalizationDiagnostics $diagnostics Ce que la conversion a rencontre.
     */
    private function __construct(
        public ExactLootAmounts $amounts,
        public int $rateInBasisPoints,
        public string $allocatorVersion,
        public string $policyVersion,
        public string $snapshotFingerprint,
        public ResourceNormalizationDiagnostics $diagnostics,
    ) {
    }

    /**
     * Le potentiel fige depuis l'issue du moteur, sous les versions du combat.
     *
     * **Les versions sont verifiees, pas copiees.** Un resultat calcule sous un autre allocateur ou
     * une autre politique que ceux du combat n'est pas le resultat de ce combat : le figer ferait
     * regler une bataille sous une regle qu'elle n'a jamais connue. La comparaison leve plutot que
     * de rendre `false`, comme partout ailleurs — il n'y a pas de branche juste apres un desaccord.
     *
     * @throws MismatchedRuleVersionSet Si le moteur a calcule sous d'autres versions que le combat.
     */
    public static function frozenFrom(BattleResult $result, FrozenCombatVersionSet $versions): self
    {
        $attendues = [
            'loot_allocator' => $versions->lootAllocator,
            'loot_policy' => $versions->lootPolicy,
        ];

        $recues = [
            'loot_allocator' => $result->lootAllocatorVersion,
            'loot_policy' => $result->lootPolicyVersion,
        ];

        if ($attendues !== $recues) {
            throw new MismatchedRuleVersionSet($attendues, $recues);
        }

        $diagnostics = ResourceNormalizationDiagnostics::none();
        $entiers = [];

        foreach (['metal', 'crystal', 'deuterium'] as $composante) {
            $normalise = ResourceBoundary::wholeUnitsOfFrozenFact(
                $result->loot->{$composante}->get(),
                $composante,
                self::PHASE
            );

            $entiers[] = $normalise->units;
            $diagnostics = $diagnostics->mergedWith($normalise->diagnostics);
        }

        return new self(
            ExactLootAmounts::of(...$entiers),
            $result->lootRateInBasisPoints,
            $result->lootAllocatorVersion,
            $result->lootPolicyVersion,
            $result->lootSnapshotFingerprint,
            $diagnostics,
        );
    }

    /**
     * Le potentiel tel qu'il a ete persiste avec l'instance, ou `null` s'il ne l'a pas encore ete.
     *
     * **`null` et « corrompu » ne se confondent pas.** Une instance dont le potentiel n'a pas encore
     * ete fige est un etat normal — le combat n'est pas resolu. Une instance qui porte un potentiel
     * illisible est une donnee gelee corrompue, et elle leve.
     *
     * Aucune hydratation coercitive : une chaine numerique rendue par un pilote de base, un flottant
     * glisse dans une colonne entiere, sont refuses par `ExactLootAmounts::fromStorage()`.
     *
     * @throws CorruptedFrozenLootAmounts Si les colonnes ne portent pas ce qui y a ete ecrit.
     */
    public static function fromInstance(CombatInstance $combat): self|null
    {
        if ($combat->potential_loot_frozen_at === null) {
            return null;
        }

        $montants = ExactLootAmounts::fromStorage([
            'metal' => $combat->potential_loot_metal,
            'crystal' => $combat->potential_loot_crystal,
            'deuterium' => $combat->potential_loot_deuterium,
        ]);

        $taux = $combat->potential_loot_rate_in_basis_points;

        if (!is_int($taux) || $taux < 0) {
            throw new CorruptedFrozenLootAmounts('le taux gele n est pas un entier non negatif', $taux);
        }

        return new self(
            $montants,
            $taux,
            (string)$combat->loot_allocator_version,
            (string)$combat->loot_policy_version,
            (string)$combat->loot_snapshot_fingerprint,
            // Les diagnostics de conversion ont ete audites au gel ; a la relecture, les montants
            // sont deja entiers et n'en produisent aucun.
            ResourceNormalizationDiagnostics::none(),
        );
    }

    /**
     * Les colonnes a ecrire sur l'instance, dans la transaction de resolution.
     *
     * Pur : cette methode n'ecrit rien elle-meme. C'est l'orchestrateur qui pose ces colonnes et
     * sauve, sous son verrou et avant son commit — le potentiel doit etre persiste **avant** que le
     * debit ne soit visible, sans quoi une reprise ne saurait plus ce qui etait dû.
     *
     * @return array<string, int|string>
     */
    public function toColumns(int $frozenAt): array
    {
        return [
            'potential_loot_metal' => $this->amounts->metal,
            'potential_loot_crystal' => $this->amounts->crystal,
            'potential_loot_deuterium' => $this->amounts->deuterium,
            'potential_loot_rate_in_basis_points' => $this->rateInBasisPoints,
            'potential_loot_frozen_at' => $frozenAt,
            'loot_snapshot_fingerprint' => $this->snapshotFingerprint,
        ];
    }
}
