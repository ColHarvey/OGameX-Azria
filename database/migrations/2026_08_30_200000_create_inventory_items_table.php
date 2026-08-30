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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Reference de l'objet dans le catalogue defini en code (ShopService::ITEMS).
            // Les objets ne vivent pas en base : seule la quantite possedee y est stockee.
            $table->string('item_ref', 64);
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            // Une seule ligne par joueur et par objet : la contrainte est posee en base et pas
            // seulement dans le code, pour que deux achats simultanes ne creent pas deux lignes.
            $table->unique(['user_id', 'item_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
