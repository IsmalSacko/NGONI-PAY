<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Fournisseur;
use App\Support\Money\Montant;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Fiche d'un fournisseur, la même depuis l'application et le back-office :
 * nom et téléphone (pour lui envoyer les commandes sur WhatsApp).
 */
class Fournisseurs
{
    /** @param  array<string, mixed>  $donnees */
    public function modifier(Fournisseur $fournisseur, array $donnees): Fournisseur
    {
        $data = Validator::make($donnees, [
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $fournisseur->update($data);

        return $fournisseur->fresh();
    }

    /**
     * Retiré de la liste ; ses achats passés restent dans l'historique. Tant que
     * la boutique lui doit de l'argent, il reste : la dette disparaîtrait avec lui.
     */
    public function retirer(Fournisseur $fournisseur): void
    {
        if (($du = $fournisseur->soldeDu()) > 0) {
            throw ValidationException::withMessages([
                'fournisseur' => ['Vous lui devez encore '.Montant::format($du).' : réglez-le avant de le retirer.'],
            ]);
        }
        $fournisseur->delete();
    }
}
