<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use LogicException;
use OGame\History\ClassHistoryRecorder;
use Tests\TestCase;

/**
 * **L enregistreur refuse d ecrire hors d une transaction** : un changement valide sans sa ligne, ou une
 * ligne sans son changement, laisserait un historique qui ment.
 */
class ClassHistoryRecorderTransactionTest extends TestCase
{
    public function testEveryChangeWriterRefusesToWriteOutsideATransaction(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'The premise is missing: the test itself runs inside a transaction.');

        $enregistreur = new ClassHistoryRecorder();

        $ecritures = [
            'classe personnelle' => static fn () => $enregistreur->personalClass(1, null, 'test'),
            'appartenance' => static fn () => $enregistreur->membership(1, null, 'test'),
            'classe d alliance' => static fn () => $enregistreur->allianceClass(1, null, 'test'),
        ];

        foreach ($ecritures as $quoi => $ecrire) {
            try {
                $ecrire();
                $this->fail('The history of ' . $quoi . ' was written outside any transaction.');
            } catch (LogicException $refus) {
                $this->assertStringContainsString('hors de toute transaction', $refus->getMessage());
            }
        }
    }
}
