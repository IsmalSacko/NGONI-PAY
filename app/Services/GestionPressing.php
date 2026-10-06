<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\CommandePressing;
use App\Models\DepensePressing;
use App\Models\ForfaitPressing;
use App\Models\FourniturePressing;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pressing, offre Pro : dépenses, fournitures, forfaits clients et relevé
 * mensuel des comptes clients (entreprises). Réservé aux pressings.
 */
class GestionPressing
{
    public function __construct(private readonly CommandesPressing $commandes, private readonly SessionCaisseService $sessions) {}

    /** Pressing et offre Pro, sinon refus. */
    public function exiger(): void
    {
        $this->commandes->exigerPro($this->commandes->boutique(), 'La gestion du pressing (dépenses, fournitures, forfaits) fait partie de l’offre Pro.');
    }

    /** @param  array{libelle: string, categorie: string, montant: int, moyen_paiement?: ?string, jour?: ?string}  $data */
    public function depenser(array $data, User $auteur): DepensePressing
    {
        return DepensePressing::create([
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
     * Entrée (achat, livraison) ou sortie (utilisation) d'une fourniture.
     * Un achat payé ([montant]) devient aussi une dépense.
     */
    public function mouvement(FourniturePressing $f, float $quantite, ?int $montant, ?string $moyen, User $auteur): FourniturePressing
    {
        return DB::transaction(function () use ($f, $quantite, $montant, $moyen, $auteur): FourniturePressing {
            $f = FourniturePressing::whereKey($f->id)->lockForUpdate()->firstOrFail();
            if ($f->quantite + $quantite < 0) {
                throw ValidationException::withMessages(['quantite' => ["Il ne reste que {$f->quantite} {$f->unite}."]]);
            }
            $f->update(['quantite' => $f->quantite + $quantite]);
            if ($quantite > 0 && $montant !== null && $montant > 0) {
                $this->depenser(['libelle' => "Achat : {$f->nom}", 'categorie' => 'fournitures', 'montant' => $montant, 'moyen_paiement' => $moyen], $auteur);
            }

            return $f;
        });
    }

    /**
     * Vend un forfait : la vente est encaissée tout de suite, le client
     * dispose de [pieces] pièces jusqu'à [fin].
     *
     * @param  array{client_id: string, libelle: string, pieces: int, prix: int, fin: string, moyen_paiement: string}  $data
     */
    public function vendreForfait(array $data, User $caissier): ForfaitPressing
    {
        if (! Client::whereKey($data['client_id'])->exists()) {
            throw ValidationException::withMessages(['client_id' => ['Choisissez le client.']]);
        }

        return DB::transaction(function () use ($data, $caissier): ForfaitPressing {
            $vente = app(VenteService::class)->encaisser([
                'client_id' => $data['client_id'],
                'lignes' => [['libelle' => "Forfait : {$data['libelle']} ({$data['pieces']} pièces)", 'prix_unitaire' => (int) $data['prix'], 'quantite' => 1, 'taux_tva' => 0]],
                'moyen_paiement' => $data['moyen_paiement'],
            ], $caissier);

            return ForfaitPressing::create([
                'client_id' => $data['client_id'], 'vente_id' => $vente->id, 'libelle' => trim($data['libelle']),
                'pieces' => (int) $data['pieces'], 'prix' => (int) $data['prix'], 'debut' => now()->toDateString(), 'fin' => $data['fin'],
            ]);
        });
    }

    /**
     * Relevé d'un client pour un mois (comptes entreprises) : ses commandes
     * retirées, leur total, et ce qu'il doit encore aujourd'hui.
     *
     * @return array<string, mixed>
     */
    public function releve(Client $client, Carbon $mois): array
    {
        $debut = $mois->copy()->startOfMonth();
        $fin = $mois->copy()->endOfMonth();
        $commandes = CommandePressing::with('lignes')->where('client_id', $client->id)
            ->where('statut', CommandePressing::RETIREE)->whereBetween('retiree_le', [$debut, $fin])
            ->orderBy('retiree_le')->get();
        $ventes = Vente::whereIn('id', $commandes->pluck('vente_id')->filter())->get()->keyBy('id');

        return [
            'client' => ['id' => $client->id, 'nom' => $client->nom, 'telephone' => $client->telephone],
            'du' => $debut->toDateString(),
            'au' => $fin->toDateString(),
            'commandes' => $commandes->map(fn (CommandePressing $c) => [
                'numero' => $c->numeroLisible(), 'retiree_le' => $c->retiree_le?->toIso8601String(), 'pieces' => (int) $c->lignes->sum('quantite'),
                'total' => $c->total, 'paye' => (int) ($ventes[$c->vente_id]?->montant_paye ?? $c->total), 'reste' => (int) ($ventes[$c->vente_id]?->reste_du ?? 0),
            ])->values(),
            'total' => (int) $commandes->sum('total'),
            'reste_du_mois' => (int) $ventes->sum('reste_du'),
            // Toute sa dette, ce mois et les autres (les règlements la font baisser).
            'solde_du' => $client->soldeDu(),
        ];
    }
}
