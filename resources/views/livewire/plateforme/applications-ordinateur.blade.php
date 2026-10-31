@php
    $champ = 'h-11 px-3.5 rounded-xl border border-border-strong bg-white text-sm font-normal focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30';
@endphp
<div class="flex flex-col gap-5">
    <div>
        <h1 class="font-display font-extrabold text-2xl md:text-3xl">Applications ordinateur</h1>
        <p class="text-sm text-muted">Les installateurs Windows et Mac proposés sur la vitrine (« Télécharger pour Windows / Mac ») et sur /telecharger. Un fichier déposé ici remplace le précédent aussitôt.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        @foreach ($systemes as $s)
            <div class="bg-white rounded-2xl shadow-carte flex flex-col" wire:key="systeme-{{ $s['code'] }}"
                 x-data="envoiInstallateur(@js($s['code']), @js($s['version']), @js(route('plateforme.applications.envoi')), @js(csrf_token()))">
                <div class="px-4 md:px-5 py-3.5 rounded-t-2xl bg-linear-to-r from-accent-soft to-white border-b border-separateur flex items-center justify-between gap-3">
                    <h2 class="font-display font-extrabold text-xl">{{ $s['nom'] }}</h2>
                    <span class="text-xs text-muted">{{ $s['requis'] }}</span>
                </div>
                <div class="p-4 md:p-5 flex flex-col gap-4">
                    @if ($s['enLigne']['store'] ?? null)
                        <p class="rounded-xl bg-paper px-4 py-3 text-sm">Le bouton {{ $s['nom'] }} de la vitrine mène au <a href="{{ $s['enLigne']['store'] }}" target="_blank" rel="noopener" class="font-semibold text-accent hover:underline">Microsoft Store</a> : les mises à jour s’y déposent (Partner Center). Un fichier déposé ici n’est pas proposé.</p>
                    @elseif ($s['enLigne'])
                        <div class="rounded-xl bg-paper px-4 py-3 text-sm flex flex-wrap items-center justify-between gap-2">
                            <span>
                                <span class="font-bold">En ligne : version {{ $s['enLigne']['version'] }}</span> · {{ $s['enLigne']['mo'] }} Mo
                                @if ($s['enLigne']['depose_le'])
                                    <span class="text-muted">· déposée le {{ \Illuminate\Support\Carbon::parse($s['enLigne']['depose_le'])->timezone('Europe/Paris')->format('d/m/Y à H:i') }}@if ($s['enLigne']['par']) par {{ $s['enLigne']['par'] }}@endif</span>
                                @endif
                            </span>
                            <a href="{{ route('telecharger.bureau', $s['code']) }}" class="font-semibold text-accent hover:underline">Télécharger</a>
                        </div>
                    @else
                        <p class="rounded-xl bg-paper px-4 py-3 text-sm text-muted">Aucun fichier en ligne : le bouton {{ $s['nom'] }} n’apparaît pas sur la vitrine.</p>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-[8rem_1fr] gap-3">
                        <label class="flex flex-col gap-1 text-sm font-semibold">Version
                            <input x-model="version" type="text" inputmode="decimal" placeholder="1.0.1" :disabled="enCours" class="{{ $champ }}">
                        </label>
                        <label class="flex flex-col gap-1 text-sm font-semibold">Fichier .{{ $s['extension'] }}
                            <input x-ref="fichier" type="file" accept=".{{ $s['extension'] }}" :disabled="enCours" @change="choisir($event)"
                                   class="text-sm font-normal file:mr-3 file:h-11 file:px-4 file:rounded-xl file:border-0 file:bg-accent-soft file:text-accent-dark file:font-bold">
                        </label>
                    </div>

                    <template x-if="enCours || pourcent > 0">
                        <div class="flex flex-col gap-1.5">
                            <div class="h-3 rounded-full bg-paper overflow-hidden" role="progressbar" :aria-valuenow="pourcent" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full bg-accent transition-all duration-200" :style="`width: ${pourcent}%`"></div>
                            </div>
                            <div class="flex justify-between text-xs text-muted">
                                <span x-text="`${pourcent} % · ${envoyeMo} / ${totalMo} Mo`"></span>
                                <span x-text="detail"></span>
                            </div>
                        </div>
                    </template>
                    <p x-show="message" x-text="message" class="rounded-xl px-4 py-3 text-sm font-semibold"
                       :class="erreur ? 'bg-danger-bg text-danger-fg' : 'bg-accent-soft text-accent-dark'"></p>

                    <div class="flex gap-3">
                        <button type="button" @click="envoyer()" :disabled="enCours || !fichier"
                                class="h-11 px-5 rounded-xl bg-accent text-white font-bold shadow-carte hover:bg-accent-dark disabled:opacity-50">
                            <span x-text="enCours ? 'Envoi en cours…' : 'Déposer et mettre en ligne'"></span>
                        </button>
                        <button type="button" x-show="enCours" @click="annuler = true" class="h-11 px-4 rounded-xl border border-border-strong font-semibold">Annuler</button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

