<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Models\Boutique;
use App\Support\Money\Currencies;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Réglages d'une boutique : nom, pays, devise, coordonnées. Pour l'app comme
 * pour le back-office, réservé à qui a `boutique.update` (l'admin).
 *
 * La devise suit le pays sauf choix contraire (boutique au Mali tenue par un
 * commerçant joignable en France : pays ML, devise XOF). Les montants déjà
 * enregistrés ne sont pas convertis : ils sont stockés en unités entières.
 */
class ReglagesBoutique
{
    /** @param  array<string, mixed>  $donnees */
    public function mettreAJour(Boutique $boutique, array $donnees): Boutique
    {
        $data = Validator::make($donnees, [
            'nom' => ['required', 'string', 'max:255'],
            'pays' => ['required', 'string', Rule::in(array_map(fn (Country $c) => $c->value, Country::cases()))],
            'devise' => ['nullable', 'string', Rule::in(Currencies::codes())],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ], [], ['devise' => 'devise', 'pays' => 'pays'])->validate();

        $pays = Country::from($data['pays']);

        $boutique->update([
            'nom' => $data['nom'],
            'pays' => $pays->value,
            'devise' => $data['devise'] ?? $pays->currency(),
            'telephone' => filled($data['telephone'] ?? null) ? PhoneNumber::normalize((string) $data['telephone'], $pays) : null,
            'email' => $data['email'] ?? null,
            'adresse' => $data['adresse'] ?? null,
        ]);

        return $boutique->fresh();
    }
}
