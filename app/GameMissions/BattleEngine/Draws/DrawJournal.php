<?php

namespace OGame\GameMissions\BattleEngine\Draws;

/**
 * Ce qu'une source a graine a tire : combien de fois, et une empreinte de chaque tirage.
 *
 * ## Pourquoi une empreinte, et de quoi
 *
 * Le banc de parite ne compare pas seulement deux issues : il verifie que les deux moteurs ont
 * consomme **la meme bande, entierement et dans le meme ordre**. Une suite de nombres bruts
 * pourrait se decaler d'un cran et donner deux batailles voisines qui se ressemblent ; l'empreinte
 * nomme le genre de chaque tirage et sa borne — cible parmi N, explosion sur 101, tir rapide sur
 * 10 000 — avec la valeur tiree. Deux consommations differentes des memes nombres ont deux
 * empreintes differentes.
 *
 * L'empreinte est un FNV-1a sur soixante-quatre bits de `genre:borne:valeur;` pour chaque tirage,
 * calcule ici en entiers PHP sans debordement — par tranches de seize bits — et a l'identique en
 * Rust (`DrawJournal` dans `lib.rs`), ou elle est un `u64` natif.
 */
final class DrawJournal
{
    private const int OFFSET = -3750763034362895579; // 0xcbf29ce484222325 en entier signe

    private const int PRIME = 0x100000001b3;

    private int $count = 0;

    private int $digest = self::OFFSET;

    /**
     * Inscrit un tirage : son genre, sa borne, sa valeur.
     */
    public function record(string $kind, int $bound, int $value): void
    {
        $this->count++;
        $this->digest = self::absorb($this->digest, $kind . ':' . $bound . ':' . $value . ';');
    }

    /**
     * L'empreinte FNV-1a de soixante-quatre bits d'une chaine seule, en hexadecimal.
     *
     * C'est ce qui permet d'eprouver l'arithmetique contre les vecteurs publics de FNV, et de
     * l'epingler face au moteur Rust.
     */
    public static function fnv1a64(string $bytes): string
    {
        return sprintf('%016x', self::absorb(self::OFFSET, $bytes));
    }

    private static function absorb(int $digest, string $bytes): int
    {
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $digest = self::multiply($digest ^ $byte, self::PRIME);
        }

        return $digest;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * L'empreinte, en seize chiffres hexadecimaux — la forme que le moteur Rust rend.
     */
    public function digest(): string
    {
        return sprintf('%016x', $this->digest);
    }

    /**
     * Le produit de deux entiers de soixante-quatre bits modulo deux puissance soixante-quatre.
     *
     * PHP deborde en flottant des que le produit depasse l'entier signe : les operandes sont
     * decoupes en tranches de seize bits, dont les produits partiels tiennent chacun dans
     * trente-deux bits, et les retenues se propagent tranche par tranche.
     */
    private static function multiply(int $a, int $b): int
    {
        $tranchesA = [$a & 0xFFFF, ($a >> 16) & 0xFFFF, ($a >> 32) & 0xFFFF, ($a >> 48) & 0xFFFF];
        $tranchesB = [$b & 0xFFFF, ($b >> 16) & 0xFFFF, ($b >> 32) & 0xFFFF, ($b >> 48) & 0xFFFF];

        $resultat = [0, 0, 0, 0];

        for ($i = 0; $i < 4; $i++) {
            $retenue = 0;

            for ($j = 0; $i + $j < 4; $j++) {
                $somme = $resultat[$i + $j] + $tranchesA[$i] * $tranchesB[$j] + $retenue;
                $resultat[$i + $j] = $somme & 0xFFFF;
                $retenue = $somme >> 16;
            }
        }

        return $resultat[0] | ($resultat[1] << 16) | ($resultat[2] << 32) | ($resultat[3] << 48);
    }
}
