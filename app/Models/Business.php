<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Business extends Model
{
    // Factory and other model properties can be defined here
    use HasFactory;
    protected $fillable = [
        'owner_id',
        'name',
        'type',
        'address',
        'phone',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function staff()
    {
        // ici pivot veut dire la table intermédiaire entre Business et User
        // 'business_users' est le nom de la table pivot qui contient les relations entre les entreprises et les utilisateurs
        return $this->belongsToMany(User::class, 'business_users')->withPivot('role')->withTimestamps();
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }
}
