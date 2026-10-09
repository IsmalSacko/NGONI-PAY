<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Annonce;
use App\Models\Boutique;
use App\Models\Tutoriel;
use App\Models\User;

/**
 * Une nouvelle vidéo d'aide est annoncée aux commerçants : notification dans
 * la cloche et push, qui ouvre la vidéo directement sur YouTube (les anciennes
 * versions de l'application n'ont pas l'écran d'aide). Envoyée par le système
 * d'annonces : par lots, en arrière-plan, visible dans l'historique.
 *
 * Une vidéo d'un module (restaurant, pressing, pharmacie) ne part qu'aux
 * boutiques de cette activité ; les autres, à tous.
 */
class AnnonceTutoriel
{
    private const MODULES = ['restaurant', 'pressing', 'pharmacie'];

    public function __construct(private readonly GestionAnnonces $annonces) {}

    public function annoncer(Tutoriel $tutoriel, User $auteur): ?Annonce
    {
        // Juste créée, la vidéo ne porte pas encore la valeur par défaut de la base.
        if ($tutoriel->actif === false) {
            return null;
        }

        $donnees = [
            'type' => 'message',
            'titre' => mb_substr("🎬 Nouvelle vidéo : {$tutoriel->titre}", 0, 120),
            'message' => ($tutoriel->sous_titre ? "{$tutoriel->sous_titre}. " : '').'Touchez pour la regarder. Retrouvez toutes les vidéos dans Plus → Aide et tutoriels.',
            'lien' => $tutoriel->url,
            'audience' => 'tous',
            'quand' => 'maintenant',
        ];

        if (in_array($tutoriel->categorie, self::MODULES, true)) {
            $boutiques = Boutique::withoutGlobalScopes()->where('activite', $tutoriel->categorie)->select('id');
            $cibles = User::where('is_active', true)
                ->where(fn ($q) => $q->whereIn('boutique_id', $boutiques)
                    ->orWhereIn('id', Boutique::withoutGlobalScopes()->where('activite', $tutoriel->categorie)->whereNotNull('proprietaire_id')->select('proprietaire_id')))
                ->pluck('id')->all();

            if ($cibles === []) {
                return null;
            }
            $donnees = ['audience' => 'selection', 'cibles' => $cibles] + $donnees;
        }

        return $this->annonces->creer($donnees, $auteur)['annonce'];
    }
}
