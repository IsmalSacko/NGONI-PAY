<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Annonce extends Model
{
    public const TYPES = ['mise_a_jour' => 'Mise à jour', 'message' => 'Message libre', 'campagne' => 'Campagne'];

    public const AUDIENCES = [
        'tous' => 'Tous les comptes',
        'essai' => 'Essais en cours',
        'basic' => 'Abonnés Basic',
        'pro' => 'Abonnés Pro',
        'expires' => 'Essais et abonnements expirés',
        'selection' => 'Comptes choisis',
    ];

    public const RECURRENCES = ['' => 'Une seule fois', 'hebdomadaire' => 'Chaque semaine', 'mensuelle' => 'Chaque mois'];

    protected $fillable = [
        'type', 'titre', 'message', 'version', 'lien', 'audience', 'par_email', 'statut',
        'programmee_le', 'recurrence', 'derniere_diffusion', 'nb_notifies', 'nb_emails', 'nb_echecs', 'cree_par',
    ];

    protected function casts(): array
    {
        return [
            'par_email' => 'boolean',
            'programmee_le' => 'datetime',
            'derniere_diffusion' => 'datetime',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function cibles(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'annonce_cibles');
    }
}
