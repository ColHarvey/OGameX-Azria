<?php

namespace OGame\Combat\Presentation;

use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatsInvolvingPlayer;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\PlayerService;

/**
 * Ce que la vue generale montre d'un combat durable a un joueur qui y est partie.
 *
 * ## Ce que le panneau dit, et ce qu'il tait
 *
 * Il dit le role du joueur, la cible, la phase, et **les pertes du joueur deja visibles** : celles
 * dont l'instant de visibilite est passe, lues sur le fil fige.
 *
 * Il tait tout le reste, et c'est une regle de jeu : aucune perte future, meme masquee ; aucun
 * numero ni frontiere de round ; rien des autres participants ; et **aucune echeance de la
 * bataille**, sous aucune forme. Des secondes restantes accompagnees de l'heure du serveur
 * reconstituent l'instant de fin aussi surement que l'instant lui-meme : le panneau n'en envoie
 * donc aucune. Les horaires des vols ordinaires, eux, restent ceux du jeu, ailleurs.
 *
 * ## Pourquoi un service, comme le panneau des PNJ
 *
 * La vue generale et son rafraichissement AJAX rendent le meme fragment ; un seul endroit produit
 * les donnees, la vue ne calcule rien.
 */
final class CombatPanelService
{
    /**
     * Combien de temps une bataille finie reste sur la carte, en secondes.
     */
    public const FINISHED_STAYS_FOR = 1800;

    public const string ROLE_ATTACKER = 'attacker';

    public const string ROLE_TARGET = 'target';

    public const string ROLE_REINFORCEMENT = 'reinforcement';

    public function __construct(
        private readonly CombatPresentationTimelineReader $reader = new CombatPresentationTimelineReader(),
    ) {
    }

    /**
     * Le panneau d'un joueur : ses combats en cours, chacun decrit pour la vue.
     *
     * @return array{visible: bool, server_now: int, combats: array<int, array<string, mixed>>}
     */
    public function forPlayer(PlayerService $player, int $now): array
    {
        $corps = $this->bodiesOf($player);
        $lignes = [];

        foreach (CombatsInvolvingPlayer::stillRunning($player->getId(), $corps) as $combat) {
            $lignes[] = $this->describe($combat, $player->getId(), $corps, $now);
        }

        // **Une bataille finie reste sur la carte une demi-heure** : c'est la que le joueur apprend
        // que son rapport est disponible, sans attendre d'ouvrir sa messagerie. Passe ce delai, la
        // messagerie seule en garde la trace — le deroulant n'est pas une archive.
        foreach (CombatsInvolvingPlayer::recentlyFinished($player->getId(), $corps, $now - self::FINISHED_STAYS_FOR) as $combat) {
            $lignes[] = $this->describe($combat, $player->getId(), $corps, $now);
        }

        return ['visible' => $lignes !== [], 'server_now' => $now, 'combats' => $lignes];
    }

    /**
     * Ce joueur est-il partie a ce combat ?
     */
    public function isPartyTo(CombatInstance $combat, PlayerService $player): bool
    {
        return CombatsInvolvingPlayer::isPartyTo($combat, $player->getId(), $this->bodiesOf($player));
    }

    /**
     * Un combat, decrit pour ce joueur a cet instant.
     *
     * @param array<int, int> $corps Les corps du joueur.
     * @return array<string, mixed>
     */
    public function describe(CombatInstance $combat, int $playerId, array $corps, int $now): array
    {
        $statut = $combat->status;
        $pertes = $this->visibleLosses($combat, $playerId, $now, 0);

        return [
            'id' => (int)$combat->id,
            'status' => $statut->value,
            'status_label' => __('t_ingame.combat.status_' . $statut->value),
            'role' => $this->roleOf($combat, $playerId, $corps),
            'target' => [
                'body_id' => $combat->target_planet_id === null ? null : (int)$combat->target_planet_id,
                'name' => $this->targetNameOf($combat),
                // Une lune et une planete portent la meme adresse : sans ce fait, la carte montrait
                // toujours une planete, et le joueur ne savait pas lequel de ses deux corps est
                // attaque.
                'is_moon' => $this->targetIsMoon($combat),
                'galaxy' => (int)$combat->galaxy,
                'system' => (int)$combat->system,
                'position' => (int)$combat->position,
            ],
            // Le rapport n'est propose que lorsqu'il existe reellement : une bataille reglee sans
            // rapport ecrit n'offre aucun lien, plutot qu'un lien vers rien.
            'report_available' => $statut === CombatState::Resolved && $combat->battle_report_id !== null,
            'events' => $pertes,
            // Le resume ferme de la carte : combien d'unites le joueur a perdues, tout compris. Une
            // synthese, pour que la liste depliee reste une consultation et non un mur.
            'losses_total' => array_sum(array_column($pertes, 'amount')),
        ];
    }

