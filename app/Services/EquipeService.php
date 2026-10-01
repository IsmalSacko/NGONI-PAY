<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Models\Boutique;
use App\Models\User;
use App\Support\Authorization\Permissions;
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
 *
 * En plus du rôle, chaque gérant ou caissier a ses droits (voir
 * Permissions::DROITS : chiffre d'affaires, articles, annulations, achats,
 * back-office), que le propriétaire ou un admin coche un par un. Ils sont
 * rangés en permissions directes, par boutique comme les rôles.
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
    public function ajouter(Boutique $boutique, string $nom, string $telephone, string $role, ?string $motDePasse = null, ?array $droits = null): array
    {
        $this->roleValide($role);
        $this->droitsValides($droits);

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
            $this->appliquerDroits($existant, $role, $droits);

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
        $this->appliquerDroits($user, $role, $droits);

        return ['user' => $user, 'cree' => true, 'mot_de_passe' => $provisoire];
    }

    /**
     * Change le rôle et les droits d'un membre. `$droits` à null : ceux par
     * défaut du rôle s'il change, sinon ceux qu'il a déjà.
     */
    public function changerRole(Boutique $boutique, User $acteur, string $userId, string $role, ?array $droits = null): User
    {
        $this->roleValide($role);
        $this->droitsValides($droits);
        $user = $this->membre($boutique, $userId);

        if ($user->id === $acteur->id) {
            throw ValidationException::withMessages(['role' => ['Vous ne pouvez pas changer votre propre rôle.']]);
        }
        if ($user->id === $boutique->proprietaire_id) {
            throw ValidationException::withMessages(['role' => ['Le propriétaire reste administrateur de sa boutique.']]);
        }

        $this->equipe($boutique);
        $ancien = $user->roles->first()?->name;
        $user->syncRoles([$role]);
        $this->appliquerDroits($user, $role, $droits ?? ($ancien === $role ? $this->droitsDe($user) : null));

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
        $user->syncPermissions([]);

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

    /**
     * Droits d'un membre dans la boutique en cours (voir equipe()) : ceux dont
     * il a toutes les permissions. Un admin les a tous.
     *
     * @return list<string>
     */
    public function droitsDe(User $user): array
    {
        if ($user->hasRole('admin')) {
            return array_keys(Permissions::DROITS);
        }
        $directes = $user->getDirectPermissions()->pluck('name')->all();

        return array_values(array_filter(array_keys(Permissions::DROITS),
            fn (string $d) => array_diff(Permissions::DROITS[$d]['permissions'], $directes) === []));
    }

    /** Droits d'un membre, dans sa boutique (pour l'affichage de l'équipe). */
    public function droitsDans(Boutique $boutique, User $user): array
    {
        $this->equipe($boutique);

        return $this->droitsDe($user->load('roles', 'permissions'));
    }

    /**
     * Range les droits en permissions directes. Un admin n'en a pas besoin
     * (son rôle a tout) ; null : ceux par défaut du rôle.
     *
     * @param  list<string>|null  $droits
     */
    /** Pour qui reçoit un rôle hors de ce service (import) : les droits par défaut du rôle. */
    public static function droitsParDefaut(User $user, string $role): void
    {
        $user->givePermissionTo(Permissions::permissionsDes($role === 'admin' ? [] : Permissions::DROITS_PAR_DEFAUT[$role] ?? []));
    }

    private function appliquerDroits(User $user, string $role, ?array $droits): void
    {
        $droits = $role === 'admin' ? [] : ($droits ?? Permissions::DROITS_PAR_DEFAUT[$role] ?? []);
        $user->syncPermissions(Permissions::permissionsDes($droits));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  list<string>|null  $droits */
    private function droitsValides(?array $droits): void
    {
        if ($droits !== null && array_diff($droits, array_keys(Permissions::DROITS)) !== []) {
            throw ValidationException::withMessages(['droits' => ['Droit inconnu.']]);
        }
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
