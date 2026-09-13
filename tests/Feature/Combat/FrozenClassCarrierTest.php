<?php

namespace Tests\Feature\Combat;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use OGame\Combat\Support\CombatantFrozenAtEntry;
use OGame\Combat\Support\FrozenClassCarrier;
use OGame\Combat\Support\FrozenCombatCharacteristics;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Services\CharacterClassService;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;
use Tests\RecordsClassHistory;

/**
 * **Le porteur de la classe d admission est detache, et il n ecrit jamais** (condition de Keven, 13 septembre
 * 2026 : « getUser() doit rendre un porteur detache avec la classe gelee, sans modifier le modele vivant ni un
 * objet partage »).
 *
 * Le compte est Collecteur ; le combattant a ete admis General. Ces essais etablissent ce que le porteur rend,
 * ce qu il ne touche pas — la base, l instance de la fabrique, le modele que le combattant a charge — et chaque
 * ecriture qu il refuse.
 */
final class FrozenClassCarrierTest extends AccountTestCase
{
    use RecordsClassHistory;

    public function testTheCombatantGivesADetachedCarrierOfItsAdmissionClass(): void
    {
        $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);

        // **Le joueur que la fabrique garde, relu a neuf** : ses instances survivent a une ecriture faite
        // ailleurs, et un exemplaire perime dirait n importe quoi de ce porteur.
        $partage = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertSame(CharacterClass::COLLECTOR->value, $partage->getUser()->character_class, 'The premise is missing: the shared player does not carry the class of the account.');

        $combattant = $this->aCombatantAdmittedAs(CharacterClass::GENERAL);

        $porteur = $combattant->getUser();

        $this->assertInstanceOf(FrozenClassCarrier::class, $porteur);
        $this->assertSame(CharacterClass::GENERAL->value, $porteur->character_class, 'The carrier does not carry the class of the admission.');
        $this->assertSame($this->currentUserId, (int)$porteur->id);
        $this->assertSame(DB::table('users')->where('id', $this->currentUserId)->value('username'), $porteur->username, 'The carrier lost the columns of the account.');
        $this->assertTrue($porteur->exists);
        $this->assertNotSame($porteur, $combattant->getUser(), 'Two readers share one carrier.');

        $this->assertSame(CharacterClass::COLLECTOR->value, (int)DB::table('users')->where('id', $this->currentUserId)->value('character_class'), 'The account in the database changed class.');
        $this->assertSame(CharacterClass::COLLECTOR->value, $partage->getUser()->character_class, 'The shared player of the factory changed class.');

        $modele = $this->theModelLoadedBy($combattant);
        $this->assertNotInstanceOf(FrozenClassCarrier::class, $modele, 'The combatant replaced the model it loaded.');
        $this->assertSame(CharacterClass::COLLECTOR->value, $modele->character_class, 'The model the combatant loaded was modified.');
    }

    public function testTheCarrierAnswersTheClassQuestionsOfTheGame(): void
    {
        $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);
        $porteur = $this->aCombatantAdmittedAs(CharacterClass::GENERAL)->getUser();
        $classes = resolve(CharacterClassService::class);

        $this->assertTrue($classes->isGeneral($porteur), 'The carrier does not answer as a General.');
        $this->assertFalse($classes->isCollector($porteur), 'The carrier still answers as the Collector the account is.');
        $this->assertSame(CharacterClass::GENERAL, $classes->getCharacterClass($porteur));

        $sansClasse = $this->aCombatantAdmittedAs(null)->getUser();
        $this->assertNull($sansClasse->character_class, 'A combatant admitted without a class carries the class of the account.');
        $this->assertFalse($classes->isCollector($sansClasse));
    }

    public function testTheCarrierRefusesEveryWrite(): void
    {
        $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);
        $porteur = $this->aCombatantAdmittedAs(CharacterClass::GENERAL)->getUser();
        $avant = (array)DB::table('users')->where('id', $this->currentUserId)->first();

        $ecritures = [
            'save' => static fn (): mixed => $porteur->save(),
            'saveQuietly' => static fn (): mixed => $porteur->saveQuietly(),
            'update' => static fn (): mixed => $porteur->update(['character_class' => CharacterClass::GENERAL->value]),
            'push' => static fn (): mixed => $porteur->push(),
            'touch' => static fn (): mixed => $porteur->touch(),
            'forceFill' => static fn (): mixed => $porteur->forceFill(['username' => 'porteur']),
            'affectation' => static function () use ($porteur): void {
                $porteur->character_class = CharacterClass::DISCOVERER->value;
            },
            'unset' => static function () use ($porteur): void {
                unset($porteur['username']);
            },
            'increment' => static fn (): mixed => $porteur->increment('dark_matter'),
            'decrement' => static fn (): mixed => $porteur->decrement('dark_matter'),
            'delete' => static fn (): mixed => $porteur->delete(),
        ];

        foreach ($ecritures as $geste => $ecriture) {
            try {
                $ecriture();
                $this->fail('The carrier accepted « ' . $geste . ' ».');
            } catch (LogicException $refus) {
                $this->assertStringContainsString('n ecrit jamais', $refus->getMessage(), 'The refusal of « ' . $geste . ' » does not say why.');
            }
        }

        $this->assertSame($avant, (array)DB::table('users')->where('id', $this->currentUserId)->first(), 'A refused write still reached the account.');
        $this->assertSame(CharacterClass::GENERAL->value, $porteur->character_class, 'A refused write still changed the carrier.');
    }

    public function testTheCarrierReachesTheRelationsOfTheAccount(): void
    {
        $porteur = $this->aCombatantAdmittedAs(CharacterClass::GENERAL)->getUser();

        $this->assertSame('users', $porteur->getTable());
        $this->assertSame('user_id', $porteur->getForeignKey());
        $this->assertSame((new User())->getMorphClass(), $porteur->getMorphClass());
        $this->assertSame($this->currentUserId, (int)$porteur->tech()->value('user_id'), 'A relation loaded from the carrier does not reach the rows of the account.');
    }

    private function aCombatantAdmittedAs(CharacterClass|null $classe): CombatantFrozenAtEntry
    {
        return new CombatantFrozenAtEntry($this->currentUserId, new FrozenCombatCharacteristics(0, 0, 0, 0), $classe);
    }

    private function theModelLoadedBy(PlayerService $joueur): User
    {
        $lecture = Closure::bind(function (): User {
            return $this->user;
        }, $joueur, PlayerService::class);

        return $lecture();
    }
}
