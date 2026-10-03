<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\Plan;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Programme de fidélité de la boutique : au bout de `seuil` achats, le client
 * a droit à `remise_pct` % sur son achat suivant.
 *
 * Le compte d'achats d'un client, ce sont ses ventes validées depuis la
 * dernière remise de fidélité (ou depuis toujours). Le serveur calcule la
 * remise lui-même — l'application ne fait que la demander — et ne refuse
 * jamais une vente qui la porte : faite hors ligne, elle a déjà été remise au
 * client, sur un ticket imprimé.
 */
class Fidelite
{
    /** @return array{seuil: int, remise_pct: int}|null */
    public function programme(Boutique $boutique): ?array
    {
        // Hors du plan (Plan::FIDELITE) : le réglage est gardé, mais ni
        // remise ni suivi tant que l'offre ne l'inclut pas.
        if (! app(AbonnementService::class)->permet($boutique, Plan::FIDELITE)) {
            return null;
        }

        return $boutique->fidelite_seuil && $boutique->fidelite_remise_pct
            ? ['seuil' => (int) $boutique->fidelite_seuil, 'remise_pct' => (int) $boutique->fidelite_remise_pct]
            : null;
    }

    /** @param  array<string, mixed>  $donnees */
    public function regler(Boutique $boutique, array $donnees): Boutique
    {
        $seuil = $donnees['seuil'] ?? null;
        $pct = $donnees['remise_pct'] ?? null;

        if ($seuil !== null && (! is_int($seuil) || $seuil < 2 || $seuil > 100)) {
            throw ValidationException::withMessages(['seuil' => ['Entre 2 et 100 achats.']]);
        }
        if ($seuil !== null && (! is_int($pct) || $pct < 1 || $pct > 50)) {
            throw ValidationException::withMessages(['remise_pct' => ['Une remise entre 1 et 50 %.']]);
        }

        $boutique->forceFill([
            'fidelite_seuil' => $seuil,
            'fidelite_remise_pct' => $seuil === null ? null : $pct,
        ])->save();

        return $boutique->fresh();
    }

    /** Remise due sur `$sousTotal` : arrondie vers le bas, jamais un centime offert de trop. */
    public function remise(int $sousTotal, int $pct): int
    {
        return intdiv($sousTotal * $pct, 100);
    }

    /**
     * Colonne SQL : achats validés du client depuis sa dernière remise de
     * fidélité. S'ajoute à une requête sur `clients`.
     */
    public static function colonneAchats(): Expression
    {
        return DB::raw(<<<'SQL'
            (SELECT COUNT(*) FROM ventes v
             WHERE v.client_id = clients.id AND v.statut = 'validee' AND v.remise_fidelite = 0
               AND v.created_at > COALESCE((SELECT MAX(r.created_at) FROM ventes r
                   WHERE r.client_id = clients.id AND r.statut = 'validee' AND r.remise_fidelite = 1), '1970-01-01')
            ) AS fidelite_achats
            SQL);
    }
}
