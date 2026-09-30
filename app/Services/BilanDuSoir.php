<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\NotificationApp;
use App\Models\User;
use App\Models\Vente;
use App\Support\Money\Montant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Le bilan de la journée, poussé au propriétaire chaque soir : combien de
 * ventes, combien encaissé, et l'écart avec la veille.
 *
 * Un seul message par propriétaire, toutes ses boutiques dedans. Rien pour
 * une journée sans vente — un « 0 vente » quotidien s'apprend à ignorer —, ni
 * pour qui l'a coupé dans son compte. Notification et push, jamais d'e-mail :
 * un e-mail par soir épuiserait le quota des e-mails qui comptent.
 */
class BilanDuSoir
{
    public const TYPE = 'bilan';

    public function __construct(private readonly NotifierCompte $notifier) {}

    /** Envoie le bilan de `$jour` ; rend le nombre de propriétaires prévenus. */
    public function envoyer(Carbon $jour): int
    {
        $aujourdhui = $this->totaux($jour);
        if ($aujourdhui->isEmpty()) {
            return 0;
        }
        $hier = $this->totaux($jour->copy()->subDay());

        $boutiques = Boutique::whereIn('id', $aujourdhui->keys())->whereNotNull('proprietaire_id')->get();
        $prevenus = 0;

        foreach ($boutiques->groupBy('proprietaire_id') as $proprietaireId => $siennes) {
            $proprietaire = User::find($proprietaireId);
            if ($proprietaire === null || ! $proprietaire->is_active || ! $proprietaire->bilan_quotidien || $this->dejaEnvoye($proprietaire, $jour)) {
                continue;
            }

            [$titre, $message] = $this->contenu($siennes->sortBy('nom')->values(), $aujourdhui, $hier);
            $this->notifier->envoyer($proprietaire, $titre, $message, '/pilotage', self::TYPE, email: false);
            $prevenus++;
        }

        return $prevenus;
    }

    /**
     * Ventes validées de la journée d'affaires, par boutique.
     *
     * @return Collection<string, array{nombre: int, total: int}>
     */
    private function totaux(Carbon $jour): Collection
    {
        return Vente::withoutBoutiqueScope()->valides()
            ->where('jour_affaire', $jour->toDateString())
            ->selectRaw('boutique_id, COUNT(*) as nombre, SUM(total) as total')
            ->groupBy('boutique_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->boutique_id => ['nombre' => (int) $r->nombre, 'total' => (int) $r->total]]);
    }

    private function dejaEnvoye(User $user, Carbon $jour): bool
    {
        return NotificationApp::where('user_id', $user->id)->where('type', self::TYPE)
            ->whereDate('created_at', $jour->toDateString())->exists();
    }

    /**
     * @param  Collection<int, Boutique>  $boutiques
     * @param  Collection<string, array{nombre: int, total: int}>  $aujourdhui
     * @param  Collection<string, array{nombre: int, total: int}>  $hier
     * @return array{string, string}
     */
    public function contenu(Collection $boutiques, Collection $aujourdhui, Collection $hier): array
    {
        $ligne = function (Boutique $b) use ($aujourdhui, $hier): string {
            $jour = $aujourdhui[$b->id];
            $veille = $hier[$b->id]['total'] ?? 0;
            $texte = $jour['nombre'].' vente'.($jour['nombre'] > 1 ? 's' : '').' · '.$this->montant($jour['total'], (string) $b->devise);
            if ($veille > 0) {
                $ecart = (int) round(($jour['total'] - $veille) * 100 / $veille);
                $texte .= ' ('.($ecart >= 0 ? '+' : '').$ecart.' % par rapport à hier)';
            }

            return $texte;
        };

        if ($boutiques->count() === 1) {
            $b = $boutiques->first();

            return ["Bilan du jour — {$b->nom}", 'Aujourd’hui : '.$ligne($b).'.'];
        }

        return ['Bilan du jour de vos boutiques', $boutiques->map(fn (Boutique $b) => "{$b->nom} : ".$ligne($b))->implode("\n")];
    }

    private function montant(int $mineur, string $devise): string
    {
        return Montant::format($mineur, $devise).' '.(in_array($devise, ['XOF', 'XAF'], true) ? 'F CFA' : $devise);
    }
}
