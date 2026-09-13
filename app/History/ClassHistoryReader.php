<?php

namespace OGame\History;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Causality\DecisionOrder;

/**
 * Ce qu un compte ou une alliance etait a un instant passe : classe personnelle, alliance, classe d alliance.
 *
 * ## La regle d ordre, et d ou elle vient
 *
 * Un changement de classe ou d appartenance est une **decision** de joueur. La valeur a un instant est donc
 * celle de la derniere decision **strictement anterieure**, selon `DecisionOrder::isStrictlyBefore()` —
 * la regle deja appliquee a toute decision face a une barriere du combat. Une decision prise a la seconde
 * meme de l instant compte pour « apres » ; deux decisions de la meme seconde se departagent par
 * l identifiant de leur ligne, comme `DecisionOrder` le prevoit.
 *
 * C est aussi ce que le jeu fait : le middleware traite les missions dues d un joueur **avant** l action
 * de sa requete, si bien qu une action a la seconde d une arrivee s execute apres elle.
 *
 * ## Inconnu, et pourquoi deux causes
 *
 * - **aucune ligne avant l instant** : l historique commence apres lui (ligne de base de la migration, ou
 *   compte ne apres) ;
 * - **la derniere ligne contredit la colonne** : un changement a ete ecrit sans sa ligne. Tout ce que
 *   l historique affirme devient douteux, y compris sur le passe — une ligne manquante peut tomber
 *   n importe ou.
 *
 * Dans les deux cas, la reponse est `HistoricValue::unknown()`, jamais la valeur actuelle.
 *
 * ## Les deux lectures n en font qu une
 *
 * Les lignes et la valeur courante viennent d une **seule requete** — la colonne du porteur y entre
 * comme sous-requete. Deux requetes separees pourraient tomber de part et d autre d un changement
 * valide entre elles : l historique d avant et la colonne d apres se contrediraient, et un combat se
 * suspendrait pour une anomalie qui n existe pas. Se fier a la transaction de l appelant ne suffirait
 * pas — la garantie dependrait alors du niveau d isolation, du genre de lecture et de ce que les autres
 * ecrivent au meme moment. Une requete unique ne depend de rien de tout cela.
 */
final class ClassHistoryReader
{
    /**
     * La classe personnelle du compte a cet instant.
     */
    public function personalClassAt(int $userId, int $instant): HistoricValue
    {
        return $this->valueAt(
            'character_class_history',
            'user_id',
            $userId,
            'character_class',
            $instant,
            DB::table('users')->where('id', $userId),
            'character_class',
            self::integerOrNull(...),
            'la classe personnelle du compte ' . $userId
        );
    }

    /**
     * L alliance du compte a cet instant, ou aucune.
     */
    public function membershipAt(int $userId, int $instant): HistoricValue
    {
        return $this->valueAt(
            'alliance_membership_history',
            'user_id',
            $userId,
            'alliance_id',
            $instant,
            DB::table('users')->where('id', $userId),
            'alliance_id',
            self::integerOrNull(...),
            'l appartenance du compte ' . $userId
        );
    }

    /**
     * La classe de l alliance a cet instant, par son nom stocke, ou aucune.
     *
     * Une alliance dissoute n a plus de ligne a confronter : son historique fait foi seul, et il reste
     * vrai pour les instants ou elle existait.
     */
    public function allianceClassAt(int $allianceId, int $instant): HistoricValue
    {
        return $this->valueAt(
            'alliance_class_history',
            'alliance_id',
            $allianceId,
            'alliance_class',
            $instant,
            DB::table('alliances')->where('id', $allianceId),
            'alliance_class',
            self::stringOrNull(...),
            'la classe de l alliance ' . $allianceId
        );
    }

    /**
     * L instant de la ligne de base, ou `null` si la migration n a trouve aucun compte a inscrire.
     *
     * Avant lui — et a lui —, rien n est connu des comptes qui existaient deja.
     */
    public function baselineInstant(): int|null
    {
        $instant = DB::table('character_class_history')->where('cause', ClassHistoryBaseline::CAUSE)->min('changed_at');

        return is_numeric($instant) ? (int)$instant : null;
    }

    /**
     * @param Builder $porteur La ligne qui porte la valeur courante : le compte, ou l alliance.
     * @param Closure(mixed): (int|string|null) $normaliser
     */
    private function valueAt(
        string $table,
        string $sujet,
        int $identifiant,
        string $colonne,
        int $instant,
        Builder $porteur,
        string $colonnePorteur,
        Closure $normaliser,
        string $quoi,
    ): HistoricValue {
        // **Une seule requete, donc un seul instantane** : les lignes, la valeur courante du porteur et
        // sa presence. Deux requetes separees pourraient encadrer un changement valide entre elles.
        $lignes = DB::table($table)
            ->where($sujet, $identifiant)
            ->oldest($table . '.changed_at')
            ->orderBy($table . '.id')
            ->select([$table . '.id', $table . '.' . $colonne, $table . '.changed_at'])
            ->selectSub((clone $porteur)->select($colonnePorteur)->limit(1), 'valeur_courante')
            ->selectSub((clone $porteur)->selectRaw('1')->limit(1), 'porteur_present')
            ->get();

        if ($lignes->isEmpty()) {
            return HistoricValue::unknown($quoi . ' n a aucun historique.');
        }

        $derniere = $lignes->last();

        // **Un porteur disparu ne contredit plus rien** : une alliance dissoute, un compte supprime,
        // leur historique fait foi seul et reste vrai pour les instants ou ils existaient.
        if ($derniere->porteur_present !== null && $normaliser($derniere->{$colonne}) !== $normaliser($derniere->valeur_courante)) {
            return HistoricValue::unknown(
                $quoi . ' : l historique finit sur « ' . var_export($normaliser($derniere->{$colonne}), true)
                . ' », la colonne dit « ' . var_export($normaliser($derniere->valeur_courante), true)
                . ' ». Un changement a ete ecrit sans sa ligne.'
            );
        }

        $retenue = null;

        foreach ($lignes as $ligne) {
            if ((new DecisionOrder((int)$ligne->changed_at, (int)$ligne->id))->isStrictlyBefore($instant)) {
                $retenue = $ligne;
            }
        }

        if ($retenue === null) {
            return HistoricValue::unknown($quoi . ' : aucune decision connue avant l instant ' . $instant . '.');
        }

        return HistoricValue::known($normaliser($retenue->{$colonne}));
    }

    private static function integerOrNull(mixed $valeur): int|null
    {
        if ($valeur === null) {
            return null;
        }

        return is_int($valeur) || (is_string($valeur) && ctype_digit($valeur)) ? (int)$valeur : -1;
    }

    private static function stringOrNull(mixed $valeur): string|null
    {
        return is_string($valeur) ? $valeur : null;
    }
}