    /**
     * Les pertes du joueur deja visibles, apres un rang, pretes pour la vue.
     *
     * @return array<int, array{key: string, sequence: int, at: int, at_label: string, side: string, unit: string, unit_label: string, amount: int}>
     */
    public function visibleLosses(CombatInstance $combat, int $playerId, int $now, int $afterSequence): array
    {
        $lignes = [];

        foreach ($this->reader->visibleTo($combat, $playerId, $now, $afterSequence) as $evenement) {
            // **Un seul composeur pour les deux chemins.** La carte et la diffusion decrivaient la
            // meme perte a deux endroits, et les deux formes avaient diverge — libelle et heure.
            $lignes[] = PresentedLoss::describe(
                (int)$combat->id,
                (int)$evenement->sequence,
                (int)$evenement->visibleAt,
                (string)$evenement->side,
                (string)$evenement->unit,
                (int)$evenement->amount
            );
        }

        return $lignes;
    }

    /**
     * Le role du joueur : cible, attaquant, ou renfort.
     *
     * Avant la cloture personne n'est inscrit : le role se lit du corps vise et de la mission
     * liee. Apres, l'inscription fait foi — la garnison y figure sous la clef du corps.
     *
     * @param array<int, int> $corps
     */
    private function roleOf(CombatInstance $combat, int $playerId, array $corps): string
    {
        if ($combat->target_planet_id !== null && in_array((int)$combat->target_planet_id, $corps, true)) {
            return self::ROLE_TARGET;
        }

        $inscription = CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('player_id', $playerId)
            ->orderBy('id')
            ->first(['side', 'participant_key']);

        if ($inscription !== null) {
            if ((string)$inscription->side === CombatParticipant::SIDE_ATTACKER) {
                return self::ROLE_ATTACKER;
            }

            return CombatParticipantKey::isBody((string)$inscription->participant_key) ? self::ROLE_TARGET : self::ROLE_REINFORCEMENT;
        }

        // En ralliement : une mission liee du joueur. Une attaque ouvre ou rejoint ; tout autre
        // genre retenu sur le corps est un renfort.
        $genre = FleetMission::query()
            ->where('combat_instance_id', $combat->id)
            ->where('user_id', $playerId)
            ->orderBy('id')
            ->value('mission_type');

        return in_array((int)$genre, [1, 2], true) ? self::ROLE_ATTACKER : self::ROLE_REINFORCEMENT;
    }

    /**
     * Le corps vise est-il une lune ? Un corps disparu n'en est plus une : la carte le montre alors
     * comme une planete, ce qui est aussi ce que ses coordonnees designent.
     */
    private function targetIsMoon(CombatInstance $combat): bool
    {
        if ($combat->target_planet_id === null) {
            return false;
        }

        return PlanetType::tryFrom((int)(Planet::query()->whereKey((int)$combat->target_planet_id)->value('planet_type') ?? 0)) === PlanetType::Moon;
    }

    private function targetNameOf(CombatInstance $combat): string
    {
        if ($combat->target_planet_id === null) {
            return '';
        }

        return (string)(Planet::query()->whereKey((int)$combat->target_planet_id)->value('name') ?? '');
    }

    /**
     * @return array<int, int>
     */
    private function bodiesOf(PlayerService $player): array
    {
        $corps = [];

        foreach ($player->planets->all() as $planete) {
            $corps[] = $planete->getPlanetId();
        }

        return $corps;
    }
}
