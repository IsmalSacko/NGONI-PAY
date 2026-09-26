<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le plan « free » disparaît : ce n'était qu'un essai. Après l'essai, plus aucun
 * encaissement sans plan payant — il n'existe plus de palier gratuit.
 *
 * - Les abonnements `free` deviennent `trial` et gardent leur date de fin :
 *   un essai expiré reste expiré.
 * - Le plan du catalogue `free` devient `trial` ; sa durée d'essai reste réglable
 *   depuis la console.
 * - Le quota mensuel de paiements en ligne disparaît avec PayDunya : tous les
 *   encaissements sont désormais déclaratifs.
 */
return new class extends Migration
{
    private const OLD_DESCRIPTIONS = [
        'free' => "Pour démarrer : encaissements en espèces, factures et suivi des paiements.",
        'basic' => "Pour les petites entreprises : paiements en ligne (5 par mois) et support amélioré.",
        'pro' => "Pour les professionnels : paiements en ligne illimités, rapports avancés, assistance prioritaire.",
    ];

    private const NEW_DESCRIPTIONS = [
        'trial' => "Essai offert à la création de l'entreprise : toutes les fonctionnalités, sans engagement.",
        'basic' => "Pour les petites entreprises : encaissements, factures et support amélioré.",
        'pro' => "Pour les professionnels : rapports avancés et assistance prioritaire.",
    ];

    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('plan', ['free', 'trial', 'basic', 'pro'])->default('trial')->change();
        });

        DB::table('subscriptions')->where('plan', 'free')->update(['plan' => 'trial']);

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('plan', ['trial', 'basic', 'pro'])->default('trial')->change();
        });

        DB::table('subscription_plans')->where('code', 'free')->update(['code' => 'trial']);
        DB::table('subscription_plans')->where('code', 'trial')->where('name', 'Free')
            ->update(['name' => 'Essai gratuit']);

        // Descriptions livrées qui promettaient des paiements en ligne. Une
        // description réécrite par l'exploitant depuis la console est laissée telle quelle.
        foreach (self::OLD_DESCRIPTIONS as $code => $old) {
            $newCode = $code === 'free' ? 'trial' : $code;
            DB::table('subscription_plans')
                ->where('code', $newCode)
                ->where('description', $old)
                ->update(['description' => self::NEW_DESCRIPTIONS[$newCode]]);
        }

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('monthly_online_payments');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('monthly_online_payments')->nullable()->after('trial_days');
        });

        DB::table('subscription_plans')->where('code', 'trial')->update(['code' => 'free', 'name' => 'Free']);

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('plan', ['free', 'trial', 'basic', 'pro'])->default('free')->change();
        });

        DB::table('subscriptions')->where('plan', 'trial')->update(['plan' => 'free']);

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('plan', ['free', 'basic', 'pro'])->default('free')->change();
        });
    }
};
