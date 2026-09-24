<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Models\Boutique;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Phone\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Inscription d'une nouvelle boutique : crée le tenant et son premier
 * utilisateur, avec le rôle `admin`.
 */
class BoutiqueRegistrationService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{nom: string, pays: string, telephone: string, email: ?string, password: string, nom_utilisateur: string}  $data
     * @return array{boutique: Boutique, user: User}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $pays = Country::tryFrom(strtoupper($data['pays'])) ?? Country::default();

            $boutique = Boutique::create([
                'nom' => $data['nom'],
                'pays' => $pays->value,
                'devise' => $pays->currency(),
                'telephone' => PhoneNumber::normalize($data['telephone'], $pays),
                'email' => $data['email'] ?? null,
            ]);

            // Le contexte tenant doit être posé avant toute écriture qui en
            // dépend (BelongsToBoutique) : ni l'utilisateur admin n'existe
            // encore pour le fournir lui-même.
            $this->tenant->setBoutique($boutique->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);

            $this->provisionnerRoles($boutique);

            $user = User::create([
                'boutique_id' => $boutique->id,
                'name' => $data['nom_utilisateur'],
                'phone' => PhoneNumber::normalize($data['telephone'], $pays),
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
            ]);
            $user->assignRole('admin');

            return ['boutique' => $boutique, 'user' => $user->fresh()];
        });
    }

    private function provisionnerRoles(Boutique $boutique): void
    {
        // Une boutique peut être la toute première du catalogue : les
        // permissions elles-mêmes (globales, sans colonne d'équipe) doivent
        // exister avant qu'un rôle ne tente de les lui rattacher.
        foreach (Permissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (Permissions::roleMatrix() as $role => $permissions) {
            Role::firstOrCreate(
                ['name' => $role, 'guard_name' => 'web', 'boutique_id' => $boutique->id],
            )->syncPermissions($permissions);
        }
    }
}
