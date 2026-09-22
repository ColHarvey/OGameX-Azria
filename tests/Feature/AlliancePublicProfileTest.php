<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Enums\AllianceClass;
use OGame\Models\Alliance;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\BbCodeParserService;
use Tests\AccountTestCase;

/**
 * **La fiche publique d une alliance : une route, un contenu, deux enveloppes, et rien d interne.**
 *
 * Avec `overlay=1` la reponse est le seul fragment ; sans, une page complete qui porte le meme fragment. Une
 * alliance inconnue rend une 404 avec un message propre dans les deux cas. Ce qui n existe pas se dit — « Non
 * classee », « Aucune classe » — et ne s invente pas. Le texte public passe par le parseur serveur, dont on
 * eprouve la sortie : son nom ne suffit pas a etablir sa securite.
 */
class AlliancePublicProfileTest extends AccountTestCase
{
    private function uneAlliance(array $attributs = []): Alliance
    {
        $fondateur = User::factory()->create(['username' => 'fond_' . Str::random(10)]);
        $alliance = resolve(AllianceService::class)->createAlliance(
            $fondateur->id,
            'P' . strtoupper(Str::random(5)),
            'Profil ' . Str::random(8),
        );

        if ($attributs !== []) {
            $alliance->forceFill($attributs)->save();
        }

        return $alliance->fresh() ?? $alliance;
    }

    private function fragment(int $allianceId, int $statut = 200): string
    {
        return (string)$this->get('/alliance/info/' . $allianceId . '?overlay=1')->assertStatus($statut)->getContent();
    }

    private function page(int $allianceId, int $statut = 200): string
    {
        return (string)$this->get('/alliance/info/' . $allianceId)->assertStatus($statut)->getContent();
    }

    /**
     * **Le fragment ne porte ni document ni assets ; la page porte les deux et le meme contenu.**
     */
    public function testTheFragmentAndThePageCarryTheSameContentInTwoEnvelopes(): void
    {
        $alliance = $this->uneAlliance();

        $fragment = $this->fragment($alliance->id);
        $page = $this->page($alliance->id);

        $this->assertStringContainsString('[' . $alliance->alliance_tag . ']', $fragment);
        $this->assertStringContainsString('class="azria-alliance-profile"', $fragment);
        $this->assertStringNotContainsString('<html', $fragment, 'Le fragment ne doit pas etre un document.');
        $this->assertStringNotContainsString('<script', $fragment, 'Le fragment ne doit charger aucun script : le bundle est deja la.');

        $this->assertStringContainsString('<html', $page);
        $this->assertStringContainsString('class="azria-alliance-profile"', $page);
        $this->assertStringContainsString('[' . $alliance->alliance_tag . ']', $page);
        $this->assertStringContainsString('/build/assets/', $page, 'La page autonome charge ses assets par Vite.');
    }

    /**
     * **Une alliance inconnue rend une 404 avec un message propre**, dans les deux enveloppes.
     */
    public function testAnUnknownAllianceIsAProper404InBothEnvelopes(): void
    {
        $inexistante = (int)(DB::table('alliances')->max('id') ?? 0) + 1000;

        $fragment = $this->fragment($inexistante, 404);
        $page = $this->page($inexistante, 404);

        $this->assertStringContainsString(__('t_ingame.alliance.profile_missing'), $fragment);
        $this->assertStringContainsString('role="alert"', $fragment);
        $this->assertStringContainsString(__('t_ingame.alliance.profile_missing'), $page);
        $this->assertStringContainsString('<html', $page);
    }

    /**
     * **Les trois classes s affichent avec leur embleme et leur nom, et l absence de classe se dit.**
     */
    public function testEachAllianceClassAndItsAbsenceAreRendered(): void
    {
        foreach (AllianceClass::cases() as $classe) {
            $alliance = $this->uneAlliance(['alliance_class' => $classe->name]);
            $fragment = $this->fragment($alliance->id);

            $this->assertStringContainsString('sprite allianceclass medium ' . $classe->getMachineName(), $fragment, $classe->name);
            $this->assertStringContainsString(e($classe->getName()), $fragment, $classe->name);
            $this->assertStringNotContainsString(__('t_ingame.alliance.class_none_selected'), $fragment, $classe->name);

            foreach ($classe->bonusLabels() as $bonus) {
                $this->assertStringContainsString(e($bonus), $fragment, $classe->name . ' : bonus manquant.');
            }
        }

        $sansClasse = $this->uneAlliance(['alliance_class' => null]);
        $fragment = $this->fragment($sansClasse->id);

        $this->assertStringContainsString('sprite allianceclass medium none', $fragment);
        $this->assertStringContainsString(__('t_ingame.alliance.class_none_selected'), $fragment);
    }

