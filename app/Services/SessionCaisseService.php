<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Models\SessionCaisse;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Ouverture/fermeture d'une séance de caisse.
 *
 * Une séance par caissier à la fois : ouvrir une seconde séance avant
 * d'avoir fermé la première est refusé, pour qu'une vente ne se retrouve
 * jamais rattachée à la mauvaise séance faute d'avoir été fermée.
 */
class SessionCaisseService
{
    public function ouvrir(User $caissier, int $fondInitial): SessionCaisse
    {
        if ($this->courante($caissier) !== null) {
            throw ValidationException::withMessages(['session' => ['Une séance de caisse est déjà ouverte.']]);
        }

        return SessionCaisse::create([
            'user_id' => $caissier->id,
            'fond_initial' => $fondInitial,
            'statut' => 'ouverte',
            'ouverte_le' => now(),
        ]);
    }

    public function fermer(SessionCaisse $session, int $fondFinal, ?string $notes = null): SessionCaisse
    {
        if (! $session->estOuverte()) {
            throw ValidationException::withMessages(['session' => ['Cette séance est déjà fermée.']]);
        }

        $totalEspeces = (int) $session->ventes()
            ->where('moyen_paiement', MoyenPaiement::Especes)
            ->sum('total');

        $fondAttendu = $session->fond_initial + $totalEspeces;

        $session->update([
            'fond_final' => $fondFinal,
            'ecart' => $fondFinal - $fondAttendu,
            'statut' => 'fermee',
            'fermee_le' => now(),
            'notes' => $notes,
        ]);

        return $session->fresh();
    }

    public function courante(User $caissier): ?SessionCaisse
    {
        return SessionCaisse::where('user_id', $caissier->id)->where('statut', 'ouverte')->first();
    }
}
