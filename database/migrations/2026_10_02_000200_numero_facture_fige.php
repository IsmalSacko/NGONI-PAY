<?php

declare(strict_types=1);

use App\Models\Vente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Numéros de facture figés.
 *
 * - `ventes.numero_facture` : le numéro tel qu'il est remis au client,
 *   enregistré à l'encaissement. Il était recalculé à chaque affichage depuis
 *   le nom ACTUEL de la boutique : renommer la boutique changeait le numéro
 *   des factures déjà remises. Les ventes existantes reçoivent le numéro
 *   qu'elles affichent aujourd'hui.
 * - `boutiques.dernier_numero_vente` : compteur qui ne recule jamais. Après
 *   « Repartir de zéro », la numérotation repartait de 1 et redonnait des
 *   numéros déjà remis ; elle continue désormais. Il part du plus grand
 *   numéro connu, sauvegardes de remise à zéro comprises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->string('numero_facture', 40)->nullable()->after('numero');
        });
        Schema::table('boutiques', function (Blueprint $table) {
            $table->unsignedInteger('dernier_numero_vente')->default(0);
        });

        DB::table('ventes')->orderBy('id')->select(['id', 'boutique_id', 'numero', 'created_at'])->chunk(500, function ($ventes) {
            foreach ($ventes as $v) {
                DB::table('ventes')->where('id', $v->id)->update([
                    'numero_facture' => Vente::formater($v->boutique_id, (int) $v->numero, $v->created_at),
                ]);
            }
        });

        $maximums = DB::table('ventes')->groupBy('boutique_id')->select('boutique_id', DB::raw('MAX(numero) AS n'))->pluck('n', 'boutique_id')
            ->map(fn ($n) => (int) $n)->all();
        foreach ($this->numerosSauvegardes() as $boutique => $n) {
            $maximums[$boutique] = max($maximums[$boutique] ?? 0, $n);
        }
        foreach ($maximums as $boutique => $n) {
            DB::table('boutiques')->where('id', $boutique)->update(['dernier_numero_vente' => $n]);
        }

        Schema::table('ventes', function (Blueprint $table) {
            $table->unique(['boutique_id', 'numero_facture']);
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropUnique(['boutique_id', 'numero_facture']);
            $table->dropColumn('numero_facture');
        });
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn('dernier_numero_vente');
        });
    }

    /**
     * Plus grand numéro de vente de chaque boutique dans les sauvegardes de
     * remise à zéro : ces numéros ont été remis à des clients.
     *
     * @return array<string, int>
     */
    private function numerosSauvegardes(): array
    {
        $numeros = [];
        foreach (Storage::disk('local')->files('reinitialisations') as $chemin) {
            if (! str_ends_with($chemin, '.json')) {
                continue;
            }
            $d = json_decode((string) Storage::disk('local')->get($chemin), true);
            foreach ($d['tables']['ventes'] ?? [] as $v) {
                if (isset($v['boutique_id'], $v['numero'])) {
                    $numeros[$v['boutique_id']] = max($numeros[$v['boutique_id']] ?? 0, (int) $v['numero']);
                }
            }
        }

        return $numeros;
    }
};
