<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Models\Boutique;
use App\Models\User;
use App\Support\Phone\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Son propre compte, depuis l'application comme depuis le web : nom,
 * téléphone, e-mail, mot de passe. Jamais le rôle, qui reste l'affaire du
 * titulaire de la boutique (EquipeService).
 */
class MonCompte
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  array<string, mixed>  $donnees  name, telephone, email */
    public function modifierProfil(User $user, array $donnees): User
    {
        $data = Validator::make($donnees, [
            'name' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ], [], ['name' => 'nom', 'telephone' => 'téléphone', 'email' => 'e-mail'])->validate();

        // Le numéro se lit dans le pays de la boutique, comme à l'inscription ;
        // il sert d'identifiant de connexion : deux comptes ne le partagent pas.
        $telephone = PhoneNumber::normalize($data['telephone'], $this->pays($user));
        if (User::where('phone', $telephone)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['telephone' => ['Ce numéro est déjà celui d’un autre compte.']]);
        }

        $user->update([
            'name' => trim($data['name']),
            'phone' => $telephone,
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
        ]);

        return $user->fresh();
    }

    /**
     * Nouveau mot de passe, l'actuel à l'appui. Les appareils connectés au
     * compte sont déconnectés, sauf `$jetonConserve` (celui qui fait la demande
     * depuis l'application) : un mot de passe changé parce qu'il a fuité ne doit
     * pas laisser une session ouverte ailleurs.
     *
     * @param  array<string, mixed>  $donnees  mot_de_passe_actuel, mot_de_passe, mot_de_passe_confirmation
     */
    public function changerMotDePasse(User $user, array $donnees, ?int $jetonConserve = null): void
    {
        $data = Validator::make($donnees, [
            'mot_de_passe_actuel' => ['required', 'string'],
            'mot_de_passe' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'mot_de_passe.min' => 'Au moins 8 caractères.',
            'mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ], ['mot_de_passe_actuel' => 'mot de passe actuel', 'mot_de_passe' => 'nouveau mot de passe'])->validate();

        if (! Hash::check($data['mot_de_passe_actuel'], $user->password)) {
            throw ValidationException::withMessages(['mot_de_passe_actuel' => ['Mot de passe actuel incorrect.']]);
        }

        $user->forceFill(['password' => $data['mot_de_passe']])->save();
        $user->tokens()->when($jetonConserve !== null, fn ($q) => $q->whereKeyNot($jetonConserve))->delete();
    }

    /** Pays de la boutique active, à défaut de la boutique du compte (l'exploitant n'en a pas). */
    private function pays(User $user): Country
    {
        $boutique = $this->tenant->boutiqueId() ?? $user->boutique_id;

        return Country::tryFrom((string) Boutique::withoutGlobalScopes()->whereKey($boutique)->value('pays')) ?? Country::default();
    }
}
