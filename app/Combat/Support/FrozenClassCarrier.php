<?php

namespace OGame\Combat\Support;

use Illuminate\Database\Eloquent\Attributes\Table;
use LogicException;
use OGame\Enums\CharacterClass;
use OGame\Models\User;

/**
 * Le compte d un combattant tel que sa bataille le lit : ses colonnes, avec la classe de son admission.
 *
 * ## Pourquoi un porteur
 *
 * Le jeu pose ses questions de classe a un `User`, jamais a un combattant : la manoeuvre de Hamill
 * (`isGeneral`), le fret des transporteurs du Collecteur et des vaisseaux du General
 * (`CapacityPropertyService`), la part de pillage du Decouvreur (`LiveLootContextFactory`), puis la
 * photographie d application — champ d epaves du General, classe nommee au rapport. Geler la classe **la
 * ou ces questions sont posees** les fait toutes repondre depuis l admission sans toucher a un seul site
 * d application : aucun effet compte deux fois, aucun oublie.
 *
 * ## Detache
 *
 * Le porteur copie les colonnes du compte que le combattant a charge — jamais ses relations deja chargees —
 * et y pose la classe gelee. Le modele du combattant n est pas modifie, et aucune instance partagee
 * (celle de la fabrique, celle de la requete en cours) n est touchee : le porteur est un objet neuf.
 *
 * ## Il n ecrit jamais
 *
 * Une classe d admission ecrite en base remplacerait la classe du joueur par une classe passee. Le porteur
 * refuse donc toute affectation d attribut, `save()` — et ce qui y passe : `update()`, `push()`, `touch()`,
 * `saveQuietly()` —, `delete()`, `increment()` et `decrement()`. Une relation chargee depuis lui reste un
 * modele ordinaire, qui lit le monde comme avant.
 *
 * ## Il reste un compte pour ses relations
 *
 * Table, clef etrangere et classe polymorphe sont celles de `User` : une relation chargee depuis le porteur
 * vise les memes lignes que depuis le compte.
 */
#[Table(name: 'users')]
final class FrozenClassCarrier extends User
{
    /**
     * Pose apres la copie : avant, Eloquent construit l objet ; apres, plus rien ne s y ecrit.
     */
    private bool $sealed = false;

    /**
     * Un porteur neuf : les colonnes de ce compte, et cette classe a la place de la sienne.
     */
    public static function carrying(User $account, CharacterClass|null $characterClass): self
    {
        $porteur = new self();
        $porteur->setConnection($account->getConnectionName());
        $porteur->setRawAttributes(['character_class' => $characterClass?->value] + $account->getAttributes(), true);
        $porteur->exists = true;
        $porteur->sealed = true;

        return $porteur;
    }

    public function setAttribute($key, $value)
    {
        if ($this->sealed) {
            throw $this->refusal('affecter l attribut « ' . $key . ' »');
        }

        return parent::setAttribute($key, $value);
    }

    public function offsetUnset($offset): void
    {
        if ($this->sealed) {
            throw $this->refusal('effacer un attribut');
        }

        parent::offsetUnset($offset);
    }

    public function save(array $options = []): bool
    {
        throw $this->refusal('s enregistrer');
    }

    public function delete(): bool|null
    {
        throw $this->refusal('se supprimer');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw $this->refusal($method . ' « ' . $column . ' »');
    }

    public function getForeignKey(): string
    {
        return (new User())->getForeignKey();
    }

    public function getMorphClass(): string
    {
        return (new User())->getMorphClass();
    }

    private function refusal(string $geste): LogicException
    {
        return new LogicException(
            'Le compte ' . $this->getKey() . ', tel que sa bataille le lit, a ete sollicite pour ' . $geste
            . ' : il porte la classe de son admission et n ecrit jamais.'
        );
    }
}
