<?php

declare(strict_types=1);

namespace App\Livewire\Utilisateurs;

use App\Enums\Country;
use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\User;
use App\Services\AbonnementService;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Équipe de la boutique active.
 *
 * Un compte peut appartenir à plusieurs boutiques (son rôle y est rangé par
 * boutique). Ajouter un numéro qui a déjà un compte le rattache à cette
 * boutique ; le retirer ne touche qu'à cette boutique. Désactiver un compte le
 * coupe de toutes ses boutiques : ce n'est permis que s'il n'appartient qu'à
 * celle-ci.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    public bool $modaleOuverte = false;

    public string $name = '';

    public string $telephone = '';

    public string $password = '';

    public string $role = 'caissier';

    public ?string $info = null;

    public ?string $alerte = null;

    public function nouveauCompte(): void
    {
        $this->resetValidation();
        $this->reset(['name', 'telephone', 'password', 'info', 'alerte']);
        $this->role = 'caissier';
        $this->modaleOuverte = true;
    }

    private function boutique(): Boutique
    {
        return Boutique::findOrFail($this->boutiqueActiveId());
    }

    public function enregistrer(): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can('utilisateurs.create') || abort(403);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'role' => ['required', Rule::in(['admin', 'gerant', 'caissier'])],
        ]);

        $boutique = $this->boutique();

        if (! app(AbonnementService::class)->peutAjouterMembre($boutique, count($this->membreIds()))) {
            $abonnement = app(AbonnementService::class)->pourBoutique($boutique);
            $this->addError('telephone', $abonnement?->estEnCours()
                ? 'Votre plan ne permet pas d’autre membre dans cette boutique. Passez au plan supérieur.'
                : 'Votre abonnement est terminé : abonnez-vous pour ajouter un membre.');

            return;
        }

        $pays = Country::tryFrom($boutique->pays) ?? Country::default();
        $phone = PhoneNumber::normalize($this->telephone, $pays);
        $existant = User::whereIn('phone', PhoneNumber::candidates($this->telephone, $pays))->first();

        if ($existant !== null) {
            if ($existant->appartientA($this->boutiqueActiveId())) {
                $this->addError('telephone', 'Ce compte fait déjà partie de l’équipe.');

                return;
            }

            // Compte existant (un autre commerçant, un employé partagé) : il est
            // rattaché à la boutique et garde son mot de passe.
            $existant->assignRole($this->role);
            $this->info = "{$existant->name} a été ajouté à l’équipe. Il se connecte avec son mot de passe habituel.";
            $this->modaleOuverte = false;

            return;
        }

        $this->validate(['password' => ['required', 'string', 'min:8']]);

        $user = User::create([
            'boutique_id' => $this->boutiqueActiveId(),
            'name' => $this->name,
            'phone' => $phone,
            'password' => Hash::make($this->password),
        ]);
        $user->assignRole($this->role);

        $this->info = "Compte de {$user->name} créé.";
        $this->modaleOuverte = false;
    }

    /** Retire le compte de cette boutique seulement. */
    public function retirer(string $userId): void
    {
        Auth::user()->can('utilisateurs.update') || abort(403);
        $this->reset(['info', 'alerte']);

        $user = $this->membre($userId);

        if ($user->id === Auth::id()) {
            $this->alerte = 'Vous ne pouvez pas vous retirer vous-même.';

            return;
        }

        if ($user->id === $this->boutique()->proprietaire_id) {
            $this->alerte = 'Le propriétaire ne peut pas être retiré de sa boutique.';

            return;
        }

        $user->syncRoles([]);

        // Sa boutique par défaut était celle-ci : on en choisit une autre.
        if ($user->boutique_id === $this->boutiqueActiveId()) {
            $user->update(['boutique_id' => $user->boutiqueIds()[0] ?? null]);
        }

        $this->info = "{$user->name} n’a plus accès à cette boutique.";
    }

    public function basculerActivation(string $userId): void
    {
        Auth::user()->can('utilisateurs.update') || abort(403);
        $this->reset(['info', 'alerte']);

        $user = $this->membre($userId);

        if ($user->id === Auth::id()) {
            return;
        }

        if (count($user->boutiqueIds()) > 1) {
            $this->alerte = "{$user->name} travaille aussi dans une autre boutique : retirez-le de l’équipe plutôt que de désactiver son compte.";

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }
    }

    private function membre(string $userId): User
    {
        return User::whereIn('id', $this->membreIds())->findOrFail($userId);
    }

    /** @return list<string> */
    private function membreIds(): array
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.team_foreign_key'), $this->boutiqueActiveId())
            ->where('model_type', (new User)->getMorphClass())
            ->pluck(config('permission.column_names.model_morph_key'))
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function render()
    {
        $membres = User::whereIn('id', $this->membreIds())
            ->with('roles')
            ->orderBy('name')
            ->get();

        return view('livewire.utilisateurs.index', [
            'membres' => $membres,
            'proprietaireId' => $this->boutique()->proprietaire_id,
        ]);
    }
}
