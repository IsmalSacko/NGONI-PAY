<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\NotificationApp;
use App\Models\User;
use App\Models\Vente;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Le bilan d'un mois en PDF : chiffre, marge, meilleurs articles, moyens de
 * paiement, caissiers — à montrer à la banque ou à un associé.
 *
 * Le 1er de chaque mois, il part au propriétaire de chaque boutique qui a
 * vendu le mois précédent : notification et push. Pas d'e-mail : envoyé à
 * tous les propriétaires à la fois, il dépasserait le quota du serveur de
 * mails. Le PDF se télécharge à tout moment depuis l'application.
 */
class BilanMensuel
{
    public const TYPE = 'bilan_mensuel';

    public function __construct(
        private readonly Rapports $rapports,
        private readonly TenantContext $tenant,
        private readonly NotifierCompte $notifier,
    ) {}

    /** PDF du mois de `$mois` (n'importe quel jour du mois) pour `$boutique`. */
    public function pdf(Boutique $boutique, Carbon $mois): string
    {
        $precedent = $this->tenant->boutiqueId();
        $this->tenant->setBoutique($boutique->id);

        try {
            $debut = $mois->copy()->startOfMonth();
            $fin = $mois->copy()->endOfMonth();

            return Pdf::loadView('rapports.bilan-mensuel', [
                'boutique' => $boutique,
                'r' => $this->rapports->periode($debut, $fin),
                'precedent' => $this->rapports->periode($debut->copy()->subMonthNoOverflow()->startOfMonth(), $debut->copy()->subMonthNoOverflow()->endOfMonth()),
                'libelleMois' => $this->libelleMois($mois),
            ])->setPaper('a4')->output();
        } finally {
            $precedent === null ? $this->tenant->forget() : $this->tenant->setBoutique($precedent);
        }
    }

    public function nomFichier(Boutique $boutique, Carbon $mois): string
    {
        return 'bilan-'.Str::slug($boutique->nom).'-'.$mois->format('Y-m').'.pdf';
    }

    /** Envoie le bilan du mois précédent `$aujourdhui` ; rend le nombre de boutiques traitées. */
    public function envoyer(Carbon $aujourdhui): int
    {
        $mois = $aujourdhui->copy()->subMonthNoOverflow()->startOfMonth();
        $boutiqueIds = Vente::withoutBoutiqueScope()->valides()
            ->whereBetween('jour_affaire', [$mois->toDateString(), $mois->copy()->endOfMonth()->toDateString()])
            ->distinct()->pluck('boutique_id');

        $envoyes = 0;
        foreach (Boutique::whereIn('id', $boutiqueIds)->whereNotNull('proprietaire_id')->orderBy('nom')->get() as $boutique) {
            $proprietaire = User::find($boutique->proprietaire_id);
            if ($proprietaire === null || ! $proprietaire->is_active) {
                continue;
            }

            $titre = "Votre bilan de {$this->libelleMois($mois)} — {$boutique->nom}";
            if (NotificationApp::where('user_id', $proprietaire->id)->where('type', self::TYPE)->where('titre', $titre)->exists()) {
                continue;
            }

            $message = "Le bilan de {$this->libelleMois($mois)} est prêt : chiffre d’affaires, marge, meilleurs articles. "
                .'Retrouvez-le dans Pilotage, et téléchargez-le en PDF.';
            $this->notifier->envoyer($proprietaire, $titre, $message, '/pilotage', self::TYPE, email: false);
            $envoyes++;
        }

        return $envoyes;
    }

    private function libelleMois(Carbon $mois): string
    {
        return $mois->copy()->locale('fr')->translatedFormat('F Y');
    }
}
