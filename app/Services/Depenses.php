<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Depense;
use App\Models\PaiementFournisseur;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Les dépenses de la boutique, et le bilan : ce qui est entré dans la caisse,
 * ce qui en est sorti, ce qui reste. Toutes les activités, toutes les offres.
 */
class Depenses
{
    public function __construct(private readonly SessionCaisseService $sessions, private readonly Rapports $rapports) {}

    /** @param  array{libelle: string, categorie: string, montant: int, moyen_paiement?: ?string, jour?: ?string}  $data */
    public function depenser(array $data, User $auteur): Depense
    {
        return Depense::create([
            'user_id' => $auteur->id,
            // Payée maintenant : elle sort du tiroir de la séance en cours.
            'session_caisse_id' => $this->sessions->courante($auteur)?->id,
            'libelle' => trim($data['libelle']),
            'categorie' => $data['categorie'],
            'montant' => (int) $data['montant'],
            'moyen_paiement' => $data['moyen_paiement'] ?? 'especes',
            'jour' => $data['jour'] ?? now()->toDateString(),
        ]);
    }

    /**
     * Recettes (l'argent réellement reçu : ventes payées, acomptes, crédits
     * remboursés), dépenses par catégorie, paiements aux fournisseurs, et ce
     * qui reste.
     *
     * @return array<string, mixed>
     */
    public function bilan(Carbon $du, Carbon $au): array
    {
        $debut = $du->copy()->startOfDay();
        $fin = $au->copy()->endOfDay();
        $encaisse = $this->rapports->periode($debut, $fin)['encaisse'];
        $recettes = (int) $encaisse['total'];

        // whereDate : la date est gardée avec une heure (00:00:00), une comparaison de texte la manquerait.
        $parCategorie = Depense::whereDate('jour', '>=', $debut->toDateString())->whereDate('jour', '<=', $fin->toDateString())
            ->selectRaw('categorie, SUM(montant) as total')->groupBy('categorie')->pluck('total', 'categorie');
        $depenses = (int) $parCategorie->sum();
        $fournisseurs = (int) PaiementFournisseur::whereBetween('created_at', [$debut, $fin])->sum('montant');

        return [
            'du' => $debut->toDateString(),
            'au' => $fin->toDateString(),
            'recettes' => [
                'total' => $recettes,
                'ventes' => (int) $encaisse['ventes'],
                'acomptes' => (int) ($encaisse['acomptes'] ?? 0),
                'remboursements' => (int) $encaisse['remboursements'],
            ],
            'depenses' => [
                'total' => $depenses,
                'par_categorie' => collect(Depense::CATEGORIES)
                    ->map(fn (string $libelle, string $code) => ['categorie' => $code, 'libelle' => $libelle, 'total' => (int) ($parCategorie[$code] ?? 0)])
                    ->filter(fn (array $c) => $c['total'] > 0)->sortByDesc('total')->values(),
            ],
            // Commerce : la marchandise payée aux fournisseurs sort aussi de la caisse.
            'fournisseurs' => $fournisseurs,
            'resultat' => $recettes - $depenses - $fournisseurs,
        ];
    }
}
