<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Champs pour les abonnements accordés manuellement par un system_admin.
     * - is_manual : marque un plan forcé (le renouvellement/downgrade auto ne doit pas y toucher).
     * - granted_by : l'admin qui a accordé le plan (traçabilité).
     * - admin_note : commentaire libre facultatif.
     * Un ends_at NULL sur un abonnement is_manual signifie « à vie ».
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('is_active');
            $table->foreignId('granted_by')->nullable()->after('is_manual')
                ->constrained('users')->nullOnDelete();
            $table->string('admin_note')->nullable()->after('granted_by');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by');
            $table->dropColumn(['is_manual', 'admin_note']);
        });
    }
};
