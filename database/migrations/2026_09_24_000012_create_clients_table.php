<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id')->index();
            $table->string('nom');
            $table->string('telephone')->nullable();
            $table->unsignedInteger('points_fidelite')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
