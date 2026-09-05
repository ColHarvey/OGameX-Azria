<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Exceptions\ContradictoryEffectRecord;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\FleetMission;
use RuntimeException;

/**
 * Le registre des effets que le monde applique a un corps pendant qu'un combat le tient.
 *
 * ## Pourquoi un registre, et pas une mesure a la fermeture
 *
 * La fermeture mesure le delta d'un effet admissible autour de son gestionnaire. Cette mesure n'est
 * juste que si c'est **elle** qui applique l'effet. Un effet que le travailleur a deja livre pendant
 * le ralliement rejoue a vide — le gestionnaire est idempotent par `processed` — et la mesure donne
 * zero, pas le delta historique : la bataille se jouerait contre des defenses qu'un missile a
 * detruites, ou sans des vaisseaux qui sont pourtant la. C'est le second ordre du missile, nomme par
 * la revue 89, et c'est une faute de conservation.
 *
 * La porte des mouvements ecrit donc, dans la transaction meme de l'application, ce que l'effet a
 * change sur le corps — quand une barriere le tient. La fermeture lit ce delta pour tout effet deja
 * traite, et ne rejoue rien.
 *
 * ## Ce que le registre ne porte pas
 *
 * Un effet applique **avant l'ouverture** : aucune barriere ne le tenait, il n'a pas de ligne, et
 * c'est juste — l'etat d'ouverture le reflete deja. La fermeture distingue les deux cas par l'instant
 * d'arrivee : traite et arrive avant l'ouverture, il est reflete ; traite et arrive apres, il **doit**
 * avoir une ligne, sinon un chemin a applique un effet gouverne sans passer par la porte, et la
 * fermeture refuse plutot que d'inventer un delta.
 *
 * ## Une ecriture, une seule
 *
 * `record()` refuse un delta different pour une meme identite (`ContradictoryEffectRecord`) au lieu
 * de garder le premier : deux mesures d'un meme effet qui ne concordent pas sont un defaut a voir,
 * pas a arbitrer. Un delta identique rejoue sans effet.
 */
final class CombatEffectLedger
{
    /**
     * Applique un effet sur le corps qu'une mission vise et, si une barriere tient ce corps, inscrit au
     * registre du combat ce que l'effet y a change.
     *
     * Appelee par `FleetMissionService::updateMission()`, la porte unique de toute arrivee — c'est
     * la seule place ou l'ecriture couvre tous les chemins : le travailleur des pages sous la porte
     * des mouvements, la mise a jour d'un corps, l'administration, le bac, la fermeture elle-meme.
     * Une premiere version l'ecrivait dans le seul decideur de `PlayerService`, et le bac MariaDB a
     * montre aussitot un chemin qui passait a cote. **La ligne n'est ecrite que si l'effet a
     * reellement eu lieu ici** — `processed` passe de zero a un pendant l'application — pour qu'un
     * rejeu a vide n'inscrive pas un delta nul sous l'identite d'un effet deja inscrit.
     */
    public function applyUnderAnOpenBarrier(FleetMission $tenue, Closure $apply): void
    {
        $corps = $tenue->planet_id_to === null ? null : (int)$tenue->planet_id_to;
        $barriere = $corps === null
            ? null
            : CelestialBodyCombatBarrier::query()->where('target_body_id', $corps)->first();

        // **`processed` se lit en base, pas sur l'objet recu** : un appelant peut tenir un modele
        // d'avant, et un effet deja livre rejoue a vide — inscrire ce zero sous son identite
        // contredirait la ligne que sa vraie application a ecrite.
        $dejaTraitee = (int)FleetMission::query()->whereKey($tenue->id)->value('processed') === 1;
        if ($corps === null || $barriere === null || $dejaTraitee) {
            $apply();

            return;
        }

        // **Les faits d'une salve se relevent avant qu'elle frappe** : ce que le monde detruit ne dit
        // pas ce que la photographie perd, et la fermeture rejouera la salve sur la photographie.
        $faits = self::isAMissileSalvo($tenue) ? $this->missileFactsBefore($tenue, $corps) : null;

        $avant = self::garrisonOf($corps);
        $apply();
        $apres = self::garrisonOf($corps);

        if ((int)FleetMission::query()->whereKey($tenue->id)->value('processed') !== 1) {
            return;
        }

        $this->record(
            (int)$barriere->combat_instance_id,
            CombatEventIdentity::forFleetArrival((int)$tenue->id),
            self::deltaBetween($avant, $apres),
            (int)Date::now()->timestamp,
            $faits
        );
    }

    private static function isAMissileSalvo(FleetMission $mission): bool
    {
        return $mission->parent_id === null
            && CombatMissionKind::fromMissionType((int)$mission->mission_type) === CombatMissionKind::Missile;
    }

