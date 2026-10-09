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
use Illuminate\Validation\ValidationException;

/**
 * Réglages d'une boutique : nom, pays, devise, coordonnées. Pour l'app comme
 * pour le back-office, réservé à qui a `boutique.update` (l'admin).
 *
 * La devise suit le pays sauf choix contraire (boutique au Mali tenue par un
 * commerçant joignable en France : pays ML, devise XOF). Au changement de
 * devise, les montants sont convertis : au taux fixe pour franc CFA ↔ euro
 * (655,957), au taux indiqué sinon — ou gardés tels quels sur demande.
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
            // Mentions du ticket.
            'identifiant_fiscal' => ['nullable', 'string', 'max:60'],
            'rccm' => ['nullable', 'string', 'max:60'],
            'message_ticket' => ['nullable', 'string', 'max:160'],
            // Numéro de facture : PRÉFIXE-2026-0001-SUFFIXE (lettres et chiffres).
            'facture_prefixe' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'facture_suffixe' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'facture_annee' => ['nullable', 'boolean'],
            'facture_prochain_numero' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            // Changement de devise : convertir (par défaut, au taux fixe s'il
            // existe, sinon au taux donné) ou garder les mêmes nombres.
            'convertir' => ['nullable', 'boolean'],
            'taux' => ['nullable', 'numeric', 'gt:0'],
        ], [
            'facture_prefixe.regex' => 'Le préfixe ne garde que des lettres et des chiffres.',
            'facture_suffixe.regex' => 'Le suffixe ne garde que des lettres et des chiffres.',
        ], ['devise' => 'devise', 'pays' => 'pays', 'taux' => 'taux de conversion', 'facture_prefixe' => 'préfixe', 'facture_suffixe' => 'suffixe'])->validate();

        $pays = Country::from($data['pays']);
        $devise = $data['devise'] ?? $pays->currency();

        $ancienne = (string) $boutique->devise;
        if ($ancienne !== $devise) {
            $taux = null;
            if ($data['convertir'] ?? true) {
                $taux = isset($data['taux']) ? (float) $data['taux'] : Reechelonnement::tauxFixe($ancienne, $devise);
                if ($taux === null) {
                    throw ValidationException::withMessages(['taux' => [
                        "Indiquez combien de {$ancienne} vaut 1 {$devise}, ou choisissez de garder les mêmes montants.",
                    ]]);
                }
            }
            // Sans conversion : même valeur affichée (25 000 F → 25 000,00 €).
            Reechelonnement::entreDevises($boutique->id, $ancienne, $devise, $taux);
        }
        Montant::oublier();

        $boutique->update([
            'nom' => $data['nom'],
            'pays' => $pays->value,
            'devise' => $devise,
            'telephone' => filled($data['telephone'] ?? null) ? PhoneNumber::normalize((string) $data['telephone'], $pays) : null,
            'email' => $data['email'] ?? null,
            'adresse' => $data['adresse'] ?? null,
            // Absents de la requête (application plus ancienne) : inchangés.
            'identifiant_fiscal' => array_key_exists('identifiant_fiscal', $donnees) ? ($data['identifiant_fiscal'] ?? null) : $boutique->identifiant_fiscal,
            'rccm' => array_key_exists('rccm', $donnees) ? ($data['rccm'] ?? null) : $boutique->rccm,
            'message_ticket' => array_key_exists('message_ticket', $donnees) ? ($data['message_ticket'] ?? null) : $boutique->message_ticket,
            'facture_prefixe' => array_key_exists('facture_prefixe', $donnees) ? (strtoupper((string) ($data['facture_prefixe'] ?? '')) ?: null) : $boutique->facture_prefixe,
            'facture_suffixe' => array_key_exists('facture_suffixe', $donnees) ? (strtoupper((string) ($data['facture_suffixe'] ?? '')) ?: null) : $boutique->facture_suffixe,
            'facture_annee' => array_key_exists('facture_annee', $donnees) ? (bool) ($data['facture_annee'] ?? true) : $boutique->facture_annee,
        ]);

        // Le prochain numéro vaut pour la série choisie ci-dessus (préfixe, suffixe, année).
        if (filled($data['facture_prochain_numero'] ?? null)) {
            app(NumerotationFactures::class)->fixerProchain($boutique->fresh(), (int) $data['facture_prochain_numero']);
        }

        return $boutique->fresh();
    }
}
