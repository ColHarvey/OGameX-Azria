<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OGame\Services\PlayerService;
use OGame\Services\ShopService;

class ShopController extends OGameController
{
    /**
     * Shows the shop index page.
     *
     * @param Request $request
     * @param ShopService $shopService
     * @param PlayerService $player
     * @return View
     */
    public function index(Request $request, ShopService $shopService, PlayerService $player): View
    {
        $this->setBodyId('shop');

        $categories = $shopService->getCategoryCounts();

        // Une categorie inconnue retombe sur le catalogue complet plutot que sur une page vide.
        $category = (string) $request->query('category', ShopService::CATEGORY_ALL);
        if (!isset($categories[$category])) {
            $category = ShopService::CATEGORY_ALL;
        }

        $inventory = $shopService->getInventory($player->getUser());

        // L'inventaire n'affiche que ce que le joueur possede reellement.
        $inventoryItems = [];
        foreach ($shopService->getItems() as $item) {
            if (($inventory[$item['ref']] ?? 0) > 0) {
                $inventoryItems[] = $item;
            }
        }

        return view('ingame.shop.index', [
            'shopItems' => $shopService->getItems($category),
            'inventoryItems' => $inventoryItems,
            'inventory' => $inventory,
            'categories' => $categories,
            'activeCategory' => $category,
            'darkMatter' => $player->getDarkMatter(),
        ]);
    }

    /**
     * Buy one unit of an item.
     *
     * @param Request $request
     * @param ShopService $shopService
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function buy(Request $request, ShopService $shopService, PlayerService $player): JsonResponse
    {
        $validated = $request->validate([
            'ref' => 'required|string|max:64',
        ]);

        try {
            $item = $shopService->buy($player->getUser(), $validated['ref']);

            return response()->json([
                'status' => 'success',
                'message' => __('t_ingame.shop.msg_bought', ['item' => $this->itemLabel($item)]),
                'newAjaxToken' => csrf_token(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'failure',
                'message' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ], 400);
        }
    }

    /**
     * Use one unit of an item from the inventory.
     *
     * @param Request $request
     * @param ShopService $shopService
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function useItem(Request $request, ShopService $shopService, PlayerService $player): JsonResponse
    {
        $validated = $request->validate([
            'ref' => 'required|string|max:64',
        ]);

        try {
            $item = $shopService->useItem($player, $validated['ref']);

            return response()->json([
                'status' => 'success',
                'message' => __('t_ingame.shop.msg_used', [
                    'item' => $this->itemLabel($item),
                    'duration' => (string) $item['duration'],
                ]),
                'newAjaxToken' => csrf_token(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'failure',
                'message' => $e->getMessage(),
                'newAjaxToken' => csrf_token(),
            ], 400);
        }
    }

    /**
     * Build the display label of an item, e.g. "KRAKEN Bronze".
     *
     * @param array<string, mixed> $item
     * @return string
     */
    private function itemLabel(array $item): string
    {
        return __('t_resources.' . $item['name_key'] . '.title')
            . ' ' . __('t_ingame.shop.tier_' . $item['tier_key']);
    }
}
