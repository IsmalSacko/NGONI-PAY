<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use App\Services\Images;
use App\Services\ModeLibre;
use App\Services\NumerotationFactures;
use Database\Factories\BoutiqueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Racine du multi-tenant : un commerçant qui utilise e-caisse.
 *
 * Entité racine — n'utilise pas {@see BelongsToBoutique}
 * pour la même raison que {@see User} : elle EST le tenant, elle ne lui
 * appartient pas.
 */
#[Fillable(['proprietaire_id', 'nom', 'pays', 'devise', 'telephone', 'email', 'adresse', 'logo', 'identifiant_fiscal', 'rccm', 'message_ticket', 'facture_prefixe', 'facture_suffixe', 'facture_annee', 'mode_rodage'])]
class Boutique extends Model
{
    /** Adresse du logo, pour l'application et les tickets. */
    protected $appends = ['logo_url', 'logo_vignette_url', 'prochaine_facture', 'mode_libre_actif'];

    /** Les compteurs restent au serveur : l'application reçoit `prochaine_facture`. */
    protected $hidden = ['compteurs_facture'];

    /**
     * Activité choisie dans les réglages : la pharmacie, le pressing et le
     * restaurant adaptent la caisse.
     */
    public const ACTIVITES = ['commerce', 'pharmacie', 'pressing', 'restaurant'];

    /** Vos ventes : au détail, au détail et en gros, en gros uniquement. */
    public const MODES_VENTE = ['detail', 'detail_gros', 'gros'];

    public function venteEnGros(): bool
    {
        return $this->mode_vente === 'detail_gros';
    }

    public function estPharmacie(): bool
    {
        return $this->activite === 'pharmacie';
    }

    public function estPressing(): bool
    {
        return $this->activite === 'pressing';
    }

    public function estRestaurant(): bool
    {
        return $this->activite === 'restaurant';
    }

    /**
     * Un pressing vend des prestations (repassage, lavage…) : rien à compter
     * en stock, ni rupture ni seuil d'alerte. Un restaurant non plus : un plat
     * ne se compte pas, ce sont ses ingrédients qui se suivent (à part).
     */
    public function suitLeStock(): bool
    {
        return ! $this->estPressing() && ! $this->estRestaurant();
    }

    /** Au passage en pressing : les services courants, si la boutique n'en a aucun. */
    public function preparerPressing(): void
    {
        if (! $this->estPressing() || ServicePressing::withoutBoutiqueScope()->where('boutique_id', $this->id)->exists()) {
            return;
        }
        foreach (ServicePressing::PAR_DEFAUT as $i => $nom) {
            (new ServicePressing(['nom' => $nom, 'ordre' => $i]))->forceFill(['boutique_id' => $this->id])->save();
        }
    }

    /** Catégories d'une carte de restaurant, et celles que prépare le bar. */
    public const CATEGORIES_RESTAURANT = [
        'Entrées', 'Poulet / Viandes', 'Poissons', 'Plats africains', 'Sauces', 'Grillades',
        'Accompagnements', 'Boissons', 'Jus naturels', 'Desserts',
    ];

    public const CATEGORIES_BAR = ['Boissons', 'Jus naturels'];

    /**
     * Au passage en restaurant : les catégories d'une carte (celles qui
     * manquent), et le bar pour les boissons. Rien ne change pour les autres.
     */
    public function preparerRestaurant(): void
    {
        if (! $this->estRestaurant()) {
            return;
        }
        $existantes = CategorieProduit::withoutBoutiqueScope()->where('boutique_id', $this->id)->pluck('id', 'nom');
        $ordre = (int) CategorieProduit::withoutBoutiqueScope()->where('boutique_id', $this->id)->max('ordre');
        foreach (self::CATEGORIES_RESTAURANT as $nom) {
            $id = $existantes[$nom] ?? null;
            if ($id === null) {
                $categorie = (new CategorieProduit(['nom' => $nom, 'ordre' => ++$ordre]))->forceFill(['boutique_id' => $this->id]);
                $categorie->save();
                $id = $categorie->id;
            }
            if (in_array($nom, self::CATEGORIES_BAR, true)
                && ! PosteRestaurant::withoutBoutiqueScope()->where('categorie_produit_id', $id)->exists()) {
                (new PosteRestaurant(['categorie_produit_id' => $id, 'poste' => PosteRestaurant::BAR]))->forceFill(['boutique_id' => $this->id])->save();
            }
        }
    }

    protected function casts(): array
    {
        return [
            'vente_commence_en_gros' => 'boolean',
            'objectif_mensuel' => 'integer',
            'fidelite_seuil' => 'integer',
            'fidelite_remise_pct' => 'integer',
            'facture_annee' => 'boolean',
            'compteurs_facture' => 'array',
            'mode_rodage' => 'boolean',
            'mode_libre' => 'boolean',
        ];
    }

    /** Le numéro que portera la prochaine facture (aperçu dans Ma boutique). */
    public function getProchaineFactureAttribute(): string
    {
        return app(NumerotationFactures::class)->apercu($this);
    }

    /** Mode libre actif, accepté dans la version en vigueur du texte. */
    public function getModeLibreActifAttribute(): bool
    {
        return ModeLibre::actif($this);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? route('image.logo', ['boutique' => $this->id, 'v' => Images::version($this->logo)]) : null;
    }

    /** Logo réduit, pour l'affichage à l'écran ; l'impression garde la taille réelle. */
    public function getLogoVignetteUrlAttribute(): ?string
    {
        return $this->logo ? route('image.logo.vignette', ['boutique' => $this->id, 'v' => Images::version($this->logo)]) : null;
    }

    /** @use HasFactory<BoutiqueFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Compte propriétaire : il porte l'abonnement de toutes ses boutiques.
     *
     * @return BelongsTo<User, $this>
     */
    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proprietaire_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<CategorieProduit, $this>
     */
    public function categoriesProduits(): HasMany
    {
        return $this->hasMany(CategorieProduit::class);
    }

    /**
     * @return HasMany<Produit, $this>
     */
    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return HasMany<Vente, $this>
     */
    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }
}
