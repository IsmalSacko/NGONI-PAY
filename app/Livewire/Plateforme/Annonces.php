<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\Annonce;
use App\Models\User;
use App\Services\GestionAnnonces;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Annonces aux commerçants : mise à jour de l'application, message libre ou
 * campagne. Envoi immédiat ou programmé, une fois ou chaque semaine/mois.
 * Par défaut : notification push (Firebase) et cloche de l'application,
 * gratuites. L'e-mail (payant au-delà du quota Mailjet) seulement si coché.
 * Mêmes règles que la console de l'application (GestionAnnonces).
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

    public function nouvelle(GestionAnnonces $annonces, string $type = 'message'): void
    {
        $this->resetValidation();
        $this->reset(['par_email', 'programmee_le', 'recurrence', 'cibles', 'recherche', 'info']);
        $modele = $annonces->modele($type);
        [$this->type, $this->titre, $this->message, $this->version, $this->lien, $this->quand] =
            [$modele['type'], $modele['titre'], $modele['message'], $modele['version'], $modele['lien'], $modele['quand']];
        $this->audience = 'tous';
        $this->formulaire = true;
    }

    public function basculerCible(string $userId): void
    {
        $this->cibles = in_array($userId, $this->cibles, true)
            ? array_values(array_diff($this->cibles, [$userId]))
            : [...$this->cibles, $userId];
    }

    public function enregistrer(GestionAnnonces $annonces): void
    {
        // La validation se fait dans le service : les erreurs d'un essai précédent
        // ne s'effacent pas d'elles-mêmes.
        $this->resetErrorBag();
        ['annonce' => $annonce, 'resultat' => $r] = $annonces->creer(
            $this->only(['type', 'titre', 'message', 'version', 'lien', 'audience', 'par_email', 'quand', 'programmee_le', 'recurrence', 'cibles']),
            Auth::user(),
        );

        $this->formulaire = false;
        $this->info = $r !== null
            ? GestionAnnonces::resume($annonce, $r)
            : "« {$annonce->titre} » programmée le {$annonce->programmee_le->format('d/m/Y à H:i')}.";
    }

    public function envoyerMaintenant(int $id, GestionAnnonces $annonces): void
    {
        $annonce = Annonce::findOrFail($id);
        $this->info = GestionAnnonces::resume($annonce, $annonces->envoyerMaintenant($annonce));
    }

    public function arreter(int $id, GestionAnnonces $annonces): void
    {
        $annonces->arreter(Annonce::findOrFail($id));
        $this->info = 'Envoi programmé arrêté.';
    }

    public function render(GestionAnnonces $annonces)
    {
        return view('livewire.plateforme.annonces', [
            'annonces' => Annonce::latest('id')->limit(50)->get(),
            'apercu' => $this->formulaire ? $annonces->destinatairesPrevus($this->audience, $this->cibles) : null,
            'comptes' => $this->formulaire && $this->audience === 'selection' ? $annonces->rechercherComptes($this->recherche) : collect(),
            'choisis' => User::whereIn('id', $this->cibles)->get(['id', 'name', 'phone']),
        ]);
    }
}
