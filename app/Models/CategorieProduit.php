<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoutique;
use Database\Factories\CategorieProduitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nom', 'couleur', 'ordre'])]
class CategorieProduit extends Model
{
    /** @use HasFactory<CategorieProduitFactory> */
    use BelongsToBoutique, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'categories_produits';

    /**
     * Couleurs proposées (on en coche une) : les mêmes que dans l'application
     * (lib/models/categorie.dart, couleursCategorie). Un sélecteur libre
     * (dégradé, R G B) ne parlait pas aux commerçants.
     */
    public const COULEURS = [
        '#1D4E89' => 'Bleu',
        '#0B6E4F' => 'Vert',
        '#CA6702' => 'Orange',
        '#9B2226' => 'Rouge',
        '#6A4C93' => 'Violet',
        '#E3A008' => 'Jaune',
        '#2A9D8F' => 'Turquoise',
        '#D61F69' => 'Rose',
        '#7A3E06' => 'Marron',
        '#495057' => 'Gris',
    ];

    /** Pour une nouvelle catégorie : la première couleur qu'aucune autre n'a encore. */
    public static function couleurLibre(): string
    {
        $prises = static::query()->pluck('couleur')->map(fn ($c) => strtoupper((string) $c))->all();

        return collect(array_keys(self::COULEURS))->first(fn (string $c) => ! in_array($c, $prises, true)) ?? array_key_first(self::COULEURS);
    }

    /**
     * @return HasMany<Produit, $this>
     */
    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }
}
