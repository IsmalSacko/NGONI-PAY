<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propriétaire de la boutique : le compte qui l'a créée. Un propriétaire peut
 * avoir plusieurs boutiques, et c'est son compte qui porte l'abonnement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->foreignUuid('proprietaire_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
        });

        // Boutiques existantes : leur plus ancien administrateur.
        foreach (DB::table('boutiques')->whereNull('proprietaire_id')->pluck('id') as $boutiqueId) {
            $admin = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->join('users', 'users.id', '=', 'model_has_roles.model_id')
                ->where('model_has_roles.boutique_id', $boutiqueId)
                ->where('roles.name', 'admin')
                ->orderBy('users.created_at')
                ->value('users.id');

            if ($admin !== null) {
                DB::table('boutiques')->where('id', $boutiqueId)->update(['proprietaire_id' => $admin]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proprietaire_id');
        });
    }
};
