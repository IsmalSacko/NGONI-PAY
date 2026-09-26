<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Cloture;
use App\Models\Produit;
use App\Models\Vente;
use App\Services\Rapports;
use App\Support\Money\Montant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports pour le comptable, de la boutique active : fichiers CSV qu'Excel
 * ouvre tels quels (UTF-8 avec BOM, séparateur « ; », montants en décimal
 * français), et rapport imprimable (enregistrable en PDF par le navigateur).
 */
class ExportController extends Controller
{
    public function ventes(Request $request): StreamedResponse
    {
        [$du, $au] = $this->periode($request);
        $devise = $this->boutique()->devise;

        return $this->csv("ventes-{$du->toDateString()}-{$au->toDateString()}", [
            'Date', 'Heure', 'N° facture', 'Statut', 'Caissier', 'Client', 'Paiement',
            'Article', 'Quantité', 'Prix unitaire', 'Total ligne', 'Remise (vente)', 'Total vente', 'Devise',
        ], function ($ecrire) use ($du, $au, $devise): void {
            Vente::with(['lignes', 'caissier:id,name', 'client:id,nom'])
                ->whereBetween('created_at', [$du->copy()->startOfDay(), $au->copy()->endOfDay()])
                ->orderBy('created_at')
                ->chunk(200, function ($ventes) use ($ecrire, $devise): void {
                    foreach ($ventes as $v) {
                        foreach ($v->lignes as $i => $l) {
                            $ecrire([
                                $v->created_at->format('d/m/Y'), $v->created_at->format('H:i'), $v->numero_facture,
                                $v->estAnnulee() ? 'Annulée' : 'Validée', $v->caissier?->name, $v->client?->nom,
                                $v->moyen_paiement->label(), $l->nom_produit, $l->quantite,
                                $this->nombre($l->prix_unitaire, $devise), $this->nombre($l->total_ligne, $devise),
                                $i === 0 ? $this->nombre($v->remise, $devise) : '', $i === 0 ? $this->nombre($v->total, $devise) : '', $devise,
                            ]);
                        }
                    }
                });
        });
    }

    public function stocks(): StreamedResponse
    {
        $devise = $this->boutique()->devise;

        return $this->csv('stocks-'.today()->toDateString(), [
            'Article', 'Format', 'Catégorie', 'Code-barres', 'Stock', 'Seuil d’alerte',
            'Prix d’achat', 'Prix de vente', 'Valeur au prix d’achat', 'Valeur au prix de vente', 'Devise',
        ], function ($ecrire) use ($devise): void {
            Produit::with('categorie:id,nom')->orderBy('nom')->chunk(300, function ($produits) use ($ecrire, $devise): void {
                foreach ($produits as $p) {
                    $ecrire([
                        $p->nom, $p->format, $p->categorie?->nom, $p->code_barre, $p->stock, $p->seuil_alerte,
                        $p->prix_achat === null ? '' : $this->nombre($p->prix_achat, $devise), $this->nombre($p->prix_vente, $devise),
                        $p->prix_achat === null ? '' : $this->nombre($p->prix_achat * $p->stock, $devise),
                        $this->nombre($p->prix_vente * $p->stock, $devise), $devise,
                    ]);
                }
            });
        });
    }

    public function credits(): StreamedResponse
    {
        $devise = $this->boutique()->devise;

        return $this->csv('dettes-clients-'.today()->toDateString(), ['Client', 'Téléphone', 'Doit', 'Devise'], function ($ecrire) use ($devise): void {
            Client::avecSoldeDu()->orderBy('nom')->get()
                ->map(fn (Client $c) => [$c, max(0, (int) $c->credit_total - (int) $c->reglements_total)])
                ->filter(fn ($l) => $l[1] > 0)
                ->each(fn ($l) => $ecrire([$l[0]->nom, $l[0]->telephone, $this->nombre($l[1], $devise), $devise]));
        });
    }

    public function imprimer(Request $request, Rapports $rapports): View
    {
        [$du, $au] = $this->periode($request);

        return view('rapports.imprimer', [
            'r' => $rapports->periode($du, $au),
            'boutique' => $this->boutique(),
            // Journée clôturée : le rapport est son ticket Z.
            'z' => $du->isSameDay($au) ? Cloture::whereDate('jour_affaire', $du)->first() : null,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periode(Request $request): array
    {
        $du = rescue(fn () => Carbon::createFromFormat('Y-m-d', (string) $request->query('du'))->startOfDay(), today(), false);
        $au = rescue(fn () => Carbon::createFromFormat('Y-m-d', (string) $request->query('au'))->startOfDay(), $du->copy(), false);
        if ($au->lt($du)) {
            [$du, $au] = [$au, $du];
        }

        return [$du, $du->diffInDays($au) > 366 ? $du->copy()->addDays(366) : $au];
    }

    private function boutique(): Boutique
    {
        return Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
    }

    /** Montant en décimal français (« 2500,00 »), lisible par Excel. */
    private function nombre(int $mineur, string $devise): string
    {
        return str_replace(' ', '', Montant::format($mineur, $devise));
    }

    private function csv(string $nom, array $entetes, callable $lignes): StreamedResponse
    {
        $fichier = Str::slug($this->boutique()->nom).'-'.$nom.'.csv';

        return response()->streamDownload(function () use ($entetes, $lignes): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, $entetes, ';');
            $lignes(fn (array $ligne) => fputcsv($sortie, $ligne, ';'));
            fclose($sortie);
        }, $fichier, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
