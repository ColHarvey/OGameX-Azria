<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\BuildingQueue;
use OGame\Models\InventoryItem;
use OGame\Models\ResearchQueue;
use OGame\Models\UnitQueue;
use OGame\Models\User;

/**
 * Boutique et inventaire d'objets.
 *
 * Le catalogue est defini en code, comme les objets de jeu de app/GameObjects : la base ne
 * porte que la quantite possedee par chaque joueur (table inventory_items).
 *
 * Les trois familles d'objets sont des accelerateurs instantanes, conformement aux
 * descriptions du jeu : KRAKEN raccourcit la construction de batiments, DETROIDE le
 * chantier naval, NEWTRON la recherche. Il n'y a donc aucun effet a duree a gerer, seulement
 * le champ time_end de la file concernee a diminuer.
 */
class ShopService
{
    /**
     * Categorie affichant tous les objets.
     */
    public const CATEGORY_ALL = 'all';

    /**
     * Catalogue des objets.
     *
     * Les prix sont calibres sur l'economie de ce serveur et non sur celle du jeu officiel,
     * ou la matiere noire s'achete en argent reel. Reperes : une expedition rapporte 150 a
     * 200 MN (300 a 400 avec un Eclaireur), la regeneration est desactivee, et l'Amiral coute
     * 5 000 MN pour sept jours. Des prix officiels (700 / 2 500 / 7 000) rendraient la
     * boutique inaccessible. Pour les modifier, c'est ici et nulle part ailleurs.
     *
     * @var array<string, array<string, mixed>>
     */
    private const ITEMS = [
        // KRAKEN — batiments
        '929d5e15709cc51a4500de4499e19763c879f7f7' => [
            'name_key' => 'kraken', 'tier_key' => 'gold', 'rarity' => 'rare',
            'category' => 'construction', 'target' => 'building',
            'duration' => '6h', 'seconds' => 21600, 'price' => 1800, 'price_label' => '1.8K',
            'image_hash' => '40a1644e104985a3e72da28b76069197128f9fb5',
        ],
        '4a58d4978bbe24e3efb3b0248e21b3b4b1bfbd8a' => [
            'name_key' => 'kraken', 'tier_key' => 'silver', 'rarity' => 'uncommon',
            'category' => 'construction', 'target' => 'building',
            'duration' => '2h', 'seconds' => 7200, 'price' => 750, 'price_label' => '750',
            'image_hash' => '1ee55efe00bb03743ca031a9eaa1374bb936d863',
        ],
        '40f6c78e11be01ad3389b7dccd6ab8efa9347f3c' => [
            'name_key' => 'kraken', 'tier_key' => 'bronze', 'rarity' => 'common',
            'category' => 'construction', 'target' => 'building',
            'duration' => '30m', 'seconds' => 1800, 'price' => 250, 'price_label' => '250',
            'image_hash' => '98629d11293c9f2703592ed0314d99f320f45845',
        ],

        // DETROIDE — chantier naval
        '0968999df2fe956aa4a07aea74921f860af7d97f' => [
            'name_key' => 'detroid', 'tier_key' => 'gold', 'rarity' => 'rare',
            'category' => 'shipyard', 'target' => 'unit',
            'duration' => '6h', 'seconds' => 21600, 'price' => 1800, 'price_label' => '1.8K',
            'image_hash' => '55d4b1750985e4843023d7d0acd2b9bafb15f0b7',
        ],
        '27cbcd52f16693023cb966e5026d8a1efbbfc0f9' => [
            'name_key' => 'detroid', 'tier_key' => 'silver', 'rarity' => 'uncommon',
            'category' => 'shipyard', 'target' => 'unit',
            'duration' => '2h', 'seconds' => 7200, 'price' => 750, 'price_label' => '750',
            'image_hash' => 'd0b8fb3d307b815b3182f3872e8eab654fe677df',
        ],
        'd3d541ecc23e4daa0c698e44c32f04afd2037d84' => [
            'name_key' => 'detroid', 'tier_key' => 'bronze', 'rarity' => 'common',
            'category' => 'shipyard', 'target' => 'unit',
            'duration' => '30m', 'seconds' => 1800, 'price' => 250, 'price_label' => '250',
            'image_hash' => '56724c3a1dcae8036bb172f0be833a6f9a28bc27',
        ],

        // NEWTRON — recherche
        '8a4f9e8309e1078f7f5ced47d558d30ae15b4a1b' => [
            'name_key' => 'newtron', 'tier_key' => 'gold', 'rarity' => 'rare',
            'category' => 'research', 'target' => 'research',
            'duration' => '6h', 'seconds' => 21600, 'price' => 1800, 'price_label' => '1.8K',
            'image_hash' => 'd949732b01a7f7f6d92e814f2de99479a324e1e3',
        ],
        'd26f4dab76fdc5296e3ebec11a1e1d2558c713ea' => [
            'name_key' => 'newtron', 'tier_key' => 'silver', 'rarity' => 'uncommon',
            'category' => 'research', 'target' => 'research',
            'duration' => '2h', 'seconds' => 7200, 'price' => 750, 'price_label' => '750',
            'image_hash' => 'a92734028d1bf2e75c5c25ae134b4d298a5ca36e',
        ],
        'da4a2a1bb9afd410be07bc9736d87f1c8059e66d' => [
            'name_key' => 'newtron', 'tier_key' => 'bronze', 'rarity' => 'common',
            'category' => 'research', 'target' => 'research',
            'duration' => '30m', 'seconds' => 1800, 'price' => 250, 'price_label' => '250',
            'image_hash' => '4bc4327a3fd508b5da84267e2cfd58d47f9e4dcb',
        ],
    ];

