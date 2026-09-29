<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['boutique_id', 'name', 'phone', 'email', 'password', 'is_active'])]
// Présence (vu_*) : pour la console, pas pour les réponses de l'application.
#[Hidden(['password', 'remember_token', 'vu_le', 'vu_plateforme', 'vu_modele', 'vu_version', 'vu_boutique_id', 'parraine_par', 'parrainage_recompense_le'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'est_admin_plateforme' => 'boolean',
            'vu_le' => 'datetime',
            'parrainage_recompense_le' => 'datetime',
        ];
    }

    /**
     * Qui a parrainé ce compte (code saisi à l'inscription).
     *
     * @return BelongsTo<User, $this>
     */
    public function parrain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parraine_par');
    }

    /**
     * @return BelongsTo<Boutique, $this>
     */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    /**
     * Boutiques où ce compte a un rôle (admin, gérant ou caissier).
     *
     * L'appartenance EST le rôle : Spatie range les rôles par boutique
     * (équipe), si bien qu'un même compte peut être admin de sa boutique et
     * caissier d'une autre.
     *
     * @return list<string>
     */
    public function boutiqueIds(): array
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', $this->getMorphClass())
            ->where(config('permission.column_names.model_morph_key'), $this->getKey())
            ->distinct()
            ->pluck(config('permission.column_names.team_foreign_key'))
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Boutiques où ce compte a accès au back-office (admin ou gérant).
     *
     * @return list<string>
     */
    public function boutiquesBackOffice(): array
    {
        return DB::table(config('permission.table_names.model_has_roles').' as mhr')
            ->join(config('permission.table_names.roles').' as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $this->getMorphClass())
            ->where('mhr.'.config('permission.column_names.model_morph_key'), $this->getKey())
            ->whereIn('r.name', ['admin', 'gerant'])
            ->pluck('mhr.'.config('permission.column_names.team_foreign_key'))
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Peut ouvrir une nouvelle boutique : un compte sans boutique (nouveau
     * commerçant) ou admin d'au moins une. Un caissier ou un gérant, non.
     */
    public function peutOuvrirBoutique(): bool
    {
        if ($this->boutiqueIds() === []) {
            return true;
        }

        return DB::table(config('permission.table_names.model_has_roles').' as mhr')
            ->join(config('permission.table_names.roles').' as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $this->getMorphClass())
            ->where('mhr.'.config('permission.column_names.model_morph_key'), $this->getKey())
            ->where('r.name', 'admin')
            ->exists();
    }

    public function appartientA(?string $boutiqueId): bool
    {
        return $boutiqueId !== null && in_array($boutiqueId, $this->boutiqueIds(), true);
    }

    /**
     * Boutiques dont ce compte est propriétaire.
     *
     * @return HasMany<Boutique, $this>
     */
    public function boutiquesPossedees(): HasMany
    {
        return $this->hasMany(Boutique::class, 'proprietaire_id');
    }

    /**
     * Recherche de la console : nom, téléphone, e-mail, ou nom d'une boutique
     * — possédée ou où le compte travaille. L'exploitant cherche souvent la
     * boutique (« Sacko multi Services ») et non la personne (« Sacko
     * Fassely ») : une recherche qui ignorait les boutiques ne trouvait rien.
     *
     * @param  Builder<User>  $query
     */
    public function scopeRecherche(Builder $query, string $terme): void
    {
        $motif = '%'.mb_strtolower(trim($terme)).'%';

        $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(name) LIKE ?', [$motif])
            ->orWhereRaw('LOWER(phone) LIKE ?', [$motif])
            ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$motif])
            ->orWhereHas('boutiquesPossedees', fn ($b) => $b->whereRaw('LOWER(nom) LIKE ?', [$motif]))
            ->orWhereHas('boutique', fn ($b) => $b->whereRaw('LOWER(nom) LIKE ?', [$motif])));
    }

    /**
     * Abonnement porté par ce compte, s'il est propriétaire.
     *
     * @return HasOne<Abonnement, $this>
     */
    public function abonnement(): HasOne
    {
        return $this->hasOne(Abonnement::class);
    }
}
