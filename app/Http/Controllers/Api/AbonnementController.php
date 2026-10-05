<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CycleFacturation;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Models\PlanTarif;
use App\Services\AbonnementService;
use App\Services\PaiementJeko;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Abonnement vu depuis l'application : état, plans proposés, demandes.
 */
class AbonnementController extends Controller
{
    public function __construct(private readonly AbonnementService $abonnements) {}

    /** Catalogue public : plans payants, leurs tarifs, et le contact de l'exploitant. */
    public function plans(): JsonResponse
    {
        $plans = Plan::with('tarifs')->actifs()->ordonnes()->get()->map(fn (Plan $plan) => [
            'code' => $plan->code,
            'nom' => $plan->nom,
            'description' => $plan->description,
            'fonctionnalites' => $plan->fonctionnalitesIncluses(),
            'est_essai' => $plan->estEssai(),
            'jours_essai' => $plan->estEssai() ? $plan->joursEssai() : null,
            'max_boutiques' => $plan->max_boutiques,
            'max_membres' => $plan->max_membres,
            'tarifs' => $plan->tarifs->where('est_actif', true)
                ->sortBy(fn (PlanTarif $t) => $t->cycle->mois())
                ->map(fn (PlanTarif $t) => [
                    'cycle' => $t->cycle->value,
                    'libelle' => $t->cycle->libelle(),
                    'unite' => $t->cycle->unite(),
                    'mois' => $t->cycle->mois(),
                    'montant' => $t->montant,
                    'devise' => $t->devise,
                ])->values(),
        ]);

        return response()->json([
            'data' => $plans,
            // Les fonctions que la console coche par plan, dans l'ordre : le
            // comparatif de l'application les affiche telles quelles.
            'communes' => Plan::COMMUNES,
            'fonctionnalites' => collect(Plan::FONCTIONNALITES)->map(fn (string $libelle, string $code) => ['code' => $code, 'libelle' => $libelle])->values(),
            'support' => [
                'whatsapp' => config('ecaisse.support_whatsapp'),
                'whatsapps' => array_values(array_filter(array_map('trim', explode(';', (string) config('ecaisse.supports_whatsapp'))))),
                'paiement' => $this->numerosPaiement(),
            ],
        ]);
    }

    /** État de l'abonnement qui couvre la boutique active. */
    public function show(Request $request): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        $abonnement = $this->abonnements->pourBoutique($boutique);
        $plan = $this->abonnements->planDe($abonnement);

        // Abonnement terminé ou révoqué : l'application ne doit plus présenter
        // les fonctions du plan comme incluses (le serveur les refuse déjà).
        // Seul ce qui reste consultable après l'échéance demeure : le
        // back-office et l'historique des achats.
        $fonctions = $plan?->fonctionnalitesIncluses() ?? [];
        if (! ($abonnement?->estEnCours() ?? false)) {
            $fonctions = array_values(array_intersect($fonctions, [Plan::BACKOFFICE_WEB, Plan::ACHATS_FOURNISSEURS]));
        }

        $enAttente = $boutique->proprietaire_id === null ? null
            : DemandeAbonnement::where('user_id', $boutique->proprietaire_id)->enAttente()->latest('id')->first();

