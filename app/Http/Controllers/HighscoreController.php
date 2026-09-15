<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Military\MilitaryTallyPublisher;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Services\HighscoreService;
use OGame\Services\PlayerService;

class HighscoreController extends OGameController
{
    /**
     * Shows the highscore index page
     *
     * @param Request $request
     * @param PlayerService $player
     * @param HighscoreService $highscoreService
     * @return View
     */
    public function index(Request $request, PlayerService $player, HighscoreService $highscoreService): View
    {
        $this->setBodyId('highscore');

        return view('ingame.highscore.index')->with([
            'initialContent' => $this->ajax($request, $player, $highscoreService),
            'militaryTallyButtons' => $this->militaryTallyButtons(),
        ]);
    }

    /**
     * Returns highscore AJAX paging content.
     *
     * @param Request $request
     * @param PlayerService $player
     * @param HighscoreService $highscoreService
     * @return View
     */
    public function ajax(Request $request, PlayerService $player, HighscoreService $highscoreService): View
    {
        // **Un classement que ce jeu ne connait pas ne rend aucun classement.** Une demande qui arrive quand meme — un
        // lien garde, une adresse tapee — recoit le message, jamais un autre classement sous son nom ni une erreur qui
        // ferait tourner la page.
        $requestedType = $request->input('type', '0');
        $requestedType = empty($requestedType) ? 0 : (int)$requestedType;

        if (HighscoreTypeEnum::tryFrom($requestedType) === null) {
            return view('ingame.highscore.unavailable')->with([
                'highscoreCurrentCategory' => (int)$request->input('category', '1') === 2 ? 2 : 1,
                'highscoreCurrentType' => $requestedType,
            ]);
        }

        // **Un cumul militaire n est servi qu une fois la collecte activee et publiee.** Avant l activation, ses
        // compteurs ne couvrent aucune periode : des zeros passeraient pour des statistiques completes. Avant la premiere
        // publication, aucune valeur ni aucun rang ne vient d un etat agrege. L activation est une decision de Keven,
        // apres raccordement de tous les chemins de credit (`ogamex:military:demarrer-cumuls`).
        if (HighscoreTypeEnum::from($requestedType)->isMilitaryTally() && !$this->militaryTalliesServed()) {
            return view('ingame.highscore.unavailable')->with([
                'highscoreCurrentCategory' => (int)$request->input('category', '1') === 2 ? 2 : 1,
                'highscoreCurrentType' => $requestedType,
            ]);
        }

        // Check if we received category parameter, if so, use it to determine which highscore category to show.
        // 1 = players
        // 2 = alliances
        $category = $request->input('category', '1');
        if (!empty($category)) {
            $category = (int)$category;
        } else {
            $category = 0;
        }

        if ($category == 1) {
            return $this->ajaxPlayer($request, $player, $highscoreService);
        } else {
            return $this->ajaxAlliance($request, $player, $highscoreService);
        }
    }

