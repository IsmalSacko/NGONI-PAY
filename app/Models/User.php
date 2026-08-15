<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    // Rôles applicatifs (colonne `role`).
    public const ROLE_OWNER = 'owner';
    public const ROLE_STAFF = 'staff';
    public const ROLE_SYSTEM_ADMIN = 'system_admin';

    /**
     * Super-administrateur : accès total, ignore toute restriction d'abonnement.
     */
    public function isSystemAdmin(): bool
    {
        return $this->role === self::ROLE_SYSTEM_ADMIN;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'is_active',
        'avatar_url',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array  // cast ici signifie que les attributs spécifiés seront automatiquement convertis en types de données spécifiques lors de l'accès ou de la modification.
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'owner_id'); // c'est la relation propriétaire → entreprise
    }

    // Staff → businesses
    public function workingBusinesses() // utilisateurs qui travaillent dans plusieurs entreprises
    {
        return $this->belongsToMany(Business::class, 'business_users')->withPivot('role')->withTimestamps();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function campaignSends()
    {
        return $this->hasMany(CampaignSend::class);
    }
}
