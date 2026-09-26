<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appareil extends Model
{
    protected $fillable = ['user_id', 'jeton', 'plateforme'];
}
