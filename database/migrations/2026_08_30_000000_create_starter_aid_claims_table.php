<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('starter_aid_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedTinyInteger('day');
            $table->timestamp('claimed_at');

            // Une recompense ne peut etre reclamee qu'une seule fois par joueur. La contrainte
            // est posee en base et non seulement dans le code : deux onglets ouverts ne peuvent
            // donc pas crediter deux fois la meme recompense.
            $table->unique(['user_id', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('starter_aid_claims');
    }
};
