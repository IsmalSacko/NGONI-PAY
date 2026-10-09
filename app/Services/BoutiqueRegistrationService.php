<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Country;
use App\Mail\FraudeEssaiMail;
use App\Mail\InscriptionMail;
use App\Models\Boutique;
use App\Models\User;
use App\Support\Apres;
use App\Support\Authorization\Permissions;
use App\Support\Phone\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Inscription d'une nouvelle boutique : crée le tenant et son premier
 * utilisateur, avec le rôle `admin`.
 */
class BoutiqueRegistrationService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{nom: string, pays: string, telephone: string, email: ?string, password: string, nom_utilisateur: string, code_parrainage?: ?string}  $data
     * @return array{boutique: Boutique, user: User}
     */
    public function register(array $data): array
    {
        $paysSaisi = Country::tryFrom(strtoupper($data['pays'])) ?? Country::default();
        $telephone = PhoneNumber::normalize($data['telephone'], $paysSaisi);

        // Cas réel : « Amadou » tapé dans la case du numéro — compte créé sans
        // numéro, introuvable par la console et pour « Mot de passe oublié ».
        if (strlen(PhoneNumber::digits($data['telephone'])) < 6 || preg_match('/\p{L}/u', $data['telephone'])) {
            throw ValidationException::withMessages(['telephone' => [
                'Tapez votre numéro de téléphone, en chiffres (ex. 07 07 12 34 56) : il sert à vous connecter et à retrouver votre compte.',
            ]]);
        }

        $this->verifierLongueur($telephone, $paysSaisi);

        // Un numéro déjà inscrit : le dire, plutôt que de laisser la base
        // refuser l'insertion — l'application affichait une erreur serveur, et
        // le commerçant recommençait sans comprendre.
        if (User::withTrashed()->where('phone', $telephone)->exists()) {
            throw ValidationException::withMessages(['telephone' => [
                'Ce numéro a déjà un compte Ngoni Caisse. Connectez-vous, ou utilisez « Mot de passe oublié ».',
            ]]);
        }

        // Code vérifié avant de rien créer : une faute de frappe se corrige
        // sans laisser de compte à moitié inscrit.
        $parrain = null;
        if (filled($data['code_parrainage'] ?? null)) {
            $parrain = app(Parrainage::class)->parrainPour($data['code_parrainage'], $telephone);
        }

        $resultat = DB::transaction(function () use ($data, $parrain): array {
            $pays = Country::tryFrom(strtoupper($data['pays'])) ?? Country::default();

            $boutique = Boutique::create([
                'nom' => $data['nom'],
                'pays' => $pays->value,
                'devise' => $pays->currency(),
                'telephone' => PhoneNumber::normalize($data['telephone'], $pays),
                'email' => $data['email'] ?? null,
            ]);

            // Le contexte tenant doit être posé avant toute écriture qui en
            // dépend (BelongsToBoutique) : ni l'utilisateur admin n'existe
            // encore pour le fournir lui-même.
            $this->tenant->setBoutique($boutique->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);

            $this->provisionnerRoles($boutique);

            $user = User::create([
                'boutique_id' => $boutique->id,
                'name' => $data['nom_utilisateur'],
                'phone' => PhoneNumber::normalize($data['telephone'], $pays),
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
            ]);
            $user->assignRole('admin');

            $boutique->update(['proprietaire_id' => $user->id]);

            // Essai offert au compte, une seule fois : une boutique ajoutée plus
            // tard ne le relance pas. Un téléphone qui a déjà servi à un autre
            // propriétaire n'en redonne pas : le compte naît avec un essai échu.
            $essai = app(AbonnementService::class)->demarrerEssai($user);

            if ($parrain !== null) {
                app(Parrainage::class)->lier($user, $parrain, $essai);
            }

            // Après le parrainage, qui rallonge l'essai : un téléphone déjà
            // utilisé n'en a aucun, parrainé ou non.
            $dejaVus = [];
            if (EssaisAppareils::dejaUtilise($data['empreinte'] ?? null)) {
                $essai->update(['fin' => now()->subDay()->toDateString()]);
                $dejaVus = EssaisAppareils::autresComptes($data['empreinte'], $user);
            }

            // Deux articles d'exemple avec photo : la caisse n'est pas vide au premier lancement.
            app(CatalogueDeDepart::class)->installer($boutique);

            EssaisAppareils::noter($data['empreinte'] ?? null, $user);

            return ['boutique' => $boutique->fresh(), 'user' => $user->fresh(), 'essai_offert' => $essai->fresh()->fin?->isFuture() ?? false, 'deja_vus' => $dejaVus];
        });

        $this->prevenirExploitant($resultat['user'], $resultat['boutique'], nouveauCompte: true);

        // Inscription depuis un téléphone déjà utilisé : essai refusé, et
        // l'exploitant le sait tout de suite, avec les comptes du même téléphone.
        if ($resultat['deja_vus'] !== []) {
            $this->alerterFraude($resultat['user'], $resultat['boutique'], $resultat['deja_vus'], $data['appareil'] ?? null);
        }
        unset($resultat['deja_vus']);

        return $resultat;
    }

    /**
     * Mail à l'exploitant, une fois l'inscription enregistrée. Un mail perdu ne
     * doit jamais faire échouer l'inscription : le compte est visible dans la console.
     */
    /**
     * Essai refusé : téléphone déjà utilisé. Alerte dans la console (cloche et
     * push) et e-mail à l'exploitant, avec le téléphone, la personne, les
     * comptes déjà vus et un lien WhatsApp qui ouvre un message d'avertissement
     * prêt à envoyer.
     *
     * @param  list<array{nom: string, telephone: ?string, inscrit_le: ?string}>  $dejaVus
     */
    private function alerterFraude(User $user, Boutique $boutique, array $dejaVus, ?string $appareil): void
    {
        $autres = implode(', ', array_map(fn (array $c) => "{$c['nom']} ({$c['telephone']})", $dejaVus));
        app(AlertesExploitant::class)->envoyer(
            '⚠️ Alerte fraude : essai bloqué',
            "{$user->name} ({$user->phone}) s'est inscrit depuis le téléphone de : {$autres}.",
            '/console/comptes',
        );

        Apres::reponse(function () use ($user, $boutique, $dejaVus, $appareil): void {
            try {
                Mail::to(config('ecaisse.notification_email'))->send(new FraudeEssaiMail($user, $boutique, $dejaVus, $appareil));
            } catch (\Throwable $e) {
                Log::error('Mail de tentative de fraude non envoyé', ['user' => $user->id, 'error' => $e->getMessage()]);
            }
        });
    }

    private function prevenirExploitant(User $user, Boutique $boutique, bool $nouveauCompte): void
    {
        Apres::reponse(function () use ($user, $boutique, $nouveauCompte): void {
            try {
                Mail::to(config('ecaisse.notification_email'))->send(new InscriptionMail($user, $boutique, $nouveauCompte));
            } catch (\Throwable $e) {
                Log::error('Mail d’inscription non envoyé', ['user' => $user->id, 'boutique' => $boutique->id, 'error' => $e->getMessage()]);
            }
        });
        app(AlertesExploitant::class)->envoyer(
            $nouveauCompte ? 'Nouvelle inscription' : 'Nouvelle boutique',
            "{$user->name} · {$boutique->nom} · {$user->phone}",
            '/console/comptes',
        );
    }

    /**
     * Nouvelle boutique pour un compte existant, qui en devient propriétaire et
     * administrateur. Sa boutique par défaut ne change pas.
     *
     * @param  array{nom: string, pays: ?string, telephone: ?string, email: ?string, adresse: ?string}  $data
     */
    public function ajouterBoutique(User $proprietaire, array $data): Boutique
    {
        if (! $proprietaire->peutOuvrirBoutique()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Seul l’administrateur d’une boutique peut en ouvrir une nouvelle.',
                'code' => 'ROLE_INSUFFISANT',
            ], 403));
        }

        $abonnements = app(AbonnementService::class);

        if (! $abonnements->peutCreerBoutique($proprietaire)) {
            $abonnement = $proprietaire->abonnement()->first();

            throw new HttpResponseException(response()->json([
                'message' => $abonnement !== null && ! $abonnement->estEnCours()
                    ? 'Votre abonnement est terminé : abonnez-vous pour ouvrir une nouvelle boutique.'
                    : 'Votre plan ne permet pas d’autre boutique. Passez au plan supérieur.',
                'code' => $abonnement !== null && ! $abonnement->estEnCours() ? 'ABONNEMENT_EXPIRE' : 'LIMITE_BOUTIQUES',
            ], 403));
        }

        $equipePrecedente = app(PermissionRegistrar::class)->getPermissionsTeamId();
        $tenantPrecedent = $this->tenant->boutiqueId();

        try {
            $boutique = DB::transaction(function () use ($proprietaire, $data): Boutique {
                $pays = Country::tryFrom(strtoupper((string) ($data['pays'] ?? ''))) ?? Country::default();

                $boutique = Boutique::create([
                    'proprietaire_id' => $proprietaire->id,
                    'nom' => $data['nom'],
                    'pays' => $pays->value,
                    'devise' => $pays->currency(),
                    'telephone' => filled($data['telephone'] ?? null)
                        ? PhoneNumber::normalize((string) $data['telephone'], $pays)
                        : $proprietaire->phone,
                    'email' => $data['email'] ?? $proprietaire->email,
                    'adresse' => $data['adresse'] ?? null,
                ]);

                $this->tenant->setBoutique($boutique->id);
                app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);

                $this->provisionnerRoles($boutique);
                $proprietaire->unsetRelation('roles')->assignRole('admin');

                // Premier compte propriétaire (un salarié qui ouvre sa boutique) :
                // son essai démarre ici.
                app(AbonnementService::class)->demarrerEssai($proprietaire);

                return $boutique;
            });
        } finally {
            // La requête continue dans la boutique où elle avait commencé.
            $this->tenant->setBoutique($tenantPrecedent);
            app(PermissionRegistrar::class)->setPermissionsTeamId($equipePrecedente);
            $proprietaire->unsetRelation('roles')->unsetRelation('permissions');
        }

        $this->prevenirExploitant($proprietaire, $boutique, nouveauCompte: false);

        return $boutique;
    }

    public function provisionnerRoles(Boutique $boutique): void
    {
        // Une boutique peut être la toute première du catalogue : les
        // permissions elles-mêmes (globales, sans colonne d'équipe) doivent
        // exister avant qu'un rôle ne tente de les lui rattacher.
        foreach (Permissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (Permissions::roleMatrix() as $role => $permissions) {
            Role::firstOrCreate(
                ['name' => $role, 'guard_name' => 'web', 'boutique_id' => $boutique->id],
            )->syncPermissions($permissions);
        }
    }

    /**
     * Un numéro qui n'a pas le bon nombre de chiffres pour son pays est refusé :
     * tapé avec un chiffre en moins, il ne correspond à aucun compte, et le
     * commerçant qui ne retrouvait pas le sien en créait un second, avec un
     * nouvel essai. Un numéro d'un autre pays (saisi avec son indicatif) n'est
     * pas vérifié ici.
     */
    private function verifierLongueur(string $telephone, Country $pays): void
    {
        $longueurs = $pays->subscriberLengths();
        $prefixe = '+'.$pays->dialingCode();

        if ($longueurs === null || ! str_starts_with($telephone, $prefixe)) {
            return;
        }

        $tape = strlen(substr($telephone, strlen($prefixe)));
        if (in_array($tape, $longueurs, true)) {
            return;
        }

        $attendu = implode(' ou ', $longueurs);
        throw ValidationException::withMessages(['telephone' => [
            "Pour le pays choisi ({$pays->label()}), un numéro a {$attendu} chiffres : vous en avez tapé {$tape}. Vérifiez votre numéro. "
            .'Si vous avez déjà un compte, connectez-vous ou utilisez « Mot de passe oublié ».',
        ]]);
    }
}
