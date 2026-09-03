<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\GameMissions\AttackMission;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use Throwable;

/**
 * Le battement du combat durable : fermer les ralliements echus, regler les combats termines.
 *
 * ## Deux echeances, un seul passage
 *
 * Un combat traverse deux instants qui ne dependent d'aucun joueur : l'echeance de son ralliement,
 * ou les rangs se figent et la bataille se calcule, et sa fin, ou le resultat s'applique. Les deux
 * arrivent pendant que personne ne regarde. Ce service les fait arriver.
 *
 * Il ne decide rien : il **nomme** les combats dont l'heure est venue et laisse la fermeture et le
 * reglement juger. Chacun d'eux relit l'etat sous verrou et repart sans rien faire si l'heure n'est
 * pas venue ou si le travail a deja ete fait — ce service peut donc etre lance deux fois de suite,
 * ou en retard, sans consequence.
 *
 * ## Un combat corrompu ne bloque pas les autres, et ne boucle pas
 *
 * Chaque combat est traite pour lui-meme : ce qui leve est attrape, compte, et n'empeche pas les
 * suivants. Mais reprendre indefiniment un combat qui echoue a chaque minute remplirait les
 * journaux sans jamais guerir. Le compteur `advance_attempts` — incremente **hors** de la
 * transaction annulee, sans quoi il ne compterait rien — met le combat de cote passe un seuil, avec
 * la derniere raison a cote. Un humain corrige et remet le compteur a zero.
 *
 * ## Une portee bornee
 *
 * Un passage traite au plus `$batchSize` combats de chaque sorte, par identifiant croissant. Un
 * arriere accumule pendant une panne se resorbe en plusieurs passages au lieu de tenir une
 * transaction geante ; l'ordre par identifiant rend le rattrapage previsible.
 */
final class PersistentCombatAdvancer
{
    /**
     * Le nombre d'echecs au-dela duquel un combat est laisse a l'exploitation.
     */
    public const int MAX_ATTEMPTS = 5;

    /**
     * La longueur maximale conservee d'une raison : de quoi enqueter, pas de quoi remplir la table.
     */
    private const int ERROR_LENGTH = 1000;

    public function __construct(
        private RallyClosureService $closure = new RallyClosureService(),
        private AttackMission|null $mission = null,
        private int $batchSize = 200,
    ) {
    }

    /**
     * Fait avancer ce que l'heure permet.
     */
    public function advance(int $now): PersistentCombatAdvance
    {
        $fermes = 0;
        $regles = 0;
        $echecs = [];

        foreach ($this->ralliesDue($now) as $id) {
            try {
                if ($this->closure->close($id, $now)->closed) {
                    $fermes++;
                }
            } catch (Throwable $panne) {
                $echecs[$id] = $this->recordFailure($id, $panne);
            }
        }

        foreach ($this->combatsDue($now) as $id) {
            try {
                if ($this->mission()->settlePersistentCombat($id, $now)->settled) {
                    $regles++;
                }
            } catch (Throwable $panne) {
                $echecs[$id] = $this->recordFailure($id, $panne);
            }
        }

        return new PersistentCombatAdvance($fermes, $regles, $echecs, $this->quarantined());
    }

    /**
     * Les ralliements dont l'echeance est passee.
     *
     * L'echeance vit sur la barriere : c'est elle qui porte l'heure calculee a l'ouverture, et la
     * fermeture la relira sous verrou. Ce qui est lu ici n'est qu'une liste de candidats.
     *
     * @return array<int, int>
     */
    private function ralliesDue(int $now): array
    {
        return CombatInstance::query()
            ->where('status', CombatState::Rallying->value)
            ->where('advance_attempts', '<', self::MAX_ATTEMPTS)
            ->whereIn('id', CelestialBodyCombatBarrier::query()
                ->where('owned_through_effect_at', '<=', $now)
                ->select('combat_instance_id'))
            ->orderBy('id')
            ->limit($this->batchSize)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();
    }

    /**
     * Les combats dont la fin est passee.
     *
     * @return array<int, int>
     */
    private function combatsDue(int $now): array
    {
        return CombatInstance::query()
            ->where('status', CombatState::Active->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->where('advance_attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit($this->batchSize)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();
    }

    /**
     * Inscrit l'echec sur le combat et rend la raison conservee.
     *
     * **Hors de toute transaction annulee** : le reglement qui vient de lever a defait tout ce
     * qu'il avait ecrit, y compris un compteur qu'il aurait incremente lui-meme.
     */
    private function recordFailure(int $combatInstanceId, Throwable $panne): string
    {
        $raison = mb_substr($panne->getMessage(), 0, self::ERROR_LENGTH);

        CombatInstance::query()->whereKey($combatInstanceId)->update([
            'advance_attempts' => DB::raw('advance_attempts + 1'),
            'advance_last_error' => $raison,
        ]);

        return $raison;
    }

    /**
     * Combien de combats attendent une intervention.
     */
    private function quarantined(): int
    {
        return CombatInstance::query()
            ->where('advance_attempts', '>=', self::MAX_ATTEMPTS)
            ->whereIn('status', [CombatState::Rallying->value, CombatState::Active->value])
            ->count();
    }

    private function mission(): AttackMission
    {
        return $this->mission ??= resolve(AttackMission::class);
    }
}
