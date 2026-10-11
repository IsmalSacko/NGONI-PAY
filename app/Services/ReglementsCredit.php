<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\ReglementCredit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Un client rembourse tout ou partie de son crédit. */
class ReglementsCredit
{
    public function enregistrer(Client $client, User $caissier, int $montant, string $moyen, ?string $note = null): ReglementCredit
    {
        // La séance du caissier : un remboursement en espèces entre dans son
        // tiroir. Numéro de reçu et dette avant / après, figés maintenant.
        return DB::transaction(function () use ($client, $caissier, $montant, $moyen, $note): ReglementCredit {
            $solde = $client->soldeDu();

            return $client->reglements()->create([
                'montant' => $montant,
                'moyen_paiement' => $moyen,
                'note' => $note,
                'user_id' => $caissier->id,
                'session_caisse_id' => app(SessionCaisseService::class)->courante($caissier)?->id,
                'numero' => (int) ReglementCredit::lockForUpdate()->max('numero') + 1,
                'solde_avant' => $solde,
                'solde_apres' => $solde - $montant,
            ]);
        });
    }
}
