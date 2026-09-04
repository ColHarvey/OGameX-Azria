<?php

namespace OGame\Combat\Replay;

use OGame\Combat\Exceptions\CorruptedBattleResult;
use OGame\Combat\Exceptions\MismatchedCombatIdentity;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use Throwable;

/**
 * L'identite qu'un resultat fige porte avec lui.
 *
 * ## Pourquoi la colonne voisine ne suffit pas
 *
 * `battle_result` vit sur la ligne de l'instance : un appelant ne peut pas lui passer un autre
 * objet. Mais une ligne peut avoir ete ecrite a moitie, reparee a la main, ou recopiee d'un autre
 * combat par une administration qui voulait bien faire. Rien, dans le document lui-meme, ne disait
 * alors de quel combat il parlait.
 *
 * Cette enveloppe le dit : **le combat, la cible, l'initiatrice, l'ensemble canonique des
 * participants, la photographie de l'ouverture et les cinq versions de regle**. Elle est ecrite
 * avec le resultat a la cloture, relue avec lui au reglement, et comparee a ce que l'instance et
 * ses participants disent **maintenant**, sous verrou. Un desaccord est un refus avant tout debit.
 *
 * ## Pourquoi les participants, et pas seulement les flottes du resultat
 *
 * Le resultat nomme ses flottes attaquantes ; l'effectif relu les compare deja. Les participants
 * inscrits sont l'autre moitie de la photographie — les deux camps, sous leur clef canonique — et
 * c'est cette liste-la, triee, que l'enveloppe fige.
 */
