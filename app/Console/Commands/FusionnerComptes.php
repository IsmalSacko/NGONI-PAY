<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FusionComptes;
use Illuminate\Console\Command;

class FusionnerComptes extends Command
{
    protected $signature = 'ecaisse:fusionner-comptes {garde : identifiant du compte gardé} {absorbe : identifiant du compte fusionné dans le premier} {--force : sans confirmation}';

    protected $description = 'Fusionne deux comptes d’une même personne (boutiques, rôles, ventes, abonnement).';

    public function handle(FusionComptes $fusion): int
    {
        $garde = User::withoutGlobalScopes()->whereNull('deleted_at')->find($this->argument('garde'));
        $absorbe = User::withoutGlobalScopes()->whereNull('deleted_at')->find($this->argument('absorbe'));

        if ($garde === null || $absorbe === null) {
            $this->error('Compte introuvable.');

            return self::FAILURE;
        }

        $this->line("Garder   : {$garde->name} ({$garde->phone})");
        $this->line("Fusionner: {$absorbe->name} ({$absorbe->phone})");

        if (! $this->option('force') && ! $this->confirm('Confirmer la fusion ?')) {
            return self::FAILURE;
        }

        foreach ($fusion->fusionner($garde, $absorbe) as $quoi => $combien) {
            $this->line("  {$quoi} : {$combien}");
        }
        $this->info('Fusion terminée.');

        return self::SUCCESS;
    }
}
