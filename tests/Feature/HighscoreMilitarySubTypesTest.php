<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Models\Highscore;
use OGame\Services\HighscoreService;
use Tests\AccountTestCase;

/**
 * **Les sous-boutons du classement militaire ne font plus tourner la page dans le vide, et aucun ne rend un autre
 * classement sous son nom.**
 *
 * ## Le defaut, tel que Keven l a vu
 *
 * Cliquer « Militaire » fait apparaitre quatre sous-boutons ; cliquer l un d eux laissait un rond tourner
 * indefiniment. La vue demandait les types 4 a 7, le classement n en connaissait que quatre (0 a 3), et
 * `HighscoreTypeEnum::cases()[$type]` levait une erreur de clef absente : la requete tombait, et le
 * JavaScript n avait rien pour s arreter.
 *
 * ## Ce qui est desormais vrai
 *
 * - **Les points d honneur se classent pour de bon** (`HighscoreHonourRankingTest`).
 * - **Construits, detruits, perdus ne sont servis qu une fois la collecte activee** (`MilitaryTalliesRankingTest`). Avant, leurs boutons sont
 *   **desactives** et disent « Statistiques non encore disponibles ». Une premiere version leur faisait rendre le
 *   classement militaire ; Keven l a refuse le 13 septembre 2026 — ce sont des donnees differentes, et les montrer
 *   sous leur nom tromperait le joueur. Le service refuse un type inconnu ; la page repond par le message.
 *
 * ## Pourquoi des valeurs toutes differentes, et pourquoi on lit la ligne
 *
 * La ligne du joueur porte 0 en general, 4 242 en honneur et 7 777 en militaire : lire 4 242, c est savoir **quel**
 * classement a ete rendu. On lit la cellule de score de la ligne du joueur, jamais la page : la base d un processus
 * garde les classements des essais voisins, et l une de ces lignes affichait justement 4 242 en militaire.
 */
class HighscoreMilitarySubTypesTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ces essais supposent la collecte des cumuls **non activee** : ils l etablissent, une classe voisine du meme
        // processus ayant pu la laisser activee.
        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
    }

    /**
     * **Le classement par points d honneur rend l honneur**, et pas un autre score.
     */
    public function testTheHonourRankingShowsTheHonourScore(): void
    {
        $this->uneLigneDeClassement();

        $this->assertSame('4,242', $this->scoreAffiche(['category' => 1, 'type' => 4]));
    }

    /**
     * **Un sous-classement pas encore compte ne rend aucun classement**, ni pour les joueurs ni pour les alliances :
     * le message, et pas une ligne.
     *
     * Les trois valeurs sont celles que les boutons envoyaient : construits, detruits, perdus.
     */
    public function testTheUncountedMilitaryStatisticsShowNoRankingAtAll(): void
    {
        $this->uneLigneDeClassement();

        foreach ([1, 2] as $categorie) {
            foreach ([5, 6, 8] as $type) {
                $this->assertIndisponible($this->post('/ajax/highscore', ['category' => $categorie, 'type' => $type]), "Categorie $categorie, type $type.");
            }
        }
    }

    /**
     * **Une valeur que personne n envoie ne rend pas davantage de classement.**
     */
    public function testATypeNobodyOffersShowsNoRankingEither(): void
    {
        $this->uneLigneDeClassement();

        $this->assertIndisponible($this->post('/ajax/highscore', ['category' => 1, 'type' => 99]), 'Type 99.');
    }

    /**
     * **La page du classement ouverte sur un tel type s ouvre quand meme**, le message a la place du tableau.
     */
    public function testTheHighscorePageOpenedOnAnUncountedTypeStillOpens(): void
    {
        $this->uneLigneDeClassement();

        $this->assertIndisponible($this->get('/highscore?type=5'), 'Page complete, type 5.');
    }

    /**
     * **Les trois boutons sont desactives et disent pourquoi ; celui de l honneur reste un lien.**
     *
     * Le script du classement n ecoute que `a.subnavButton` et envoie la valeur de `rel` comme type : un bouton qui
     * n est pas un lien et ne porte aucun `rel` n envoie rien. Il garde ses classes, donc son icone.
     */
    public function testTheUncountedButtonsAreDisabledAndSayWhy(): void
    {
        $page = (string)$this->get('/highscore')->assertStatus(200)->getContent();
        $message = __('t_ingame.highscore.statistics_not_yet_available');

        preg_match_all('#<(\w+)\b([^>]*)\bclass="subnavButton subnavButton_(\w+)\b[^"]*"([^>]*)>#', $page, $boutons, PREG_SET_ORDER);

        $parNom = [];

        foreach ($boutons as $bouton) {
            $parNom[$bouton[3]] = ['balise' => $bouton[1], 'attributs' => $bouton[2] . ' ' . $bouton[4]];
        }

        $this->assertSame(['built', 'destroyed', 'lost', 'honor'], array_keys($parNom));

        $this->assertSame('a', $parNom['honor']['balise'], 'Le bouton d honneur n est plus un lien.');
        $this->assertStringContainsString('rel="' . HighscoreTypeEnum::honor->value . '"', $parNom['honor']['attributs'], 'Le bouton d honneur n envoie pas la valeur de l honneur.');

        foreach (['built', 'destroyed', 'lost'] as $nom) {
            $this->assertSame('span', $parNom[$nom]['balise'], "Le bouton $nom est un lien : le script du classement l ecouterait et enverrait une demande.");
            $this->assertStringNotContainsString('rel=', $parNom[$nom]['attributs'], "Le bouton $nom porte encore un type a envoyer.");
            $this->assertStringContainsString('aria-disabled="true"', $parNom[$nom]['attributs'], "Le bouton $nom ne se dit pas desactive.");
            $this->assertStringContainsString($message, $parNom[$nom]['attributs'], "Le bouton $nom ne dit pas pourquoi il est desactive.");
        }
    }

    /**
     * **Le service refuse un type inconnu** au lieu d en choisir un autre en silence.
     */
    public function testTheServiceRefusesAnUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        resolve(HighscoreService::class)->setHighscoreType(99);
    }

    /**
     * La ligne de classement du joueur courant, telle que la tache planifiee l ecrirait.
     */
    private function uneLigneDeClassement(): void
    {
        $ligne = Highscore::query()->firstOrNew(['player_id' => $this->currentUserId]);

        $ligne->general = 0;
        $ligne->general_rank = 1;
        $ligne->economy = 0;
        $ligne->economy_rank = 1;
        $ligne->research = 0;
        $ligne->research_rank = 1;
        $ligne->military = 7_777;
        $ligne->military_rank = 1;
        $ligne->honor = 4_242;
        $ligne->honor_rank = 1;
        $ligne->save();
    }

    /**
     * Le score qu affiche la ligne du joueur courant.
     *
     * @param array<string, int> $demande
     */
    private function scoreAffiche(array $demande): string
    {
        $page = (string)$this->post('/ajax/highscore', $demande)->assertStatus(200)->getContent();

        $debut = strpos($page, 'id="position' . $this->currentUserId . '"');
        $this->assertNotFalse($debut, 'The player has no row in this ranking.');

        $fin = strpos($page, '</tr>', $debut);
        $ligne = substr($page, $debut, $fin === false ? null : $fin - $debut);

        if (preg_match('#<td class="score">(.*?)</td>#s', $ligne, $cellule) !== 1) {
            $this->fail('The player row has no score cell.');
        }

        return trim(strip_tags($cellule[1]));
    }

    /**
     * Une reponse qui dit « Statistiques non encore disponibles » et ne montre aucune ligne de classement.
     *
     * On cherche l identifiant des lignes (`id="position…"`), pas un score : un nombre peut apparaitre ailleurs sur
     * la page, une ligne de classement non.
     */
    private function assertIndisponible(TestResponse $reponse, string $cas): void
    {
        $page = (string)$reponse->assertStatus(200)->getContent();

        $this->assertStringContainsString(__('t_ingame.highscore.statistics_not_yet_available'), $page, $cas . ' Le message manque.');
        $this->assertStringNotContainsString('id="position', $page, $cas . ' Une ligne de classement est rendue sous un type qui n est pas compte.');
    }
}
