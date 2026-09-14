<?php

namespace Tests\Feature;

use OGame\Enums\HighscoreTypeEnum;
use OGame\Models\Highscore;
use Tests\AccountTestCase;

/**
 * **Les sous-boutons du classement militaire ne font plus tourner la page dans le vide.**
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
 * - **Les points d honneur se classent pour de bon** : la donnee existait sur le compte, elle a maintenant sa
 *   colonne et son rang dans la photographie du classement, joueurs et alliances (`HighscoreHonourRankingTest`).
 * - **Les trois autres ne sont pas encore comptes** — vaisseaux construits, detruits, perdus. Rien ne les
 *   cumule. En attendant, ils rendent le classement militaire, leur categorie parente.
 *
 * ## Pourquoi des valeurs toutes differentes, et pourquoi on lit la ligne
 *
 * La ligne du joueur porte 0 en general, 4 242 en honneur et 7 777 en militaire. Lire 7 777 et pas 4 242, c est
 * savoir **quel** classement a ete rendu ; des valeurs egales feraient coincider le juste et le faux. Les nombres
 * s ecrivent avec une virgule pour les milliers (`AppUtil::formatNumber`).
 *
 * **On lit la cellule de score de la ligne du joueur, jamais la page.** La premiere version cherchait le nombre
 * dans la page entiere : la base d un processus garde les classements des essais voisins, et l une de ces lignes
 * affichait justement 4 242 en militaire. L essai tombait sans que le code soit en faute — c est une mutation
 * sans rapport qui l a montre.
 */
class HighscoreMilitarySubTypesTest extends AccountTestCase
{
    /**
     * **Le classement par points d honneur rend l honneur**, et pas un autre score.
     */
    public function testTheHonourRankingShowsTheHonourScore(): void
    {
        $this->uneLigneDeClassement();

        $this->assertSame('4,242', $this->scoreAffiche(['category' => 1, 'type' => 4]));
    }

    /**
     * **Un sous-classement pas encore compte rend le classement militaire**, au lieu de faire tomber la page.
     *
     * Les trois valeurs sont celles que les boutons envoient : construits, detruits, perdus.
     */
    public function testTheUncountedMilitarySubTypesShowTheMilitaryRanking(): void
    {
        $this->uneLigneDeClassement();

        foreach ([5, 6, 8] as $type) {
            $this->assertSame('7,777', $this->scoreAffiche(['category' => 1, 'type' => $type]), "Type $type.");
        }
    }

    /**
     * **Une valeur que personne n envoie ne casse rien non plus**, et rend le meme repli.
     */
    public function testATypeNobodyOffersFallsBackToTheMilitaryRanking(): void
    {
        $this->uneLigneDeClassement();

        $this->assertSame('7,777', $this->scoreAffiche(['category' => 1, 'type' => 99]));
    }

    /**
     * **Chaque sous-bouton envoie un type que le serveur sait rendre.**
     *
     * Le JavaScript envoie la valeur de `rel` telle quelle comme type. Le bouton d honneur doit donc porter la valeur
     * de l honneur, et les trois sous-classements pas encore comptes des valeurs qu aucun classement ne revendique :
     * « perdus » portait 4, et rendrait aujourd hui l honneur. Les quatre valeurs restent distinctes, la page s en
     * servant pour savoir quel bouton est actif.
     */
    public function testTheSubButtonsSendTheTypesTheServerKnows(): void
    {
        $page = (string)$this->get('/highscore')->assertStatus(200)->getContent();

        preg_match_all('#<a\b[^>]*\brel="(\d+)"[^>]*\bclass="subnavButton subnavButton_(\w+)\b#', $page, $boutons, PREG_SET_ORDER);

        $types = [];
        foreach ($boutons as $bouton) {
            $types[$bouton[2]] = (int)$bouton[1];
        }

        $this->assertSame(['built', 'destroyed', 'lost', 'honor'], array_keys($types));
        $this->assertSame(HighscoreTypeEnum::honor->value, $types['honor']);

        foreach (['built', 'destroyed', 'lost'] as $nom) {
            $this->assertNull(HighscoreTypeEnum::tryFrom($types[$nom]), "The $nom button sends a type that another ranking already answers.");
        }

        $this->assertCount(4, array_unique($types));
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
}
