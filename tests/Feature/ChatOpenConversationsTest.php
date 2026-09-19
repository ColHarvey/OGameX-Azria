<?php

namespace Tests\Feature;

use OGame\Chat\OpenConversations;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\ChatService;
use Tests\AccountTestCase;

/**
 * **Les conversations ouvertes arrivent avec la page** (constat de Keven, 19 septembre 2026, journal §170).
 *
 * Le navigateur memorise les fenetres ouvertes dans le cookie `visibleChats` ; la page rend leur historique tout de
 * suite, au lieu que le script le redemande apres coup — « la convo disparait et revient, c est comme si elle se
 * reloadait ». Ce que ces temoins prouvent : la page porte bien la charge utile, elle porte **les memes droits** que la
 * route d historique (le canal d une alliance dont on n est pas membre n y est pas), une memoire illisible ne rend
 * rien, et le nombre de conversations rendues est borne.
 */
class ChatOpenConversationsTest extends AccountTestCase
{
    /**
     * Le cookie tel que `chat.js` l ecrit : du JSON, encode pour une entete HTTP.
     *
     * @param array<int, int> $joueurs
     * @param array<int, int> $alliances
     */
    private static function memoire(array $joueurs, array $alliances = []): string
    {
        return rawurlencode((string)json_encode(['chatbar' => false, 'players' => $joueurs, 'associations' => $alliances]));
    }

    /**
     * Ce que la page publie pour le script, lu sur le HTML rendu.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chargeDeLaPage(string|null $cookie): array
    {
        $requete = $cookie === null ? $this : $this->withUnencryptedCookie('visibleChats', $cookie);
        $page = (string)$requete->get('/overview')->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('#var chatRestore = (\[.*?\]);#s', $page, $trouve), 'La page publie chatRestore.');
        $charge = json_decode($trouve[1] ?? '', true);
        $this->assertIsArray($charge);

        return $charge;
    }

    private function unAutreJoueur(): User
    {
        $autre = User::factory()->create();
        $this->assertNotNull($autre);

        return $autre;
    }

    public function testThePageCarriesTheHistoryOfAnOpenConversation(): void
    {
        $autre = $this->unAutreJoueur();
        resolve(ChatService::class)->sendDirectMessage((int)$autre->id, $this->currentUserId, 'Bonjour de l autre bout de la galaxie');

        $charge = $this->chargeDeLaPage(self::memoire([(int)$autre->id]));

        $this->assertCount(1, $charge, 'La conversation memorisee est rendue, et elle seule.');
        $this->assertSame((int)$autre->id, $charge[0]['playerId']);
        $this->assertSame($autre->username, $charge[0]['playerName']);
        $this->assertNotEmpty($charge[0]['chatItems'], 'Avec son historique : c est tout l interet.');
        $this->assertStringContainsString('Bonjour de l autre bout de la galaxie', (string)json_encode($charge[0]['chatItems']));
    }

    public function testAnAllianceChannelIsRenderedOnlyForItsMembers(): void
    {
        // Une vraie alliance, fondee par un autre joueur : l appartenance passe par le service, jamais par la colonne.
        $etranger = $this->unAutreJoueur();
        $tag = strtoupper(substr('C' . dechex(random_int(0x100000, 0xffffff)), 0, 7));
        $alliance = resolve(AllianceService::class)->createAlliance((int)$etranger->id, $tag, 'Alliance du banc ' . $tag);
        resolve(ChatService::class)->sendAllianceMessage((int)$etranger->id, (int)$alliance->id, 'Reunion de l alliance a 20 h');

        // Le compte de l essai n en est pas membre.
        $charge = $this->chargeDeLaPage(self::memoire([], [(int)$alliance->id]));

        $this->assertSame([], $charge, 'Le canal d une alliance dont on n est pas membre n est jamais rendu.');
        $this->assertNull(resolve(OpenConversations::class)->withAlliance($this->currentUserId, (int)$alliance->id), 'Et la fabrique le refuse aussi, directement.');
        $this->assertNotNull(resolve(OpenConversations::class)->withAlliance((int)$etranger->id, (int)$alliance->id), 'Pour un membre, en revanche, le canal existe — sinon l essai ne prouverait que l absence de donnees.');
    }

    public function testWithoutMemoryOrWithAnUnreadableOneThePageCarriesNothing(): void
    {
        $this->assertSame([], $this->chargeDeLaPage(null), 'Aucun cookie : rien.');
        $this->assertSame([], $this->chargeDeLaPage(rawurlencode('{ceci n est pas du JSON')), 'Une memoire illisible est ignoree.');
        $this->assertSame([], $this->chargeDeLaPage(self::memoire([0, -3])), 'Un identifiant qui n est pas un entier positif est ignore.');
    }

    public function testAtMostFiveConversationsAreRendered(): void
    {
        $identifiants = [];
        foreach (range(1, 7) as $ignore) {
            $autre = $this->unAutreJoueur();
            resolve(ChatService::class)->sendDirectMessage((int)$autre->id, $this->currentUserId, 'Message');
            $identifiants[] = (int)$autre->id;
        }

        $charge = $this->chargeDeLaPage(self::memoire($identifiants));

        $this->assertCount(OpenConversations::LIMITE, $charge, 'Une page ne rend pas dix historiques.');
    }

    public function testAConversationOfSomeoneElseIsNeverRendered(): void
    {
        $premier = $this->unAutreJoueur();
        $second = $this->unAutreJoueur();
        resolve(ChatService::class)->sendDirectMessage((int)$premier->id, (int)$second->id, 'Ceci ne regarde que nous deux');

        $charge = $this->chargeDeLaPage(self::memoire([(int)$premier->id]));

        $this->assertCount(1, $charge, 'La conversation avec ce joueur existe pour le lecteur.');
        $this->assertSame([], $charge[0]['chatItems'], 'Mais elle est vide : les messages echanges entre deux tiers n y sont pas.');
    }
}
