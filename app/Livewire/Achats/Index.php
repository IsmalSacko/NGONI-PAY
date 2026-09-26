<?php

declare(strict_types=1);

namespace App\Livewire\Achats;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\Achat;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Services\AchatService;
use App\Support\Money\Montant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Achats : fournisseurs (ce qu'on leur doit), réceptions de marchandise, paiements. */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    public bool $receptionOuverte = false;

    public string $fournisseurId = '';

    public string $reference = '';

    /** @var list<array{produit_id: string, quantite: string, prix_achat: string}> */
    public array $lignes = [];

    public string $montantPaye = '';

    public string $moyen = 'especes';

    public bool $fournisseurOuvert = false;

    public string $nomFournisseur = '';

    public string $telFournisseur = '';

    public ?string $paiementPour = null;

    public string $paiementMontant = '';

    public ?string $info = null;

    public function nouvelleReception(): void
    {
        Auth::user()->can('achats.create') || abort(403);
        $this->resetValidation();
        $this->reset(['fournisseurId', 'reference', 'montantPaye', 'info']);
        $this->lignes = [['produit_id' => '', 'quantite' => '1', 'prix_achat' => '']];
        $this->receptionOuverte = true;
    }

    public function ajouterLigne(): void
    {
        $this->lignes[] = ['produit_id' => '', 'quantite' => '1', 'prix_achat' => ''];
    }

    public function retirerLigne(int $i): void
    {
        unset($this->lignes[$i]);
        $this->lignes = array_values($this->lignes);
    }

    /** Le prix d'achat actuel de l'article est proposé à la sélection. */
    public function updatedLignes($valeur, string $cle): void
    {
        [$i, $champ] = explode('.', $cle) + [null, null];
        if ($champ === 'produit_id' && $valeur) {
            $p = Produit::find($valeur);
            if ($p?->prix_achat !== null && ($this->lignes[$i]['prix_achat'] ?? '') === '') {
                $this->lignes[$i]['prix_achat'] = Montant::saisie($p->prix_achat);
            }
        }
    }

    public function enregistrerReception(AchatService $achats): void
    {
        if (! $this->abonnementActif()) {
            return;
        }
        Auth::user()->can('achats.create') || abort(403);
        $this->resetValidation();

        $lignes = [];
        foreach ($this->lignes as $i => $l) {
            $prix = Montant::parse($l['prix_achat']);
            if ($l['produit_id'] === '' || (int) $l['quantite'] < 1 || $prix === null) {
                $this->addError("lignes.$i", 'Article, quantité et prix d’achat requis.');

                continue;
            }
            $lignes[] = ['produit_id' => $l['produit_id'], 'quantite' => (int) $l['quantite'], 'prix_achat' => $prix];
        }
        if ($lignes === [] || $this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $achat = $achats->receptionner(Auth::user(), $lignes, $this->fournisseurId ?: null, $this->reference ?: null,
                (int) (Montant::parse($this->montantPaye) ?? 0), $this->moyen);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $champ => $messages) {
                $this->addError($champ, $messages[0]);
            }

            return;
        }

        $this->receptionOuverte = false;
        $this->info = 'Réception enregistrée : '.Montant::format($achat->total).'. Stock et prix d’achat mis à jour.';
    }

    public function creerFournisseur(): void
    {
        Auth::user()->can('achats.create') || abort(403);
        $this->validate(['nomFournisseur' => ['required', 'string', 'max:255'], 'telFournisseur' => ['nullable', 'string', 'max:30']], [], ['nomFournisseur' => 'nom']);
        $f = Fournisseur::create(['nom' => $this->nomFournisseur, 'telephone' => $this->telFournisseur ?: null]);
        $this->reset(['nomFournisseur', 'telFournisseur', 'fournisseurOuvert']);
        $this->fournisseurId = $f->id;
    }

    public function ouvrirPaiement(string $id): void
    {
        $this->resetValidation();
        $this->paiementPour = $id;
        $this->paiementMontant = Montant::saisie(Fournisseur::findOrFail($id)->soldeDu());
    }

    public function payer(AchatService $achats): void
    {
        Auth::user()->can('achats.create') || abort(403);
        $this->resetValidation();
        try {
            $achats->payer(Fournisseur::findOrFail($this->paiementPour), Auth::user(), (int) (Montant::parse($this->paiementMontant) ?? 0), $this->moyen);
        } catch (ValidationException $e) {
            $this->addError('paiementMontant', collect($e->errors())->flatten()->first());

            return;
        }
        $this->paiementPour = null;
    }

    public function render()
    {
        return view('livewire.achats.index', [
            'fournisseurs' => Fournisseur::avecSoldeDu()->orderBy('nom')->get(),
            'achats' => Achat::with(['lignes', 'fournisseur:id,nom'])->latest()->limit(30)->get(),
            'produits' => Produit::orderBy('nom')->get(['id', 'nom', 'format', 'prix_achat']),
        ]);
    }
}
