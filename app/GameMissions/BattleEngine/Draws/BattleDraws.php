<?php

namespace OGame\GameMissions\BattleEngine\Draws;

/**
 * La source des tirages d'une bataille : une bande **semantique**, pas une suite de nombres.
 *
 * ## Pourquoi chaque tirage nomme son genre et sa borne
 *
 * Le moteur PHP et le moteur Rust doivent pouvoir jouer la meme bataille : memes flottes, memes
 * tirages, meme issue — c'est ce qu'un banc de parite compare. Une suite de nombres bruts ne
 * suffit pas : un moteur qui consommerait un tirage de plus, ou le meme nombre pour un autre usage,
 * se decalerait en silence et donnerait deux batailles voisines qui se ressemblent. Ici chaque
 * tirage est demande pour ce qu'il est — une cible parmi N, un pour-cent d'explosion sur 101, un
 * centieme de pour-cent de tir rapide sur 10 000 — et une source a graine en tient le journal
 * (`DrawJournal`), que le banc compare a celui du moteur Rust : meme nombre de tirages, meme
 * empreinte, donc meme bande consommee entierement et dans le meme ordre.
 *
 * Les formules sont celles que le moteur PHP avait deja (`Draw`) ; le moteur Rust les reproduit.
 * En jeu, la source est le hasard du systeme (`SystemDraws`) ; sur un banc, une graine
 * (`SeededDraws`).
 */
interface BattleDraws
{
    /**
     * Une position uniforme parmi `$count` candidats, de 0 a `$count - 1`.
     */
    public function targetIndex(int $count): int;

    /**
     * Un pour-cent entier de 0 a 100, pour l'explosion d'une coque entamee.
     */
    public function explosionPercent(): int;

    /**
     * Un centieme de pour-cent de 1 a 10 000, pour le tir rapide.
     */
    public function rapidfireCentipercent(): int;

    /**
     * Un entier de 1 a `$bound`, pour les tirages qui n'appartiennent pas aux rounds : la manoeuvre
     * de Hamill (une chance sur N), la reparation des defenses et la lune (sur cent).
     */
    public function chanceOutOf(int $bound): int;

    /**
     * La source des tirages **des rounds**, distincte de celle du reste de la bataille.
     *
     * Le moteur Rust recoit la graine et commence sa suite au premier round ; le moteur PHP doit
     * faire de meme, alors que la manoeuvre de Hamill a deja tire avant les rounds et que la
     * reparation des defenses et la lune tireront apres. Une source a graine rend donc une suite
     * neuve pour les rounds ; la source du systeme se rend elle-meme.
     */
    public function forRounds(): BattleDraws;

    /**
     * Ce que cette source a tire — null pour le hasard du systeme, qui n'a rien a comparer.
     */
    public function journal(): DrawJournal|null;
}
