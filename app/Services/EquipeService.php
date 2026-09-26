<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Models\Boutique;
use App\Models\User;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Équipe d'une boutique, pour l'application comme pour le back-office.
 *
 * Un compte peut appartenir à plusieurs boutiques (son rôle y est rangé par
 * boutique). Ajouter un numéro qui a déjà un compte le rattache ; le retirer
 * ne touche qu'à cette boutique. Le propriétaire reste admin de sa boutique,
 * et personne ne change son propre rôle.
 */
class EquipeService
{
    public const ROLES = ['admin', 'gerant', 'caissier'];

    public function __construct(private readonly AbonnementService $abonnements) {}

    /** @return Collection<int, User> */
    public function membres(Boutique $boutique): Collection
    {
        $this->equipe($boutique);

        return User::whereIn('id', $this->membreIds($boutique))->with('roles')->orderBy('name')->get();
    }

    /**
     * Ajoute un membre. `mot_de_passe` du résultat : provisoire, généré pour un
     * compte créé sans mot de passe choisi, à transmettre une seule fois.
     *
     * @return array{user: User, cree: bool, mot_de_passe: ?string}
     */
    public function ajouter(Boutique $boutique, string $nom, string $telephone, string $role, ?string $motDePasse = null): array
    {
        $this->roleValide($role);

        if (! $this->abonnements->peutAjouterMembre($boutique, count($this->membreIds($boutique)))) {
            throw ValidationException::withMessages(['telephone' => [
                $this->abonnements->pourBoutique($boutique)?->estEnCours()
                    ? 'Votre plan ne permet pas d’autre membre dans cette boutique. Passez au plan supérieur.'
                    : 'Votre abonnement est terminé : abonnez-vous pour ajouter un membre.',
            ]]);
        }

        $pays = Country::tryFrom((string) $boutique->pays) ?? Country::default();
        $existant = User::withoutGlobalScopes()->whereIn('phone', PhoneNumber::candidates($telephone, $pays))->first();

        $this->equipe($boutique);

        if ($existant !== null) {
            if ($existant->appartientA($boutique->id)) {
                throw ValidationException::withMessages(['telephone' => ['Ce compte fait déjà partie de l’équipe.']]);
            }

            // Compte existant : rattaché, il garde son mot de passe.
            $existant->assignRole($role);

            return ['user' => $existant, 'cree' => false, 'mot_de_passe' => null];
        }

        $provisoire = $motDePasse === null || $motDePasse === '' ? Str::lower(Str::random(8)) : null;

        $user = User::create([
            'boutique_id' => $boutique->id,
            'name' => $nom,
            'phone' => PhoneNumber::normalize($telephone, $pays),
            'password' => Hash::make($provisoire ?? $motDePasse),
        ]);
        $user->assignRole($role);

        return ['user' => $user, 'cree' => true, 'mot_de_passe' => $provisoire];
    }

    public function changerRole(Boutique $boutique, User $acteur, string $userId, string $role): User
    {
        $this->roleValide($role);
        $user = $this->membre($boutique, $userId);

        if ($user->id === $acteur->id) {
            throw ValidationException::withMessages(['role' => ['Vous ne pouvez pas changer votre propre rôle.']]);
        }
        if ($user->id === $boutique->proprietaire_id) {
            throw ValidationException::withMessages(['role' => ['Le propriétaire reste administrateur de sa boutique.']]);
        }

        $this->equipe($boutique);
        $user->syncRoles([$role]);

        return $user->load('roles');
    }

    public function retirer(Boutique $boutique, User $acteur, string $userId): User
    {
        $user = $this->membre($boutique, $userId);

        if ($user->id === $acteur->id) {
            throw ValidationException::withMessages(['membre' => ['Vous ne pouvez pas vous retirer vous-même.']]);
        }
        if ($user->id === $boutique->proprietaire_id) {
            throw ValidationException::withMessages(['membre' => ['Le propriétaire ne peut pas être retiré de sa boutique.']]);
        }

        $this->equipe($boutique);
        $user->syncRoles([]);

        // Sa boutique par défaut était celle-ci : on en choisit une autre.
        if ($user->boutique_id === $boutique->id) {
            $user->update(['boutique_id' => $user->boutiqueIds()[0] ?? null]);
        }

        return $user;
    }

    /** Désactive ou réactive un compte qui n'appartient qu'à cette boutique. */
    public function basculerActivation(Boutique $boutique, User $acteur, string $userId): User
    {
        $user = $this->membre($boutique, $userId);

        if ($user->id === $acteur->id) {
            throw ValidationException::withMessages(['membre' => ['Vous ne pouvez pas désactiver votre propre compte.']]);
        }
        if (count($user->boutiqueIds()) > 1) {
            throw ValidationException::withMessages(['membre' => [
                "{$user->name} travaille aussi dans une autre boutique : retirez-le de l’équipe plutôt que de désactiver son compte.",
            ]]);
        }

        $user->update(['is_active' => ! $user->is_active]);
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return $user;
    }

    public function membre(Boutique $boutique, string $userId): User
    {
        return User::whereIn('id', $this->membreIds($boutique))->findOrFail($userId);
    }

    /** @return list<string> */
    private function membreIds(Boutique $boutique): array
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.team_foreign_key'), $boutique->id)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck(config('permission.column_names.model_morph_key'))
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function roleValide(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['role' => ['Rôle inconnu.']]);
        }
    }

    /** Les rôles Spatie sont rangés par boutique : on travaille dans celle-ci. */
    private function equipe(Boutique $boutique): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
    }
}
