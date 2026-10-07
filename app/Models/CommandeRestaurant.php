<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commande d'un restaurant : à table, à emporter ou en livraison, prise en
 * salle ou par téléphone. Ses lignes passent en cuisine ; la vente naît à
 * l'addition (une ou plusieurs, si l'addition est partagée).
 */
#[Fillable([
    'boutique_id', 'numero', 'reference_locale', 'type', 'telephone', 'table', 'couverts', 'client_id', 'adresse', 'heure_prevue', 'envoi_prevu_le', 'entree_file_le', 'prete_vers', 'en_livraison_le',
    'user_id', 'statut', 'total', 'paye', 'acompte', 'moyen_acompte', 'pourboire', 'envois', 'payee_le', 'terminee_le',
    'annulee_le', 'motif_annulation', 'notes', 'historique',
])]
class CommandeRestaurant extends Model
{
    use BelongsToBoutique, HasUuids;

    public const OUVERTE = 'ouverte';

    public const PAYEE = 'payee';

    public const ANNULEE = 'annulee';

    public const TYPES = ['sur_place', 'emporter', 'livraison'];

    /** Une commande différée part en cuisine ce temps avant son heure. */
    public const MINUTES_AVANT_DIFFEREE = 30;

    protected $table = 'commandes_restaurant';

    protected function casts(): array
    {
        return [
            'numero' => 'integer', 'telephone' => 'boolean', 'couverts' => 'integer', 'total' => 'integer', 'paye' => 'integer',
            'acompte' => 'integer', 'pourboire' => 'integer', 'envois' => 'integer', 'historique' => 'array',
            'heure_prevue' => 'datetime', 'envoi_prevu_le' => 'datetime', 'entree_file_le' => 'datetime', 'prete_vers' => 'datetime', 'en_livraison_le' => 'datetime', 'payee_le' => 'datetime', 'terminee_le' => 'datetime', 'annulee_le' => 'datetime',
        ];
    }

    /** Ce qu'il reste à payer : le total, moins l'acompte et ce qui est déjà réglé. */
    public function reste(): int
    {
        return max(0, $this->total - $this->acompte - $this->paye);
    }

    /**
     * Où en est la commande, dans l'ordre de la chaîne : enregistree (prise,
     * pas encore partie) → en_attente (reçue en cuisine) → en_preparation →
     * prete → en_livraison (livraison) → servie (ou remise, livrée) →
     * terminee (servie et payée) ; ou annulee. Avec plusieurs envois, c'est
     * le plat le moins avancé qui compte : rien n'est servi tant qu'il manque.
     */
    public function etape(): string
    {
        if ($this->statut === self::ANNULEE) {
            return 'annulee';
        }
        if ($this->terminee_le !== null) {
            return 'terminee';
        }
        // Différée (appel à 20 h pour 22 h) : elle attend son heure pour partir en cuisine.
        if ($this->envoi_prevu_le !== null) {
            return 'differee';
        }
        $ordre = [
            LigneCommandeRestaurant::ATTENTE => 'enregistree',
            LigneCommandeRestaurant::EN_CUISINE => 'en_attente',
            LigneCommandeRestaurant::EN_PREPARATION => 'en_preparation',
            LigneCommandeRestaurant::PRETE => 'prete',
        ];
        $etats = $this->lignes->where('etat', '!=', LigneCommandeRestaurant::ANNULEE)->pluck('etat');
        foreach ($ordre as $etat => $etape) {
            if ($etats->contains($etat)) {
                return $etape === 'prete' && $this->en_livraison_le !== null ? 'en_livraison' : $etape;
            }
        }

        return $etats->isEmpty() ? 'enregistree' : 'servie';
    }

    /** Le paiement, à part de l'avancement : non_payee, partielle (acompte, addition partagée), payee. */
    public function etatPaiement(): string
    {
        if ($this->total > 0 && $this->reste() === 0) {
            return 'payee';
        }

        return $this->paye > 0 || $this->acompte > 0 ? 'partielle' : 'non_payee';
    }

    /** Toujours à suivre : pas annulée, et pas à la fois payée et entièrement servie. */
    public function enCours(): bool
    {
        return $this->statut !== self::ANNULEE && $this->terminee_le === null;
    }

    /**
     * Ajoute un pas à l'historique : quoi, quand, par qui.
     *
     * @return list<array{quoi: string, le: string, par: ?string}>
     */
    public function avecPas(string $quoi, ?User $par): array
    {
        return [...($this->historique ?? []), ['quoi' => $quoi, 'le' => now()->toIso8601String(), 'par' => $par?->name]];
    }

    /** @return HasMany<LigneCommandeRestaurant, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeRestaurant::class, 'commande_id')->orderBy('ordre');
    }

    /** @return HasMany<EncaissementRestaurant, $this> */
    public function encaissements(): HasMany
    {
        return $this->hasMany(EncaissementRestaurant::class, 'commande_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** Le serveur qui a pris la commande. */
    public function serveur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
