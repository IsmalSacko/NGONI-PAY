<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Une vidéo d'aide (YouTube), proposée dans l'application. */
class Tutoriel extends Model
{
    /** Catégories, dans l'ordre des filtres de l'application. */
    public const CATEGORIES = [
        'ventes' => 'Ventes',
        'stock' => 'Stock',
        'clients' => 'Clients',
        'abonnement' => 'Abonnement',
        'restaurant' => 'Restaurant',
        'pressing' => 'Pressing',
        'pharmacie' => 'Pharmacie',
        'autre' => 'Autre',
    ];

    protected $fillable = ['titre', 'sous_titre', 'categorie', 'url', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'ordre' => 'integer'];
    }

    /**
     * Règles de saisie, communes à la console web et à celle de l'application.
     *
     * @return array<string, mixed>
     */
    public static function regles(bool $partiel = false): array
    {
        $requis = $partiel ? 'sometimes' : 'required';

        return [
            'titre' => [$requis, 'string', 'max:120'],
            'sous_titre' => ['nullable', 'string', 'max:160'],
            'categorie' => [$requis, \Illuminate\Validation\Rule::in(array_keys(self::CATEGORIES))],
            'url' => [$requis, 'url', 'max:255', function (string $attribut, mixed $valeur, \Closure $echec): void {
                if (self::idYoutube((string) $valeur) === null) {
                    $echec('Collez le lien d’une vidéo YouTube (youtube.com/watch?v=… ou youtu.be/…).');
                }
            }],
            'ordre' => ['sometimes', 'integer', 'min:0'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }

    /** Identifiant de la vidéo YouTube (youtu.be/…, watch?v=…, shorts/…, embed/…, live/…), ou null. */
    public static function idYoutube(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/))([A-Za-z0-9_-]{11})~', $url, $m) === 1 ? $m[1] : null;
    }

    /** @return array<string, mixed> */
    public function versApplication(): array
    {
        $id = self::idYoutube($this->url);

        return [
            'id' => $this->id,
            'titre' => $this->titre,
            'sous_titre' => $this->sous_titre,
            'categorie' => $this->categorie,
            'categorie_libelle' => self::CATEGORIES[$this->categorie] ?? $this->categorie,
            'url' => $this->url,
            'miniature' => $id === null ? null : "https://img.youtube.com/vi/{$id}/mqdefault.jpg",
            'ordre' => $this->ordre,
            'actif' => $this->actif,
        ];
    }
}
