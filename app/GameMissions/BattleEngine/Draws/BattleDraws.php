<?php

namespace OGame\GameMissions\BattleEngine\Draws;

/**
 * La source des tirages d'une bataille.
 *
 * ## Pourquoi une source, et non des appels a `rand()`
 *
 * Le moteur PHP et le moteur Rust doivent pouvoir jouer **la meme bataille** : memes flottes, memes
 * tirages, meme issue — c'est ce qu'un banc de parite compare. Deux generateurs independants ne se
 * comparent pas : une difference ne dirait rien sur les moteurs. La source est donc injectee, et les
 * trois usages qu'en fait un moteur — choisir une cible, faire exploser une coque entamee, accorder
 * un tir rapide — derivent d'un seul entier uniforme par des formules fixes (`Draw`), que le moteur
 * Rust reproduit a l'identique.
 *
 * En jeu, la source est le hasard du systeme (`SystemDraws`). Sur un banc, une graine
 * (`SeededDraws`) rend la bataille rejouable.
 */
interface BattleDraws
{
    /**
     * Le prochain tirage : un entier uniforme sur trente-deux bits, de 0 a 4 294 967 295.
     */
    public function next(): int;

    /**
     * La source des tirages **des rounds**, distincte de celle du reste de la bataille.
     *
     * Le moteur Rust recoit la graine et commence sa suite au premier round ; le moteur PHP doit
     * faire de meme, alors que la manoeuvre de Hamill a deja tire avant les rounds et que la
     * reparation des defenses et la lune tireront apres. Une source a graine rend donc une suite
     * neuve pour les rounds ; la source du systeme se rend elle-meme.
     */
    public function forRounds(): BattleDraws;
}
