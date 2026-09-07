<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Chat\ChatEmojiPalette;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Un emoji ecrit dans le chat en ressort intact — mesure que SQLite ne peut pas faire.
 *
 * ## Le defaut que cet essai ferme d'avance
 *
 * Un emoji occupe **quatre octets en UTF-8**. Une colonne ou une connexion en `utf8` — trois octets
 * chez MySQL et MariaDB, malgre le nom — ne peut pas les porter : selon le mode, l'ecriture est
 * refusee, ou bien le texte est **tronque a l'endroit du premier emoji** et le joueur perd la fin
 * de son message. Rien n'echoue bruyamment ; il faut lire le message rendu pour s'en apercevoir.
 *
 * ## Pourquoi il vit au bac, et nulle part ailleurs
 *
 * SQLite stocke du texte sans notion de jeu de caracteres : l'aller-retour y reussit **quel que
 * soit** le reglage du projet. Le juste et le faux y coincident exactement, et aucun essai de la
 * suite ordinaire ne pourrait les separer. Seul le moteur de production tranche.
 *
 * L'essai porte sur toute la palette, pas sur un signe : les emoji composes — ceux qui portent un
 * selecteur de variante, comme l'epee ou le bouclier — sont plusieurs points de code, et c'est
 * precisement le genre que la troncature coupe en deux.
 */
#[Group('mariadb')]
final class EmojiSurviveTheRoundTripTest extends TestCase
{
    // **Seulement pour `requiresMariaDb()`.** Cet essai ne lance aucun processus : il ne mesure
    // pas une course, il mesure ce que la colonne accepte. Le garde de pilote vit la, il est
    // repris tel quel plutot que recopie.
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresMariaDb();
    }

    /**
     * Chaque signe de la palette revient identique, octet pour octet.
     */
    public function testEveryEmojiOfThePaletteComesBackIntact(): void
    {
        $auteur = User::factory()->create();
        $palette = ChatEmojiPalette::all();
        $texte = 'Salut ' . implode(' ', $palette) . ' a bientot';

        /** @var ChatMessage $message */
        $message = ChatMessage::create([
            'sender_id' => $auteur->id,
            'message' => $texte,
        ]);

        // Relu depuis la base, pas depuis l'instance en memoire : c'est l'aller-retour qui est
        // eprouve, et une instance conservee repondrait ce qu'on vient de lui donner.
        $relu = (string)DB::table('chat_messages')->where('id', $message->id)->value('message');

        $this->assertSame(
            $texte,
            $relu,
            'The chat text does not survive the database: emoji are four bytes, and a utf8 column truncates at the first one.'
        );

        foreach ($palette as $signe) {
            $this->assertStringContainsString(
                $signe,
                $relu,
                'The emoji ' . bin2hex($signe) . ' is gone or mangled after the round trip.'
            );
        }
    }

    /**
     * La colonne elle-meme porte un jeu de caracteres sur quatre octets.
     *
     * L'essai precedent etablit le comportement ; celui-ci nomme la cause, pour qu'un echec dise
     * quoi corriger au lieu de laisser chercher.
     */
    public function testTheColumnItselfCanHoldFourByteCharacters(): void
    {
        $colonne = DB::selectOne(
            'SELECT CHARACTER_SET_NAME AS jeu FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['chat_messages', 'message']
        );

        $this->assertNotNull($colonne, 'The chat_messages.message column is gone.');

        $this->assertSame(
            'utf8mb4',
            $colonne->jeu,
            'The chat column holds at most three bytes per character: every emoji a player writes is lost there.'
        );
    }
}
