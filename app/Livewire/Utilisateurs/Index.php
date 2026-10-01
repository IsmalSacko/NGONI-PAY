<?php

declare(strict_types=1);

namespace App\Livewire\Utilisateurs;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Services\EquipeService;
use App\Support\Authorization\Permissions;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

/**
 * Équipe de la boutique active. Les règles (limites du plan, compte déjà
 * existant, propriétaire intouchable) sont dans {@see EquipeService}, partagé
 * avec l'application. Le propriétaire ou un admin y coche aussi les droits de
 * chaque gérant et caissier (Permissions::DROITS).
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

    /** Droits du nouveau membre, ceux du rôle choisi au départ. */
    public array $droits = [];

    /** Membre dont on règle les droits, et ses droits cochés. */
    public ?string $droitsDe = null;

    public array $droitsMembre = [];

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
        $this->droits = Permissions::DROITS_PAR_DEFAUT['caissier'];
        $this->modaleOuverte = true;
    }

    /** Un autre rôle : ses droits par défaut, que l'on peut ensuite ajuster. */
    public function updatedRole(string $role): void
    {
        $this->droits = Permissions::DROITS_PAR_DEFAUT[$role] ?? [];
    }

    public function ouvrirDroits(EquipeService $equipe, string $userId): void
    {
        Auth::user()->can('utilisateurs.update') || abort(403);
        $this->reset(['info', 'alerte', 'motDePasseProvisoire', 'lienWhatsApp']);
        $membre = $equipe->membre($this->boutique(), $userId);
        $this->droitsDe = $membre->id;
        $this->droitsMembre = $equipe->droitsDans($this->boutique(), $membre);
    }

    public function enregistrerDroits(EquipeService $equipe): void
    {
        Auth::user()->can('utilisateurs.update') || abort(403);
        $boutique = $this->boutique();
        $membre = $equipe->membre($boutique, (string) $this->droitsDe);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
        $role = $membre->load('roles')->roles->first()?->name ?? 'caissier';

        try {
            $user = $equipe->changerRole($boutique, Auth::user(), $membre->id, $role, array_values($this->droitsMembre));
            $this->info = "Droits de {$user->name} enregistrés.";
            $this->droitsDe = null;
        } catch (ValidationException $e) {
            $this->alerte = collect($e->errors())->flatten()->first();
        }
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
            'droits.*' => [Rule::in(array_keys(Permissions::DROITS))],
        ]);

        $resultat = $equipe->ajouter($this->boutique(), $this->name, $this->telephone, $this->role, $this->password ?: null, array_values($this->droits));
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
            $message = "Bonjour {$user->name}, votre compte Ngoni Caisse est prêt. Numéro : {$user->phone}. "
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
            'catalogueDroits' => Permissions::DROITS,
            'droitsParMembre' => $equipe->membres($boutique)->mapWithKeys(fn ($m) => [$m->id => $equipe->droitsDans($boutique, $m)])->all(),
            'membreDroits' => $this->droitsDe ? $equipe->membres($boutique)->firstWhere('id', $this->droitsDe) : null,
        ]);
    }
}
