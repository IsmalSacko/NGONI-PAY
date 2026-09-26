<?php

declare(strict_types=1);

namespace App\Livewire\Utilisateurs;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Services\EquipeService;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Équipe de la boutique active. Les règles (limites du plan, compte déjà
 * existant, propriétaire intouchable) sont dans {@see EquipeService}, partagé
 * avec l'application.
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

    /** Mot de passe provisoire d'un compte créé, affiché une seule fois. */
    public ?string $motDePasseProvisoire = null;

    public ?string $lienWhatsApp = null;

    public function nouveauCompte(): void
    {
        $this->resetValidation();
        $this->reset(['name', 'telephone', 'password', 'info', 'alerte', 'motDePasseProvisoire', 'lienWhatsApp']);
        $this->role = 'caissier';
        $this->modaleOuverte = true;
    }

    private function boutique(): Boutique
    {
        return Boutique::findOrFail($this->boutiqueActiveId());
    }

    public function enregistrer(EquipeService $equipe): void
    {
        if (! $this->abonnementActif()) {
            return;
        }

        Auth::user()->can('utilisateurs.create') || abort(403);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in(EquipeService::ROLES)],
        ]);

        $resultat = $equipe->ajouter($this->boutique(), $this->name, $this->telephone, $this->role, $this->password ?: null);
        $user = $resultat['user'];

        $this->modaleOuverte = false;

        if (! $resultat['cree']) {
            $this->info = "{$user->name} a été ajouté à l’équipe. Il se connecte avec son mot de passe habituel.";

            return;
        }

        $this->info = "Compte de {$user->name} créé.";
        $this->motDePasseProvisoire = $resultat['mot_de_passe'];

        if ($this->motDePasseProvisoire !== null) {
            $lien = WhatsApp::link($user->phone);
            $message = "Bonjour {$user->name}, votre compte e-caisse est prêt. Numéro : {$user->phone}. "
                ."Mot de passe provisoire : {$this->motDePasseProvisoire}";
            $this->lienWhatsApp = $lien === null ? null : $lien.'?text='.rawurlencode($message);
        }
    }

    public function changerRole(EquipeService $equipe, string $userId, string $role): void
    {
        Auth::user()->can('utilisateurs.update') || abort(403);
        $this->reset(['info', 'alerte', 'motDePasseProvisoire', 'lienWhatsApp']);

        try {
            $user = $equipe->changerRole($this->boutique(), Auth::user(), $userId, $role);
            $this->info = "{$user->name} est maintenant ".(['admin' => 'admin', 'gerant' => 'gérant', 'caissier' => 'caissier'][$role]).'.';
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();
        }
    }

    /** Retire le compte de cette boutique seulement. */
    public function retirer(EquipeService $equipe, string $userId): void
    {
        Auth::user()->can('utilisateurs.delete') || abort(403);
        $this->reset(['info', 'alerte', 'motDePasseProvisoire', 'lienWhatsApp']);

        try {
            $user = $equipe->retirer($this->boutique(), Auth::user(), $userId);
            $this->info = "{$user->name} n’a plus accès à cette boutique.";
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();
        }
    }

    public function basculerActivation(EquipeService $equipe, string $userId): void
    {
        Auth::user()->can('utilisateurs.update') || abort(403);
        $this->reset(['info', 'alerte', 'motDePasseProvisoire', 'lienWhatsApp']);

        try {
            $equipe->basculerActivation($this->boutique(), Auth::user(), $userId);
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();
        }
    }

    public function render(EquipeService $equipe)
    {
        $boutique = $this->boutique();

        return view('livewire.utilisateurs.index', [
            'membres' => $equipe->membres($boutique),
            'proprietaireId' => $boutique->proprietaire_id,
            'peutGerer' => Auth::user()->can('utilisateurs.update'),
            'peutAjouter' => Auth::user()->can('utilisateurs.create'),
        ]);
    }
}
