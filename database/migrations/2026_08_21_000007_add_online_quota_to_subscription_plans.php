<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quota de paiements en ligne, porté par le plan.
 *
 * Il était écrit dans le contrôleur : « Basic → 5 par mois ». La description du
 * plan, elle, vit en base depuis que l'exploitant la tient — et rien n'empêchait
 * les deux de se contredire. Un plan annonçant dix paiements en aurait laissé
 * passer cinq.
 *
 * `null` signifie « sans limite » : c'est le cas de Pro. Zéro signifie « aucun
 * paiement en ligne », ce qu'est le plan gratuit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('monthly_online_payments')->nullable()
                ->after('trial_days');
        });

        // Les valeurs qui étaient en dur, reprises telles quelles.
        DB::table('subscription_plans')->where('code', 'free')
            ->update(['monthly_online_payments' => 0]);
        DB::table('subscription_plans')->where('code', 'basic')
            ->update(['monthly_online_payments' => 5]);
        DB::table('subscription_plans')->where('code', 'pro')
            ->update(['monthly_online_payments' => null]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('monthly_online_payments');
        });
    }
};
