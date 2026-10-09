<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mode libre : le propriétaire gère lui-même ses ventes et factures, sous sa
 * responsabilité acceptée (config/mode_libre.php). L'acceptation est gardée
 * comme preuve ; chaque vente supprimée l'est dans le journal.
 */
class ModeLibre
{
    public static function version(): string
    {
        return (string) config('mode_libre.version');
    }

    /** @return list<string> */
    public static function texte(): array
    {
        return array_values((array) config('mode_libre.texte'));
    }

    /** Actif, et accepté dans la version en vigueur du texte. */
    public static function actif(?Boutique $boutique): bool
    {
        return $boutique !== null && $boutique->mode_libre && $boutique->mode_libre_version === self::version();
    }

    public function activer(Boutique $boutique, User $proprietaire, Request $request): void
    {
        DB::transaction(function () use ($boutique, $proprietaire, $request): void {
            $this->tracer($boutique, $proprietaire, $request, 'activation');
            $boutique->forceFill(['mode_libre' => true, 'mode_libre_version' => self::version()])->save();
        });
    }

    public function desactiver(Boutique $boutique, User $proprietaire, Request $request): void
    {
        DB::transaction(function () use ($boutique, $proprietaire, $request): void {
            $this->tracer($boutique, $proprietaire, $request, 'desactivation');
            $boutique->forceFill(['mode_libre' => false])->save();
        });
    }

    /** Une vente supprimée laisse sa trace : numéro, montant, lignes, auteur. */
    public function journaliser(Vente $vente, User $auteur): void
    {
        DB::table('journal_suppressions')->insert([
            'boutique_id' => $vente->boutique_id,
            'user_id' => $auteur->id,
            'par' => $auteur->name,
            'vente_id' => $vente->id,
            'numero_facture' => $vente->numeroFormate(),
            'total' => (int) $vente->total,
            'jour' => $vente->jour_affaire ?? $vente->created_at?->toDateString(),
            'lignes' => json_encode($vente->lignes()->get(['nom_produit', 'quantite', 'prix_unitaire', 'total_ligne'])->toArray()),
            'essai' => (bool) $vente->essai,
            'supprimee_le' => now(),
        ]);
    }

    private function tracer(Boutique $boutique, User $proprietaire, Request $request, string $action): void
    {
        DB::table('acceptations_mode_libre')->insert([
            'boutique_id' => $boutique->id,
            'user_id' => $proprietaire->id,
            'nom' => $proprietaire->name,
            'telephone' => $proprietaire->phone,
            'boutique_nom' => $boutique->nom,
            'action' => $action,
            'version' => self::version(),
            'texte' => implode("\n\n", self::texte()),
            'ip' => $request->ip(),
            'appareil' => ConditionsUtilisation::appareil($request),
            'le' => now(),
        ]);
    }
}
