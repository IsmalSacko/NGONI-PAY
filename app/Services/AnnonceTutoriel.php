<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Annonce;
use App\Models\Boutique;
use App\Models\Tutoriel;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Les nouvelles vidéos d'aide sont annoncées aux commerçants une fois par
 * semaine, en un seul résumé, et non à chaque publication : l'audit du
 * 23/10/2026 comptait 4 annonces de vidéos le même jour, lues à 1 %.
 *
 * Cocher « Prévenir les commerçants » met la vidéo en attente ; le lundi, une
 * annonce reprend les vidéos de la semaine (une vidéo : elle s'ouvre
 * directement sur YouTube ; plusieurs : l'écran Aide et tutoriels). Une vidéo
 * d'un module (restaurant, pressing, pharmacie) ne part qu'aux boutiques de
 * cette activité.
 */
class AnnonceTutoriel
{
    private const MODULES = ['restaurant', 'pressing', 'pharmacie'];

    public function __construct(private readonly GestionAnnonces $annonces) {}

    /** La vidéo attend le résumé du lundi. */
    public function mettreEnAttente(Tutoriel $tutoriel): bool
    {
        // Juste créée, la vidéo ne porte pas encore la valeur par défaut de la base.
        if ($tutoriel->actif === false) {
            return false;
        }
        $tutoriel->update(['a_annoncer' => true, 'annonce_le' => null]);

        return true;
    }

    /**
     * Le résumé de la semaine : une annonce par public (tous, puis chaque
     * module), pour les vidéos en attente encore en ligne.
     *
     * @return list<Annonce>
     */
    public function resumeDeLaSemaine(User $auteur): array
    {
        $enAttente = Tutoriel::where('a_annoncer', true)->whereNull('annonce_le')->where('actif', true)
            ->orderBy('ordre')->orderBy('id')->get();
        if ($enAttente->isEmpty()) {
            return [];
        }
        $estModule = fn (Tutoriel $t) => in_array($t->categorie, self::MODULES, true);
        $generales = $enAttente->reject($estModule)->values();
        $creees = [];
        $servis = [];

        // Un seul message par commerçant : une boutique d'un module reçoit ses
        // vidéos et les vidéos générales ensemble.
        foreach ($enAttente->filter($estModule)->groupBy('categorie') as $module => $videos) {
            $cibles = app(DiffusionAnnonces::class)->destinataires(new Annonce(['audience' => 'tous', 'type' => 'message']))
                ->whereIn('id', Boutique::withoutGlobalScopes()->where('activite', $module)->whereNotNull('proprietaire_id')->select('proprietaire_id'))
                ->whereNotIn('id', $servis)
                ->pluck('id')->all();
            if ($cibles !== []) {
                $creees[] = $this->annonces->creer(['audience' => 'selection', 'cibles' => $cibles] + $this->donnees($generales->concat($videos)), $auteur)['annonce'];
                $servis = [...$servis, ...$cibles];
            }
        }

        if ($generales->isNotEmpty()) {
            $donnees = $this->donnees($generales);
            if ($servis !== []) {
                $cibles = app(DiffusionAnnonces::class)->destinataires(new Annonce(['audience' => 'tous', 'type' => 'message']))
                    ->whereNotIn('id', $servis)->pluck('id')->all();
                $donnees = $cibles === [] ? null : ['audience' => 'selection', 'cibles' => $cibles] + $donnees;
            }
            if ($donnees !== null) {
                $creees[] = $this->annonces->creer($donnees, $auteur)['annonce'];
            }
        }

        Tutoriel::whereIn('id', $enAttente->pluck('id'))->update(['annonce_le' => now()]);

        return $creees;
    }

    /**
     * @param  Collection<int, Tutoriel>  $videos
     * @return array<string, mixed>
     */
    private function donnees(Collection $videos): array
    {
        $une = $videos->count() === 1 ? $videos->first() : null;

        return [
            'type' => 'message',
            'titre' => $une
                ? mb_substr("🎬 Nouvelle vidéo : {$une->titre}", 0, 120)
                : "🎬 {$videos->count()} nouvelles vidéos d’aide",
            'message' => $une
                ? ($une->sous_titre ? "{$une->sous_titre}. " : '').'Touchez pour la regarder. Retrouvez toutes les vidéos dans Plus → Aide et tutoriels.'
                : mb_substr($videos->pluck('titre')->join(' · '), 0, 1500).'. Retrouvez-les dans Plus → Aide et tutoriels.',
            'lien' => $une ? $une->url : '/aide',
            'audience' => 'tous',
            'quand' => 'maintenant',
        ];
    }
}
