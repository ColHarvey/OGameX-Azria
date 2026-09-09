<?php

namespace OGame\GameMissions\BattleEngine\State;

use OGame\GameMissions\BattleEngine\Draws\BattleDraws;
use OGame\GameMissions\BattleEngine\Models\BattleUnit;
use OGame\GameObjects\Models\Units\UnitCollection;

/**
 * L etat du champ de bataille entre deux rounds.
 *
 * ## Pourquoi cet objet existe
 *
 * Aujourd hui la bataille se calcule d un seul tenant a la fermeture du ralliement : six rounds,
 * un verdict, et plus rien a decider. Le moteur progressif demande l inverse — jouer un round,
 * s arreter, admettre ce qui est arrive, reprendre. Pour s arreter, il faut savoir **ce qui
 * traverse un round**, et le nommer.
 *
 * Cet objet est ce nom. Il ne persiste rien et ne change aucun comportement : il rend seulement
 * explicite ce que la boucle portait dans ses variables locales. C est la premiere etape de la
 * conception (`moteur-progressif-conception.html`, section 5), celle dont la seule livraison est
 * l equivalence.
 *
 * ## Ce qui traverse un round, et le piege qu il ferme
 *
 * Les **coques entamees**. Le moteur remet `currentShieldPoints` a sa valeur d origine a la fin de
 * chaque round — les boucliers se regenerent — mais **jamais** `currentHullPlating`. Un vaisseau
 * touche et survivant traverse le round avec sa coque entamee.
 *
 * Porter un simple compte d unites d une etape a l autre **soignerait donc la flotte a chaque
 * etape** : un defaut invisible en lecture, enorme en jeu. Les unites voyagent en objets, avec leur
 * coque, et c est la raison d etre de ce transport.
 *
 * ## L etat des tirages voyage avec le champ
 *
 * Le generateur est un xorshift 32 bits : son etat tient en un mot. Le porter ici rend la suite de
 * tirages **independante du decoupage** — jouer six rounds d affilee ou six etapes d un round
 * consomme exactement la meme sequence, dans le meme ordre. C est ce qui permettra de prouver que
 * decouper la bataille ne la change pas.
 *
 * ## Ce que cet objet n est pas encore
 *
 * Il ne se persiste pas, ne porte pas d index d etape en base, et ne connait ni admission de
 * renfort ni photographie par participant. Ces trois-la sont les etapes 2 et 4 de la conception.
 * Le dire plutot que de laisser croire que la couture est complete.
 */
final class BattleFieldState
{
    /**
     * @param array<int, BattleUnit> $attackerUnits Les unites attaquantes vivantes, en ordre canonique.
     * @param array<int, BattleUnit> $defenderUnits Les unites defenseuses vivantes, en ordre canonique.
     * @param BattleDraws $roundDraws La bande des rounds, avec ce qu elle a deja consomme.
     * @param int $roundsPlayed Combien de rounds ont ete joues sur ce champ.
     * @param UnitCollection $attackerRemainingShips Le decompte attaquant apres les pertes.
     * @param UnitCollection $defenderRemainingShips Le decompte defenseur apres les pertes.
     * @param UnitCollection $attackerLosses Le cumul des pertes attaquantes.
     * @param UnitCollection $defenderLosses Le cumul des pertes defenseuses.
     * @param array<int, UnitCollection> $attackerLossesPerFleet Le cumul des pertes, flotte par flotte.
     * @param array<int, UnitCollection> $attackerShipsPerFleet Ce qui reste, flotte par flotte.
     */
    public function __construct(
        public array $attackerUnits,
        public array $defenderUnits,
        public BattleDraws $roundDraws,
        public int $roundsPlayed,
        public UnitCollection $attackerRemainingShips,
        public UnitCollection $defenderRemainingShips,
        public UnitCollection $attackerLosses,
        public UnitCollection $defenderLosses,
        public array $attackerLossesPerFleet,
        public array $attackerShipsPerFleet,
    ) {
    }

    /**
     * Reste-t-il des combattants des deux cotes ?
     *
     * La condition d arret du moteur, sortie de la boucle pour que l avanceur puisse la poser sans
     * rejouer un round pour la decouvrir.
     */
    public function bothSidesStillStand(): bool
    {
        return $this->attackerUnits !== [] && $this->defenderUnits !== [];
    }
}
