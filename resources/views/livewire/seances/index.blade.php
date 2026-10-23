@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v))
@php($ecart = fn ($e) => $e === null ? '—' : ($e === 0 ? 'Aucun' : ($e > 0 ? '+' : '−').$m(abs($e))))
<div class="flex flex-col gap-5">
    {{-- Même habit que l'écran Caisse de l'application. --}}
    <div class="flex flex-col gap-1">
        <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Séances de caisse</h1>
        <span class="text-muted text-sm">Le fond de départ, ce que le tiroir devrait contenir, ce qui a été compté, et l’écart.</span>
    </div>

    @if ($info)<p class="rounded-2xl bg-succes-doux text-succes-fonce px-4 py-3 text-sm font-bold">{{ $info }}</p>@endif

    <x-charte.carte class="p-5 flex flex-col gap-3">
        <x-charte.en-tete-section icone="point_of_sale" titre="Caisses ouvertes" sous-titre="Elles s’ouvrent et se ferment au comptoir, dans l’application." />
        <div>
            @forelse ($ouvertes as $o)
                <div class="flex flex-wrap items-center gap-3 py-3 border-b border-separateur last:border-b-0" wire:key="o-{{ $o['s']->id }}">
                    <x-charte.pastille icone="lock_open" :taille="38" actif />
                    <span class="flex-1 min-w-0 flex flex-col">
                        <span class="font-bold text-ink truncate">{{ $o['s']->caissier?->name ?? '—' }}</span>
                        <span class="text-xs text-muted">Ouverte {{ \App\Support\Fuseau::heure($o['s']->ouverte_le, 'd/m à H:i') }} · fond {{ $m($o['s']->fond_initial) }}</span>
                    </span>
                    <span class="text-sm">Le tiroir devrait contenir <span class="font-extrabold text-accent tabular-nums">{{ $m($o['attendu']) }}</span></span>
                    @if ($peutFermer)
                        <button wire:click="ouvrirFermeture('{{ $o['s']->id }}')" class="h-9 px-3 rounded-xl bg-accent text-white text-xs font-bold">Fermer</button>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted py-2">Aucune caisse ouverte en ce moment.</p>
            @endforelse
        </div>
    </x-charte.carte>

    <div class="flex items-center justify-center gap-3">
        <button wire:click="moisPrecedent" title="Mois précédent" class="w-10 h-10 rounded-xl bg-white border border-border-strong inline-flex items-center justify-center"><x-charte.icone nom="chevron_left" /></button>
        <span class="font-extrabold text-lg min-w-40 text-center">{{ $titreMois }}</span>
        <button wire:click="moisSuivant" title="Mois suivant" @disabled($dernierMois) class="w-10 h-10 rounded-xl bg-white border border-border-strong inline-flex items-center justify-center disabled:opacity-40"><x-charte.icone nom="chevron_right" /></button>
    </div>

    <x-charte.carte-heros icone="balance" titre="Écart du mois" :valeur="$ecart($ecartTotal)">
        <span>{{ $fermees->count() }} séance{{ $fermees->count() > 1 ? 's' : '' }} fermée{{ $fermees->count() > 1 ? 's' : '' }}{{ $manques > 0 ? ' · '.$manques.' avec un manque' : '' }}</span>
    </x-charte.carte-heros>

    <x-charte.carte class="p-5 flex flex-col gap-3">
        <x-charte.en-tete-section icone="history" titre="Séances fermées" sous-titre="Écart : l’argent compté moins ce que le tiroir devait contenir." />
        <div class="overflow-x-auto">
            @if ($fermees->isEmpty())
                <x-charte.etat-vide icone="point_of_sale" titre="Aucune séance fermée ce mois-ci." />
            @else
                <table class="w-full text-sm">
                    <thead class="text-left text-muted">
                        <tr><th class="py-2 pr-3">Caissier</th><th class="py-2 pr-3">Ouverte</th><th class="py-2 pr-3">Fermée</th><th class="py-2 pr-3 text-right">Fond</th><th class="py-2 pr-3 text-right">Compté</th><th class="py-2 pr-3 text-right">Écart</th><th class="py-2">Note</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($fermees as $s)
                            <tr class="border-t border-separateur" wire:key="f-{{ $s->id }}">
                                <td class="py-2 pr-3 font-semibold">{{ $s->caissier?->name ?? '—' }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ \App\Support\Fuseau::heure($s->ouverte_le, 'd/m H:i') }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ \App\Support\Fuseau::heure($s->fermee_le, 'd/m H:i') }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $m($s->fond_initial) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $s->fond_final === null ? '—' : $m($s->fond_final) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums font-bold {{ (int) $s->ecart < 0 ? 'text-danger-fg' : ((int) $s->ecart > 0 ? 'text-warn-fg' : 'text-succes-fonce') }}">{{ $ecart($s->ecart === null ? null : (int) $s->ecart) }}</td>
                                <td class="py-2 text-muted">{{ $s->notes }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </x-charte.carte>

    @if ($aFermer)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('aFermer', null)">
            <form wire:submit="fermer" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-sm flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">Fermer la caisse</h2>
                <p class="text-sm text-muted">Comptez l’argent du tiroir. Le montant attendu est déjà saisi : corrigez-le seulement si vous trouvez autre chose.</p>
                <label class="text-sm font-semibold">Argent compté
                    <input wire:model="compte" type="text" inputmode="decimal" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('compte') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <label class="text-sm font-semibold">Note <span class="font-normal text-muted">(facultatif)</span>
                    <input wire:model="notes" type="text" maxlength="255" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('aFermer', null)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Fermer</button>
                </div>
            </form>
        </div>
    @endif
</div>
