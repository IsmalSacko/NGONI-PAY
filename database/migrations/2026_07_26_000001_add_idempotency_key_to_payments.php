<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clé d'idempotence envoyée par le client mobile (en-tête `Idempotency-Key`).
     *
     * Elle est générée une seule fois par encaissement, avant la première
     * tentative d'envoi, et rejouée à l'identique à chaque nouvelle tentative
     * (réseau coupé, réponse perdue, rejeu de la file hors ligne). L'index
     * unique par entreprise est ce qui garantit qu'un rejeu ne crée jamais un
     * second paiement, même si deux requêtes arrivent en parallèle.
     *
     * Nullable : les paiements créés hors application mobile (abonnements,
     * retours de provider) n'en fournissent pas — et les valeurs NULL ne sont
     * pas contraintes par un index unique (MySQL, PostgreSQL et SQLite).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 128)->nullable()->after('transaction_ref');
            $table->unique(['business_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
