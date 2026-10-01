<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\Country;
use App\Enums\CycleFacturation;
use App\Enums\StatutDemande;
use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\DemandeAbonnement;
use App\Models\LigneVente;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueRegistrationService;
use App\Services\EquipeService;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reprise des données de Ngoni Pay dans e-caisse, à la bascule.
 *
 * - users → comptes (même téléphone, même empreinte de mot de passe : chacun se
 *   reconnecte avec son mot de passe habituel) ; system_admin → exploitant ;
 * - businesses → boutiques (propriétaire, pays du propriétaire, devise) ; une
 *   entreprise désactivée devient une boutique supprimée (soft delete) ;
 * - business_users → équipe (manager → gérant) ;
 * - clients → clients (e-mail et notes compris) ;
 * - payments « sale » → ventes libres (montant et libellé, sans produit),
 *   numérotées par boutique dans l'ordre ; « cancelled » → vente annulée,
 *   gardée mais jamais comptée ; les paiements d'abonnement ne sont pas repris ;
 * - subscriptions (par entreprise) → abonnement du compte propriétaire : le
 *   meilleur de ses entreprises ; trial → essai ;
 * - subscription_requests → demandes d'abonnement.
 *
 * Tout ou rien : une seule transaction.
 */
class ImportNgoniPay
{
    private const MOYENS = [
        'cash' => 'especes', 'orange_money' => 'orange_money', 'moov_money' => 'moov_money',
        'wave' => 'wave', 'bank_transfer' => 'virement', 'card' => 'carte',
    ];

    private const ROLES = ['manager' => 'gerant', 'owner' => 'admin', 'admin' => 'admin', 'cashier' => 'caissier', 'staff' => 'caissier'];

    private const STATUTS_DEMANDE = [
        'pending' => StatutDemande::EnAttente, 'approved' => StatutDemande::Approuvee,
        'refused' => StatutDemande::Refusee, 'cancelled' => StatutDemande::Annulee,
    ];

    /** @var array<int, string> id Ngoni Pay → uuid e-caisse */
    private array $users = [];

    /** @var array<int, string> */
    private array $boutiques = [];

