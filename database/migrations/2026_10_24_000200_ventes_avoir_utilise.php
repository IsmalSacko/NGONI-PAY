<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avoir client : la part du reste d'une vente à crédit payée par ce que la
 * boutique gardait au client. Le ticket le dit (« Payé avec l'avoir »), et le
 * « Vendu à crédit » des rapports ne la compte pas comme une dette.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table): void {
            $table->unsignedBigInteger('avoir_utilise')->default(0)->after('reste_du');
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table): void {
            $table->dropColumn('avoir_utilise');
        });
    }
};