    /**
     * **Un logo valide est rendu ; un logo absent ou invalide cede la place au logo de remplacement**, jamais a
     * une adresse quelconque dans un `src`.
     */
    public function testTheLogoIsRenderedOnlyWhenItIsAValidHttpImage(): void
    {
        $valide = $this->uneAlliance(['logo_url' => 'https://exemple.test/embleme.png']);
        $this->assertStringContainsString('src="https://exemple.test/embleme.png"', $this->fragment($valide->id));

        foreach ([null, '', 'javascript:alert(1)', 'ftp://exemple.test/logo.png', 'https://exemple.test/pas-une-image', 'https://exemple.test/x.png?<script>'] as $invalide) {
            $alliance = $this->uneAlliance(['logo_url' => $invalide]);
            $fragment = $this->fragment($alliance->id);

            $this->assertStringContainsString('alliance-placeholder.svg', $fragment, var_export($invalide, true));
            $this->assertStringNotContainsString('javascript:', $fragment, var_export($invalide, true));
            $this->assertStringNotContainsString('ftp://', $fragment, var_export($invalide, true));
        }
    }

    /**
     * **Sans rang publie, la fiche dit « Non classee » — pas un zero.** Avec un rang, elle donne rang et points.
     */
    public function testAnUnrankedAllianceSaysSoAndARankedOneShowsRankAndPoints(): void
    {
        $sansRang = $this->uneAlliance();
        $fragment = $this->fragment($sansRang->id);

        $this->assertStringContainsString(__('t_ingame.alliance.profile_unranked'), $fragment);
        $this->assertStringNotContainsString('<dd>0</dd>', $fragment, 'Un rang absent ne doit pas devenir zero.');

        $classee = $this->uneAlliance();
        DB::table('alliance_highscores')->insert([
            'alliance_id' => $classee->id,
            'general' => 123_456,
            'economy' => 0,
            'research' => 0,
            'military' => 0,
            'general_rank' => 7,
            'economy_rank' => 7,
            'research_rank' => 7,
            'military_rank' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fragment = $this->fragment($classee->id);

        $this->assertStringContainsString('<dd>7</dd>', $fragment);
        $this->assertStringContainsString('123,456', $fragment);
        $this->assertStringNotContainsString(__('t_ingame.alliance.profile_unranked'), $fragment);
    }

    /**
     * **Rien de reserve aux membres ne sort** : ni le texte interne, ni le texte de candidature.
     */
    public function testNoInternalDataLeaksIntoThePublicProfile(): void
    {
        $alliance = $this->uneAlliance([
            'internal_text' => 'SECRET-INTERNE-' . Str::random(12),
            'application_text' => 'SECRET-CANDIDATURE-' . Str::random(12),
            'external_text' => 'Presentation publique visible',
        ]);

        foreach ([$this->fragment($alliance->id), $this->page($alliance->id)] as $sortie) {
            $this->assertStringContainsString('Presentation publique visible', $sortie);
            $this->assertStringNotContainsString('SECRET-INTERNE', $sortie);
            $this->assertStringNotContainsString('SECRET-CANDIDATURE', $sortie);
        }
    }

    /**
     * **Le parseur BBCode est eprouve sur sa sortie** : le HTML est echappe, les balises permises rendues, et un
     * lien vers autre chose que http ou https perd son lien en gardant son texte.
     */
    public function testTheBbCodeOutputIsEscapedAndLinksAreRestrictedToHttp(): void
    {
        $alliance = $this->uneAlliance([
            'external_text' => "<script>alert(1)</script>[b]gras[/b] [url=javascript:alert(1)]piege[/url] [url=https://exemple.test/page]sain[/url] [url]data:text/html,x[/url]",
        ]);

        $fragment = $this->fragment($alliance->id);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $fragment);
        $this->assertStringNotContainsString('<script>', $fragment);
        $this->assertStringContainsString('<strong style="font-weight:bold">gras</strong>', $fragment);
        $this->assertStringContainsString('<a href="https://exemple.test/page" target="_blank" rel="noopener noreferrer"', $fragment);
        $this->assertStringContainsString('piege', $fragment);
        $this->assertStringNotContainsString('javascript:', $fragment, 'Un schema javascript ne doit jamais devenir un href.');
        $this->assertStringNotContainsString('href="data:', $fragment, 'Un schema data ne doit jamais devenir un href.');

        // Et le parseur lui-meme, hors de toute vue.
        $parseur = resolve(BbCodeParserService::class);
        $this->assertSame('x', $parseur->parse('[url=javascript:alert(1)]x[/url]'));
        $this->assertSame('x', $parseur->parse("[url=JAVASCRIPT:alert(1)]x[/url]"));
        $this->assertStringContainsString('href="https://a.b/c"', $parseur->parse('[url=https://a.b/c]x[/url]'));
    }

    /**
     * **La page d accueil n est rendue que si elle est une adresse http valide.**
     */
    public function testTheHomepageIsRenderedOnlyWhenValid(): void
    {
        $valide = $this->uneAlliance(['homepage_url' => 'https://exemple.test/']);
        $this->assertStringContainsString('href="https://exemple.test/" target="_blank" rel="noopener noreferrer"', $this->fragment($valide->id));

        $invalide = $this->uneAlliance(['homepage_url' => 'javascript:alert(1)']);
        $fragment = $this->fragment($invalide->id);
        $this->assertStringNotContainsString('javascript:', $fragment);
        $this->assertStringNotContainsString('ap-homepage', $fragment);
    }

    /**
     * **Le bouton de candidature ne s offre que si elle peut aboutir**, et le serveur reverifie au POST : une
     * alliance fermee refuse, sans annoncer de succes.
     */
    public function testTheApplyButtonFollowsTheServerRulesAndARefusalIsAProperRefusal(): void
    {
        $ouverte = $this->uneAlliance(['is_open' => true]);
        $this->assertStringContainsString('js_allianceApply', $this->fragment($ouverte->id));

        $fermee = $this->uneAlliance(['is_open' => false]);
        $this->assertStringNotContainsString('js_allianceApply', $this->fragment($fermee->id));

        // Le refus au POST, tel que le module le recoit : 400, JSON, `success` faux, un message.
        $reponse = $this->postJson('/alliance/apply', ['alliance_id' => $fermee->id, 'message' => '']);
        $reponse->assertStatus(400);
        $reponse->assertJson(['success' => false]);
        $this->assertNotSame('', (string)$reponse->json('message'));

        // Et un joueur deja en alliance ne voit pas le bouton d une alliance ouverte.
        $mienne = $this->uneAlliance();
        DB::table('users')->where('id', $this->currentUserId)->update(['alliance_id' => $mienne->id]);
        $this->assertStringNotContainsString('js_allianceApply', $this->fragment($ouverte->id));
        DB::table('users')->where('id', $this->currentUserId)->update(['alliance_id' => null]);
    }

    /**
     * **Les liens de consultation publique portent l adresse reelle et le marqueur du module, sans les marqueurs
     * du gestionnaire historique** — sinon un clic simple ouvrirait deux fenetres.
     */
    public function testThePublicLinksCarryTheRealUrlAndOnlyTheModuleMarker(): void
    {
        $alliance = $this->uneAlliance();
        DB::table('alliance_highscores')->insert([
            'alliance_id' => $alliance->id, 'general' => 10, 'economy' => 0, 'research' => 0, 'military' => 0,
            'general_rank' => 1, 'economy_rank' => 1, 'research_rank' => 1, 'military_rank' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $classement = (string)$this->post('/ajax/highscore', ['category' => 2, 'type' => 0])->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression(
            '#<a href="[^"]*/alliance/info/' . $alliance->id . '" class="txt_link" data-alliance-profile="' . $alliance->id . '" data-alliance-tag="' . preg_quote($alliance->alliance_tag, '#') . '">#',
            $classement,
        );
        $this->assertStringNotContainsString('overlay=1', $classement, 'Le marqueur du gestionnaire historique ferait une seconde ouverture.');
        $this->assertStringNotContainsString('target="_blank" class="txt_link">[', $classement, 'L ancien lien en nouvel onglet ne doit plus exister.');
    }
}
