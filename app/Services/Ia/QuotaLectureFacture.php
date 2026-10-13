<?php

declare(strict_types=1);

namespace App\Services\Ia;

use App\Models\Boutique;
use App\Services\AbonnementService;
use Illuminate\Support\Facades\DB;

/**
 * « Ma facture » : combien de factures un commerçant peut faire lire ce mois-ci.
 * Le quota suit l'offre en cours du titulaire (config/ia.php) et vaut pour
 * toutes ses boutiques ; une offre terminée n'en donne plus.
 */
final class QuotaLectureFacture
{
    public function __construct(private readonly AbonnementService $abonnements) {}

    public function parMois(Boutique $boutique): int
    {
        $abonnement = $this->abonnements->pourBoutique($boutique);
        if ($abonnement === null || ! $abonnement->estEnCours()) {
            return 0;
        }

        return (int) (config('ia.lectures_facture_par_mois')[$abonnement->plan] ?? 0);
    }

    public function utilisees(Boutique $boutique): int
    {
        return DB::table('lectures_facture')
            ->where('proprietaire_id', $boutique->proprietaire_id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function restantes(Boutique $boutique): int
    {
        return max(0, $this->parMois($boutique) - $this->utilisees($boutique));
    }

    public function compter(Boutique $boutique, string $userId): void
    {
        DB::table('lectures_facture')->insert([
            'proprietaire_id' => $boutique->proprietaire_id,
            'boutique_id' => $boutique->id,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    /** @return array{par_mois: int, restantes: int, renouvellement: string} */
    public function etat(Boutique $boutique): array
    {
        return [
            'par_mois' => $this->parMois($boutique),
            'restantes' => $this->restantes($boutique),
            'renouvellement' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
        ];
    }
}
