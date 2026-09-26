<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cloture;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Journée d'affaires de la boutique active : aujourd'hui, ou le lendemain de
 * la dernière journée clôturée si elle est déjà close (une vente faite après
 * la clôture compte pour la journée suivante).
 */
class Journee
{
    public function __construct(private readonly Rapports $rapports) {}

    public function courante(): Carbon
    {
        $derniere = Cloture::max('jour_affaire');
        $aujourdhui = today();

        return $derniere !== null && Carbon::parse($derniere)->gte($aujourdhui)
            ? Carbon::parse($derniere)->addDay()->startOfDay()
            : $aujourdhui;
    }

    public function estCloturee(Carbon|string|null $jour): bool
    {
        return $jour !== null && Cloture::whereDate('jour_affaire', Carbon::parse($jour))->exists();
    }

    /**
     * Clôture la journée en cours : ses chiffres sont figés dans un ticket Z
     * numéroté ; la numérotation du jour repart à 1 pour la suivante.
     */
    public function cloturer(User $auteur): Cloture
    {
        return DB::transaction(function () use ($auteur): Cloture {
            $jour = $this->courante();
            if ($this->estCloturee($jour) || $jour->gt(today())) {
                throw ValidationException::withMessages(['journee' => [
                    'La journée d’aujourd’hui est déjà clôturée : les prochaines ventes comptent pour demain.',
                ]]);
            }

            return Cloture::create([
                'numero' => (int) Cloture::lockForUpdate()->max('numero') + 1,
                'jour_affaire' => $jour->toDateString(),
                'user_id' => $auteur->id,
                'totaux' => $this->rapports->periode($jour, $jour),
            ]);
        });
    }
}
