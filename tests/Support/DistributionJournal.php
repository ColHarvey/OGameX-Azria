<?php

namespace Tests\Support;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Le journal de repartition : quel processus a joue quelles classes, et dans quel ordre.
 *
 * ## Le defaut qu'il ferme
 *
 * Trois essais de ce depot dependaient de l'etat laisse par leurs voisins. Quand l'un d'eux rougit,
 * les bases conservees disent **ce que les voisins ont laisse**, mais rien ne dit **qui** etait ces
 * voisins ni dans quel ordre ils sont passes : ParaTest distribue les classes aux processus a mesure
 * qu'ils se liberent, et cette distribution depend du minutage. Le rapport JUnit fusionne les
 * processus et perd cette information.
 *
 * Sans elle, un rouge attrape est **observe**, jamais rejouable a l'identique.
 *
 * ## Inerte par defaut
 *
 * L'extension ne fait rien tant que `OGAMEX_TEST_JOURNAL` ne nomme pas un dossier. La suite normale
 * et l'integration continue ne l'arment pas : elles paient une lecture d'environnement au demarrage,
 * et rien d'autre. **Un outil de diagnostic ne casse pas ce qu'il observe** : toute defaillance
 * d'ecriture le rend inerte au lieu de faire rougir un passage.
 *
 * ## Un fichier par processus
 *
 * Un fichier partage entre seize processus melangerait les ordres et exigerait un verrou a chaque
 * ligne. Un fichier par jeton donne la repartition **et** l'ordre, sans concurrence.
 */
final class DistributionJournal implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $dossier = getenv('OGAMEX_TEST_JOURNAL');

        if (!is_string($dossier) || $dossier === '') {
            return;
        }

        if (!is_dir($dossier) && !@mkdir($dossier, 0777, true) && !is_dir($dossier)) {
            return;
        }

        $jeton = getenv('TEST_TOKEN');
        $jeton = is_string($jeton) && $jeton !== '' ? $jeton : '1';

        $fichier = @fopen($dossier . DIRECTORY_SEPARATOR . 'processus-' . $jeton . '.txt', 'a');

        if ($fichier === false) {
            return;
        }

        $facade->registerSubscriber(new class ($fichier) implements PreparationStartedSubscriber {
            private int $rang = 0;

            private float $depart;

            /**
             * @param resource $fichier
             */
            public function __construct(private $fichier)
            {
                $this->depart = microtime(true);
            }

            public function notify(PreparationStarted $event): void
            {
                $this->rang++;

                // L'identifiant du test porte la classe, la methode et le jeu de donnees : c'est ce
                // qu'il faut pour rejouer, et c'est la seule forme stable entre versions de PHPUnit.
                @fwrite(
                    $this->fichier,
                    $this->rang . "\t"
                    . (int)round((microtime(true) - $this->depart) * 1000) . "\t"
                    . $event->test()->id() . "\n"
                );
            }
        });
    }
}
