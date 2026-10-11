<?php

declare(strict_types=1);

namespace App\Livewire\CommandesFournisseur;

use App\Livewire\Concerns\EstScopeParBoutique;
use App\Models\CommandeFournisseur;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Commandes aux grossistes, en consultation : celles que la boutique a
 * passées à ses fournisseurs, et celles que ses revendeurs lui ont passées.
 * On les traite dans l'application (confirmer, livrer).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use EstScopeParBoutique;

    public function render()
    {
        $boutiqueId = $this->boutiqueActiveId();

        return view('livewire.commandes-fournisseur.index', [
            'envoyees' => CommandeFournisseur::with(['lignes', 'fournisseur:id,nom', 'boutiqueGrossiste:id,nom'])->latest()->limit(50)->get(),
            'recues' => CommandeFournisseur::withoutBoutiqueScope()->where('boutique_grossiste_id', $boutiqueId)
                ->with(['lignes.produitGrossiste', 'boutique:id,nom'])
                ->orderByRaw("CASE statut WHEN 'envoyee' THEN 0 WHEN 'confirmee' THEN 1 WHEN 'en_livraison' THEN 2 ELSE 3 END")
                ->latest()->limit(50)->get(),
        ]);
    }
}
