<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute le statut 'cancelled' aux paiements (annulation d'un paiement
     * enregistré par erreur). Compatible MySQL/MariaDB (ENUM) et PostgreSQL (CHECK).
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check");
            DB::statement(
                "ALTER TABLE payments ADD CONSTRAINT payments_status_check " .
                "CHECK (status::text = ANY (ARRAY['pending'::varchar, 'success'::varchar, 'failed'::varchar, 'cancelled'::varchar]::text[]))"
            );
        } else {
            DB::statement(
                "ALTER TABLE payments MODIFY COLUMN status ENUM('pending', 'success', 'failed', 'cancelled') NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Repasser les 'cancelled' en 'failed' avant de retirer la valeur.
        DB::table('payments')->where('status', 'cancelled')->update(['status' => 'failed']);

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check");
            DB::statement(
                "ALTER TABLE payments ADD CONSTRAINT payments_status_check " .
                "CHECK (status::text = ANY (ARRAY['pending'::varchar, 'success'::varchar, 'failed'::varchar]::text[]))"
            );
        } else {
            DB::statement(
                "ALTER TABLE payments MODIFY COLUMN status ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending'"
            );
        }
    }
};
