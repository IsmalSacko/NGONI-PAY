<?php

use App\Enums\BillingCycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans d'abonnement et leurs tarifs, tenus par l'exploitant.
 *
 * Les prix étaient écrits dans le code — 5 000 pour Basic, 15 000 pour Pro — à
 * trois endroits : le contrôleur, l'écran des plans, l'écran de paiement. Les
 * ajuster demandait un déploiement, et une promotion était impossible.
 *
 * Ils vivent désormais en base, une ligne par plan et par durée : l'exploitant
 * augmente, diminue, ouvre ou ferme une durée depuis la console, sans qu'on
 * touche au code. Un trimestre n'est donc pas forcément trois fois le mois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            // Chaîne libre : `free`, `basic` et `pro` sont les plans livrés, mais
            // l'exploitant doit pouvoir en ajouter sans migration.
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('description', 500)->nullable();
            $table->json('features')->nullable();
            // Durée de l'essai, pour le plan gratuit uniquement.
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')
                ->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('cycle', 20);
            $table->decimal('amount', 14, 2);
            // L'abonnement est facturé par l'éditeur, dans sa monnaie, quelle que
            // soit celle dans laquelle le business tient ses comptes.
            $table->string('currency', 3)->default('XOF');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['subscription_plan_id', 'cycle']);
        });

        $this->seedPlans();
    }

    /**
     * Reprend les tarifs qui étaient codés en dur, sans les changer.
     *
     * Les durées longues sont initialisées au multiple exact du mois : aucune
     * remise n'est inventée, c'est à l'exploitant de décider s'il en accorde une.
     */
    private function seedPlans(): void
    {
        $maintenant = now();

        $plans = [
            [
                'code' => 'free',
                'name' => 'Free',
                'description' => "Pour démarrer : encaissements en espèces, factures et suivi des paiements.",
                'trial_days' => 7,
                'monthly' => 0,
                'sort_order' => 1,
            ],
            [
                'code' => 'basic',
                'name' => 'Basic',
                'description' => "Pour les petites entreprises : paiements en ligne (5 par mois) et support amélioré.",
                'trial_days' => null,
                'monthly' => 5000,
                'sort_order' => 2,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => "Pour les professionnels : paiements en ligne illimités, rapports avancés, assistance prioritaire.",
                'trial_days' => null,
                'monthly' => 15000,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            $id = DB::table('subscription_plans')->insertGetId([
                'code' => $plan['code'],
                'name' => $plan['name'],
                'description' => $plan['description'],
                'features' => null,
                'trial_days' => $plan['trial_days'],
                'is_active' => true,
                'sort_order' => $plan['sort_order'],
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);

            // Le plan gratuit n'a pas de tarif : rien à régler, rien à choisir.
            if ($plan['monthly'] <= 0) {
                continue;
            }

            foreach (BillingCycle::cases() as $cycle) {
                DB::table('subscription_plan_prices')->insert([
                    'subscription_plan_id' => $id,
                    'cycle' => $cycle->value,
                    'amount' => $plan['monthly'] * $cycle->months(),
                    'currency' => 'XOF',
                    'is_active' => true,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_prices');
        Schema::dropIfExists('subscription_plans');
    }
};