    /** @var array<int, string> */
    private array $clients = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(
        private readonly BoutiqueRegistrationService $registration,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array<string, int> */
    public function importer(ConnectionInterface $source): array
    {
        $this->stats = [];

        DB::transaction(function () use ($source): void {
            $this->importerUtilisateurs($source);
            $this->importerBoutiques($source);
            $this->importerEquipes($source);
            $this->definirBoutiquesParDefaut();
            $this->importerClients($source);
            $this->importerVentes($source);
            $this->importerAbonnements($source);
            $this->importerDemandes($source);
        });

        $this->tenant->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->stats;
    }

    private function compter(string $cle, int $n = 1): void
    {
        $this->stats[$cle] = ($this->stats[$cle] ?? 0) + $n;
    }

    private function importerUtilisateurs(ConnectionInterface $source): void
    {
        foreach ($source->table('users')->orderBy('id')->get() as $u) {
            $id = (string) Str::uuid7();
            // Insertion directe : l'empreinte bcrypt est reprise telle quelle
            // (le cast « hashed » la rehacherait).
            DB::table('users')->insert([
                'id' => $id,
                'boutique_id' => null,
                'name' => $u->name,
                'phone' => $u->phone,
                'email' => $u->email ?: null,
                'email_verified_at' => $u->email_verified_at,
                'password' => $u->password,
                'is_active' => (bool) $u->is_active,
                'est_admin_plateforme' => $u->role === 'system_admin',
                'created_at' => $u->created_at,
                'updated_at' => $u->updated_at,
            ]);
            $this->users[(int) $u->id] = $id;
            $this->compter('utilisateurs');
        }
    }

    private function importerBoutiques(ConnectionInterface $source): void
    {
        $pays = $source->table('users')->pluck('country', 'id');

        foreach ($source->table('businesses')->orderBy('id')->get() as $b) {
            $proprietaire = $this->users[(int) $b->owner_id] ?? null;
            $country = Country::tryFrom(strtoupper((string) ($pays[$b->owner_id] ?? ''))) ?? Country::default();

            $boutique = new Boutique;
            $boutique->forceFill([
                'id' => (string) Str::uuid7(),
                'proprietaire_id' => $proprietaire,
                'nom' => $b->name,
                'pays' => $country->value,
                'devise' => strtoupper((string) ($b->currency ?: $country->currency())),
                'telephone' => $b->phone,
                'adresse' => $b->address,
                'created_at' => $b->created_at,
                'updated_at' => $b->updated_at,
                'deleted_at' => $b->is_active ? null : ($b->updated_at ?? now()),
            ])->save();

            $this->boutiques[(int) $b->id] = $boutique->id;
            $this->entrer($boutique->id);
            $this->registration->provisionnerRoles($boutique);

            if ($proprietaire !== null) {
                User::find($proprietaire)?->assignRole('admin');
            }

            $this->compter($b->is_active ? 'boutiques' : 'boutiques_desactivees');
        }
    }

    private function importerEquipes(ConnectionInterface $source): void
    {
        foreach ($source->table('business_users')->orderBy('id')->get() as $m) {
            $boutique = $this->boutiques[(int) $m->business_id] ?? null;
            $user = isset($this->users[(int) $m->user_id]) ? User::find($this->users[(int) $m->user_id]) : null;

            if ($boutique === null || $user === null) {
                continue;
            }

            $this->entrer($boutique);
            $user->unsetRelation('roles');

            // Le propriétaire est déjà admin de sa boutique.
            if (! $user->hasRole('admin')) {
                $role = self::ROLES[$m->role] ?? 'caissier';
                $user->assignRole($role);
                // Un gérant importé garde ce que son rôle donnait (chiffre d'affaires…).
                EquipeService::droitsParDefaut($user, $role);
                $this->compter('membres');
            }
        }
    }

    /** Boutique ouverte à la connexion : la première active du compte. */
    private function definirBoutiquesParDefaut(): void
    {
        $actives = Boutique::pluck('id')->all();

        foreach (User::all() as $user) {
            $defaut = collect($user->boutiqueIds())->first(fn ($id) => in_array($id, $actives, true));
            if ($defaut !== null) {
                $user->forceFill(['boutique_id' => $defaut])->saveQuietly();
            }
        }
    }

    private function importerClients(ConnectionInterface $source): void
    {
        foreach ($source->table('clients')->orderBy('id')->get() as $c) {
            $boutique = $this->boutiques[(int) $c->business_id] ?? null;
            if ($boutique === null) {
                continue;
            }

            $this->entrer($boutique);
            $client = new Client;
            $client->forceFill([
                'id' => (string) Str::uuid7(),
                'boutique_id' => $boutique,
                'nom' => $c->name ?: 'Client',
                'telephone' => $c->phone,
                'email' => $c->email,
                'notes' => $c->notes,
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
            ])->save();

            $this->clients[(int) $c->id] = $client->id;
            $this->compter('clients');
        }
    }

    private function importerVentes(ConnectionInterface $source): void
    {
        $proprietaires = Boutique::withTrashed()->pluck('proprietaire_id', 'id');
        $numeros = [];

        $paiements = $source->table('payments')
            ->where('purpose', 'sale')
            ->whereIn('status', ['success', 'cancelled'])
            ->orderBy('created_at')->orderBy('id')
            ->get();

        foreach ($paiements as $p) {
            $boutique = $this->boutiques[(int) $p->business_id] ?? null;
            $caissier = $this->users[(int) $p->user_id] ?? ($boutique !== null ? $proprietaires[$boutique] : null);

            if ($boutique === null || $caissier === null) {
                $this->compter('paiements_ignores');

                continue;
            }

            $this->entrer($boutique);
            $montant = (int) round((float) $p->amount);
            $numeros[$boutique] = ($numeros[$boutique] ?? 0) + 1;
            $reference = Str::isUuid((string) $p->idempotency_key) ? $p->idempotency_key
                : (Str::isUuid((string) $p->transaction_ref) ? $p->transaction_ref : (string) Str::uuid7());

            $vente = new Vente;
            $vente->forceFill([
                'id' => (string) Str::uuid7(),
                'boutique_id' => $boutique,
                'user_id' => $caissier,
                'client_id' => $this->clients[(int) $p->client_id] ?? null,
                'reference_locale' => $reference,
                'numero' => $numeros[$boutique],
                'sous_total' => $montant,
                'remise' => 0,
                'tva' => 0,
                'total' => $montant,
                'moyen_paiement' => self::MOYENS[$p->method] ?? 'especes',
                'montant_recu' => null,
                'monnaie_rendue' => null,
                'statut' => $p->status === 'cancelled' ? Vente::STATUT_ANNULEE : Vente::STATUT_VALIDEE,
                'vendue_hors_ligne' => false,
                'synchronisee_le' => $p->paid_at ?? $p->created_at,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ])->save();

            $ligne = new LigneVente;
            $ligne->forceFill([
                'id' => (string) Str::uuid7(),
                'vente_id' => $vente->id,
                'produit_id' => null,
                'nom_produit' => 'Encaissement',
                'prix_unitaire' => $montant,
                'taux_tva' => 0,
                'quantite' => 1,
                'total_ligne' => $montant,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ])->save();

            $this->compter($p->status === 'cancelled' ? 'ventes_annulees' : 'ventes');
        }
    }

    /**
     * L'abonnement était par entreprise ; il devient celui du compte
     * propriétaire : le meilleur de ses entreprises (en cours d'abord, puis la
     * plus longue échéance, sans échéance en tête).
     */
    private function importerAbonnements(ConnectionInterface $source): void
    {
        $proprietaireDe = $source->table('businesses')->pluck('owner_id', 'id');
        $parCompte = [];

        foreach ($source->table('subscriptions')->get() as $s) {
            $proprietaire = $this->users[(int) ($proprietaireDe[$s->business_id] ?? 0)] ?? null;
            if ($proprietaire === null) {
                continue;
            }

            $candidat = [
                'plan' => $s->plan === 'trial' || $s->plan === 'free' ? 'essai' : $s->plan,
                'debut' => Carbon::parse($s->starts_at)->toDateString(),
                'fin' => $s->ends_at === null ? null : Carbon::parse($s->ends_at)->toDateString(),
                'est_actif' => (bool) $s->is_active,
                'est_manuel' => (bool) $s->is_manual,
                'accorde_par' => $this->users[(int) $s->granted_by] ?? null,
                'note_admin' => $s->admin_note,
            ];

            $actuel = $parCompte[$proprietaire] ?? null;
            if ($actuel === null || $this->meilleur($candidat, $actuel)) {
                $parCompte[$proprietaire] = $candidat;
            }
        }

        foreach ($parCompte as $userId => $a) {
            Abonnement::create(['user_id' => $userId] + $a);
            $this->compter('abonnements');
        }
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function meilleur(array $a, array $b): bool
    {
        $enCours = fn (array $x) => $x['est_actif'] && ($x['fin'] === null || $x['fin'] >= now()->toDateString());

        if ($enCours($a) !== $enCours($b)) {
            return $enCours($a);
        }
        if ($a['fin'] === null || $b['fin'] === null) {
            return $a['fin'] === null && $b['fin'] !== null;
        }

        return $a['fin'] > $b['fin'];
    }

    private function importerDemandes(ConnectionInterface $source): void
    {
        $proprietaireDe = $source->table('businesses')->pluck('owner_id', 'id');

        foreach ($source->table('subscription_requests')->orderBy('id')->get() as $d) {
            $proprietaire = $this->users[(int) ($proprietaireDe[$d->business_id] ?? 0)] ?? null;
            if ($proprietaire === null) {
                continue;
            }

            $cycle = CycleFacturation::tryFrom((string) $d->cycle) ?? CycleFacturation::Mensuel;

            DemandeAbonnement::create([
                'user_id' => $proprietaire,
                'demande_par' => $this->users[(int) $d->requested_by_user_id] ?? null,
                'boutique_id' => $this->boutiques[(int) $d->business_id] ?? null,
                'plan' => $d->plan,
                'cycle' => $cycle,
                'mois' => (int) ($d->months ?: $cycle->mois()),
                'montant' => (int) round((float) $d->amount_due),
                'devise' => $d->currency ?: 'XOF',
                'moyen' => self::MOYENS[$d->method] ?? null,
                'note' => $d->note,
                'telephone_contact' => $d->contact_phone,
                'preuve_note' => $d->proof_note,
                'statut' => self::STATUTS_DEMANDE[$d->status] ?? StatutDemande::Annulee,
                'decide_le' => $d->decided_at,
                'decide_par' => $this->users[(int) $d->decided_by_user_id] ?? null,
                'note_decision' => $d->decision_note,
            ])->forceFill(['created_at' => $d->created_at, 'updated_at' => $d->updated_at])->save();

            $this->compter('demandes');
        }
    }

    private function entrer(string $boutiqueId): void
    {
        $this->tenant->setBoutique($boutiqueId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutiqueId);
    }
}
