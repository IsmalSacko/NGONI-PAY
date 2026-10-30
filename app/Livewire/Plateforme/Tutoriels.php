<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\Tutoriel;
use App\Services\AnnonceTutoriel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Aide et tutoriels : les vidéos YouTube que les commerçants voient dans
 * l'application (Plus → Aide et tutoriels). Mêmes règles que la console de
 * l'application (Tutoriel::regles).
 */
#[Layout('layouts.plateforme', ['title' => 'Tutoriels'])]
class Tutoriels extends Component
{
    public bool $formulaire = false;

    public ?int $edite = null;

    public string $titre = '';

    public string $sous_titre = '';

    public string $categorie = 'ventes';

    public string $url = '';

    public ?string $info = null;

    /** Prévenir les commerçants d'une nouvelle vidéo (notification qui l'ouvre sur YouTube). */
    public bool $prevenir = true;

    public function nouveau(): void
    {
        $this->resetErrorBag();
        $this->reset(['edite', 'titre', 'sous_titre', 'url', 'info', 'prevenir']);
        $this->categorie = 'ventes';
        $this->formulaire = true;
    }

    public function editer(int $id): void
    {
        $t = Tutoriel::findOrFail($id);
        $this->resetErrorBag();
        [$this->edite, $this->titre, $this->sous_titre, $this->categorie, $this->url] = [$t->id, $t->titre, (string) $t->sous_titre, $t->categorie, $t->url];
        $this->formulaire = true;
    }

    public function enregistrer(): void
    {
        $this->resetErrorBag();
        $data = Validator::make(
            ['titre' => $this->titre, 'sous_titre' => $this->sous_titre ?: null, 'categorie' => $this->categorie, 'url' => trim($this->url)],
            Tutoriel::regles(),
            [],
            ['url' => 'lien YouTube'],
        )->validate();

        if ($this->edite === null) {
            $tutoriel = Tutoriel::create($data + ['ordre' => (int) Tutoriel::max('ordre') + 1]);
            $enAttente = $this->prevenir && app(AnnonceTutoriel::class)->mettreEnAttente($tutoriel);
            $this->info = "« {$data['titre']} » ajouté".($enAttente ? ' : les commerçants seront prévenus lundi, dans le résumé des vidéos de la semaine.' : ' : visible dans l’aide.');
        } else {
            Tutoriel::findOrFail($this->edite)->update($data);
            $this->info = "« {$data['titre']} » enregistré.";
        }
        $this->formulaire = false;
    }

    public function basculer(int $id): void
    {
        $t = Tutoriel::findOrFail($id);
        $t->update(['actif' => ! $t->actif]);
    }

    /** Prévenir les commerçants tout de suite, sans attendre le résumé du lundi. */
    public function annoncer(int $id): void
    {
        $t = Tutoriel::findOrFail($id);
        $resultat = app(AnnonceTutoriel::class)->annoncerMaintenant($t, Auth::user());
        $this->info = $resultat === null
            ? 'Montrez d’abord la vidéo : masquée, elle n’est pas dans l’aide des commerçants.'
            : Tutoriel::messageAnnonce($t, $resultat['destinataires']);
    }

    public function supprimer(int $id): void
    {
        $t = Tutoriel::findOrFail($id);
        $t->delete();
        $this->info = "« {$t->titre} » supprimé.";
    }

    public function render()
    {
        return view('livewire.plateforme.tutoriels', [
            'tutoriels' => Tutoriel::orderBy('ordre')->orderBy('id')->get(),
        ]);
    }
}
