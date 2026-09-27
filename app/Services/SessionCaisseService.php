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

        $fondAttendu = $this->fondAttendu($session);

        $session->update([
            'fond_final' => $fondFinal,
            'ecart' => $fondFinal - $fondAttendu,
            'statut' => 'fermee',
            'fermee_le' => now(),
            'notes' => $notes,
        ]);

        return $session->fresh();
    }

    /** Ventes en espèces encaissées pendant la séance (annulées exclues). */
    public function totalEspeces(SessionCaisse $session): int
    {
        return (int) $session->ventes()
            ->valides()
            ->where('moyen_paiement', MoyenPaiement::Especes)
            ->sum('total');
    }

    /** Ce que le tiroir devrait contenir : le fond d'ouverture plus les espèces encaissées. */
    public function fondAttendu(SessionCaisse $session): int
    {
        return $session->fond_initial + $this->totalEspeces($session);
    }

    /**
     * Fond proposé à l'ouverture : l'argent laissé dans le tiroir à la
     * dernière fermeture de la boutique (quel que soit le caissier : le
     * tiroir, lui, reste le même).
     */
    public function derniereFermeture(): ?SessionCaisse
    {
        return SessionCaisse::where('statut', 'fermee')->whereNotNull('fond_final')->latest('fermee_le')->first();
    }

    public function courante(User $caissier): ?SessionCaisse
    {
        return SessionCaisse::where('user_id', $caissier->id)->where('statut', 'ouverte')->first();
    }
}