@script
<script>
    // Envoi par morceaux de 1,5 Mo (le serveur refuse plus de 2 Mo par envoi),
    // dans l'ordre, chaque morceau réessayé 3 fois : la barre suit l'envoi réel.
    Alpine.data('envoiInstallateur', (systeme, version, adresse, jeton) => ({
        version, fichier: null, enCours: false, annuler: false, pourcent: 0,
        envoyeMo: '0', totalMo: '0', detail: '', message: '', erreur: false,
        choisir(e) { this.fichier = e.target.files[0] ?? null; this.message = ''; this.pourcent = 0; },
        async envoyer() {
            if (!/^\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(this.version.trim())) { this.echec('La version s’écrit comme 1.0.1.'); return; }
            const MORCEAU = 1.5 * 1024 * 1024, total = Math.ceil(this.fichier.size / MORCEAU), envoi = crypto.randomUUID(), debut = Date.now();
            this.enCours = true; this.annuler = false; this.erreur = false; this.message = '';
            this.totalMo = (this.fichier.size / 1048576).toFixed(1);
            try {
                for (let i = 0; i < total; i++) {
                    if (this.annuler) throw new Error('Envoi annulé.');
                    const corps = new FormData();
                    Object.entries({ systeme, version: this.version.trim(), envoi, index: i, total }).forEach(([k, v]) => corps.append(k, v));
                    corps.append('morceau', this.fichier.slice(i * MORCEAU, (i + 1) * MORCEAU), 'morceau');
                    let reponse, donnees;
                    for (let essai = 1; ; essai++) {
                        try {
                            reponse = await fetch(adresse, { method: 'POST', body: corps, headers: { 'X-CSRF-TOKEN': jeton, 'Accept': 'application/json' } });
                            donnees = await reponse.json().catch(() => ({}));
                            if (reponse.ok || reponse.status === 422) break;
                        } catch (e) { if (essai >= 3) throw new Error('Connexion perdue : réessayez.'); }
                        if (essai >= 3) throw new Error(donnees?.message ?? 'Le serveur a refusé le fichier.');
                        await new Promise(r => setTimeout(r, 1500 * essai));
                    }
                    if (reponse.status === 422) throw new Error(Object.values(donnees.errors ?? {})[0]?.[0] ?? donnees.message);
                    const envoye = Math.min(this.fichier.size, (i + 1) * MORCEAU), secondes = (Date.now() - debut) / 1000;
                    this.pourcent = Math.round(envoye / this.fichier.size * 100);
                    this.envoyeMo = (envoye / 1048576).toFixed(1);
                    const debit = envoye / Math.max(secondes, 0.1), reste = (this.fichier.size - envoye) / debit;
                    this.detail = `${(debit / 1048576).toFixed(1)} Mo/s` + (reste > 1 ? ` · encore ${reste < 60 ? Math.ceil(reste) + ' s' : Math.ceil(reste / 60) + ' min'}` : '');
                    if (donnees.termine) { this.message = donnees.message; this.detail = 'Terminé'; this.$refs.fichier.value = ''; this.fichier = null; $wire.$refresh(); }
                }
            } catch (e) { this.echec(e.message); }
            finally { this.enCours = false; }
        },
        echec(texte) { this.erreur = true; this.message = texte; },
    }));
</script>
@endscript
