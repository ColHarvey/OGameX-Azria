<?php

namespace OGame\Enums;

enum DarkMatterTransactionType: string
{
    case INITIAL_BONUS = 'initial_bonus';
    case REGENERATION = 'regeneration';
    case EXPEDITION = 'expedition';
    case COMMANDING_STAFF = 'commanding_staff';
    case PLAYER_CLASS = 'player_class';
    case ALLIANCE_CLASS = 'alliance_class';
    case MERCHANT = 'merchant';
    case PLANET_RELOCATION = 'planet_relocation';
    case SPEEDUP = 'speedup';
    case ADMIN_ADJUSTMENT = 'admin_adjustment';
    case HALVING = 'halving';
    case STARTER_AID = 'starter_aid';
    case SHOP_ITEM = 'shop_item';
    case EVENT_REWARD = 'event_reward';
    /**
     * La recompense quotidienne de connexion — **distincte de tout le reste**.
     *
     * Pas `REGENERATION` : celle-la est periodique, reglee ailleurs, et suit `dark_matter_last_regen`.
     * Pas `EVENT_REWARD` : celle-la appartient aux missions d evenement. Un type propre garde les trois
     * mecaniques lisibles dans l historique du compte (fonctionnalite d Azria, 20 septembre 2026).
     */
    case DAILY_REWARD = 'daily_reward';
}