    /**
     * Returns highscore AJAX paging content.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return View
     */
    public function ajaxPlayer(Request $request, PlayerService $player, HighscoreService $highscoreService): View
    {
        // Check if we received type parameter, if so, use it to determine which highscore type to show.
        // 0 = points
        // 1 = economy
        // 2 = research
        // 3 = military
        $type = $request->input('type', '0');
        if (!empty($type)) {
            $type = (int)$type;
        } else {
            $type = 0;
        }

        $highscoreService->setHighscoreType($type);

        // Check if we're searching for a specific player's rank
        $searchRelId = $request->input('searchRelId', null);
        if ($searchRelId) {
            // Get the rank of the searched player
            $searchedPlayer = resolve(PlayerService::class, ['player_id' => (int)$searchRelId]);
            $searchedPlayerRank = $highscoreService->getHighscorePlayerRank($searchedPlayer);
            $page = (int) (floor($searchedPlayerRank / 100) + 1);
        } else {
            // Current player rank.
            $currentPlayerRank = $highscoreService->getHighscorePlayerRank($player);
            $currentPlayerPage = floor($currentPlayerRank / 100) + 1;

            // Check if we received a page number, if so, use it instead of the current player rank.
            $page = $request->input('page', null);
            if (!empty($page)) {
                $page = (int)$page;
            } else {
                // Initial page based on current player rank (round to the nearest 100 floored).
                $page = (int)$currentPlayerPage;
            }
        }

        // Current player rank (for highlighting purposes)
        $currentPlayerRank = $highscoreService->getHighscorePlayerRank($player);
        $currentPlayerPage = floor($currentPlayerRank / 100) + 1;

        // Get highscore players content view statically to insert into page.
        return view('ingame.highscore.players_points')->with([
            'highscorePlayers' => $highscoreService->getHighscorePlayers(pageOn: $page),
            'highscorePlayerAmount' => $highscoreService->getHighscorePlayerAmount(),
            'highscoreCurrentPlayerRank' => $currentPlayerRank,
            'highscoreCurrentPlayerPage' => $currentPlayerPage,
            'highscoreCurrentPage' => $page,
            'highscoreCurrentType' => $type,
            'player' => $player,
            'highscoreAdminVisible' => $highscoreService->isAdminVisibleInHighscore(),
            'currentPlayerIsAdmin' => $player->isAdmin(),
            'militaryTallyNote' => $this->militaryTallyNoteFor($type),
            // La ligne que la page recentre : le joueur cherche, sinon moi. Elle recentrait toujours sur moi.
            'highscoreFocusPlayerId' => $searchRelId ? (int)$searchRelId : $player->getId(),
        ]);
    }

    /**
     * Returns highscore AJAX paging content.
     *
     * @param Request $request
     * @param PlayerService $player
     * @param HighscoreService $highscoreService
     * @return View
     */
    public function ajaxAlliance(Request $request, PlayerService $player, HighscoreService $highscoreService): View
    {
        // Check if we received type parameter, if so, use it to determine which highscore type to show.
        // 0 = points
        // 1 = economy
        // 2 = research
        // 3 = military
        $type = $request->input('type', '0');
        if (!empty($type)) {
            $type = (int)$type;
        } else {
            $type = 0;
        }

        $highscoreService->setHighscoreType($type);

        // Check if we're searching for a specific alliance's rank
        $searchRelId = $request->input('searchRelId', null);
        if ($searchRelId) {
            // Get the rank of the searched alliance
            $searchedAllianceRank = $highscoreService->getHighscoreAllianceRank((int)$searchRelId);
            $page = $searchedAllianceRank > 0 ? (int) (floor($searchedAllianceRank / 100) + 1) : 1;
        } else {
            // Current player's alliance rank
            $currentAllianceRank = 0;
            $currentAlliancePage = 1;

            $userAllianceId = $player->getUser()->alliance_id;
            if ($userAllianceId) {
                $currentAllianceRank = $highscoreService->getHighscoreAllianceRank($userAllianceId);
                if ($currentAllianceRank > 0) {
                    $currentAlliancePage = (int) floor($currentAllianceRank / 100) + 1;
                }
            }

            // Check if we received a page number, if so, use it instead of the current alliance rank.
            $page = $request->input('page', null);
            if (!empty($page)) {
                $page = (int)$page;
            } else {
                // Initial page based on current alliance rank (round to the nearest 100 floored).
                $page = (int)$currentAlliancePage;
            }
        }

        // Current alliance rank (for highlighting purposes)
        $currentAllianceRank = 0;
        $currentAlliancePage = 1;

        $userAllianceId = $player->getUser()->alliance_id;
        if ($userAllianceId) {
            $currentAllianceRank = $highscoreService->getHighscoreAllianceRank($userAllianceId);
            if ($currentAllianceRank > 0) {
                $currentAlliancePage = (int) floor($currentAllianceRank / 100) + 1;
            }
        }

        // Get highscore alliances content view statically to insert into page.
        return view('ingame.highscore.alliance_points')->with([
            'highscoreAlliances' => $highscoreService->getHighscoreAlliances(pageOn: $page),
            'highscoreAllianceAmount' => $highscoreService->getHighscoreAllianceAmount(),
            'highscoreCurrentAllianceRank' => $currentAllianceRank,
            'highscoreCurrentAlliancePage' => $currentAlliancePage,
            'highscoreCurrentPage' => $page,
            'highscoreCurrentType' => $type,
            'currentUserAllianceId' => $userAllianceId,
            'player' => $player,
            'militaryTallyNote' => $this->militaryTallyNoteFor($type),
            // La ligne que la page recentre : l alliance cherchee, sinon la mienne, sinon aucune.
            'highscoreFocusAllianceId' => $searchRelId ? (int)$searchRelId : (int)($userAllianceId ?? 0),
        ]);
    }

