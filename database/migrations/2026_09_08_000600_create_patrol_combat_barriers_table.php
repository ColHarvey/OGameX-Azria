<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Une patrouille, un combat a la fois.
 *
 * Le pendant, pour l espace libre, de `celestial_body_combat_barriers`. Un combat durable ne peut
 * s ouvrir aujourd hui que sur un corps celeste, parce que sa barriere est posee sur un corps.
 * Une patrouille posee a un point libre est attaquable (revues 117 et 120) : ce qui est tenu
 * n est pas un point — deux patrouilles peuvent partager un point sans partager leur sort — mais
 * **la patrouille elle-meme**. `patrol_id` est unique : la base refuse la seconde ouverture, et le
 * perdant de la course apprend qu il rejoint au lieu d ouvrir.
 *
 * L ordre global des verrous reste celui du combat : barriere, instance, union, missions. Cette
 * barriere-ci occupe la meme place que celle du corps, et n est jamais prise apres elle dans une
 * meme transaction : une arrivee vise un corps ou une patrouille, jamais les deux.
 *
 * `opened_at`, `owned_through_effect_at` et `revision` ont le meme sens que sur la barriere du corps.
 *
 * Purement additive ; inerte tant que `patrols_enabled` vaut 0.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('patrol_combat_barriers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('patrol_id')->unique('patrol_barrier_unique');
            $table->foreign('patrol_id', 'patrol_barrier_patrol_fk')
                ->references('id')
                ->on('patrols')
                ->restrictOnDelete();

            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'patrol_barrier_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->restrictOnDelete();

            $table->integer('opened_at', false, true);
            $table->integer('owned_through_effect_at', false, true);
            $table->integer('revision', false, true)->default(0);

            $table->timestamps();

            $table->index('owned_through_effect_at', 'patrol_barrier_through_idx');
            $table->index('combat_instance_id', 'patrol_barrier_instance_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patrol_combat_barriers');
    }
};
