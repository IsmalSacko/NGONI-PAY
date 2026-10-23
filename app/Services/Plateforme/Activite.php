<?php

declare(strict_types=1);

namespace App\Services\Plateforme;

use App\Models\Boutique;
use App\Support\Periode;
use App\Support\Presence\Appareil;

/**
 * L'activité de la plateforme sur une période, en données brutes, pour la
 * console de l'application (GET /api/plateforme/activite) — mêmes calculs
 * que la console web (Livewire Plateforme\Tableau).
 *
 * « En ligne » et « il y a 12 min » se calculent chez le lecteur à partir de
 * `vu_le` : un écran laissé ouvert reste juste, sans attendre le serveur.
 */
class Activite
{
    public function __construct(
        private readonly Encaissements $encaissements,
        private readonly Presences $presences,
    ) {}

    /** @return array<string, mixed> */
    public function pour(Periode $periode, int $recents = 12): array
    {
        $precedente = $periode->precedente();
        $total = $this->encaissements->totalFcfa($periode);
        $ventes = $this->encaissements->nombreDeVentes($periode);
        $nouvelles = Boutique::where('created_at', '>=', $periode->debut)->count();
        $parPaysUtilisateurs = $this->presences->parPays($periode);

        return [
            'periode' => ['code' => $periode->code, 'libelle' => $periode->libelle(), 'du' => $periode->debut->toIso8601String(), 'au' => $periode->fin->toIso8601String()],
            'en_ligne_minutes' => Presences::EN_LIGNE_MINUTES,
            'indicateurs' => [
                'boutiques' => [
                    'valeur' => Boutique::count(),
                    'nouvelles' => $nouvelles,
                    'variation' => Periode::variation($nouvelles, Boutique::whereBetween('created_at', [$precedente->debut, $precedente->fin])->count()),
                ],
                'utilisateurs_actifs' => ['valeur' => $this->presences->actifs($periode), 'total' => $this->presences->total()],
                'encaissements_fcfa' => ['valeur' => $total, 'variation' => Periode::variation($total, $this->encaissements->totalFcfa($precedente))],
                'ventes' => ['valeur' => $ventes, 'variation' => Periode::variation($ventes, $this->encaissements->nombreDeVentes($precedente))],
            ],
            'en_ligne' => [
                'nombre' => $this->presences->nombreEnLigne(),
                'recents' => $this->presences->recents($recents)->map(fn ($r) => [
                    'nom' => $r->nom,
                    'boutique' => $r->boutique,
                    'pays' => $r->pays,
                    'pays_libelle' => $r->pays ? Encaissements::libellePays($r->pays) : null,
                    'vu_le' => $r->vu_le?->toIso8601String(),
                    'plateforme' => $r->plateforme,
                    'plateforme_libelle' => Appareil::PLATEFORMES[$r->plateforme] ?? null,
                    'modele' => $r->modele,
                ])->values(),
            ],
            'par_boutique' => $this->encaissements->parBoutique($periode)->take(10)->map(fn ($b) => [
                'nom' => $b->nom,
                'pays' => $b->pays,
                'devise' => $b->devise,
                'ventes' => $b->ventes,
                'montant' => $b->montant,
                'fcfa' => $b->fcfa,
            ])->values(),
            'total_fcfa' => $total,
            'hors_fcfa' => (object) $this->encaissements->horsFcfa($periode),
            'par_jour' => collect($this->encaissements->parJour($periode))->map(fn (int $v, string $jour) => ['date' => $jour, 'fcfa' => $v])->values(),
            'plus_actives' => $this->encaissements->plusActives($periode)->map(fn ($b) => ['nom' => $b->nom, 'ventes' => $b->ventes, 'variation' => $b->variation])->values(),
            'par_pays' => $this->encaissements->parPays($periode)->map(fn (int $v, string $pays) => ['code' => $pays, 'libelle' => Encaissements::libellePays($pays), 'fcfa' => $v])->values(),
            'par_appareil' => $this->presences->parAppareil($periode)->map(fn (int $n, string $p) => ['plateforme' => $p, 'libelle' => Appareil::PLATEFORMES[$p] ?? 'Inconnu', 'nombre' => $n])->values(),
            'pays_plus_actif' => $parPaysUtilisateurs->isEmpty() ? null : [
                'code' => $parPaysUtilisateurs->keys()->first(),
                'libelle' => Encaissements::libellePays((string) $parPaysUtilisateurs->keys()->first()),
                'part' => (int) round($parPaysUtilisateurs->first() * 100 / max(1, $parPaysUtilisateurs->sum())),
            ],
        ];
    }
}