    /**
     * Ce que la salve etait, lu dans le monde juste avant qu'elle frappe : ses missiles, sa priorite,
     * la technologie d'armes du lanceur, et les antimissiles que le corps et sa planete mere pouvaient
     * lui opposer.
     */
    private function missileFactsBefore(FleetMission $tenue, int $corps): MissileStrikeFacts
    {
        $planetes = resolve(PlanetServiceFactory::class);
        $cible = $planetes->make($corps, true);
        if ($cible === null) {
            throw new RuntimeException('Le corps ' . $corps . ' vise par la salve ' . $tenue->id . ' n existe pas.');
        }
        $lanceur = $tenue->planet_id_from === null ? null : $planetes->make((int)$tenue->planet_id_from, true)?->getPlayer();
        if ($lanceur === null) {
            throw new RuntimeException('La salve ' . $tenue->id . ' n a pas de lanceur : sa technologie d armes ne peut pas etre relevee.');
        }

        $mere = $cible->isMoon() ? $cible->planet() : null;

        return new MissileStrikeFacts(
            (int)$tenue->interplanetary_missile,
            (int)($tenue->target_priority ?? 0),
            $lanceur->getResearchLevel('weapon_technology'),
            $cible->getObjectAmount('anti_ballistic_missile'),
            $mere === null ? 0 : $mere->getObjectAmount('anti_ballistic_missile')
        );
    }

    /**
     * @param array<string, int> $unitDelta
     */
    public function record(int $combatInstanceId, string $eventIdentity, array $unitDelta, int $appliedAt, MissileStrikeFacts|null $facts = null): void
    {
        ksort($unitDelta);
        $existante = DB::table('combat_effect_ledger')
            ->where('combat_instance_id', $combatInstanceId)
            ->where('event_identity', $eventIdentity)
            ->first(['unit_delta', 'facts']);

        if ($existante !== null) {
            $relu = json_decode((string)$existante->unit_delta, true);
            $faitsRelus = $existante->facts === null ? null : json_decode((string)$existante->facts, true);
            if ($relu !== $unitDelta || $faitsRelus !== $facts?->toFrozenFacts()) {
                throw new ContradictoryEffectRecord($combatInstanceId, $eventIdentity, is_array($relu) ? $relu : [], $unitDelta);
            }

            return;
        }

        DB::table('combat_effect_ledger')->insert([
            'combat_instance_id' => $combatInstanceId,
            'event_identity' => $eventIdentity,
            'unit_delta' => json_encode($unitDelta, JSON_THROW_ON_ERROR),
            'facts' => $facts === null ? null : json_encode($facts->toFrozenFacts(), JSON_THROW_ON_ERROR),
            'applied_at' => $appliedAt,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }

    /**
     * Les faits de la salve inscrite pour cet effet, ou `null` s'il n'en porte pas.
     */
    public function factsOf(int $combatInstanceId, string $eventIdentity): MissileStrikeFacts|null
    {
        $ligne = DB::table('combat_effect_ledger')
            ->where('combat_instance_id', $combatInstanceId)
            ->where('event_identity', $eventIdentity)
            ->first(['facts']);

        if ($ligne === null || $ligne->facts === null) {
            return null;
        }

        $faits = json_decode((string)$ligne->facts, true);
        if (!is_array($faits)) {
            throw new RuntimeException('Le registre des effets du combat ' . $combatInstanceId . ' porte des faits de salve illisibles pour ' . $eventIdentity . '.');
        }

        return MissileStrikeFacts::fromFrozenFacts($faits);
    }

    /**
     * Le delta inscrit pour cet effet, ou `null` s'il n'a pas ete applique pendant la vie de ce combat.
     *
     * @return array<string, int>|null
     */
    public function deltaOf(int $combatInstanceId, string $eventIdentity): array|null
    {
        $ligne = DB::table('combat_effect_ledger')
            ->where('combat_instance_id', $combatInstanceId)
            ->where('event_identity', $eventIdentity)
            ->first(['unit_delta']);

        if ($ligne === null) {
            return null;
        }

        $delta = json_decode((string)$ligne->unit_delta, true);
        if (!is_array($delta)) {
            throw new RuntimeException('Le registre des effets du combat ' . $combatInstanceId . ' porte un delta illisible pour ' . $eventIdentity . '.');
        }

        $entier = [];
        foreach ($delta as $nom => $quantite) {
            if (!is_string($nom) || !is_int($quantite)) {
                throw new RuntimeException('Le registre des effets du combat ' . $combatInstanceId . ' porte un delta qui n est pas un entier par unite pour ' . $eventIdentity . '.');
            }
            $entier[$nom] = $quantite;
        }

        return $entier;
    }

    /**
     * L'effectif de combat du corps, tel que la garnison l'emploie.
     *
     * @return array<string, int>
     */
    public static function garrisonOf(int $bodyId): array
    {
        $corps = resolve(PlanetServiceFactory::class)->make($bodyId, true);

        return $corps === null ? [] : DefenderFleet::fromPlanet($corps)->units->toArray();
    }

    /**
     * Ce qui a change entre deux lectures, unite par unite, dans les deux sens.
     *
     * @param array<string, int> $avant
     * @param array<string, int> $apres
     * @return array<string, int>
     */
    public static function deltaBetween(array $avant, array $apres): array
    {
        $delta = [];
        foreach ($apres as $nom => $quantite) {
            $difference = $quantite - ($avant[$nom] ?? 0);
            if ($difference !== 0) {
                $delta[$nom] = $difference;
            }
        }
        foreach ($avant as $nom => $quantite) {
            if (!array_key_exists($nom, $apres) && $quantite !== 0) {
                $delta[$nom] = -$quantite;
            }
        }
        ksort($delta);

        return $delta;
    }
}
