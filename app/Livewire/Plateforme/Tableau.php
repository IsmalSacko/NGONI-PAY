<?php

declare(strict_types=1);

namespace App\Livewire\Plateforme;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.plateforme', ['title' => 'Tableau de bord'])]
class Tableau extends Component
{
    public function render()
    {
        $actifs = Abonnement::where('est_actif', true)
            ->where(fn ($q) => $q->whereNull('fin')->orWhereDate('fin', '>=', today()))
            ->count();
        $parPlan = Abonnement::select('plan', DB::raw('COUNT(*) as n'))->groupBy('plan')->pluck('n', 'plan');

        return view('livewire.plateforme.tableau', [
            'comptes' => Abonnement::count(),
            'actifs' => $actifs,
            'expires' => Abonnement::count() - $actifs,
            'parPlan' => $parPlan,
            'boutiques' => Boutique::count(),
            'utilisateurs' => User::count(),
            'demandes' => DemandeAbonnement::enAttente()->count(),
            'ventesJour' => Vente::withoutBoutiqueScope()->whereDate('created_at', today())->count(),
            'montantJour' => (int) Vente::withoutBoutiqueScope()->whereDate('created_at', today())->sum('total'),
        ]);
    }
}
