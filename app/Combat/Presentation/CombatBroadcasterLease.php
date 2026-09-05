<?php

namespace OGame\Combat\Presentation;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Le bail du diffuseur continu : un seul diffuseur effectif, une releve quand il meurt.
 *
 * ## Ce que le bail garantit, et comment
 *
 * Une ligne unique en base porte le detenteur et son dernier battement. **Prendre** le bail est une
 * seule mise a jour conditionnelle — la ligne n'existe pas encore, ou son battement est plus vieux
 * que la tolerance — et c'est cette condition, evaluee par le moteur de la base, qui rend la prise
 * atomique : deux candidats au meme instant ne peuvent pas la reussir tous les deux. **Battre** est
 * une mise a jour conditionnee au detenteur : si elle ne touche aucune ligne, quelqu'un a pris la
 * releve, et le detenteur sortant doit s'arreter.
 *
 * ## Ce que le bail ne garantit pas
 *
 * Qu'un diffuseur mort soit remplace a la seconde : la releve attend le prochain candidat — le
 * tick suivant du planificateur — et la tolerance. C'est un creux **apres une panne**, pas a la
 * jonction nominale ; il se dit tel quel.
 */
final class CombatBroadcasterLease
{
    public const NAME = 'combat-broadcaster';

    /**
     * Secondes sans battement au-dela desquelles le detenteur est tenu pour mort.
     */
    public const TOLERANCE = 10;

    public function __construct(private readonly string $holder)
    {
    }

    /**
     * Tente de prendre le bail a cet instant. Vrai si ce detenteur le tient desormais.
     */
    public function acquire(int $now): bool
    {
        $seuil = $now - self::TOLERANCE;

        // La ligne n'existe pas encore : la creer est la prise. Une clef primaire fait echouer le
        // second des deux candidats, ce qui est exactement le verdict voulu.
        $existante = DB::table('combat_broadcaster_leases')->where('name', self::NAME)->exists();

        if (!$existante) {
            try {
                DB::table('combat_broadcaster_leases')->insert([
                    'name' => self::NAME,
                    'holder' => $this->holder,
                    'heartbeat_at' => $now,
                    'started_at' => $now,
                ]);

                return true;
            } catch (QueryException) {
                // Quelqu'un l'a creee entre la lecture et l'ecriture : on retombe sur la reprise.
            }
        }

        // La ligne existe : la reprise n'aboutit que si le battement est perime — une seule mise a
        // jour, conditionnee, atomique par construction.
        return DB::table('combat_broadcaster_leases')
            ->where('name', self::NAME)
            ->where('heartbeat_at', '<', $seuil)
            ->update(['holder' => $this->holder, 'heartbeat_at' => $now, 'started_at' => $now]) === 1;
    }

    /**
     * Bat le bail. Faux si ce detenteur ne le tient plus : un autre a pris la releve.
     */
    public function heartbeat(int $now): bool
    {
        return DB::table('combat_broadcaster_leases')
            ->where('name', self::NAME)
            ->where('holder', $this->holder)
            ->update(['heartbeat_at' => $now]) === 1;
    }

    /**
     * Rend le bail, pour qu'un successeur n'attende pas la tolerance.
     */
    public function release(): void
    {
        DB::table('combat_broadcaster_leases')
            ->where('name', self::NAME)
            ->where('holder', $this->holder)
            ->delete();
    }

    /**
     * Le detenteur courant tel que la base le voit, ou null.
     */
    public static function currentHolder(): string|null
    {
        $ligne = DB::table('combat_broadcaster_leases')->where('name', self::NAME)->first(['holder']);

        return $ligne === null ? null : (string)$ligne->holder;
    }
}