final readonly class CombatResultIdentity
{
    private const array KEYS = ['combat_instance_id', 'target_body_id', 'initiator_mission_id', 'participants', 'frozen_facts_fingerprint', 'versions'];

    /**
     * @param array<int, string> $participantKeys Les clefs canoniques des participants, triees.
     * @param array<string, string> $versions Les cinq versions de regle, telles que l'instance les porte.
     */
    private function __construct(
        public int $combatInstanceId,
        public int $targetBodyId,
        public int $initiatorMissionId,
        public array $participantKeys,
        public string $frozenFactsFingerprint,
        public array $versions,
    ) {
    }

    /**
     * L'identite de ce combat, lue sur l'instance et ses participants inscrits — sous le verrou de
     * l'appelant.
     */
    public static function of(CombatInstance $combat): self
    {
        $cles = array_map(
            'strval',
            CombatParticipant::query()->where('combat_instance_id', $combat->id)->pluck('participant_key')->all()
        );
        sort($cles, SORT_STRING);

        return new self(
            (int)$combat->id,
            (int)$combat->target_planet_id,
            (int)$combat->mission_id,
            $cles,
            (string)$combat->frozen_facts_fingerprint,
            FrozenCombatVersionSet::fromInstance($combat)->toStorage()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'combat_instance_id' => $this->combatInstanceId,
            'target_body_id' => $this->targetBodyId,
            'initiator_mission_id' => $this->initiatorMissionId,
            'participants' => $this->participantKeys,
            'frozen_facts_fingerprint' => $this->frozenFactsFingerprint,
            'versions' => $this->versions,
        ];
    }

    public static function fromStorage(mixed $stored): self
    {
        if (!is_array($stored)) {
            throw new CorruptedBattleResult('resultat.identity est un ' . get_debug_type($stored) . ' et non une structure', $stored);
        }

        $inconnues = array_diff(array_keys($stored), self::KEYS);
        $manquantes = array_diff(self::KEYS, array_keys($stored));

        if ($inconnues !== [] || $manquantes !== []) {
            throw new CorruptedBattleResult(
                'resultat.identity porte des clefs inconnues (' . implode(', ', array_map('strval', $inconnues))
                . ') ou manquantes (' . implode(', ', $manquantes) . ')',
                $stored
            );
        }

        foreach (['combat_instance_id', 'target_body_id', 'initiator_mission_id'] as $champ) {
            if (!is_int($stored[$champ]) || $stored[$champ] < 1) {
                throw new CorruptedBattleResult(
                    'resultat.identity.' . $champ . ' est ' . (is_scalar($stored[$champ]) ? var_export($stored[$champ], true) : 'un ' . get_debug_type($stored[$champ]))
                    . ' et non un identifiant entier positif',
                    $stored
                );
            }
        }

        $participants = $stored['participants'];

        if (!is_array($participants)) {
            throw new CorruptedBattleResult('resultat.identity.participants est un ' . get_debug_type($participants) . ' et non une liste', $stored);
        }

        $cles = [];
        foreach ($participants as $rang => $cle) {
            if (!is_string($cle) || $cle === '') {
                throw new CorruptedBattleResult('resultat.identity.participants[' . $rang . '] n est pas une clef de participant', $stored);
            }
            $cles[] = $cle;
        }

        $triees = $cles;
        sort($triees, SORT_STRING);

        if ($triees !== $cles || count(array_unique($cles)) !== count($cles)) {
            throw new CorruptedBattleResult('resultat.identity.participants n est pas la liste canonique : triee, sans doublon', $stored);
        }

        $empreinte = $stored['frozen_facts_fingerprint'];

        if (!is_string($empreinte) || $empreinte === '') {
            throw new CorruptedBattleResult('resultat.identity.frozen_facts_fingerprint n est pas une empreinte', $stored);
        }

        if (!is_array($stored['versions'])) {
            throw new CorruptedBattleResult('resultat.identity.versions est un ' . get_debug_type($stored['versions']) . ' et non une structure', $stored);
        }

        // L'ensemble de versions refuse lui-meme une clef absente ou une valeur qui n'est pas un
        // texte. Son refus est retraduit ici : tout ce que cette enveloppe refuse porte le meme nom,
        // et l'appelant n'a qu'une seule exception a connaitre.
        try {
            $versions = FrozenCombatVersionSet::fromStorage($stored['versions'])->toStorage();
        } catch (Throwable $refus) {
            throw new CorruptedBattleResult("resultat.identity.versions : " . $refus->getMessage(), $stored);
        }

        return new self(
            $stored['combat_instance_id'],
            $stored['target_body_id'],
            $stored['initiator_mission_id'],
            $cles,
            $empreinte,
            $versions
        );
    }

    /**
     * Le resultat fige decrit-il ce combat, tel qu'il est persiste maintenant ?
     */
    public function assertDescribes(CombatInstance $combat): void
    {
        $attendue = self::of($combat);

        if ($this->combatInstanceId !== $attendue->combatInstanceId) {
            throw new MismatchedCombatIdentity(
                'la bataille figee appartient au combat ' . $this->combatInstanceId
                . ' alors qu on regle le combat ' . $attendue->combatInstanceId
            );
        }

        if ($this->targetBodyId !== $attendue->targetBodyId) {
            throw new MismatchedCombatIdentity(
                'la bataille figee du combat ' . $combat->id . ' vise le corps ' . $this->targetBodyId
                . ' alors que le combat vise le corps ' . $attendue->targetBodyId
            );
        }

        if ($this->initiatorMissionId !== $attendue->initiatorMissionId) {
            throw new MismatchedCombatIdentity(
                'la bataille figee du combat ' . $combat->id . ' a pour initiatrice la mission ' . $this->initiatorMissionId
                . ' alors que le combat porte la mission ' . $attendue->initiatorMissionId
            );
        }

        if ($this->participantKeys !== $attendue->participantKeys) {
            throw new MismatchedCombatIdentity(
                'la bataille figee du combat ' . $combat->id . ' porte les participants [' . implode(', ', $this->participantKeys)
                . '] alors que le combat en inscrit [' . implode(', ', $attendue->participantKeys) . ']'
            );
        }

        if ($this->frozenFactsFingerprint !== $attendue->frozenFactsFingerprint) {
            throw new MismatchedCombatIdentity(
                'la bataille figee du combat ' . $combat->id . ' porte la photographie ' . $this->frozenFactsFingerprint
                . ' alors que le combat porte ' . $attendue->frozenFactsFingerprint
            );
        }

        FrozenCombatVersionSet::fromStorage($this->versions)->ensureSameAs(FrozenCombatVersionSet::fromStorage($attendue->versions));
    }
}
