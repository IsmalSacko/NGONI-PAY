<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories_produits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->string('nom');
            $table->string('couleur', 7)->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories_produits');
    }
};