    /**
     * Les trois sous-boutons des cumuls militaires, et s ils peuvent etre servis.
     *
     * Tant que les cumuls ne sont pas servis, ils restent visibles mais inertes : ce ne sont pas des liens, le script
     * du classement n ecoute que `a.subnavButton`, ils n envoient donc rien. La valeur envoyee vient de l enum, jamais
     * d un nombre ecrit dans la vue.
     *
     * @return array{active: bool, buttons: list<array{type: int, name: string, label: string}>}
     */
    private function militaryTallyButtons(): array
    {
        return [
            'active' => $this->militaryTalliesServed(),
            'buttons' => [
                ['type' => HighscoreTypeEnum::military_built->value, 'name' => 'built', 'label' => 't_ingame.highscore.military_built'],
                ['type' => HighscoreTypeEnum::military_destroyed->value, 'name' => 'destroyed', 'label' => 't_ingame.highscore.military_destroyed'],
                ['type' => HighscoreTypeEnum::military_lost->value, 'name' => 'lost', 'label' => 't_ingame.highscore.military_lost'],
            ],
        ];
    }

    /**
     * Les cumuls militaires sont-ils servis ? Il faut une collecte activee **et** une publication : avant la premiere,
     * aucune valeur ni aucun rang ne vient d un etat agrege.
     */
    private function militaryTalliesServed(): bool
    {
        return resolve(MilitaryTallyRecorder::class)->collectingSince() !== null && MilitaryTallyPublisher::publishedAt() !== null;
    }

    /**
     * Ce que la page dit d un cumul militaire : depuis quand il couvre, quand il a ete publie, et combien d evenements
     * attendent encore.
     *
     * Nul pour tout autre classement, et nul tant que les cumuls ne sont pas servis — la page ne sert alors pas ces
     * classements du tout.
     *
     * Les instants sont rendus deux fois : formates en heure du serveur, pour qui lit sans script, et bruts, pour que
     * la page les affiche a l heure du navigateur comme l horloge du bandeau.
     *
     * @return array{since: string, since_at: int, refreshed: string, refreshed_at: int, pending: int}|null
     */
    private function militaryTallyNoteFor(int $type): array|null
    {
        $classement = HighscoreTypeEnum::tryFrom($type);

        if ($classement === null || !$classement->isMilitaryTally()) {
            return null;
        }

        $registre = resolve(MilitaryTallyRecorder::class);
        $depuis = $registre->collectingSince();
        $publie = MilitaryTallyPublisher::publishedAt();

        if ($depuis === null || $publie === null) {
            return null;
        }

        return [
            'since' => Date::createFromTimestamp($depuis)->format('d.m.Y'),
            'since_at' => $depuis,
            'refreshed' => Date::createFromTimestamp($publie)->format('d.m.Y H:i:s'),
            'refreshed_at' => $publie,
            'pending' => $registre->pendingCount(),
        ];
    }
}
