<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Boutique;
use App\Models\NotificationApp;
use App\Models\User;
use App\Models\Vente;
use App\Support\Fuseau;
use App\Support\Money\Montant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Le bilan de la journée, poussé au propriétaire chaque soir : combien de
 * ventes, combien encaissé, et l'écart avec la veille.
 *
 * À 20 h à l'heure du propriétaire : celle du pays de sa boutique (de sa
 * boutique par défaut s'il en a plusieurs), et non celle du serveur (UTC) —
 * 20 h UTC, c'est 22 h à Paris et 21 h à Douala. La tâche tourne donc chaque
 * heure et ne prévient que ceux pour qui il est 20 h.
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

    public const HEURE = 20;

    /**
     * Prévient les propriétaires pour qui il est 20 h à `$instant` ; rend leur
     * nombre. Appelée chaque heure.
     */
    public function envoyer(CarbonInterface $instant): int
    {
        $prevenus = 0;
        $boutiques = Boutique::whereNotNull('proprietaire_id')->get()->groupBy('proprietaire_id');

        foreach ($boutiques as $proprietaireId => $siennes) {
            $proprietaire = User::find($proprietaireId);
            if ($proprietaire === null || ! $proprietaire->is_active || ! $proprietaire->bilan_quotidien) {
                continue;
            }
            $reference = $siennes->firstWhere('id', $proprietaire->boutique_id) ?? $siennes->sortBy('created_at')->first();
            $local = Carbon::instance($instant->toDateTime())->setTimezone(Fuseau::pourPays($reference->pays));
            if ($local->hour !== self::HEURE) {
                continue;
            }
            if ($this->envoyerA($proprietaire, $siennes, $local->copy()->startOfDay(), $instant)) {
                $prevenus++;
            }
        }

        return $prevenus;
    }

    /** @param  Collection<int, Boutique>  $siennes */
    private function envoyerA(User $proprietaire, Collection $siennes, Carbon $jour, CarbonInterface $instant): bool
    {
        $ids = $siennes->pluck('id')->all();
        $aujourdhui = $this->totaux($jour, $ids);
        if ($aujourdhui->isEmpty() || $this->dejaEnvoye($proprietaire, $instant)) {
            return false;
        }
        $hier = $this->totaux($jour->copy()->subDay(), $ids);

        $avecVentes = $siennes->filter(fn (Boutique $b) => isset($aujourdhui[$b->id]))->sortBy('nom')->values();
        [$titre, $message] = $this->contenu($avecVentes, $aujourdhui, $hier);
        $this->notifier->envoyer($proprietaire, $titre, $message, '/pilotage', self::TYPE, email: false);

        return true;
    }

    /**
     * Ventes validées de la journée d'affaires, par boutique.
     *
     * @param  list<string>  $boutiques
     * @return Collection<string, array{nombre: int, total: int}>
     */
    private function totaux(Carbon $jour, array $boutiques): Collection
    {
        return Vente::withoutBoutiqueScope()->valides()
            ->whereIn('boutique_id', $boutiques)
            ->where('jour_affaire', $jour->toDateString())
            ->selectRaw('boutique_id, COUNT(*) as nombre, SUM(total) as total')
            ->groupBy('boutique_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->boutique_id => ['nombre' => (int) $r->nombre, 'total' => (int) $r->total]]);
    }

    /** Un bilan dans les 12 dernières heures : la tâche relancée ne le double pas. */
    private function dejaEnvoye(User $user, CarbonInterface $instant): bool
    {
        return NotificationApp::where('user_id', $user->id)->where('type', self::TYPE)
            ->where('created_at', '>', Carbon::instance($instant->toDateTime())->subHours(12))->exists();
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
