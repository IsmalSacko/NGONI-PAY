<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute la valeur 'system_admin' au champ role.
     * - MySQL/MariaDB : le champ est un vrai type ENUM -> MODIFY COLUMN.
     * - PostgreSQL : $table->enum() génère une contrainte CHECK -> on la recrée.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
            DB::statement(
                "ALTER TABLE users ADD CONSTRAINT users_role_check " .
                "CHECK (role::text = ANY (ARRAY['owner'::varchar, 'staff'::varchar, 'system_admin'::varchar]::text[]))"
            );
        } else {
            // MySQL / MariaDB
            DB::statement(
                "ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'staff', 'system_admin') NOT NULL DEFAULT 'owner'"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Rétrograder les éventuels system_admin avant de retirer la valeur.
        DB::table('users')->where('role', 'system_admin')->update(['role' => 'owner']);

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
            DB::statement(
                "ALTER TABLE users ADD CONSTRAINT users_role_check " .
                "CHECK (role::text = ANY (ARRAY['owner'::varchar, 'staff'::varchar]::text[]))"
            );
        } else {
            DB::statement(
                "ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'staff') NOT NULL DEFAULT 'owner'"
            );
        }
    }
};
