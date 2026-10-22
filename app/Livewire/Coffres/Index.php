<?php

declare(strict_types=1);

namespace App\Livewire\Coffres;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Boutique;
use App\Models\Coffre;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Services\Coffres;
use App\Services\Journee;
use App\Services\Rapports;
use App\Support\Money\Montant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Le coffre (offre Pro), comme l'écran Coffre de l'application : mettre de
 * côté une partie de la recette, à la main ou chaque soir. Ngoni compte ; il
 * ne déplace pas l'argent. Montants cachés tant que l'œil est fermé.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    /** L'œil : fermé à chaque ouverture de la page. */
    public bool $visible = false;

    /** Coffre dont la fiche (historique) est ouverte. */
    public ?string $ouvert = null;

    /** Fenêtre de règle : '' pour un nouveau coffre, l'id pour le modifier, null fermée. */
    public ?string $edite = null;

    public string $nom = '';

    public string $mode = Coffre::MANUEL;

    public string $montantFixe = '';

    public string $pourcentage = '';

    /** Fenêtre d'ajout ou de retrait. */
    public ?string $mouvementPour = null;

    public bool $ajout = true;

    public string $montant = '';

    public ?string $info = null;

    /** Les actions Livewire ne repassent pas par les middlewares de la route. */
    private function autoriser(): void
    {
        Auth::user()->can('dashboard.view') || abort(403);
        app(AbonnementService::class)->permet(Boutique::find($this->boutiqueActiveId()), Plan::COFFRE) || abort(403);
    }

    public function nouveau(): void
    {
        $this->autoriser();
        $this->resetValidation();
        $this->reset(['nom', 'montantFixe', 'pourcentage', 'info']);
        $this->mode = Coffre::MANUEL;
        $this->edite = '';
    }

    public function modifier(string $id): void
    {
        $this->autoriser();
        $this->resetValidation();
        $c = Coffre::findOrFail($id);
        $this->edite = $c->id;
        $this->nom = $c->nom;
        $this->mode = $c->mode;
        $this->montantFixe = $c->montant_fixe === null ? '' : Montant::saisie($c->montant_fixe);
        $this->pourcentage = $c->pourcentage === null ? '' : str_replace('.', ',', (string) (0 + (float) $c->pourcentage));
    }

    public function enregistrer(Coffres $coffres): void
    {
        $this->autoriser();
        if (! $this->abonnementActif()) {
            return;
        }
        $this->resetValidation();
        try {
            $coffres->regler($this->edite ? Coffre::findOrFail($this->edite) : null, [
                'nom' => $this->nom,
                'mode' => $this->mode,
                'montant_fixe' => $this->mode === Coffre::FIXE ? Montant::parse($this->montantFixe) : null,
                'pourcentage' => $this->mode === Coffre::POURCENTAGE ? str_replace(',', '.', trim($this->pourcentage)) : null,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $champ => $messages) {
                $this->addError(match ($champ) {
                    'montant_fixe' => 'montantFixe',
                    default => $champ,
                }, $messages[0]);
            }

            return;
        }
        $this->edite = null;
    }

    public function supprimer(string $id, Coffres $coffres): void
    {
        $this->autoriser();
        try {
            $coffres->supprimer(Coffre::findOrFail($id));
        } catch (ValidationException $e) {
            $this->addError('coffre', collect($e->errors())->flatten()->first());

            return;
        }
        $this->ouvert = null;
        $this->info = 'Coffre supprimé.';
    }

    public function ouvrirMouvement(string $id, bool $ajout): void
    {
        $this->autoriser();
        $this->resetValidation();
        $this->reset(['montant', 'info']);
        $this->mouvementPour = $id;
        $this->ajout = $ajout;
    }

    public function enregistrerMouvement(Coffres $coffres): void
    {
        $this->autoriser();
        // Mettre de côté demande un abonnement en cours ; reprendre son argent, jamais bloqué.
        if ($this->ajout && ! $this->abonnementActif()) {
            return;
        }
        $this->resetValidation();
        $montant = Montant::parse($this->montant);
        if ($montant === null || $montant <= 0) {
            $this->addError('montant', 'Indiquez un montant.');

            return;
        }
        $coffre = Coffre::findOrFail($this->mouvementPour);
        try {
            $this->ajout ? $coffres->ajouter($coffre, $montant, Auth::user()) : $coffres->retirer($coffre, $montant, Auth::user());
        } catch (ValidationException $e) {
            $this->addError('montant', collect($e->errors())->flatten()->first());

            return;
        }
        $this->mouvementPour = null;
        $this->ouvert = $coffre->id;
    }

    public function render(Coffres $coffres)
    {
        $liste = Coffre::orderBy('created_at')->orderBy('id')->get();
        $soldes = $coffres->soldes();
        // Le mois en cours : Encaissé → Mis de côté → Reste disponible, comme le pilotage.
        $aujourdhui = app(Journee::class)->courante();
        $mois = app(Rapports::class)->periode($aujourdhui->copy()->startOfMonth(), $aujourdhui);

        return view('livewire.coffres.index', [
            'coffres' => $liste,
            'soldes' => $soldes,
            'total' => array_sum(array_map(fn (Coffre $c) => $soldes[$c->id] ?? 0, $liste->all())),
            'encaisse' => (int) ($mois['encaisse']['total'] ?? 0),
            'epargne' => $mois['epargne'] ?? ['mis_de_cote' => 0, 'repris' => 0],
            'fiche' => $this->ouvert ? $liste->firstWhere('id', $this->ouvert) : null,
            'historique' => $this->ouvert
                ? Coffre::find($this->ouvert)?->mouvements()->with('auteur:id,name')->orderByDesc('jour')->orderByDesc('created_at')->limit(100)->get() ?? collect()
                : collect(),
        ]);
    }
}
