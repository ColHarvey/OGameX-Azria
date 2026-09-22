<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use OGame\Enums\AllianceClass;
use OGame\Models\Alliance;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\AccountTestCase;

/**
 * **La recherche d alliances dit la classe de chaque resultat, et son embleme existe.**
 *
 * Le resultat affiche un embleme de 20 px devant le nom par la regle `.alliance_class.small.<classe>` ; la classe vient
 * du serveur (`warrior`, `trader`, `explorer`, ou `none`). Les quatre regles visaient des images qui n ont jamais ete
 * dans le depot : chaque recherche et chaque rapport d espionnage demandait une 404. Le second essai epingle que
 * chaque image que ces regles nomment est bien servie.
 */
class AllianceSearchTest extends AccountTestCase
{
    private function uneAlliance(string $tag, AllianceClass|null $classe): Alliance
    {
        $fondateur = User::factory()->create(['username' => 'fond_' . Str::random(10)]);
        $alliance = resolve(AllianceService::class)->createAlliance($fondateur->id, $tag, 'Recherche ' . Str::random(8));

        if ($classe !== null) {
            $alliance->forceFill(['alliance_class' => $classe->name])->save();
        }

        return $alliance->fresh() ?? $alliance;
    }

    /**
     * @return array<int, array<string, mixed>> les resultats, indexes par identifiant d alliance
     */
    private function resultats(string $texte): array
    {
        $reponse = $this->post('/ajax/search', ['searchtext' => $texte, 'category' => 4, '_token' => csrf_token()]);
        $reponse->assertStatus(200)->assertJsonPath('status', 'success')->assertJsonPath('category', 4);

        $parId = [];
        foreach ($reponse->json('results') as $resultat) {
            $parId[(int)$resultat['id']] = $resultat;
        }

        return $parId;
    }

    /**
     * **Une alliance avec classe rend son nom machine ; une alliance sans classe rend `none`.**
     */
    public function testEachResultCarriesItsClassMachineName(): void
    {
        $prefixe = 'Q' . strtoupper(Str::random(4));
        $guerriere = $this->uneAlliance($prefixe . 'W', AllianceClass::WARRIORS);
        $sansClasse = $this->uneAlliance($prefixe . 'N', null);

        $resultats = $this->resultats($prefixe);

        $this->assertCount(2, $resultats, 'Les deux alliances du prefixe, et elles seules.');
        $this->assertSame('warrior', $resultats[$guerriere->id]['class']);
        $this->assertSame('none', $resultats[$sansClasse->id]['class']);
        $this->assertSame($guerriere->alliance_tag, $resultats[$guerriere->id]['tag']);
        $this->assertSame($guerriere->alliance_name, $resultats[$guerriere->id]['name']);
        $this->assertArrayHasKey('is_open', $resultats[$guerriere->id]);
    }

    /**
     * **Chaque embleme de 20 px que la feuille nomme est un fichier servi.**
     *
     * Les regles `.alliance_class.small.<classe>:before` sont lues dans la source de la feuille ; l image de chacune
     * doit exister sous `public/`. Un chemin vers une image absente — le defaut d origine — fait tomber cet essai.
     */
    public function testTheSmallAllianceEmblemsPointToImagesThatExist(): void
    {
        $feuille = (string)file_get_contents(base_path('resources/css/ingame/02base.css'));
        $nombre = preg_match_all('/\.alliance_class\.small\.(none|explorer|trader|warrior):before\s*\{[^}]*url\("([^"]+)"\)/', $feuille, $regles, PREG_SET_ORDER);

        $this->assertSame(4, $nombre, 'Les quatre classes portent une regle d embleme.');

        foreach ($regles as $regle) {
            $this->assertFileExists(public_path(ltrim($regle[2], '/')), 'Embleme absent pour la classe ' . $regle[1] . ' : ' . $regle[2]);
        }
    }
}
