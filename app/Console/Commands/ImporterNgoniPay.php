<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Boutique;
use App\Services\Import\ImportNgoniPay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reprend les comptes Ngoni Pay (connexion `ngonipay`) à la bascule.
 * Refuse une base e-caisse qui contient déjà des boutiques.
 */
class ImporterNgoniPay extends Command
{
    protected $signature = 'ecaisse:importer-ngonipay';

    protected $description = 'Importe comptes, boutiques, équipes, clients, ventes et abonnements depuis la base Ngoni Pay';

    public function handle(ImportNgoniPay $import): int
    {
        if (Boutique::withTrashed()->exists()) {
            $this->error('La base e-caisse contient déjà des boutiques : import refusé (il se fait une seule fois, sur une base vide).');

            return self::FAILURE;
        }

        $stats = $import->importer(DB::connection('ngonipay'));

        $this->table(['Donnée', 'Nombre'], collect($stats)->map(fn ($n, $cle) => [$cle, $n])->values()->all());
        $this->info('Import terminé.');

        return self::SUCCESS;
    }
}
