<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ArreteTransfert;
use App\Models\Boutique;
use App\Models\OperateurTransfert;
use App\Models\OperationTransfert;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le transfert d'argent de la boutique active : ses opérateurs, ses
 * opérations, les soldes qui en découlent (chaque puce, et l'argent du
 * tiroir) et l'arrêté où on les compte.
 *
 * Les soldes ne sont jamais écrits : chaque opération garde ce qu'elle change,
 * et un solde est leur somme. Corriger ou annuler une opération corrige donc
 * le solde, sans rien recalculer à la main.
 */
class TransfertsArgent
{
    public function __construct(private readonly Journee $journee) {}

    /** Au passage en transfert d'argent : les opérateurs du pays, si la boutique n'en a jamais eu. */
    public function preparer(Boutique $boutique): void
    {
        if (! $boutique->estTransfert()
            || OperateurTransfert::withoutBoutiqueScope()->withTrashed()->where('boutique_id', $boutique->id)->exists()) {
            return;
        }
        foreach (OperateurTransfert::PAR_PAYS[$boutique->pays] ?? OperateurTransfert::PAR_DEFAUT as $i => $nom) {
            (new OperateurTransfert(['nom' => $nom, 'ordre' => $i]))->forceFill(['boutique_id' => $boutique->id])->save();
        }
    }

    /**
     * Ce qu'une opération change, signé : [puce, tiroir]. Le client qui dépose
     * donne des espèces et reçoit de l'électronique ; celui qui retire,
     * l'inverse. La commission payée en espèces par le client entre au tiroir.
     *
     * @return array{0: int, 1: int}
     */
    public function effets(string $type, int $montant, int $commission = 0, bool $commissionEspeces = false, bool $viaTiroir = true): array
    {
        $frais = $commissionEspeces ? $commission : 0;

        return match ($type) {
            'retrait' => [$montant, -$montant + $frais],
            'depot', 'envoi', 'paiement', 'credit' => [-$montant, $montant + $frais],
            'approvisionnement' => [$montant, $viaTiroir ? -$montant : 0],
            'vidage' => [-$montant, $viaTiroir ? $montant : 0],
            'apport_especes' => [0, $montant],
            'retrait_especes' => [0, -$montant],
            default => [0, 0],
        };
    }

    /**
     * Les soldes : l'argent du tiroir, et chaque puce par opérateur (ceux qui
     * ont été retirés compris, pour qu'aucun franc ne disparaisse).
     *
     * @return array{especes: int, puces: array<string, int>}
     */
    public function soldes(): array
    {
        $puces = OperationTransfert::valides()->whereNotNull('operateur_id')
            ->selectRaw('operateur_id, SUM(effet_puce) AS solde')->groupBy('operateur_id')
            ->pluck('solde', 'operateur_id')->map(fn ($s) => (int) $s)->all();

        return ['especes' => (int) OperationTransfert::valides()->sum('effet_especes'), 'puces' => $puces];
    }

    /** @param  array<string, mixed>  $data */
    public function enregistrer(array $data, User $auteur): OperationTransfert
    {
        $operation = new OperationTransfert([
            'user_id' => $auteur->id,
            'jour' => $this->journee->courante()->toDateString(),
        ]);
        $this->remplir($operation, $data);
        $operation->save();

        return $operation;
    }

    /**
     * Corriger une opération (montant mal tapé, mauvais opérateur) : ses
     * effets sont refaits, les soldes suivent. Pas après l'arrêté qui l'a
     * comptée : la correction serait invisible dans ce qui a été compté.
     *
     * @param  array<string, mixed>  $data
     */
    public function modifier(OperationTransfert $operation, array $data): OperationTransfert
    {
        $this->doitEtreModifiable($operation);
        $this->remplir($operation, $data);
        $operation->save();

        return $operation;
    }

    /** L'opération reste dans l'historique, barrée ; elle ne compte plus dans les soldes. */
    public function annuler(OperationTransfert $operation, User $auteur): OperationTransfert
    {
        $this->doitEtreModifiable($operation);
        $operation->forceFill(['annulee_le' => now(), 'annulee_par' => $auteur->id])->save();

        return $operation;
    }

    public function modifiable(OperationTransfert $operation): bool
    {
        return ! $operation->estAnnulee() && $operation->type !== OperationTransfert::AJUSTEMENT && $operation->arrete_id === null;
    }

    /**
     * L'arrêté : le commerçant a compté son tiroir et chaque puce. Ce qui
     * diffère de l'attendu devient un ajustement, pour que les soldes partent
     * du compté ; les opérations comptées ne se modifient plus.
     *
     * @param  array<string, int>  $puces  opérateur => solde compté
     */
    public function arreter(int $especes, array $puces, User $auteur): ArreteTransfert
    {
        return DB::transaction(function () use ($especes, $puces, $auteur): ArreteTransfert {
            $soldes = $this->soldes();
            $operateurs = OperateurTransfert::withTrashed()->get()->keyBy('id');
            $inconnus = array_diff(array_keys($puces), $operateurs->keys()->all());
            if ($inconnus !== []) {
                throw ValidationException::withMessages(['puces' => 'Opérateur inconnu.']);
            }

            // Toutes les puces : celles qu'on n'a pas comptées gardent leur solde.
            $lignes = [];
            foreach ($operateurs as $id => $operateur) {
                $theorique = $soldes['puces'][$id] ?? 0;
                if ($operateur->trashed() && $theorique === 0 && ! array_key_exists($id, $puces)) {
                    continue;
                }
                $lignes[] = ['operateur_id' => $id, 'nom' => $operateur->nom, 'theorique' => $theorique, 'compte' => $puces[$id] ?? $theorique];
            }

            $arrete = ArreteTransfert::create([
                'user_id' => $auteur->id,
                'especes_theorique' => $soldes['especes'],
                'especes_compte' => $especes,
                'puces' => $lignes,
            ]);

            $jour = $this->journee->courante()->toDateString();
            $ajuster = function (?string $operateurId, ?string $nom, int $puce, int $tiroir) use ($arrete, $auteur, $jour): void {
                if ($puce === 0 && $tiroir === 0) {
                    return;
                }
                OperationTransfert::create([
                    'user_id' => $auteur->id, 'operateur_id' => $operateurId, 'operateur_nom' => $nom,
                    'type' => OperationTransfert::AJUSTEMENT, 'montant' => abs($puce !== 0 ? $puce : $tiroir),
                    'effet_puce' => $puce, 'effet_especes' => $tiroir, 'jour' => $jour, 'arrete_id' => $arrete->id,
                    'note' => 'Écart compté à l’arrêté',
                ]);
            };
            $ajuster(null, null, 0, $especes - $soldes['especes']);
            foreach ($lignes as $l) {
                $ajuster($l['operateur_id'], $l['nom'], $l['compte'] - $l['theorique'], 0);
            }

            OperationTransfert::whereNull('arrete_id')->update(['arrete_id' => $arrete->id]);

            return $arrete;
        });
    }

