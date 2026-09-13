<?php

namespace Tests\Unit;

use LogicException;
use OGame\History\HistoricValue;
use Tests\UnitTestCase;

/**
 * **« Inconnu » n est pas une valeur** : le lire comme s il en etait une leve.
 *
 * `null` est une reponse legitime de ces historiques — aucune classe, aucune alliance. Si « inconnu »
 * rendait `null` a son tour, un combat gelerait « sans classe » la ou personne ne sait, et la regle de
 * Keven du 13 septembre 2026 serait contournee en silence : ne jamais remplacer « inconnu » par zero ni
 * par la valeur actuelle.
 */
class HistoricValueTest extends UnitTestCase
{
    public function testAKnownValueIsGivenBackAsItIsNullIncluded(): void
    {
        $this->assertSame(4, HistoricValue::known(4)->value());
        $this->assertNull(HistoricValue::known(null)->value());
        $this->assertSame('WARRIORS', HistoricValue::known('WARRIORS')->value());
        $this->assertTrue(HistoricValue::known(null)->isKnown());
        $this->assertSame('', HistoricValue::known(null)->reason);
    }

    public function testReadingAnUnknownValueAsIfItWereKnownIsRefused(): void
    {
        $valeur = HistoricValue::unknown('le compte 7 n a aucun historique.');

        $this->assertFalse($valeur->isKnown());
        $this->assertSame('le compte 7 n a aucun historique.', $valeur->reason);

        try {
            $valeur->value();
            $this->fail('An unknown historic value was read as if it were known.');
        } catch (LogicException $refus) {
            $this->assertStringContainsString('le compte 7 n a aucun historique.', $refus->getMessage());
        }
    }
}
