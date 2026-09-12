<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use OGame\Enums\AllianceClass;
use OGame\Models\Alliance;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;
use stdClass;
use Tests\AccountTestCase;

/**
 * L ordre dans lequel les chemins d alliance atteignent les deux lignes qu ils partagent.
 *
 * ## La regle
 *
 * **L alliance, puis les comptes.** Le garde d adhesion la suit, le choix de classe aussi. La
 * dissolution faisait l inverse — elle ecrivait chaque compte membre puis supprimait l alliance — et
 * un `UPDATE` pose un verrou exclusif sous InnoDB : un choix de classe tenant l alliance en attendant
 * le compte du fondateur, et une dissolution tenant ce compte en attendant l alliance,
 * s interbloquaient (Codex, relecture de 16bdbb09).
 *
 * ## Ce que cet essai prouve, et ce qu il ne prouve pas
 *
 * Sous SQLite, `lockForUpdate()` ne compile a rien : aucun verrou ne s observe. Ce qui s observe,
 * c est **l ordre des requetes dans la transaction** — et c est lui qui fixe l ordre des verrous sous
 * MariaDB. La course de deux processus reels, elle, appartient au bac MariaDB et n est pas jouee ici.
 *
 * **Seules comptent les requetes faites dans la transaction du chemin eprouve.** Les lectures du
 * controle de droits, faites avant elle, touchent la table des alliances sans rien verrouiller : les
 * compter rendrait le temoin vert sur le code fautif, par une lecture qui ne tient aucun verrou.
 */
class AllianceLockOrderTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');

        parent::tearDown();
    }

    /**
     * **Choisir une classe atteint l alliance avant le compte.**
     */
    public function testChoosingAClassReachesTheAllianceBeforeTheAccount(): void
    {
        $alliance = resolve(AllianceService::class)->createAlliance($this->currentUserId, $this->unTag(), $this->unNom());
        DB::table('users')->where('id', $this->currentUserId)->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);
        $joueur = User::query()->findOrFail($this->currentUserId);

        $releve = $this->releverLesRequetesDansLaTransaction(function () use ($joueur, $alliance): void {
            resolve(AllianceClassService::class)->choose($joueur, $alliance, AllianceClass::WARRIORS);
        });

        $alliances = $this->premiere($releve, static fn (string $sql): bool => str_contains($sql, '"alliances"'));
        $comptes = $this->premiere($releve, static fn (string $sql): bool => str_contains($sql, '"users"'));

        $this->assertNotNull($alliances, 'Le choix de classe n a pas lu l alliance dans sa transaction.');
        $this->assertNotNull($comptes, 'Le choix de classe n a pas lu le compte dans sa transaction : le temoin ne prouverait rien.');
        $this->assertLessThan($comptes, $alliances, 'Le choix de classe atteint le compte avant l alliance : ordre inverse du depot.');
    }

    /**
     * **Dissoudre atteint l alliance avant d ecrire le moindre compte.**
     *
     * Avant la correction, la premiere requete de la transaction a toucher l alliance etait sa
     * suppression, apres l ecriture de chaque compte membre.
     */
    public function testDisbandingReachesTheAllianceBeforeWritingAnyAccount(): void
    {
        $service = resolve(AllianceService::class);
        $alliance = $service->createAlliance($this->currentUserId, $this->unTag(), $this->unNom());

        $membre = User::factory()->create();
        $candidature = $service->applyToAlliance((int)$membre->id, (int)$alliance->id);
        $service->acceptApplication((int)$candidature->id, $this->currentUserId);

        $releve = $this->releverLesRequetesDansLaTransaction(function () use ($service, $alliance): void {
            $service->disbandAlliance((int)$alliance->id, $this->currentUserId);
        });

        $alliances = $this->premiere($releve, static fn (string $sql): bool => str_contains($sql, '"alliances"'));
        $ecritureDeCompte = $this->premiere($releve, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'update "users"'));

        $this->assertNotNull($alliances, 'La dissolution n a pas atteint l alliance dans sa transaction.');
        $this->assertNotNull($ecritureDeCompte, 'La dissolution n a ecrit aucun compte : le temoin ne prouverait rien.');
        $this->assertLessThan($ecritureDeCompte, $alliances, 'La dissolution ecrit un compte avant d atteindre l alliance : elle s interbloquerait avec un choix de classe.');

        // Et le comportement reste celui d avant : l alliance disparait, les membres sont liberes.
        $this->assertNull(Alliance::query()->find((int)$alliance->id));
        $this->assertNull(User::query()->findOrFail($this->currentUserId)->alliance_id);
        $this->assertNull(User::query()->findOrFail((int)$membre->id)->alliance_id);
    }

    /**
     * Les requetes executees **plus profond** que le niveau de transaction d ou l essai part.
     *
     * Le niveau se lit au moment de chaque requete : le banc peut deja envelopper l essai dans une
     * transaction, et seul ce qui est ouvert au-dessus par le chemin eprouve compte.
     *
     * @return list<string>
     */
    private function releverLesRequetesDansLaTransaction(callable $chemin): array
    {
        $etat = new stdClass();
        $etat->base = DB::transactionLevel();
        $etat->ecoute = true;
        $etat->requetes = [];

        DB::listen(function (QueryExecuted $requete) use ($etat): void {
            if (!$etat->ecoute || DB::transactionLevel() <= $etat->base) {
                return;
            }

            $etat->requetes[] = str_replace('`', '"', $requete->sql);
        });

        try {
            $chemin();
        } finally {
            // Un ecouteur ne se retire pas ; il se tait.
            $etat->ecoute = false;
        }

        return $etat->requetes;
    }

    /**
     * @param list<string> $requetes
     */
    private function premiere(array $requetes, callable $critere): int|null
    {
        foreach ($requetes as $indice => $sql) {
            if ($critere($sql)) {
                return $indice;
            }
        }

        return null;
    }

    private function unTag(): string
    {
        return 'LK' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5);
    }

    private function unNom(): string
    {
        return 'Verrous ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
}
