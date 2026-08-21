<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Announcements;

use App\Models\Campaign;
use App\Models\User;
use App\Services\AnnouncementDispatcher;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Annonce de mise à jour : rédaction, destinataires, envoi ou programmation.
 *
 * Annoncer une version demandait jusqu'ici un déploiement — le contenu vivait
 * dans une classe de courriel. Il se rédige ici, et part par deux canaux : le
 * courriel pour ceux qui en ont un, et la notification dans l'application pour
 * tout le monde. Le second compte au moins autant : beaucoup de commerçants
 * s'inscrivent sans adresse.
 */
class Index extends Component
{
    use WithPagination;

    public string $version = '';

    public string $subject = 'NGONI PAY — nouvelle version disponible';

    public string $message = '';

    public string $storeUrl = '';

    /** all | selected */
    public string $audience = Campaign::AUDIENCE_ALL;

    /** Identifiants cochés, quand l'annonce ne s'adresse pas à tout le monde. */
    public array $selected = [];

    /** now | scheduled */
    public string $timing = 'now';

    public string $scheduledAt = '';

    public string $search = '';

    public function mount(): void
    {
        $this->version = (string) config('mobile.latest_version');
        $this->storeUrl = url('/telecharger');
        $this->message = "Une nouvelle version de NGONI PAY est disponible.\n\n"
            . "Mettez à jour l'application pour en profiter.";
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Coche ou décoche tous les destinataires filtrés.
     *
     * Sur toute la base, pas seulement la page affichée : cocher page par page
     * ferait manquer des utilisateurs sans qu'on le remarque.
     */
    public function toggleAll(): void
    {
        $ids = $this->recipientsQuery()->pluck('id')->all();

        $this->selected = count($this->selected) === count($ids) ? [] : $ids;
    }

    public function send(AnnouncementDispatcher $dispatcher): void
    {
        $this->validate([
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:2000'],
            'version' => ['nullable', 'string', 'max:20'],
            'storeUrl' => ['required', 'url', 'max:255'],
            'scheduledAt' => [
                $this->timing === 'scheduled' ? 'required' : 'nullable',
                'nullable',
                'date',
                // Programmer dans le passé partirait à la minute suivante sans
                // qu'on l'ait voulu.
                $this->timing === 'scheduled' ? 'after:now' : 'nullable',
            ],
        ], [], [
            'subject' => 'objet',
            'message' => 'message',
            'storeUrl' => 'lien de téléchargement',
            'scheduledAt' => "date d'envoi",
        ]);

        if ($this->audience === Campaign::AUDIENCE_SELECTED && $this->selected === []) {
            $this->addError('selected', 'Choisissez au moins un destinataire.');

            return;
        }

        $campaign = Campaign::create([
            // Clé unique et lisible : elle sert au suivi d'envoi et aux journaux.
            'key' => 'app-update-' . now()->format('Ymd-His') . '-' . Str::lower(Str::random(4)),
            'name' => 'Mise à jour ' . ($this->version ?: now()->format('d/m/Y')),
            'type' => Campaign::TYPE_APP_UPDATE,
            'subject' => $this->subject,
            'message' => $this->message,
            'version' => $this->version ?: null,
            'store_url' => $this->storeUrl,
            'audience' => $this->audience,
            'status' => $this->timing === 'scheduled'
                ? Campaign::STATUS_SCHEDULED
                : Campaign::STATUS_DRAFT,
            'scheduled_at' => $this->timing === 'scheduled'
                ? $this->scheduledAt
                : null,
        ]);

        if ($this->audience === Campaign::AUDIENCE_SELECTED) {
            $campaign->targets()->sync($this->selected);
        }

        if ($this->timing === 'scheduled') {
            session()->flash('status', sprintf(
                'Annonce programmée pour le %s. Elle partira sans intervention.',
                $campaign->scheduled_at->translatedFormat('d F Y à H:i'),
            ));

            return;
        }

        $bilan = $dispatcher->dispatch($campaign);

        session()->flash('status', sprintf(
            'Annonce envoyée : %d notification(s) dans l\'application, %d courriel(s)%s.',
            $bilan['notified'],
            $bilan['mailed'],
            $bilan['failed'] > 0 ? ", {$bilan['failed']} échec(s)" : '',
        ));
    }

    public function cancelScheduled(int $campaignId): void
    {
        $campaign = Campaign::where('type', Campaign::TYPE_APP_UPDATE)
            ->where('status', Campaign::STATUS_SCHEDULED)
            ->find($campaignId);

        if ($campaign === null) return;

        $campaign->delete();

        session()->flash('status', 'Annonce programmée annulée.');
    }

    private function recipientsQuery()
    {
        $needle = '%' . mb_strtolower($this->search) . '%';

        return app(AnnouncementDispatcher::class)
            ->audienceQuery()
            ->when($this->search !== '', fn ($query) => $query->where(
                fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$needle]),
            ))
            ;
    }

    public function render()
    {
        $destinataires = $this->recipientsQuery()->paginate(15);

        return view('livewire.admin.announcements.index', [
            'destinataires' => $destinataires,
            'totalActifs' => app(AnnouncementDispatcher::class)->audienceQuery()->count(),
            'avecEmail' => app(AnnouncementDispatcher::class)->audienceQuery()
                ->whereNotNull('email')->where('email', '!=', '')->count(),
            'programmees' => Campaign::where('type', Campaign::TYPE_APP_UPDATE)
                ->where('status', Campaign::STATUS_SCHEDULED)
                ->orderBy('scheduled_at')
                ->get(),
            'historique' => Campaign::where('type', Campaign::TYPE_APP_UPDATE)
                ->where('status', Campaign::STATUS_SENT)
                ->withCount([
                    'sends as envoyes' => fn ($q) => $q->where('status', 'sent'),
                    'sends as echecs' => fn ($q) => $q->where('status', 'failed'),
                ])
                ->latest('last_run_at')
                ->limit(10)
                ->get(),
        ])->layout('components.layouts.admin', ['title' => 'Annonce de mise à jour']);
    }
}
