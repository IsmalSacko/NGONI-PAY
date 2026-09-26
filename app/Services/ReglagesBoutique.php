<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Models\Boutique;
use App\Support\Money\Currencies;
use App\Support\Money\Montant;
use App\Support\Money\Reechelonnement;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Réglages d'une boutique : nom, pays, devise, coordonnées. Pour l'app comme
 * pour le back-office, réservé à qui a `boutique.update` (l'admin).
 *
 * La devise suit le pays sauf choix contraire (boutique au Mali tenue par un
 * commerçant joignable en France : pays ML, devise XOF). Les montants ne sont
 * pas convertis au taux de change : ils gardent leur valeur affichée.
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
        $devise = $data['devise'] ?? $pays->currency();

        // Même valeur affichée dans la nouvelle devise : 25 000 F deviennent
        // 25 000,00 € (montants stockés en unités mineures).
        Reechelonnement::entreDevises($boutique->id, (string) $boutique->devise, $devise);
        Montant::oublier();

        $boutique->update([
            'nom' => $data['nom'],
            'pays' => $pays->value,
            'devise' => $devise,
            'telephone' => filled($data['telephone'] ?? null) ? PhoneNumber::normalize((string) $data['telephone'], $pays) : null,
            'email' => $data['email'] ?? null,
            'adresse' => $data['adresse'] ?? null,
        ]);

        return $boutique->fresh();
    }
}
