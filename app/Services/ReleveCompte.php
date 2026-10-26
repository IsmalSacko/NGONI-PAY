<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Models\Client;
use App\Models\ReglementCredit;
use App\Models\Vente;
use App\Support\Money\Montant;
use Illuminate\Support\Carbon;

/**
 * Le relevé de compte d'un client : ce qu'il a pris à crédit et ce qu'il a
 * versé, dans l'ordre, avec ce qui reste après chaque ligne — « il prend
 * 50 000, verse 25 000, puis 2 600… ». Le solde se lit comme partout : positif,
 * il doit ; négatif, la boutique lui garde de l'argent (avoir).
 */
class ReleveCompte
{
    /**
     * @return array{du: ?string, au: ?string, report: int, lignes: list<array{date: string, detail: string, pris: int, verse: int, solde: int, type: string}>, pris: int, verse: int, solde: int}
     */
    public function pour(Client $client, ?Carbon $du = null, ?Carbon $au = null): array
    {
        $mouvements = collect();

        // Ce qu'il a pris à crédit : le reste non payé de chaque achat.
        foreach ($client->ventes()->valides()->where('reste_du', '>', 0)->with('lignes:id,vente_id,nom_produit')->get() as $v) {
            /** @var Vente $v */
            $articles = $v->lignes->pluck('nom_produit')->filter()->unique()->values();
            $detail = 'Achat '.$v->numero_facture
                .($articles->isEmpty() ? '' : ' : '.$articles->take(3)->join(', ').($articles->count() > 3 ? '…' : ''))
                .((int) $v->montant_paye > 0 ? ' (payé '.Montant::format((int) $v->montant_paye).' sur '.Montant::format((int) $v->total).')' : '');
            $mouvements->push(['le' => $v->created_at, 'ordre' => 0, 'detail' => $detail, 'pris' => (int) $v->reste_du, 'verse' => 0, 'type' => 'achat']);
        }

        // Ce qu'il a versé ; un montant négatif : de l'argent qu'on lui a rendu.
        foreach ($client->reglements()->get() as $r) {
            /** @var ReglementCredit $r */
            $montant = (int) $r->montant;
            if ($r->moyen_paiement === ReglementCredit::AJUSTEMENT) {
                $mouvements->push(['le' => $r->created_at, 'ordre' => 1, 'detail' => ($montant < 0 ? 'Dette augmentée' : 'Dette diminuée').($r->note ? ' : '.$r->note : ''),
                    'pris' => $montant < 0 ? -$montant : 0, 'verse' => max(0, $montant), 'type' => 'ajustement']);

                continue;
            }
            $moyen = MoyenPaiement::tryFrom((string) $r->moyen_paiement)?->label();
            $detail = ($montant < 0 ? 'Rendu au client' : 'Versement'.($r->note && $r->note !== 'Rendu au client' ? ' : '.$r->note : ''))
                .($moyen ? ' ('.$moyen.')' : '').($r->numero ? ' · reçu n° '.$r->numero : '');
            $mouvements->push(['le' => $r->created_at, 'ordre' => 1, 'detail' => $detail,
                'pris' => $montant < 0 ? -$montant : 0, 'verse' => max(0, $montant), 'type' => $montant < 0 ? 'rendu' : 'versement']);
        }

        $mouvements = $mouvements->sortBy([fn ($a, $b) => $a['le'] <=> $b['le'], fn ($a, $b) => $a['ordre'] <=> $b['ordre']])->values();

        // Avant la période : un seul report ; dans la période : chaque ligne.
        $solde = 0;
        $report = 0;
        $lignes = [];
        $pris = $verse = 0;
        foreach ($mouvements as $m) {
            $solde += $m['pris'] - $m['verse'];
            if ($du !== null && $m['le']->lt($du->copy()->startOfDay())) {
                $report = $solde;

                continue;
            }
            if ($au !== null && $m['le']->gt($au->copy()->endOfDay())) {
                continue;
            }
            $pris += $m['pris'];
            $verse += $m['verse'];
            $lignes[] = ['date' => $m['le']->toIso8601String(), 'detail' => $m['detail'], 'pris' => $m['pris'], 'verse' => $m['verse'], 'solde' => $solde, 'type' => $m['type']];
        }

        return [
            'du' => $du?->toDateString(),
            'au' => $au?->toDateString(),
            'report' => $report,
            'lignes' => $lignes,
            'pris' => $pris,
            'verse' => $verse,
            'solde' => $report + $pris - $verse,
        ];
    }
}
