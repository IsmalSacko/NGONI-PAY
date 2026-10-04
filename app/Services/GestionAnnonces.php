<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Annonce;
use App\Models\User;
use App\Support\VersionApplication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Annonces aux commerçants, depuis la console web comme depuis l'application :
 * mêmes types (mise à jour, message libre, campagne), mêmes audiences
 * (comptes choisis compris), envoi immédiat ou programmé, une fois ou chaque
 * semaine/mois. La diffusion elle-même est l'affaire de DiffusionAnnonces.
 */
class GestionAnnonces
{
    public function __construct(private readonly DiffusionAnnonces $diffusion) {}

    /**
     * Ce qu'un formulaire propose d'emblée pour un type : une mise à jour
     * arrive avec la dernière version et le lien du Play Store, un message
     * libre avec le lien du back-office des boutiques, une campagne se
     * programme.
     *
     * @return array{type: string, titre: string, message: string, version: string, lien: string, quand: string}
     */
    public function modele(string $type): array
    {
        $type = array_key_exists($type, Annonce::TYPES) ? $type : 'message';

        return match ($type) {
            'mise_a_jour' => [
                'type' => $type,
                'titre' => 'Nouvelle version de l’application',
                'message' => 'Mettez à jour Ngoni Caisse depuis le Play Store pour profiter des nouveautés.',
                'version' => VersionApplication::derniere(),
                'lien' => (string) config('mobile.store_url'),
                'quand' => 'maintenant',
            ],
            'campagne' => ['type' => $type, 'titre' => '', 'message' => '', 'version' => '', 'lien' => '', 'quand' => 'programmer'],
            default => ['type' => $type, 'titre' => '', 'message' => '', 'version' => '', 'lien' => url('/app'), 'quand' => 'maintenant'],
        };
    }

    /**
     * Enregistre l'annonce ; l'envoie tout de suite si `quand` vaut « maintenant ».
     *
     * @param  array<string, mixed>  $donnees  type, titre, message, version, lien, audience, par_email, quand, programmee_le, recurrence, cibles
     * @return array{annonce: Annonce, resultat: ?array<string, int>}
     */
    public function creer(array $donnees, User $auteur): array
    {
        $data = Validator::make($donnees, [
            'type' => ['required', Rule::in(array_keys(Annonce::TYPES))],
            'titre' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'version' => ['nullable', 'string', 'max:20'],
            'lien' => ['nullable', 'url', 'max:255'],
            'audience' => ['required', Rule::in(array_keys(Annonce::AUDIENCES))],
            'par_email' => ['boolean'],
            'quand' => ['required', Rule::in(['maintenant', 'programmer'])],
            'programmee_le' => ['nullable', 'required_if:quand,programmer', 'date', 'after:now'],
            'recurrence' => ['nullable', Rule::in(array_filter(array_keys(Annonce::RECURRENCES)))],
            'cibles' => ['array', 'required_if:audience,selection'],
            'cibles.*' => ['string', Rule::exists('users', 'id')],
        ], ['cibles.required_if' => 'Choisissez au moins un compte.'], ['programmee_le' => 'date d’envoi'])->validate();

        $annonce = Annonce::create([
            'type' => $data['type'],
            'titre' => $data['titre'],
            'message' => $data['message'],
            'version' => filled($data['version'] ?? null) ? $data['version'] : null,
            'lien' => filled($data['lien'] ?? null) ? $data['lien'] : null,
            'audience' => $data['audience'],
            'par_email' => (bool) ($data['par_email'] ?? false),
            'statut' => 'programmee',
            'programmee_le' => $data['quand'] === 'programmer' ? Carbon::parse($data['programmee_le']) : now(),
            'recurrence' => filled($data['recurrence'] ?? null) ? $data['recurrence'] : null,
            'cree_par' => $auteur->id,
        ]);

        if ($data['audience'] === 'selection') {
            $annonce->cibles()->sync($data['cibles']);
        }

        return ['annonce' => $annonce, 'resultat' => $data['quand'] === 'maintenant' ? $this->diffusion->diffuser($annonce) : null];
    }

    /** @return array<string, int> */
    public function envoyerMaintenant(Annonce $annonce): array
    {
        return $this->diffusion->diffuser($annonce);
    }

    /** Un envoi programmé qui ne partira plus ; l'annonce reste dans l'historique. */
    public function arreter(Annonce $annonce): void
    {
        Annonce::whereKey($annonce->id)->where('statut', 'programmee')->update(['statut' => 'brouillon', 'programmee_le' => null]);
    }

    /** Combien de comptes l'annonce toucherait. @param  list<string>  $cibles */
    public function destinatairesPrevus(string $audience, array $cibles = []): int
    {
        return $audience === 'selection'
            ? User::whereIn('id', $cibles)->count()
            : $this->diffusion->destinataires(new Annonce(['audience' => $audience]))->count();
    }

    /** Comptes de commerçants à choisir, par nom ou téléphone (2 caractères au moins). @return Collection<int, User> */
    public function rechercherComptes(string $terme): Collection
    {
        $terme = trim($terme);
        if (mb_strlen($terme) < 2) {
            return collect();
        }

        return User::where('est_admin_plateforme', false)
            ->recherche($terme)
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'email']);
    }

    /** Résumé d'un envoi, pour l'exploitant. @param  array<string, int>  $r */
    public static function resume(Annonce $annonce, array $r): string
    {
        return "« {$annonce->titre} » envoyée : {$r['notifies']} notification(s), {$r['pushs']} push, {$r['emails']} e-mail(s)"
            .(($r['echecs'] ?? 0) ? ", {$r['echecs']} échec(s)" : '').'.';
    }
}
