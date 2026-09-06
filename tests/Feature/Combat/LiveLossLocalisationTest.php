<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\App;
use OGame\Combat\Presentation\PresentedLoss;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;

/**
 * Le nom d'unite d'une perte vue en direct est celui de la langue du lecteur.
 *
 * ## Le defaut que ces essais ferment
 *
 * `unit_label` est resolu par `__()` au moment ou l'objet du jeu est construit. Dans une requete
 * c'est la langue du lecteur ; dans le diffuseur — qui tourne en boucle, hors de toute requete —
 * c'est celle de l'application. Un joueur francais recevait donc « Light Fighter » en direct, puis
 * « Chasseur léger » au rechargement : deux noms pour un meme fait.
 *
 * La correction ne traduit pas cote serveur ce que le serveur ne peut pas savoir. L'identifiant
 * voyage (`unit`), la **page** publie la table des noms dans sa propre langue, et le navigateur y
 * lit le sien. Ces essais tiennent donc les trois bouts de cette table : qu'elle suive la langue de
 * la requete, qu'elle couvre exactement ce qu'une perte peut nommer, et qu'elle parvienne
 * reellement au navigateur.
 */
class LiveLossLocalisationTest extends AccountTestCase
{
    /**
     * La table des noms suit la langue de la requete, et les deux langues different vraiment.
     *
     * Le second point n'est pas une precaution : si la table etait construite dans une langue fixe,
     * les deux lectures coincideraient et l'essai passerait sans rien prouver.
     */
    public function testTheUnitNameTableFollowsTheReaderLanguage(): void
    {
        $originale = App::getLocale();

        try {
            App::setLocale('fr');
            $francais = PresentedLoss::unitLabels();
            $titreFrancais = __('t_resources.light_fighter.title');

            App::setLocale('en');
            $anglais = PresentedLoss::unitLabels();
            $titreAnglais = __('t_resources.light_fighter.title');
        } finally {
            App::setLocale($originale);
        }

        $this->assertArrayHasKey('light_fighter', $francais);
        $this->assertArrayHasKey('light_fighter', $anglais);

        $this->assertNotSame(
            $anglais['light_fighter'],
            $francais['light_fighter'],
            'Both locales produced the same unit name: the table would prove nothing about the reader language.'
        );

        // La table dit exactement ce que la page dirait au meme instant, dans la meme langue.
        $this->assertSame($titreFrancais, $francais['light_fighter']);
        $this->assertSame($titreAnglais, $anglais['light_fighter']);
    }

    /**
     * La table couvre tout ce qu'une perte peut nommer.
     *
     * Une perte porte le nom machine d'un vaisseau ou d'une defense. Une unite absente de la table
     * retomberait sur le libelle transporte — donc sur la langue du travailleur, c'est-a-dire sur
     * le defaut qu'on ferme. La couverture se verifie donc unite par unite, pas par un comptage.
     */
    public function testEveryUnitThatCanBeLostHasAName(): void
    {
        $table = PresentedLoss::unitLabels();

        foreach (ObjectService::getUnitObjects() as $objet) {
            $this->assertArrayHasKey(
                $objet->machine_name,
                $table,
                'The unit ' . $objet->machine_name . ' can be lost but carries no name for the live feed.'
            );

            $this->assertSame(
                PresentedLoss::unitLabel($objet->machine_name),
                $table[$objet->machine_name],
                'The table and the server-rendered label disagree on ' . $objet->machine_name . '.'
            );
        }
    }

    /**
     * La page la publie vraiment : sans cela le navigateur retombe sur la langue du travailleur.
     *
     * L'essai lit le nom **dans la reponse**, pas seulement la presence de la clef : une table
     * publiee vide passerait le premier controle et ne traduirait rien.
     */
    public function testThePageCarriesTheTableToTheBrowser(): void
    {
        $reponse = $this->get('/overview');

        $reponse->assertStatus(200);
        $reponse->assertSee('COMBAT_UNIT_LABELS', false);
        // Encode comme la page l'encode : `json_encode` echappe les accents par defaut, et comparer
        // au texte brut ne trouverait rien.
        $reponse->assertSee((string)json_encode(PresentedLoss::unitLabel('light_fighter')), false);
    }
}
