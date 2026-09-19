<?php

namespace OGame\Lifeforms\Bonuses;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Planet;
use stdClass;

/**
 * La trace qu une colonie de formes de vie laisse en disparaissant, et la question qu elle permet de poser.
 *
 * ## Pourquoi une trace
 *
 * La purge de 3 h supprime un corps abandonne depuis au moins 24 heures, et **tout ce qu il portait part en cascade** :
 * niveaux, emplacements, historique des emplacements, file. Le resolveur, qui reconstruit les bonus d un compte a un
 * instant passe (l arrivee d une flotte), ne voyait alors plus la colonie du tout — et rendait **en silence** un bonus
 * reduit, la ou la colonie armait encore la flotte a son arrivee (constat de Keven, journal §167).
 *
 * La trace ne conserve pas les faits : elle conserve **qu ils ont existe**. « Ce compte avait cette colonie de A a B,
 * et ses faits ont ete purges. » Une lecture a un instant de `[A, B)` ne peut donc plus etre reconstruite, et le
 * resolveur le dit (`LifeformHistoryUnavailable`) au lieu de le taire. Le combat, lui, se suspend explicitement par le
 * chemin qu il a deja pour un historique manquant.
 *
 * ## Ce que disent A et B
 *
 * - **A** : `planets.created_at`, la date historique de naissance du corps. **Nulle quand elle manque — jamais
 *   inventee** : une trace sans A couvre tout instant anterieur a B, puisqu on ne peut pas prouver que la colonie
 *   n existait pas encore.
 * - **B** : l instant ou la colonie a **cesse de compter** — l abandon (`planets.destroyed`), et non la purge qui
 *   vient au moins 24 heures plus tard ; pour une suppression directe sans abandon, l instant de la suppression.
 * - L intervalle est **semi-ouvert** : a B, la colonie ne compte plus, comme toute ecriture de ce module
 *   (`LifeformLevels::levelsAt()` : un fait date exactement de l instant compte comme deja survenu).
 *
 * ## Atomicite
 *
 * La trace est **relevee avant** la suppression — la cascade efface la ligne de formes de vie qui dit si le corps en
 * portait — et **ecrite apres**, dans le crochet `deleted`, qui ne se declenche que si la suppression a reussi. Toute
 * suppression d un corps passe par une transaction (`PlanetService::permanentlyDeletePlanet()`, et la suppression d un
 * compte) : si l ecriture de la trace echoue, la suppression est annulee avec elle. Ni fausse trace, ni disparition
 * sans trace.
 */
final class LifeformPurgedBodies
{
    /**
     * Ce que la trace devra dire, releve au `deleting` et ecrit au `deleted`, par identifiant de corps.
     *
     * @var array<int, array{user_id: int, existed_from: int|null, existed_until: int}>
     */
    private static array $releves = [];

    /**
     * Releve, AVANT la suppression, ce que la trace dira. Un corps sans formes de vie (une lune, une planete jamais
     * peuplee) ne laisse rien : il n armait rien.
     */
    public static function noteBeforeDeletion(Planet $body, int $now): void
    {
        $id = (int)$body->id;
        unset(self::$releves[$id]);
        $releve = self::traceOf($body, $now);
        if ($releve !== null) {
            self::$releves[$id] = $releve;
        }
    }

    /**
     * Ecrit la trace APRES une suppression reussie — une suppression qui echoue n arrive jamais ici.
     */
    public static function recordAfterDeletion(Planet $body, int $now): void
    {
        $id = (int)$body->id;
        $releve = self::$releves[$id] ?? null;
        unset(self::$releves[$id]);
        if ($releve === null) {
            return;
        }
        self::insert($id, $releve, $now);
    }

    /**
     * La suppression d un compte efface ses corps en masse, sans passer par aucun observateur : elle releve et ecrit
     * ici, dans sa propre transaction, avant la suppression.
     */
    public static function recordBeforeAccountDeletion(int $userId, int $now): void
    {
        foreach (Planet::query()->where('user_id', $userId)->get() as $corps) {
            $releve = self::traceOf($corps, $now);
            if ($releve !== null) {
                self::insert((int)$corps->id, $releve, $now);
            }
        }
    }

    /**
     * Une colonie de ce compte qui existait a cet instant et dont les faits ont ete purges — ou rien.
     */
    public static function purgedAt(int $userId, int $instant): stdClass|null
    {
        $trace = DB::table('lifeform_purged_bodies')
            ->where('user_id', $userId)
            ->where('existed_until', '>', $instant)
            ->where(static fn ($q) => $q->whereNull('existed_from')->orWhere('existed_from', '<=', $instant))
            ->orderBy('id')
            ->first();

        return $trace instanceof stdClass ? $trace : null;
    }

    /**
     * @return array{user_id: int, existed_from: int|null, existed_until: int}|null
     */
    private static function traceOf(Planet $body, int $now): array|null
    {
        if (!LifeformPlanet::query()->where('planet_id', $body->id)->exists()) {
            return null;
        }
        $abandon = (int)$body->destroyed;

        return [
            'user_id' => (int)$body->user_id,
            'existed_from' => $body->created_at === null ? null : (int)$body->created_at->getTimestamp(),
            'existed_until' => $abandon > 0 ? $abandon : $now,
        ];
    }

    /**
     * @param array{user_id: int, existed_from: int|null, existed_until: int} $releve
     */
    private static function insert(int $planetId, array $releve, int $now): void
    {
        DB::table('lifeform_purged_bodies')->insert([
            'user_id' => $releve['user_id'],
            'planet_id' => $planetId,
            'existed_from' => $releve['existed_from'],
            'existed_until' => $releve['existed_until'],
            'purged_at' => $now,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }
}
