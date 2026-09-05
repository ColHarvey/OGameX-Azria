<?php

namespace Tests\Feature;

use Tests\AccountTestCase;

/**
 * Ce que le navigateur recoit pour parler a Reverb vient de la configuration, jamais de env().
 *
 * ## Pourquoi c'est un contrat, et pas un detail
 *
 * En production, l'entrypoint du conteneur execute `config:cache`. Des lors, Laravel ne charge
 * plus `.env`, et `env()` ne rend que l'environnement du processus — que le fichier Compose ne
 * remplit pas. Un layout qui lisait les cles Reverb par `env()` livrait donc au navigateur une
 * cle vide : Echo ne demarrait pas, et le chat comme le combat vivaient sans canal direct, sans
 * que rien ne le dise.
 *
 * Deux adresses, pas une : le PHP parle au serveur Reverb par `options` (le conteneur voisin, en
 * HTTP), le navigateur par `client` (l'hote public, en TLS). Le navigateur ne doit jamais recevoir
 * l'adresse interne, ni le secret.
 */
final class ReverbClientConfigurationTest extends AccountTestCase
{
    private const array VARIABLES = ['REVERB_APP_KEY', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME', 'REVERB_CLIENT_HOST', 'REVERB_CLIENT_PORT', 'REVERB_CLIENT_SCHEME'];

    protected function tearDown(): void
    {
        $this->forgetEnvironment();
        parent::tearDown();
    }

    public function testTheLayoutHandsTheBrowserTheClientAddressFromTheConfiguration(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'cle-du-banc',
            'broadcasting.connections.reverb.secret' => 'secret-du-banc',
            'broadcasting.connections.reverb.options.host' => 'ogamex-reverb',
            'broadcasting.connections.reverb.options.port' => 8090,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.client.host' => 'jeu.exemple.ca',
            'broadcasting.connections.reverb.client.port' => 443,
            'broadcasting.connections.reverb.client.scheme' => 'https',
        ]);
        $this->createAndLoginUser();

        $page = (string)$this->get('/overview')->assertStatus(200)->getContent();

        $this->assertStringContainsString('var reverbAppKey = "cle-du-banc";', $page);
        $this->assertStringContainsString('var reverbHost = "jeu.exemple.ca";', $page);
        $this->assertStringContainsString('var reverbPort = "443";', $page);
        $this->assertStringContainsString('var reverbScheme = "https";', $page);
        $this->assertStringNotContainsString('ogamex-reverb', $page, 'The browser received the address the PHP uses, not its own.');
        $this->assertStringNotContainsString('secret-du-banc', $page, 'The secret reached the browser.');
    }

    /**
     * Sous config:cache, l'environnement du processus est tout ce que env() voit ; le layout ne
     * doit rien lui demander, sinon une cle posee la — ou absente — passerait devant la configuration.
     */
    public function testTheLayoutIgnoresTheProcessEnvironment(): void
    {
        $this->setEnvironment([
            'REVERB_APP_KEY' => 'cle-de-l-environnement',
            'REVERB_HOST' => 'hote-de-l-environnement',
            'REVERB_CLIENT_HOST' => 'client-de-l-environnement',
        ]);
        config([
            'broadcasting.connections.reverb.key' => 'cle-de-la-configuration',
            'broadcasting.connections.reverb.client.host' => 'client-de-la-configuration',
            'broadcasting.connections.reverb.client.port' => 443,
            'broadcasting.connections.reverb.client.scheme' => 'https',
        ]);
        $this->createAndLoginUser();

        $page = (string)$this->get('/overview')->assertStatus(200)->getContent();

        $this->assertStringContainsString('var reverbAppKey = "cle-de-la-configuration";', $page);
        $this->assertStringContainsString('var reverbHost = "client-de-la-configuration";', $page);
        $this->assertStringNotContainsString('de-l-environnement', $page, 'The layout still reads env().');
    }

    /**
     * Le fichier de configuration lui-meme : sans variable `REVERB_CLIENT_*`, le navigateur recoit
     * l'adresse du PHP (un poste de developpement) ; avec, il recoit la sienne.
     */
    public function testTheClientAddressFallsBackOnTheServerAddressUnlessGivenItsOwn(): void
    {
        $this->setEnvironment(['REVERB_HOST' => 'ogamex-reverb', 'REVERB_PORT' => '8090', 'REVERB_SCHEME' => 'http']);
        $this->assertSame(
            ['host' => 'ogamex-reverb', 'port' => '8090', 'scheme' => 'http'],
            $this->clientBlockOfTheConfigurationFile(),
            'Without a client address of its own, the browser should receive the server address.'
        );

        $this->setEnvironment(['REVERB_CLIENT_HOST' => 'jeu.exemple.ca', 'REVERB_CLIENT_PORT' => '443', 'REVERB_CLIENT_SCHEME' => 'https']);
        $this->assertSame(
            ['host' => 'jeu.exemple.ca', 'port' => '443', 'scheme' => 'https'],
            $this->clientBlockOfTheConfigurationFile(),
            'The client address did not take precedence over the server address.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function clientBlockOfTheConfigurationFile(): array
    {
        $configuration = require base_path('config/broadcasting.php');
        $this->assertIsArray($configuration);
        $client = $configuration['connections']['reverb']['client'] ?? null;
        $this->assertIsArray($client, 'config/broadcasting.php has no client block under the reverb connection.');

        return $client;
    }

    /**
     * @param array<string, string> $variables
     */
    private function setEnvironment(array $variables): void
    {
        foreach ($variables as $nom => $valeur) {
            putenv($nom . '=' . $valeur);
            $_ENV[$nom] = $valeur;
            $_SERVER[$nom] = $valeur;
        }
    }

    private function forgetEnvironment(): void
    {
        foreach (self::VARIABLES as $nom) {
            putenv($nom);
            unset($_ENV[$nom], $_SERVER[$nom]);
        }
    }
}
