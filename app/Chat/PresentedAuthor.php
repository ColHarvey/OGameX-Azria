<?php

namespace OGame\Chat;

use OGame\Models\Alliance;
use OGame\Models\User;
use OGame\Services\HonorService;

/**
 * Ce qu'un joueur porte a cote de son nom dans le chat general.
 *
 * ## Pourquoi un composeur, et pas deux lectures
 *
 * Le chat general a **deux chemins vers l'ecran** : l'historique, que le serveur rend a
 * l'ouverture de la page, et la diffusion, qui arrive en direct. Ils doivent dire la meme chose,
 * sinon un joueur lit « [ABC] Untel (12) » en direct puis « Untel » au rechargement — et c'est
 * exactement le defaut qu'une lecture en double a deja produit ailleurs dans ce depot, ou le nom
 * d'une unite arrivait en anglais en direct et en francais au rechargement.
 *
 * Une seule classe compose donc les deux, et les temoins la prennent au mot.
 *
 * ## Les marques sont celles du classement
 *
 * Decision de Keven : le chat porte les memes decorations que le classement, ni plus ni moins —
 * le tag d'alliance, le nom, le badge d'administrateur, et les points d'honneur. Le gabarit
 * reutilise les memes classes CSS (`ally-tag`, `playername`, `badgeAdmin`, `honorScore`), de sorte
 * qu'aucune direction graphique nouvelle n'est introduite.
 */
final readonly class PresentedAuthor
{
    private function __construct(
        public int $id,
        public string $name,
        public string|null $allianceTag,
        public int|null $allianceId,
        public bool $isAdmin,
        public int $honorPoints,
    ) {
    }

    /**
     * Les marques d'un joueur, lues une fois.
     *
     * Le role vit dans la table des roles, le total d'honneur sur la ligne du joueur, et
     * l'alliance dans sa propre table.
     */
    public static function of(User $user): self
    {
        // **Par le modele, pas par la relation** : `$user->alliance` est declaree sans type de
        // retour precis, et l analyse statique n y voit qu un `Model` generique — donc ni
        // `alliance_tag`, ni `id`.
        $alliance = $user->alliance_id !== null ? Alliance::find($user->alliance_id) : null;

        return new self(
            (int)$user->id,
            (string)$user->username,
            $alliance?->alliance_tag,
            $alliance !== null ? (int)$alliance->id : null,
            $user->hasRole('admin'),
            resolve(HonorService::class)->pointsOf($user),
        );
    }

    /**
     * La forme que le navigateur recoit, identique dans l'historique et en direct.
     *
     * @return array<string, mixed>
     */
    public function forTheBrowser(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'allianceTag' => $this->allianceTag,
            'allianceId' => $this->allianceId,
            'isAdmin' => $this->isAdmin,
            'honorPoints' => $this->honorPoints,
        ];
    }
}
