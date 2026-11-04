<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Abonnement;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ce que les commerçants doivent encore sur leur abonnement : les ventes à
 * crédit de la boutique des ventes d'abonnement (`ecaisse.boutique_ventes_abonnements`),
 * moins leurs règlements. Chaque fiche client y est reliée à son compte Ngoni
 * Caisse — par son téléphone, sinon par le numéro noté dans une demande
 * d'abonnement, sinon par l'e-mail — pour le relancer par une notification.
 *
 * Relance : à la main depuis la Console Ngoni, et une fois par mois pour tous
 * (`ecaisse:relancer-creances`).
 */
class CreancesAbonnements
{
    public const TITRE = 'Rappel automatique';

    /** La tâche du mois ne relance pas un compte relancé depuis moins de 7 jours. */
    public const DELAI_JOURS = 7;

    /** @var Collection<string, array<string, mixed>>|null */
    private ?Collection $cache = null;

    public function __construct(private readonly GestionAnnonces $annonces) {}

    /**
     * Les dettes, par compte (id du commerçant).
     *
     * @return Collection<string, array{montant: int, total: int, devise: string, depuis: ?string, client_id: string, client_nom: string, derniere_relance: ?string}>
     */
    public function parCompte(): Collection
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $boutique = config('ecaisse.boutique_ventes_abonnements');
        if (blank($boutique)) {
            return $this->cache = collect();
        }

        $ventes = DB::table('ventes')->where('boutique_id', $boutique)->where('statut', 'validee')->whereNotNull('client_id')
            ->groupBy('client_id')
            ->selectRaw('client_id, SUM(reste_du) as du, SUM(CASE WHEN reste_du > 0 THEN total ELSE 0 END) as total, MIN(CASE WHEN reste_du > 0 THEN created_at END) as depuis')
            ->get()->keyBy('client_id');
        $regle = DB::table('reglements_credit')->where('boutique_id', $boutique)->groupBy('client_id')
            ->selectRaw('client_id, SUM(montant) as n')->pluck('n', 'client_id');

        $dettes = collect();
        $clients = DB::table('clients')->where('boutique_id', $boutique)->whereNull('deleted_at')
            ->whereIn('id', $ventes->keys())->get(['id', 'nom', 'telephone', 'email']);
        foreach ($clients as $c) {
            $du = (int) $ventes[$c->id]->du - (int) ($regle[$c->id] ?? 0);
            if ($du <= 0) {
                continue;
            }
            $compte = $this->compteDe($c);
            if ($compte === null || $dettes->has($compte)) {
                continue;
            }
            $dettes[$compte] = [
                'montant' => $du,
                'total' => max($du, (int) $ventes[$c->id]->total),
                'devise' => 'XOF',
                'depuis' => $ventes[$c->id]->depuis ? Carbon::parse($ventes[$c->id]->depuis)->toDateString() : null,
                'client_id' => (string) $c->id,
                'client_nom' => (string) $c->nom,
                'derniere_relance' => null,
            ];
        }

        if ($dettes->isNotEmpty()) {
            $relances = DB::table('relances_creances')->whereIn('user_id', $dettes->keys())->groupBy('user_id')
                ->selectRaw('user_id, MAX(created_at) as le')->pluck('le', 'user_id');
            $dettes = $dettes->map(fn (array $d, string $id) => ['derniere_relance' => isset($relances[$id]) ? Carbon::parse($relances[$id])->toIso8601String() : null] + $d);
        }

