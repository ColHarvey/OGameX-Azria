<?php

namespace OGame\GameMissions\BattleEngine;

use OGame\Combat\Exceptions\RustEngineContractMismatch;
use OGame\GameObjects\Models\Units\UnitCollection;

/**
 * La forme que prennent les cartes par flotte d'un round rendu par la bibliotheque Rust.
 *
 * ## Le defaut que cette classe ferme
 *
 * Le moteur PHP pose une entree **pour chaque flotte** au debut de chaque round — vide si la flotte n'a rien perdu
 * (`PhpBattleEngine`, « Le camp defenseur est suivi flotte par flotte lui aussi »). La bibliotheque Rust, elle, ne cree
 * l'entree qu'a la premiere perte (`entry().or_default()`), et ses cartes sont des tables de hachage : **une flotte sans
 * perte est absente**, et l'ordre des clefs n'est pas celui des flottes. Deux rounds identiques donnaient donc deux
 * documents differents selon le moteur — mesure de la CI du 19 septembre 2026 : sous Rust, le renfort ACS disparaissait
 * du round 0 du bloc gele du rapport de combat (journal §166).
 *
 * L'adaptateur remet donc les cartes dans la forme du contrat : **toutes les flottes, dans l'ordre des flottes**, le
 * neutre pour celles qui n'ont rien a declarer. Corriger la bibliotheque elle-meme serait l'autre chemin ; il demande un
 * cycle de compilation que ce poste n'a pas, et l'adaptateur est deja l'endroit ou la reponse devient un round PHP.
 *
 * ## Ce qui est refuse
 *
 * Une carte qui nomme une flotte absente du combat : la perte qu'elle porte n'appartiendrait a personne. Le moteur
 * partage refuserait plus loin le round dont l'attribution ne recouvre pas les pertes du camp ; ici la raison est dite.
 */
final class RustRoundShape
{
    /**
     * Les unites perdues par chaque flotte : toutes les flottes, dans leur ordre, vide quand il n'y a rien.
     *
     * @param array<int, UnitCollection> $perFleet ce que la bibliotheque a rendu
     * @param array<int, int> $fleets les identifiants de mission du camp, **dans l'ordre du moteur** : cet ordre devient
     *        celui des clefs du round, et c'est la seule chose que ce tableau doit garantir
     * @return array<int, UnitCollection>
     */
    public static function unitsOfEveryFleet(array $perFleet, array $fleets, string $side): array
    {
        self::refuseAnUnknownFleet($perFleet, $fleets, $side);

        $complet = [];
        foreach ($fleets as $mission) {
            $complet[$mission] = $perFleet[$mission] ?? new UnitCollection();
        }

        return $complet;
    }

    /**
     * Le meme contrat pour un nombre par flotte (coups portes, degats) : zero quand la flotte n'a rien fait.
     *
     * @param array<int, int> $perFleet
     * @param array<int, int> $fleets
     * @return array<int, int>
     */
    public static function numbersOfEveryFleet(array $perFleet, array $fleets, string $side): array
    {
        self::refuseAnUnknownFleet($perFleet, $fleets, $side);

        $complet = [];
        foreach ($fleets as $mission) {
            $complet[$mission] = $perFleet[$mission] ?? 0;
        }

        return $complet;
    }

    /**
     * @param array<int, mixed> $perFleet
     * @param array<int, int> $fleets
     */
    private static function refuseAnUnknownFleet(array $perFleet, array $fleets, string $side): void
    {
        foreach (array_keys($perFleet) as $mission) {
            if (!in_array($mission, $fleets, true)) {
                throw RustEngineContractMismatch::becauseTheAnswerIs(
                    'une carte par flotte du camp ' . $side . ' nomme la mission ' . $mission . ', qui ne combat pas'
                );
            }
        }
    }
}
