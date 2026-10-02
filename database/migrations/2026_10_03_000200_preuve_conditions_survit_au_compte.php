<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La preuve d'acceptation des conditions survit à la suppression du compte
 * (gardée 5 ans, voir la politique de confidentialité). Le lien au compte
 * bloquait la suppression (erreur 500) : il se vide désormais, et la preuve
 * garde elle-même le nom et le numéro de celui qui a accepté.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Table d'aujourd'hui, peu remplie : reconstruite plutôt que modifiée
        // (changer une clé étrangère en place n'est pas portable).
        Schema::rename('acceptations_conditions', 'acceptations_conditions_avant');
        // Noms propres : l'ancienne table garde les siens jusqu'à sa suppression.
        $this->creer(fn (Blueprint $t) => $t->foreign('user_id', 'preuves_conditions_user_fk')->references('id')->on('users')->nullOnDelete());

        foreach (DB::table('acceptations_conditions_avant')->orderBy('id')->get() as $l) {
            $u = DB::table('users')->where('id', $l->user_id)->first(['name', 'phone']);
            DB::table('acceptations_conditions')->insert([
                'id' => $l->id, 'user_id' => $l->user_id, 'nom' => $u?->name, 'telephone' => $u?->phone,
                'version' => $l->version, 'source' => $l->source, 'ip' => $l->ip, 'appareil' => $l->appareil, 'acceptee_le' => $l->acceptee_le,
            ]);
        }
        Schema::drop('acceptations_conditions_avant');
    }

    public function down(): void
    {
        Schema::drop('acceptations_conditions');
        Schema::create('acceptations_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('version', 20);
            $table->string('source', 20);
            $table->string('ip', 45)->nullable();
            $table->string('appareil', 255)->nullable();
            $table->timestamp('acceptee_le');
            $table->index(['user_id', 'version']);
        });
    }

    private function creer(callable $lien): void
    {
        Schema::create('acceptations_conditions', function (Blueprint $table) use ($lien) {
            $table->id();
            $table->uuid('user_id')->nullable();
            $table->string('nom')->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('version', 20);
            $table->string('source', 20);
            $table->string('ip', 45)->nullable();
            $table->string('appareil', 255)->nullable();
            $table->timestamp('acceptee_le');
            $table->index(['user_id', 'version'], 'preuves_conditions_user_version');
            $table->index('telephone', 'preuves_conditions_telephone');
            $lien($table);
        });
    }
};
