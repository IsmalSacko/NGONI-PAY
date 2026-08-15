<?php

namespace App\Console\Commands;

use App\Mail\EboutikAnnouncementMail;
use App\Models\Campaign;
use App\Models\User;
use App\Services\CampaignMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendEboutikAnnouncement extends Command
{
    protected $signature = 'campaign:eboutik-announcement
        {--test= : N\'envoie qu\'à cette adresse, pour prévisualisation}
        {--dry-run : Liste les destinataires sans rien envoyer}';

    protected $description = 'Envoie l\'annonce E-BOUTIK à tous les utilisateurs Ngoni Pay ayant un e-mail';

    public function handle(CampaignMailer $mailer): int
    {
        $campaign = Campaign::where('key', 'eboutik-announcement')->firstOrFail();

        if ($testEmail = $this->option('test')) {
            $user = User::where('email', $testEmail)->first()
                ?? new User(['name' => 'Test', 'email' => $testEmail]);

            $this->info("Envoi de test à {$testEmail}...");
            Mail::to($testEmail)->send(new EboutikAnnouncementMail($user));
            $this->info('Envoyé.');

            return self::SUCCESS;
        }

        $recipients = $mailer->recipientsQuery()->orderBy('id')->get();

        $this->info("{$recipients->count()} destinataire(s) trouvé(s).");

        if ($this->option('dry-run')) {
            $recipients->each(fn (User $u) => $this->line("- {$u->name} <{$u->email}>"));

            return self::SUCCESS;
        }

        if (! $this->confirm("Envoyer l'annonce E-BOUTIK à ces {$recipients->count()} utilisateurs maintenant ?")) {
            $this->warn('Annulé.');

            return self::SUCCESS;
        }

        $this->info('Envoi en cours...');
        $result = $mailer->sendTo($campaign, $recipients);
        $this->info("Terminé. {$result['sent']} envoyé(s), {$result['failed']} échec(s).");

        return self::SUCCESS;
    }
}
