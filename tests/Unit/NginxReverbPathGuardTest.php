<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Garde de source : nginx porte le websocket du navigateur — et lui seul — jusqu'au conteneur
 * Reverb, derriere le meme hote public. L'API de publication (/apps/{id}/events) reste interne.
 *
 * Le fichier `nginx/conf.d/app.conf` est monte tel quel dans le conteneur de production. Sans cet
 * emplacement, un websocket vers `/app/{cle}` finit dans `location /`, donc dans Laravel, en 404 :
 * le canal direct n'existe pas, et rien ne le dit. Cet essai ne lance pas nginx ; il empeche que
 * l'emplacement disparaisse ou perde une des directives sans lesquelles l'amelioration de
 * connexion n'arrive pas a l'amont.
 */
final class NginxReverbPathGuardTest extends TestCase
{
    public function testTheWebServerCarriesReverbBehindThePublicHost(): void
    {
        $chemin = dirname(__DIR__, 2) . '/nginx/conf.d/app.conf';
        $this->assertFileExists($chemin);
        $conf = str_replace("\r\n", "\n", (string)file_get_contents($chemin));

        $this->assertMatchesRegularExpression(
            '/^map \$http_upgrade \$connection_upgrade \{\n\s+default upgrade;\n\s+\'\'\s+close;\n\}/m',
            $conf,
            'The Connection header is not derived from the Upgrade header: a websocket would not be upgraded.'
        );

        $this->assertSame(1, preg_match('/^    location ~ \^\/app\/ \{\n(.*?)\n    \}/ms', $conf, $bloc), 'No location carries the /app websocket to Reverb.');
        $corps = $bloc[1] ?? '';
        $this->assertNotSame('', $corps, 'The Reverb location is empty.');

        foreach ([
            'resolver 127.0.0.11',
            'set $reverb http://ogamex-reverb:8090;',
            'proxy_http_version 1.1;',
            'proxy_set_header Host $http_host;',
            'proxy_set_header Upgrade $http_upgrade;',
            'proxy_set_header Connection $connection_upgrade;',
            'proxy_pass $reverb;',
        ] as $directive) {
            $this->assertStringContainsString($directive, $corps, "The Reverb location lacks: {$directive}");
        }

        // **Le nom est resolu a la requete**, jamais au demarrage : un `proxy_pass http://ogamex-reverb`
        // ecrit en dur empeche nginx de demarrer tant que le conteneur Reverb n'existe pas — ce qui
        // est exactement l'ordre de la mise en production.
        $this->assertStringNotContainsString('proxy_pass http://ogamex-reverb', $corps, 'The upstream is resolved at startup: nginx would refuse to start without Reverb.');

        // **L'API de publication n'est pas exposee** : le navigateur n'en a pas besoin, et le PHP y parle
        // par le reseau interne. Un emplacement qui couvrirait /apps/ ouvrirait une porte inutile.
        $this->assertDoesNotMatchRegularExpression('/location[^{]*apps/', $conf, 'A location exposes the publishing API /apps/.');
    }
}
