<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modèle commercial : plans, tarifs, abonnement du compte propriétaire et
 * demandes d'abonnement.
 *
 * L'abonnement appartient au COMPTE du propriétaire et couvre toutes ses
 * boutiques : ouvrir une nouvelle boutique ne relance pas d'essai. Il n'y a pas
 * de plan gratuit — après l'essai, un abonnement ou la lecture seule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('nom', 60);
            $table->text('description')->nullable();
            $table->json('fonctionnalites')->nullable();
            // Essai uniquement : durée offerte à l'inscription.
            $table->unsignedSmallInteger('jours_essai')->nullable();
            // Limites : NULL = illimité.
            $table->unsignedSmallInteger('max_boutiques')->nullable();
            $table->unsignedSmallInteger('max_membres')->nullable();
            $table->boolean('est_actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_tarifs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('cycle', 20);
            $table->unsignedInteger('montant');
            $table->string('devise', 3)->default('XOF');
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
            $table->unique(['plan_id', 'cycle']);
        });

        Schema::create('abonnements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('plan', 30);
            $table->date('debut');
            // NULL = sans échéance (accordé à vie par l'exploitant).
            $table->date('fin')->nullable();
            $table->boolean('est_actif')->default(true);
            $table->boolean('est_manuel')->default(false);
            $table->foreignUuid('accorde_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note_admin')->nullable();
            $table->timestamps();
        });

        Schema::create('demandes_abonnement', function (Blueprint $table) {
            $table->id();
            // Compte propriétaire : c'est lui qui sera abonné.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('demande_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('boutique_id')->nullable()->constrained('boutiques')->nullOnDelete();
            $table->string('plan', 30);
            $table->string('cycle', 20);
            $table->unsignedTinyInteger('mois');
            // Figés au dépôt : un tarif changé ensuite ne transforme pas la demande.
            $table->unsignedInteger('montant');
            $table->string('devise', 3);
            $table->string('moyen', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('telephone_contact', 30)->nullable();
            $table->string('preuve_chemin')->nullable();
            $table->string('preuve_note', 1000)->nullable();
            $table->string('statut', 20)->default('en_attente');
            $table->timestamp('decide_le')->nullable();
            $table->foreignUuid('decide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note_decision', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'statut']);
        });

        $this->seedPlans();
    }

    private function seedPlans(): void
    {
        $maintenant = now();

        $plans = [
            ['code' => 'essai', 'nom' => 'Essai gratuit', 'jours_essai' => 7, 'max_boutiques' => 1, 'max_membres' => 3, 'ordre' => 1,
                'description' => "Offert à l'inscription : toutes les fonctions pendant 7 jours, pour une boutique, sans engagement.",
                'tarifs' => []],
            ['code' => 'basic', 'nom' => 'Basic', 'jours_essai' => null, 'max_boutiques' => 1, 'max_membres' => 3, 'ordre' => 2,
                'description' => 'Une boutique : caisse, ventes libres, tickets, clients et stock.',
                'tarifs' => ['monthly' => 4000, 'quarterly' => 11000, 'biannual' => 21000, 'yearly' => 40000]],
            ['code' => 'pro', 'nom' => 'Pro', 'jours_essai' => null, 'max_boutiques' => 5, 'max_membres' => null, 'ordre' => 3,
                'description' => "Jusqu'à 5 boutiques, équipe illimitée, séances de caisse et suivi des écarts.",
                'tarifs' => ['monthly' => 10000, 'quarterly' => 30000, 'biannual' => 60000, 'yearly' => 120000]],
        ];

        foreach ($plans as $plan) {
            $id = DB::table('plans')->insertGetId([
                'code' => $plan['code'], 'nom' => $plan['nom'], 'description' => $plan['description'],
                'jours_essai' => $plan['jours_essai'], 'max_boutiques' => $plan['max_boutiques'],
                'max_membres' => $plan['max_membres'], 'est_actif' => true, 'ordre' => $plan['ordre'],
                'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);

            foreach ($plan['tarifs'] as $cycle => $montant) {
                DB::table('plan_tarifs')->insert([
                    'plan_id' => $id, 'cycle' => $cycle, 'montant' => $montant, 'devise' => 'XOF',
                    'est_actif' => true, 'created_at' => $maintenant, 'updated_at' => $maintenant,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_abonnement');
        Schema::dropIfExists('abonnements');
        Schema::dropIfExists('plan_tarifs');
        Schema::dropIfExists('plans');
    }
};