    public function __construct(
        private DarkMatterService $darkMatterService
    ) {
    }

    /**
     * Get the whole catalogue, each entry carrying its own reference.
     *
     * @param string $category Categorie a filtrer, ou CATEGORY_ALL.
     * @return array<int, array<string, mixed>>
     */
    public function getItems(string $category = self::CATEGORY_ALL): array
    {
        $items = [];

        foreach (self::ITEMS as $ref => $item) {
            if ($category !== self::CATEGORY_ALL && $item['category'] !== $category) {
                continue;
            }

            $items[] = ['ref' => $ref] + $item;
        }

        return $items;
    }

    /**
     * Get one catalogue entry, or null when the reference is unknown.
     *
     * @param string $ref
     * @return array<string, mixed>|null
     */
    public function getItemByRef(string $ref): array|null
    {
        if (!isset(self::ITEMS[$ref])) {
            return null;
        }

        return ['ref' => $ref] + self::ITEMS[$ref];
    }

    /**
     * Get the categories with the number of items each contains.
     *
     * Seules les categories reellement peuplees sont renvoyees : un onglet vide promettrait
     * un contenu qui n'existe pas.
     *
     * @return array<string, int>
     */
    public function getCategoryCounts(): array
    {
        $counts = [self::CATEGORY_ALL => count(self::ITEMS)];

        foreach (self::ITEMS as $item) {
            $category = $item['category'];
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Get the quantities owned by a player, keyed by item reference.
     *
     * @param User $user
     * @return array<string, int>
     */
    public function getInventory(User $user): array
    {
        $quantities = [];

        foreach (InventoryItem::where('user_id', $user->id)->where('quantity', '>', 0)->get() as $line) {
            $quantities[$line->item_ref] = $line->quantity;
        }

        return $quantities;
    }

    /**
     * Buy one unit of an item, paying with dark matter.
     *
     * @param User $user
     * @param string $ref
     * @return array<string, mixed> L'objet achete.
     * @throws Exception
     */
    public function buy(User $user, string $ref): array
    {
        $item = $this->getItemByRef($ref);
        if ($item === null) {
            throw new Exception(__('t_ingame.shop.msg_unknown_item'));
        }

        if (!$this->darkMatterService->canAfford($user, (int) $item['price'])) {
            throw new Exception(__('t_ingame.shop.msg_not_enough_dark_matter'));
        }

        DB::transaction(function () use ($user, $ref, $item): void {
            $this->darkMatterService->debit(
                $user,
                (int) $item['price'],
                DarkMatterTransactionType::SHOP_ITEM->value,
                'Achat boutique : ' . $item['name_key'] . ' ' . $item['tier_key']
            );

            $this->addToInventory($user, $ref);
        });

        return $item;
    }

    /**
     * Add one unit of an item to a player's inventory.
     *
     * Utilise a l'achat comme au gain en expedition.
     *
     * @param User $user
     * @param string $ref
     * @return void
     */
    public function addToInventory(User $user, string $ref): void
    {
        $line = InventoryItem::where('user_id', $user->id)
            ->where('item_ref', $ref)
            ->lockForUpdate()
            ->first();

        if ($line === null) {
            $line = new InventoryItem();
            $line->user_id = $user->id;
            $line->item_ref = $ref;
            $line->quantity = 0;
        }

        $line->quantity += 1;
        $line->save();
    }

    /**
     * Grant a random item to a player, used by the expedition outcome.
     *
     * @param User $user
     * @return array<string, mixed> L'objet attribue.
     */
    public function grantRandomItem(User $user): array
    {
        // Les objets les plus modestes sont les plus frequents : une expedition ne doit pas
        // valoir mieux qu'un achat reflechi.
        $tirage = [];
        foreach (self::ITEMS as $ref => $item) {
            $poids = match ($item['tier_key']) {
                'bronze' => 6,
                'silver' => 3,
                default => 1,
            };

            $tirage = array_merge($tirage, array_fill(0, $poids, $ref));
        }

        $ref = $tirage[array_rand($tirage)];
        $this->addToInventory($user, $ref);

        /** @var array<string, mixed> $item */
        $item = $this->getItemByRef($ref);

        return $item;
    }

    /**
     * Use one unit of an item, applying its effect to the matching queue.
     *
     * @param PlayerService $player
     * @param string $ref
     * @return array<string, mixed> L'objet consomme.
     * @throws Exception
     */
    public function useItem(PlayerService $player, string $ref): array
    {
        $item = $this->getItemByRef($ref);
        if ($item === null) {
            throw new Exception(__('t_ingame.shop.msg_unknown_item'));
        }

        $user = $player->getUser();
        $planetId = $player->planets->current()->getPlanetId();
        $now = (int) Date::now()->timestamp;

        DB::transaction(function () use ($user, $ref, $item, $player, $planetId, $now): void {
            $line = InventoryItem::where('user_id', $user->id)
                ->where('item_ref', $ref)
                ->lockForUpdate()
                ->first();

            if ($line === null || $line->quantity < 1) {
                throw new Exception(__('t_ingame.shop.msg_item_not_owned'));
            }

            $queueItem = $this->findQueueItem($item['target'], $player->getId(), $planetId);
            if ($queueItem === null || $queueItem->time_end <= $now) {
                throw new Exception(__('t_ingame.shop.msg_nothing_in_progress_' . $item['target']));
            }

            // Le temps restant ne peut pas passer sous l'instant present : la file est alors
            // simplement prete a etre traitee a la prochaine requete du joueur.
            $queueItem->time_end = max($now, $queueItem->time_end - (int) $item['seconds']);
            $queueItem->save();

            $line->quantity -= 1;
            $line->save();
        });

        return $item;
    }

    /**
     * Find the queue entry currently in progress for a given item target.
     *
     * @param string $target building, unit ou research
     * @param int $userId
     * @param int $planetId
     * @return BuildingQueue|UnitQueue|ResearchQueue|null
     */
    private function findQueueItem(string $target, int $userId, int $planetId): BuildingQueue|UnitQueue|ResearchQueue|null
    {
        return match ($target) {
            // Le drapeau building marque l'element reellement en cours, les suivants attendent.
            'building' => BuildingQueue::where('planet_id', $planetId)
                ->where('building', 1)
                ->where('processed', 0)
                ->where('canceled', 0)
                ->first(),

            // Le chantier naval n'a pas de drapeau : le lot en cours est le premier non traite.
            'unit' => UnitQueue::where('planet_id', $planetId)
                ->where('processed', 0)
                ->orderBy('time_start', 'asc')
                ->first(),

            // La recherche est a l'echelle de l'empire : elle se rattache au joueur, pas a la
            // planete depuis laquelle l'objet est utilise.
            'research' => ResearchQueue::join('planets', 'research_queues.planet_id', '=', 'planets.id')
                ->where('planets.user_id', $userId)
                ->where('research_queues.building', 1)
                ->where('research_queues.processed', 0)
                ->where('research_queues.canceled', 0)
                ->select('research_queues.*')
                ->first(),

            default => null,
        };
    }
}
