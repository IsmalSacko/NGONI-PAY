<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cloture;
use App\Models\User;
use App\Models\Vente;
use App\Support\Tenancy\TenantContext;
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
                // Le numéro d'un Z annulé (journée rouverte) ne resert pas.
                'numero' => max(
                    (int) Cloture::lockForUpdate()->max('numero'),
                    (int) DB::table('clotures_annulees')->where('boutique_id', app(TenantContext::class)->boutiqueId())->max('numero'),
                ) + 1,
                'jour_affaire' => $jour->toDateString(),
                'user_id' => $auteur->id,
                'totaux' => $this->rapports->periode($jour, $jour),
            ]);
        });
    }

    /** La dernière clôture, si elle porte sur aujourd'hui : la seule qui se rouvre. */
    public function rouvrable(): ?Cloture
    {
        $z = Cloture::latest('numero')->first();

        return $z !== null && $z->jour_affaire->isToday() ? $z : null;
    }

    /**
     * Rouvre la journée d'aujourd'hui, clôturée par erreur : le ticket Z est
     * annulé (sa trace va dans clotures_annulees, son numéro ne resert pas),
     * et les ventes faites depuis, comptées pour demain, reviennent sur
     * aujourd'hui, numérotées à la suite des tickets du jour.
     *
     * Le jour même seulement : le lendemain, la journée était finie, et ses
     * chiffres ont pu être remis (ticket Z imprimé, comptable).
     */
    public function rouvrir(User $auteur): Carbon
    {
        return DB::transaction(function () use ($auteur): Carbon {
            $z = Cloture::latest('numero')->lockForUpdate()->first();
            if ($z === null || ! $z->jour_affaire->isToday()) {
                throw ValidationException::withMessages(['journee' => [
                    'Seule la journée d’aujourd’hui se rouvre, le jour même de sa clôture.',
                ]]);
            }

            DB::table('clotures_annulees')->insert([
                'boutique_id' => $z->boutique_id,
                'numero' => $z->numero,
                'jour_affaire' => $z->jour_affaire->toDateString(),
                'cloturee_par' => $z->user_id,
                'cloturee_le' => $z->created_at,
                'totaux' => json_encode($z->totaux),
                'rouverte_par' => $auteur->id,
                'rouverte_par_nom' => $auteur->name,
                'rouverte_le' => now(),
            ]);

            $jour = $z->jour_affaire->copy();
            $numero = (int) Vente::whereDate('jour_affaire', $jour)->max('numero_jour');
            foreach (Vente::whereDate('jour_affaire', $jour->copy()->addDay())->orderBy('numero_jour')->orderBy('created_at')->get(['id']) as $vente) {
                Vente::whereKey($vente->id)->update(['jour_affaire' => $jour->toDateString(), 'numero_jour' => ++$numero]);
            }
            $z->delete();

            return $jour;
        });
    }
}
