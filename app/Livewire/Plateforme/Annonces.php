<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\Annonce;
use App\Models\User;
use App\Services\DiffusionAnnonces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Annonces aux commerçants : mise à jour de l'application, message libre ou
 * campagne. Envoi immédiat ou programmé, une fois ou chaque semaine/mois.
 * Par défaut : notification push (Firebase) et cloche de l'application,
 * gratuites. L'e-mail (payant au-delà du quota Mailjet) seulement si coché.
 */
#[Layout('layouts.plateforme', ['title' => 'Annonces'])]
class Annonces extends Component
{
    public bool $formulaire = false;

    public string $type = 'message';

    public string $titre = '';

    public string $message = '';

    public string $version = '';

    public string $lien = '';

    public string $audience = 'tous';

    public bool $par_email = false;

    /** maintenant | programmer */
    public string $quand = 'maintenant';

    public string $programmee_le = '';

    public string $recurrence = '';

    /** Recherche et sélection de comptes (audience « selection »). */
    public string $recherche = '';

    /** @var list<string> */
    public array $cibles = [];

    public ?string $info = null;

    public function nouvelle(string $type = 'message'): void
    {
        $this->resetValidation();
        $this->reset(['titre', 'message', 'version', 'lien', 'par_email', 'programmee_le', 'recurrence', 'cibles', 'recherche', 'info']);
        $this->type = $type;
        $this->audience = 'tous';
        $this->quand = $type === 'campagne' ? 'programmer' : 'maintenant';

        if ($type === 'mise_a_jour') {
            $this->version = \App\Support\VersionApplication::derniere();
            $this->lien = (string) config('mobile.store_url');
            $this->titre = 'Nouvelle version de l’application';
            $this->message = 'Mettez à jour Ngoni Caisse depuis le Play Store pour profiter des nouveautés.';
        }

        $this->formulaire = true;
    }

    public function basculerCible(string $userId): void
    {
        $this->cibles = in_array($userId, $this->cibles, true)
            ? array_values(array_diff($this->cibles, [$userId]))
            : [...$this->cibles, $userId];
    }

    public function enregistrer(DiffusionAnnonces $diffusion): void
    {
        $data = $this->validate([
            'type' => ['required', Rule::in(array_keys(Annonce::TYPES))],
            'titre' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'version' => ['nullable', 'string', 'max:20'],
            'lien' => ['nullable', 'url', 'max:255'],
            'audience' => ['required', Rule::in(array_keys(Annonce::AUDIENCES))],
            'par_email' => ['boolean'],
            'quand' => ['required', Rule::in(['maintenant', 'programmer'])],
            'programmee_le' => ['nullable', 'required_if:quand,programmer', 'date', 'after:now'],
            'recurrence' => ['nullable', Rule::in(['hebdomadaire', 'mensuelle'])],
            'cibles' => ['array', 'required_if:audience,selection'],
        ], ['cibles.required_if' => 'Choisissez au moins un compte.'], ['programmee_le' => 'date d’envoi']);

        $annonce = Annonce::create([
            'type' => $data['type'],
            'titre' => $data['titre'],
            'message' => $data['message'],
            'version' => $data['version'] ?: null,
            'lien' => $data['lien'] ?: null,
            'audience' => $data['audience'],
            'par_email' => $data['par_email'],
            'statut' => 'programmee',
            'programmee_le' => $data['quand'] === 'programmer' ? Carbon::parse($data['programmee_le']) : now(),
            'recurrence' => $data['recurrence'] ?: null,
            'cree_par' => Auth::id(),
        ]);

        if ($data['audience'] === 'selection') {
            $annonce->cibles()->sync($data['cibles']);
        }

        $this->formulaire = false;

        if ($data['quand'] === 'maintenant') {
            $r = $diffusion->diffuser($annonce);
            $this->info = "« {$annonce->titre} » envoyée : {$r['notifies']} notification(s), {$r['pushs']} push, {$r['emails']} e-mail(s)"
                .($r['echecs'] ? ", {$r['echecs']} échec(s)" : '').'.';
        } else {
            $this->info = "« {$annonce->titre} » programmée le {$annonce->programmee_le->format('d/m/Y à H:i')}.";
        }
    }

    public function envoyerMaintenant(int $id, DiffusionAnnonces $diffusion): void
    {
        $annonce = Annonce::findOrFail($id);
        $r = $diffusion->diffuser($annonce);
        $this->info = "« {$annonce->titre} » envoyée : {$r['notifies']} notification(s), {$r['emails']} e-mail(s).";
    }

    public function arreter(int $id): void
    {
        Annonce::whereKey($id)->where('statut', 'programmee')->update(['statut' => 'brouillon', 'programmee_le' => null]);
        $this->info = 'Envoi programmé arrêté.';
    }

    public function render(DiffusionAnnonces $diffusion)
    {
        $apercu = null;
        if ($this->formulaire) {
            $brouillon = new Annonce(['audience' => $this->audience]);
            $apercu = $this->audience === 'selection'
                ? count($this->cibles)
                : $diffusion->destinataires($brouillon)->count();
        }

        $comptes = $this->formulaire && $this->audience === 'selection' && strlen(trim($this->recherche)) >= 2
            ? User::where('est_admin_plateforme', false)
                ->where(fn ($q) => $q->where('name', 'like', "%{$this->recherche}%")->orWhere('phone', 'like', "%{$this->recherche}%"))
                ->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'email'])
            : collect();

        return view('livewire.plateforme.annonces', [
            'annonces' => Annonce::latest('id')->limit(50)->get(),
            'apercu' => $apercu,
            'comptes' => $comptes,
            'choisis' => User::whereIn('id', $this->cibles)->get(['id', 'name', 'phone']),
        ]);
    }
}