        return response()->json([
            'data' => [
                'plan' => $abonnement?->plan,
                'plan_nom' => $plan?->nom,
                'est_essai' => $abonnement?->estEssai() ?? false,
                'est_en_cours' => $abonnement?->estEnCours() ?? false,
                'debut' => $abonnement?->debut?->toDateString(),
                'fin' => $abonnement?->fin?->toDateString(),
                'max_boutiques' => $plan?->max_boutiques,
                'max_membres' => $plan?->max_membres,
                'fonctionnalites' => $fonctions,
                'est_proprietaire' => $boutique->proprietaire_id === $request->user()->id,
                'peut_demander' => $request->user()->can('abonnement.manage'),
                'demande_en_attente' => $enAttente === null ? null : $this->demandeJson($enAttente),
                // Paiement Mobile Money intégré (Jèko) : boutiques ivoiriennes seulement.
                'paiement_mobile' => app(PaiementJeko::class)->proposeA($boutique)
                    ? ['frais_pourcentage' => (float) config('jeko.frais_pourcentage'), 'moyens' => collect(config('jeko.moyens'))->map(fn (string $libelle, string $code) => ['code' => $code, 'libelle' => $libelle])->values()]
                    : null,
            ],
        ]);
    }

    /** Payer une offre par Mobile Money (Jèko) : la page de paiement où envoyer le commerçant. */
    public function payerMobile(Request $request, PaiementJeko $jeko): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:30'],
            'cycle' => ['nullable', Rule::enum(CycleFacturation::class)],
            'moyen' => ['required', 'string', 'max:20'],
        ]);

        $resultat = $jeko->demarrer(
            Boutique::findOrFail(app(TenantContext::class)->boutiqueId()),
            $request->user(),
            $data['plan'],
            CycleFacturation::tryFrom((string) ($data['cycle'] ?? '')) ?? CycleFacturation::Mensuel,
            $data['moyen'],
        );

        return response()->json(['data' => $this->demandeJson($resultat['demande']), 'redirect_url' => $resultat['redirect_url']], 201);
    }

    /** Au retour du commerçant : le paiement relu chez Jèko, l'abonnement activé s'il est payé. */
    public function statutPaiementMobile(DemandeAbonnement $demande, PaiementJeko $jeko): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        abort_unless($demande->user_id === $boutique->proprietaire_id && $demande->jeko_paiement_id !== null, 404);

        return response()->json(['data' => $this->demandeJson($jeko->verifier($demande))]);
    }

    public function demandes(): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());

        $demandes = DemandeAbonnement::where('user_id', $boutique->proprietaire_id)
            ->latest('id')->limit(20)->get()
            ->map(fn (DemandeAbonnement $d) => $this->demandeJson($d));

        return response()->json(['data' => $demandes]);
    }

    public function demander(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:30'],
            'cycle' => ['nullable', Rule::enum(CycleFacturation::class)],
            'moyen' => ['nullable', Rule::in(['especes', 'orange_money', 'moov_money', 'wave', 'virement'])],
            'note' => ['nullable', 'string', 'max:500'],
            'telephone_contact' => ['nullable', 'string', 'max:30'],
            'preuve' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'preuve_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());

        $demande = $this->abonnements->soumettre(
            boutique: $boutique,
            demandeur: $request->user(),
            codePlan: $data['plan'],
            cycle: CycleFacturation::tryFrom((string) ($data['cycle'] ?? '')) ?? CycleFacturation::Mensuel,
            moyen: $data['moyen'] ?? null,
            note: $data['note'] ?? null,
            telephoneContact: $data['telephone_contact'] ?? null,
            preuve: $request->file('preuve'),
            preuveNote: $data['preuve_note'] ?? null,
        );

        return response()->json([
            'message' => 'Demande envoyée. Elle sera traitée dès réception de votre paiement.',
            'code' => 'DEMANDE_EN_ATTENTE',
            'data' => $this->demandeJson($demande),
        ], 202);
    }

    public function annuler(DemandeAbonnement $demande): JsonResponse
    {
        $boutique = Boutique::findOrFail(app(TenantContext::class)->boutiqueId());
        abort_unless($demande->user_id === $boutique->proprietaire_id, 404);

        $this->abonnements->annuler($demande);

        return response()->json(['message' => 'Demande retirée.']);
    }

    /** @return array<string, mixed> */
    private function demandeJson(DemandeAbonnement $d): array
    {
        return [
            'id' => $d->id,
            'plan' => $d->plan,
            'cycle' => $d->cycle->value,
            'cycle_libelle' => $d->cycle->libelle(),
            'mois' => $d->mois,
            'montant' => $d->montant,
            'devise' => $d->devise,
            'moyen' => $d->moyen,
            // Paiement Mobile Money : frais ajoutés, et ce que le commerçant paie en tout.
            'frais_mobile' => (int) $d->frais_mobile,
            'montant_a_payer' => $d->montant + (int) $d->frais_mobile,
            'statut' => $d->statut->value,
            'statut_libelle' => $d->statut->libelle(),
            'preuve_jointe' => $d->preuve_chemin !== null || filled($d->preuve_note),
            'note_decision' => $d->note_decision,
            'fin_projetee' => $d->statut->estTranchee() ? null
                : $this->abonnements->finProjetee($d->user_id, $d->plan, $d->mois)?->toDateString(),
            'cree_le' => $d->created_at?->toIso8601String(),
        ];
    }

    /**
     * Numéros de dépôt de l'abonnement (config ecaisse.numeros_paiement).
     *
     * @return list<array{telephone: string, moyens: list<string>}>
     */
    private function numerosPaiement(): array
    {
        $libelles = ['orange_money' => 'Orange Money', 'wave' => 'Wave', 'moov' => 'Moov Money'];
        $numeros = [];
        foreach (array_filter(explode(';', (string) config('ecaisse.numeros_paiement'))) as $entree) {
            [$telephone, $moyens] = array_pad(explode(':', trim($entree), 2), 2, '');
            if (trim($telephone) === '') {
                continue;
            }
            $numeros[] = [
                'telephone' => trim($telephone),
                'moyens' => array_values(array_map(fn ($m) => $libelles[trim($m)] ?? trim($m), array_filter(explode(',', $moyens), fn ($m) => trim($m) !== ''))),
            ];
        }

        return $numeros;
    }
}