        return $this->cache = $dettes;
    }

    /** @return array<string, mixed>|null la dette du compte, avec le message qui partirait */
    public function de(User $u): ?array
    {
        $d = $this->parCompte()->get((string) $u->id);

        return $d === null ? null : $d + ['message' => $this->message($u, $d)];
    }

    /** Le compte du commerçant derrière une fiche client de la boutique des ventes d'abonnement. */
    private function compteDe(object $client): ?string
    {
        $chiffres = preg_replace('/\D/', '', (string) $client->telephone);
        $comptes = User::query()->where('est_admin_plateforme', false);

        if (strlen($chiffres) >= 8) {
            $fin = substr($chiffres, -8);
            $exact = (clone $comptes)->where('phone', $client->telephone)->value('id')
                ?? (clone $comptes)->where('phone', 'like', "%{$fin}")->orderByDesc('vu_le')->value('id');
            if ($exact !== null) {
                return (string) $exact;
            }
            // Le numéro donné dans une demande d'abonnement (contact ou note).
            $demande = DB::table('demandes_abonnement')
                ->where(fn ($q) => $q->where('telephone_contact', 'like', "%{$fin}")->orWhere('note', 'like', "%{$fin}%"))
                ->whereIn('user_id', (clone $comptes)->select('id'))
                ->orderByDesc('created_at')->value('user_id');
            if ($demande !== null) {
                return (string) $demande;
            }
        }
        if (filled($client->email)) {
            $parMail = (clone $comptes)->where('email', $client->email)->value('id');
            if ($parMail !== null) {
                return (string) $parMail;
            }
        }

        return null;
    }

    /**
     * « Bonjour Mansour, votre abonnement Pro est actif depuis le 04/10/2026.
     * Sur les 70 000 F de cet abonnement, 20 000 F ont été réglés : il reste
     * 50 000 F à payer. Merci de régulariser auprès de Ngoni Caisse. »
     *
     * @param  array{montant: int, total: int, depuis: ?string}  $d
     */
    public function message(User $u, array $d): string
    {
        $f = fn (int $n) => number_format($n, 0, ',', ' ').' F';
        $a = Abonnement::where('user_id', $u->id)->first();
        $plan = $a === null ? null : (Plan::parCode($a->plan)?->nom ?? ucfirst((string) $a->plan));

        $abonnement = match (true) {
            $a === null || $a->estEssai() => null,
            $a->estEnCours() => "votre abonnement {$plan} est actif depuis le {$a->debut->format('d/m/Y')}"
                .($a->fin !== null ? " et prend fin le {$a->fin->format('d/m/Y')}" : '').'.',
            default => "votre abonnement {$plan} a pris fin le ".($a->fin?->format('d/m/Y') ?? '').'.',
        };
        $paye = $d['total'] - $d['montant'];
        $reste = $paye > 0
            ? ($abonnement === null ? 'sur vos ' : 'Sur les ').$f($d['total']).($abonnement === null ? ' d’achats' : ' de cet abonnement').", {$f($paye)} ont été réglés : il reste {$f($d['montant'])} à payer."
            : ($abonnement === null ? 'le montant de ' : 'Le montant de ').$f($d['montant']).' n’a pas encore été réglé.';

        return 'Bonjour '.trim((string) $u->name).', '.($abonnement === null ? $reste : $abonnement.' '.$reste)
            .' Merci de régulariser auprès de Ngoni Caisse.';
    }

    /**
     * Envoie le rappel maintenant (notification dans l'application et push).
     *
     * @return array{message: string, texte: string}
     */
    public function relancer(User $u, User $auteur, string $par, bool $automatique = false): array
    {
        $d = $this->de($u);
        if ($d === null) {
            throw ValidationException::withMessages(['compte' => ['Ce compte ne doit rien : pas de relance.']]);
        }
        if (! $u->is_active) {
            throw ValidationException::withMessages(['compte' => ['Ce compte est désactivé : il ne recevrait pas la relance.']]);
        }

        ['annonce' => $annonce] = $this->annonces->creer([
            'type' => 'message', 'audience' => 'selection', 'quand' => 'maintenant',
            'cibles' => [(string) $u->id], 'titre' => self::TITRE, 'message' => $d['message'],
        ], $auteur);
        DB::table('relances_creances')->insert([
            'user_id' => $u->id, 'client_id' => $d['client_id'], 'montant' => $d['montant'], 'annonce_id' => $annonce->id,
            'automatique' => $automatique, 'par' => mb_substr($par, 0, 120), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // La dette reste ; seule la date de la dernière relance change.
        $this->cache?->put((string) $u->id, ['derniere_relance' => now()->toIso8601String()] + $this->cache->get((string) $u->id));

        return ['message' => "Relance envoyée à {$u->name} (".number_format($d['montant'], 0, ',', ' ').' F).', 'texte' => $d['message']];
    }

    /**
     * La relance du mois : chaque compte qui doit encore, sauf ceux relancés
     * depuis moins de 7 jours.
     *
     * @return array{relances: int, ignores: int}
     */
    public function relancerTous(User $auteur): array
    {
        $n = $ignores = 0;
        foreach ($this->parCompte() as $id => $d) {
            $recente = $d['derniere_relance'] !== null && Carbon::parse($d['derniere_relance'])->gt(now()->subDays(self::DELAI_JOURS));
            $u = User::find($id);
            if ($recente || $u === null || ! $u->is_active) {
                $ignores++;

                continue;
            }
            $this->relancer($u, $auteur, 'Relance du mois', automatique: true);
            $n++;
        }

        return ['relances' => $n, 'ignores' => $ignores];
    }
}