    /**
     * Le rapport d'une période : par opérateur et par type, le nombre et le
     * montant des opérations, et les commissions gagnées.
     *
     * @return array<string, mixed>
     */
    public function rapport(Carbon $du, Carbon $au): array
    {
        $lignes = OperationTransfert::valides()->whereIn('type', OperationTransfert::CLIENT)
            ->whereDate('jour', '>=', $du->toDateString())->whereDate('jour', '<=', $au->toDateString())
            ->selectRaw('operateur_id, MAX(operateur_nom) AS nom, type, COUNT(*) AS nombre, SUM(montant) AS montant, SUM(commission) AS commission, SUM(CASE WHEN commission_especes THEN commission ELSE 0 END) AS commission_tiroir')
            ->groupBy('operateur_id', 'type')->get();

        $ordre = OperateurTransfert::withTrashed()->orderBy('ordre')->orderBy('nom')->pluck('id')->flip();
        $parOperateur = $lignes->groupBy('operateur_id')
            ->sortBy(fn ($l, $id) => $ordre[$id] ?? PHP_INT_MAX)
            ->map(fn ($l, $id) => [
                'operateur_id' => $id,
                'nom' => $l->first()->nom,
                'nombre' => (int) $l->sum('nombre'),
                'commission' => (int) $l->sum('commission'),
                'types' => $l->mapWithKeys(fn ($x) => [$x->type => ['nombre' => (int) $x->nombre, 'montant' => (int) $x->montant]])->all(),
            ])->values();

        return [
            'du' => $du->toDateString(),
            'au' => $au->toDateString(),
            'nombre' => (int) $lignes->sum('nombre'),
            'commission' => (int) $lignes->sum('commission'),
            'commission_especes' => (int) $lignes->sum('commission_tiroir'),
            'par_type' => $lignes->groupBy('type')->map(fn ($l) => ['nombre' => (int) $l->sum('nombre'), 'montant' => (int) $l->sum('montant')])->all(),
            'par_operateur' => $parOperateur,
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function remplir(OperationTransfert $operation, array $data): void
    {
        $type = (string) $data['type'];
        $operateur = null;
        if (in_array($type, OperationTransfert::AVEC_OPERATEUR, true)) {
            $operateur = OperateurTransfert::find($data['operateur_id'] ?? null);
            if ($operateur === null) {
                throw ValidationException::withMessages(['operateur_id' => 'Choisissez l’opérateur.']);
            }
        }
        $client = in_array($type, OperationTransfert::CLIENT, true);
        $montant = (int) $data['montant'];
        $commission = $client ? (int) ($data['commission'] ?? 0) : 0;
        $commissionEspeces = $client && $commission > 0 && (bool) ($data['commission_especes'] ?? false);
        $viaTiroir = (bool) ($data['via_tiroir'] ?? true);
        [$puce, $tiroir] = $this->effets($type, $montant, $commission, $commissionEspeces, $viaTiroir);

        $texte = fn (string $cle) => filled($data[$cle] ?? null) ? trim((string) $data[$cle]) : null;
        $operation->fill([
            'type' => $type,
            'operateur_id' => $operateur?->id,
            'operateur_nom' => $operateur?->nom,
            'montant' => $montant,
            'commission' => $commission,
            'commission_especes' => $commissionEspeces,
            'via_tiroir' => $viaTiroir,
            'effet_puce' => $puce,
            'effet_especes' => $tiroir,
            'client_nom' => $client ? $texte('client_nom') : null,
            // Sans espaces ni tirets : « 76 00 82 01 » se retrouve en tapant 76008201.
            'client_telephone' => $client && $texte('client_telephone') !== null ? preg_replace('/[^\d+]/', '', $texte('client_telephone')) : null,
            'beneficiaire' => $client ? $texte('beneficiaire') : null,
            'reference' => $texte('reference'),
            'note' => $texte('note'),
        ]);
    }

    private function doitEtreModifiable(OperationTransfert $operation): void
    {
        if ($operation->estAnnulee()) {
            throw ValidationException::withMessages(['operation' => 'Cette opération est déjà annulée.']);
        }
        if ($operation->type === OperationTransfert::AJUSTEMENT) {
            throw ValidationException::withMessages(['operation' => 'Un écart d’arrêté ne se modifie pas : comptez de nouveau.']);
        }
        if ($operation->arrete_id !== null) {
            throw ValidationException::withMessages(['operation' => 'Cette opération a déjà été comptée à un arrêté : enregistrez plutôt une correction.']);
        }
    }
}
