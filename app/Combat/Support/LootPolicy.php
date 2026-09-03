<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\HonorPolicy;
use OGame\Combat\Enums\NoLootReason;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Policies\LootPolicyRegistry;
use OGame\Combat\Policies\NoLootV1;
use OGame\Combat\Policies\NpcBaseV1;

/**
 * Les faits qui determinent le droit de pillage d'un combat, et la regle qui s'y applique.
 *
 * ## Ce que cette classe porte, et ce qu'elle ne calcule plus
 *
 * Elle portait la formule. Elle ne porte plus que **les faits** — inactivite de la cible, fret
 * engage, part des Decouvreurs, honneur — et **l'identifiant de la regle** sous laquelle ce combat
 * a ete ouvert. Le calcul appartient a l'implementation choisie par le registre.
 *
 * La raison en est la duree : un combat persistant vit longtemps, et sa regle peut avoir
 * ete remplacee entre son ouverture et sa resolution. Une formule ecrite ici changerait le resultat
 * de tous les combats deja ouverts sans que leur version ne bouge — c'est-a-dire sans que rien ne
 * le signale.
 *
 * ## Les trois regles d'aujourd'hui
 *
 * - `cargo_weighted_v1` — un combat entre joueurs, taux pondere par le fret engage ;
 * - `npc_base_v1` — un camp entierement pilote par le serveur, cinquante pour cent fixes ;
 * - `no_loot_v1` — un combat qui ne donne droit a rien, et qui dit pourquoi.
 *
 * Le choix entre elles appartient a `LootPolicySelector`, jamais a un appelant.
 */
final readonly class LootPolicy
{
    /**
     * @param bool $targetIsInactive L'inactivite de la cible, **figee a l'ouverture**. Un changement
     *                               pendant le combat ne doit rien modifier
     *                               retroactivement.
     * @param AttackerCargoShare $cargo Le fret offensif engage et la part des Decouvreurs.
     * @param HonorPolicy $honor L'etat du systeme d'honneur. Desactive aujourd'hui.
     * @param NoLootReason|null $noLootBecause La raison du refus, quand ce combat ne pille pas.
     * @param string $version La regle applicable, persistee avec le combat.
     */
    public function __construct(
        public bool $targetIsInactive,
        public AttackerCargoShare $cargo,
        public HonorPolicy $honor = HonorPolicy::Disabled,
        public NoLootReason|null $noLootBecause = null,
        public string $version = CargoWeightedV1::VERSION,
    ) {
    }

    /**
     * Un combat qui ne donne droit a aucun pillage.
     *
     * **Un refus nomme, jamais un taux de cinquante pour cent choisi par defaut.** Tous les combats
     * du jeu ne pillent pas : un contre-espionnage et une rencontre d'expedition se battent sans
     * rien emporter. Leur donner un contexte de pillage ordinaire leur accorderait un droit par
     * inadvertance — et, dans le cas de l'expedition, sur le stock de l'attaquant lui-meme.
     *
     * @param NoLootReason $reason
     * @return self
     */
    public static function noLoot(NoLootReason $reason): self
    {
        return new self(false, AttackerCargoShare::none(), HonorPolicy::Disabled, $reason, NoLootV1::VERSION);
    }

    /**
     * La politique d'un camp entierement pilote par le serveur.
     *
     * Un pirate n'a pas de classe : aucun bonus, quel que soit l'etat de la cible. La version
     * distincte ne change pas le chiffre — cinquante pour cent dans les deux cas — mais elle
     * enregistre qu'aucune ponderation n'avait lieu d'etre, plutot qu'une ponderation ayant donne
     * zero.
     *
     * Le fret est conserve pour l audit, bien que la regle ne s en serve pas : savoir ce qu une
     * flotte pirate engageait reste utile pour relire un combat.
     *
     * @param bool $targetIsInactive
     * @param AttackerCargoShare|null $cargo
     * @return self
     */
    public static function forNpcAttacker(bool $targetIsInactive, AttackerCargoShare|null $cargo = null): self
    {
        return new self($targetIsInactive, $cargo ?? AttackerCargoShare::none(), HonorPolicy::Disabled, null, NpcBaseV1::VERSION);
    }

    /**
     * Le taux maximal legalement pillable, en points de base.
     *
     * **Sans jamais consulter l'issue du combat.** Ni vainqueur, ni survivants, ni pertes, ni
     * capacite de fret survivante n'entrent ici : c'est ce qui empeche le solde disponible du
     * defenseur de reveler le resultat avant le rapport.
     *
     * @param LootPolicyRegistry|null $registry Le registre a consulter. Celui du jeu par defaut ;
     *                                          un essai peut en fournir un autre pour demontrer
     *                                          qu'une ancienne regle reste applicable.
     * @return int
     */
    public function maximumRateInBasisPoints(LootPolicyRegistry|null $registry = null): int
    {
        return ($registry ?? LootPolicyRegistry::default())
            ->forVersion($this->version)
            ->rateInBasisPoints($this);
    }
}
