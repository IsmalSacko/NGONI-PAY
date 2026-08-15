<?php

namespace App\Console\Commands;

use App\Mail\EboutikAnnouncementMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendEboutikAnnouncement extends Command
{
    protected $signature = 'campaign:eboutik-announcement
        {--test= : N\'envoie qu\'à cette adresse, pour prévisualisation}
        {--dry-run : Liste les destinataires sans rien envoyer}';

    protected $description = 'Envoie l\'annonce E-BOUTIK à tous les utilisateurs Ngoni Pay ayant un e-mail';

    public function handle(): int
    {
        $recipients = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        if ($testEmail = $this->option('test')) {
            $user = $recipients->firstWhere('email', $testEmail)
                ?? new User(['name' => 'Test', 'email' => $testEmail]);

            $this->info("Envoi de test à {$testEmail}...");
            Mail::to($testEmail)->send(new EboutikAnnouncementMail($user));
            $this->info('Envoyé.');

            return self::SUCCESS;
        }

        $this->info("{$recipients->count()} destinataire(s) trouvé(s).");

        if ($this->option('dry-run')) {
            $recipients->each(fn (User $u) => $this->line("- {$u->name} <{$u->email}>"));

            return self::SUCCESS;
        }

        if (! $this->confirm("Envoyer l'annonce E-BOUTIK à ces {$recipients->count()} utilisateurs maintenant ?")) {
            $this->warn('Annulé.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($recipients->count());
        $bar->start();

        foreach ($recipients as $user) {
            try {
                Mail::to($user->email)->send(new EboutikAnnouncementMail($user));
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("Échec pour {$user->email} : {$e->getMessage()}");
            }

            $bar->advance();
            usleep(300_000);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Terminé. {$sent} envoyé(s), {$failed} échec(s).");

        return self::SUCCESS;
    }
}
