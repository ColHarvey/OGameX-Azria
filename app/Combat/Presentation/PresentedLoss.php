<?php

namespace OGame\Combat\Presentation;

use OGame\Services\ObjectService;
use Throwable;

/**
 * Une perte, telle que le joueur la voit — composee **une fois**, pour les deux chemins.
 *
 * ## Pourquoi un composeur unique
 *
 * La carte rendue par le serveur et la perte diffusee en direct decrivaient la meme chose a deux
 * endroits : `CombatPanelService` et `CombatPresentationBroadcaster`. Les deux formes ont diverge —
 * le direct affichait « 30 × Chasseur leger » quand la page ecrivait « 30 Chasseur leger perdus »,
 * et l'heure venait du fuseau du navigateur d'un cote, de celui du jeu de l'autre. Un joueur voyait
 * donc deux libelles pour un meme fait, selon qu'il rechargeait ou non.
 *
 * ## Ce qui est fige ici, et ce qui ne peut pas l'etre
 *
 * L'**heure** est formatee par le serveur : le fuseau est celui du jeu, une donnee globale, et un
 * travailleur la calcule aussi bien qu'une requete. Le **libelle** ne l'est pas : il depend de la
 * langue du lecteur, que le diffuseur ne connait pas — il tourne en boucle, hors de toute requete.
 * C'est donc la page qui porte les formes traduites (`jsloca`), et le navigateur qui compose la
 * ligne avec le meme nombre et le meme nom d'unite que le serveur emploierait.
 *
 * **Le nom d'unite obeit a la meme regle, et il l'a appris tard.** `unit_label` est resolu par
 * `__()` au moment ou l'objet du jeu est construit : dans une requete c'est la langue du lecteur,
 * dans le diffuseur celle de l'application. Un joueur francais recevait donc un nom anglais en
 * direct, qui changeait au rechargement. L'identifiant transporte est `unit` ; la page publie la
 * table des noms (`unitLabels()`) et le navigateur y lit le sien. `unit_label` reste dans la charge
 * utile comme **repli** — une unite absente de la table vaut mieux qu'une ligne trouee — et le
 * rendu serveur, lui, s'en sert directement puisque sa langue est la bonne.
 */
final class PresentedLoss
{
    /**
     * @return array{key: string, sequence: int, at: int, at_label: string, side: string, unit: string, unit_label: string, amount: int}
     */
    public static function describe(int $combatInstanceId, int $sequence, int $visibleAt, string $side, string $unit, int $amount): array
    {
        return [
            // **L'identite est (bataille, rang)**, jamais le rang seul : deux batailles simultanees
            // portent chacune un rang 1, et le navigateur deduplique sur cette clef.
            'key' => $combatInstanceId . ':' . $sequence,
            'sequence' => $sequence,
            'at' => $visibleAt,
            // L'heure du jeu, pas celle du navigateur : le fuseau est une donnee du serveur.
            'at_label' => date('H:i:s', $visibleAt),
            'side' => $side,
            'unit' => $unit,
            'unit_label' => self::unitLabel($unit),
            'amount' => $amount,
        ];
    }

    /**
     * Le nom du vaisseau ou de la defense, tel que le jeu l'affiche. Un objet inconnu garde son nom
     * technique : mieux vaut un nom brut qu'une ligne vide dans un bilan de pertes.
     */
    public static function unitLabel(string $machineName): string
    {
        try {
            return ObjectService::getUnitObjectByMachineName($machineName)->title;
        } catch (Throwable) {
            return $machineName;
        }
    }

    /**
     * Tous les noms d'unites, par nom machine, **dans la langue de la requete courante**.
     *
     * La page la publie une fois ; le navigateur y prend le nom de chaque perte recue en direct.
     * C'est le seul endroit ou la langue du lecteur est connue avec certitude : le diffuseur, lui,
     * ne parle que celle de l'application.
     *
     * @return array<string, string>
     */
    public static function unitLabels(): array
    {
        $labels = [];

        foreach (ObjectService::getUnitObjects() as $object) {
            $labels[$object->machine_name] = $object->title;
        }

        return $labels;
    }
}
