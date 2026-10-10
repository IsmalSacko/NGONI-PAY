<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Enums\MoyenPaiement;
use App\Enums\StatutDemande;
use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Abonnements payés, par canal : en ligne (Jèko, pawaPay, FedaPay, activés
 * seuls) ou validés à la main par l'exploitant (Wave, Orange Money… payés hors
 * de l'application). Les abonnements accordés depuis la console sans demande
 * de paiement sont comptés à part.
 */
class AbonnementsConsole
{
    public const CANAUX = [
        '' => 'Tous',
        'jeko' => 'Jèko',
        'pawapay' => 'pawaPay',
        'fedapay' => 'FedaPay',
        'manuel' => 'Validés à la main',
    ];

    public const PERIODES = [
        'mois' => 'Ce mois-ci',
        '30j' => '30 derniers jours',
        'annee' => 'Cette année',
        'tout' => 'Depuis le début',
    ];

    private const EN_LIGNE = ['jeko', 'pawapay', 'fedapay'];

    /**
     * @param  array{canal?: ?string, periode?: ?string, recherche?: ?string}  $f
     * @return Builder<DemandeAbonnement>
     */
    public function requete(array $f): Builder
    {
        $q = DemandeAbonnement::query()->where('statut', StatutDemande::Approuvee->value)
            ->with(['boutique' => fn ($b) => $b->withoutGlobalScopes(), 'proprietaire:id,name,phone']);

        $this->periode($q, $f['periode'] ?? 'tout');
        $this->canal($q, $f['canal'] ?? '');

        if (filled($f['recherche'] ?? null)) {
            $q->whereHas('proprietaire', fn ($u) => $u->recherche((string) $f['recherche']));
        }

        return $q->latest('decide_le');
    }

    /** Nombre, montant encaissé et frais par canal, sur la période. @return array<string, array{nombre: int, montant: int, frais: int}> */
    public function totaux(string $periode = 'tout'): array
    {
        $totaux = [];
        foreach (array_keys(self::CANAUX) as $canal) {
            $q = DemandeAbonnement::query()->where('statut', StatutDemande::Approuvee->value);
            $this->periode($q, $periode);
            $this->canal($q, $canal);
            $totaux[$canal] = [
                'nombre' => (clone $q)->count(),
                'montant' => (int) (clone $q)->sum('montant'),
                'frais' => (int) (clone $q)->sum('frais_mobile'),
            ];
        }

        return $totaux;
    }

    /** Abonnements accordés depuis la console, sans demande de paiement. */
    public function accordes(): int
    {
        return Abonnement::where('est_manuel', true)->count();
    }

    /** « jeko_wave » → jeko ; « wave » → manuel. */
    public static function canalDe(?string $moyen): string
    {
        $prefixe = strtok((string) $moyen, '_');

        return in_array($prefixe, self::EN_LIGNE, true) ? $prefixe : 'manuel';
    }

    /** « jeko_wave » → « Wave via Jèko » ; « orange_money » → « Orange Money ». */
    public static function moyenLibelle(?string $moyen): string
    {
        if (blank($moyen)) {
            return '—';
        }
        $canal = self::canalDe($moyen);
        if ($canal === 'manuel') {
            return MoyenPaiement::tryFrom($moyen)?->label() ?? ucfirst(str_replace('_', ' ', $moyen));
        }
        // « free_sen » → free ; « mtn_momo_ben » → mtn_momo ; « wave » → wave.
        $operateur = (string) preg_replace('/_[a-z]{3}$/', '', substr($moyen, strlen($canal) + 1));
        $nom = match ($operateur) {
            'orange' => 'Orange Money',
            'mtn', 'mtn_momo' => 'MTN MoMo',
            'moov' => 'Moov Money',
            'free' => 'Free Money',
            'airtel', 'airtel_oapi' => 'Airtel Money',
            default => (string) (config('jeko.moyens')[$operateur] ?? ucwords(str_replace('_', ' ', $operateur))),
        };

        return trim($nom.' via '.self::CANAUX[$canal]);
    }

    /** @return array<string, mixed> */
    public static function ligne(DemandeAbonnement $d): array
    {
        return [
            'id' => $d->id,
            'le' => $d->decide_le?->toIso8601String(),
            'boutique' => $d->boutique?->nom,
            'proprietaire' => $d->proprietaire?->name,
            'telephone' => $d->proprietaire?->phone,
            'plan' => $d->plan,
            'cycle' => $d->cycle?->libelle(),
            'montant' => (int) $d->montant,
            'frais' => (int) $d->frais_mobile,
            'devise' => $d->devise,
            'canal' => self::canalDe($d->moyen),
            'moyen' => self::moyenLibelle($d->moyen),
            'automatique' => $d->decide_par === null,
            'recu' => $d->recu_numero,
        ];
    }

    private function periode(Builder $q, string $periode): void
    {
        $depuis = match ($periode) {
            'mois' => now()->startOfMonth(),
            '30j' => now()->subDays(30),
            'annee' => now()->startOfYear(),
            default => null,
        };
        if ($depuis instanceof Carbon) {
            $q->where('decide_le', '>=', $depuis);
        }
    }

    private function canal(Builder $q, string $canal): void
    {
        if (in_array($canal, self::EN_LIGNE, true)) {
            $q->where('moyen', 'like', $canal.'_%');
        } elseif ($canal === 'manuel') {
            $q->where(fn ($w) => $w->whereNull('moyen')
                ->orWhere(fn ($m) => $m->where('moyen', 'not like', 'jeko_%')->where('moyen', 'not like', 'pawapay_%')->where('moyen', 'not like', 'fedapay_%')));
        }
    }
}
