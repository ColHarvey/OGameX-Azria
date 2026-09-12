<?php

namespace OGame\Http\ViewComposers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\User;
use OGame\Services\BuddyService;
use OGame\Services\ChatService;
use OGame\Services\EventMissionService;
use OGame\Services\FleetMissionService;
use OGame\Services\HighscoreService;
use OGame\Services\MessageService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use OGame\ViewModels\ResourceBarViewModel;

/**
 * Class IngameMainComposer
 * @package OGame\Http\Composers
 *
 * Contains all preprocessor logic for parsing the ingame.layouts.main
 * blade theme file.
 */
class IngameMainComposer
{
    /**
     * IngameMainComposer constructor.
     *
     * Construct view composer and get all required data via dependency
     * injection.
     *
     * @param Request $request
     * @param PlayerService $player
     * @param MessageService $messageService
     * @param SettingsService $settingsService
     * @param FleetMissionService $fleetMissionService
     * @param HighscoreService $highscoreService
     * @param BuddyService $buddyService
     */
    public function __construct(private Request $request, private PlayerService $player, private MessageService $messageService, private SettingsService $settingsService, private FleetMissionService $fleetMissionService, private HighscoreService $highscoreService, private BuddyService $buddyService, private ChatService $chatService, private EventMissionService $eventMissionService)
    {
    }

    /**
     * Compose the view and pass any required variables.
     *
     * @param View $view
     */
    public function compose(View $view): void
    {
        /*
         * **Le bandeau est compose une seule fois**, par `ResourceBarViewModel` : le HTML du
         * gabarit, l'objet que `reloadResources()` recoit au chargement et la resynchronisation
         * en direct (`/ajax/resourcebox`) lisent la meme classe. Deux constructions auraient
         * diverge, et le joueur aurait vu le bandeau sauter a chaque synchronisation.
         */
        $resourceBar = ResourceBarViewModel::of($this->player);
        $resources = $resourceBar->resources;

        // Include body_id, which might have been set in the controller.
        $body_id = $this->request->attributes->get('body_id');

        // Get current locale
        $locale = App::getLocale();

        $currentPlayerIsAdmin = $this->player->isAdmin();
        $highscoreAdminVisible = $this->highscoreService->isAdminVisibleInHighscore();

        // Show "-" for admin rank if setting is disabled, otherwise show actual rank
        if ($currentPlayerIsAdmin && !$highscoreAdminVisible) {
            $highscoreRank = '-';
        } else {
            $highscoreRank = Cache::remember('player-highscore' . $this->player->getId(), now()->addMinutes(5), function () {
                return $this->highscoreService->getHighscorePlayerRank($this->player);
            });
        }

        $impersonateManager = app('impersonate');
        $isImpersonating = $impersonateManager->isImpersonating();
        $attackBlockUntil = $this->settingsService->attackBlockUntil();
        $attackBlockActive = $this->settingsService->attackBlockActive();

        $view->with([
            'underAttack' => $this->fleetMissionService->currentPlayerUnderAttack(),
            'eventRunning' => $this->eventMissionService->isRunning(),
            'unreadMessagesCount' => $this->messageService->getUnreadMessagesCount(),
            'buddyRequestCount' => $this->buddyService->getUnreadRequestsCount((int) auth()->id()),
            'onlineBuddiesCount' => $this->getOnlineContactsCount(),
            'unreadChatCount' => $this->chatService->getTotalUnreadMessageCount((int) auth()->id()),
            'resources' => $resources,
            'resourceBarTicker' => $resourceBar->ticker,
            'currentPlayer' => $this->player,
            'currentPlanet' => $this->player->planets->current(),
            'planets' => $this->player->planets,
            'highscoreRank' => $highscoreRank,
            'settings' => $this->settingsService,
            'body_id' => $body_id,
            'locale' => $locale,
            'isImpersonating' => $isImpersonating,
            'impersonateLeaveUrl' => $isImpersonating ? route('impersonate.leave') : null,
            // **L'attente de suppression se voit** : un compte qui renforce le combat d'un autre joueur
            // ne peut pas etre efface tant que ce combat n'est pas final, et il doit le savoir a
            // chaque page — pas seulement sur celle ou il l'a demande. Lu sur la ligne du compte.
            'pendingDeletionSince' => $this->player->isPendingDeletion() ? (int)$this->player->getUser()->deletion_pending_since : null,
            'attackBlockActive' => $attackBlockActive,
            'attackBlockUntil' => $attackBlockUntil,
            'attackBlockTooltip' => $attackBlockActive
                ? __('The attack block is active till :until. In that time only friendly fleets can be started.', [
                    'until' => Date::createFromTimestamp($attackBlockUntil)->format('d.m.Y H:i:s'),
                ])
                : '',
        ]);
    }

    /**
     * Get the total number of online contacts (buddies + alliance members).
     */
    private function getOnlineContactsCount(): int
    {
        $userId = (int) auth()->id();

        // Count online buddies
        $onlineBuddies = $this->buddyService->getOnlineBuddiesCount($userId);

        // Count online alliance members (excluding self and buddies)
        $onlineAllianceMembers = 0;
        $user = User::find($userId);
        if ($user && $user->alliance_id && $user->alliance) {
            /** @var Alliance $alliance */
            $alliance = $user->alliance;
            $buddyUserIds = $this->buddyService->getBuddies($userId)->map(function ($req) use ($userId) {
                return $req->sender_user_id === $userId ? $req->receiver_user_id : $req->sender_user_id;
            })->toArray();

            $onlineAllianceMembers = $alliance->members()
                ->where('user_id', '!=', $userId)
                ->whereNotIn('user_id', $buddyUserIds)
                ->with('user')
                ->get()
                ->filter(function ($member) {
                    /** @var AllianceMember $member */
                    return $member->user && $member->user->isOnline();
                })
                ->count();
        }

        return $onlineBuddies + $onlineAllianceMembers;
    }
}
