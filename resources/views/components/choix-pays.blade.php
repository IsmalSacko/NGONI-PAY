@props(['model' => 'pays', 'listePays'])
{{--
    Choix du pays avec recherche : plus de 200 pays, on tape « cote », « 225 »
    ou « CI » plutôt que de faire défiler. Clavier : ↑ ↓ pour se déplacer,
    Entrée pour choisir, Échap pour fermer.
--}}
@php
    $options = collect($listePays)->map(fn ($p) => [
        'code' => $p->value,
        'nom' => $p->label(),
        'indicatif' => $p->dialingCode(),
        'drapeau' => $p->flag(),
    ])->values();
@endphp
<div class="relative"
     x-data="{
        ouvert: false,
        recherche: '',
        actif: 0,
        valeur: $wire.entangle('{{ $model }}'),
        options: @js($options),
        norm(t) { return (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); },
        get resultats() {
            const r = this.norm(this.recherche.trim());
            if (!r) return this.options;
            const chiffres = this.recherche.replace(/\D/g, '');
            return this.options.filter(o => this.norm(o.nom).includes(r)
                || o.code.toLowerCase() === r
                || (chiffres && o.indicatif.startsWith(chiffres)));
        },
        get choisi() { return this.options.find(o => o.code === this.valeur) || this.options[0]; },
        ouvrir() { this.ouvert = true; this.recherche = ''; this.actif = 0; this.$nextTick(() => this.$refs.recherche.focus()); },
        choisir(o) { if (!o) return; this.valeur = o.code; this.ouvert = false; },
        deplacer(pas) {
            const n = this.resultats.length;
            if (!n) return;
            this.actif = (this.actif + pas + n) % n;
            this.$nextTick(() => this.$refs.liste.children[this.actif]?.scrollIntoView({ block: 'nearest' }));
        },
     }"
     @click.outside="ouvert = false"
     @keydown.escape.prevent="ouvert = false">
    <button type="button" id="pays" @click="ouvert ? ouvert = false : ouvrir()"
            class="w-full h-12 px-4 rounded-xl border border-[--color-border-strong] bg-white text-left flex items-center justify-between focus:outline-none focus:ring-2 focus:ring-accent">
        <span x-text="choisi ? `${choisi.drapeau} ${choisi.nom} (+${choisi.indicatif})` : ''"></span>
        <span aria-hidden="true">▾</span>
    </button>

    <div x-show="ouvert" x-cloak x-transition.opacity
         class="absolute z-20 mt-1 w-full bg-white border border-[--color-border-strong] rounded-xl shadow-lg overflow-hidden">
        <input x-ref="recherche" x-model="recherche" type="text" autocomplete="off"
               placeholder="Rechercher un pays ou un indicatif"
               @input="actif = 0"
               @keydown.arrow-down.prevent="deplacer(1)"
               @keydown.arrow-up.prevent="deplacer(-1)"
               @keydown.enter.prevent="choisir(resultats[actif])"
               class="w-full h-11 px-4 border-b border-[--color-border] focus:outline-none">
        <ul x-ref="liste" class="max-h-64 overflow-y-auto" role="listbox">
            <template x-for="(o, i) in resultats" :key="o.code">
                <li role="option" :aria-selected="o.code === valeur"
                    @click="choisir(o)" @mouseenter="actif = i"
                    :class="i === actif ? 'bg-accent-soft' : ''"
                    class="px-4 py-2 cursor-pointer flex items-center justify-between text-sm">
                    <span><span x-text="o.drapeau"></span> <span x-text="o.nom" :class="o.code === valeur ? 'font-bold' : ''"></span></span>
                    <span class="text-[--color-muted]" x-text="`+${o.indicatif}`"></span>
                </li>
            </template>
            <li x-show="resultats.length === 0" class="px-4 py-3 text-sm text-[--color-muted]">Aucun pays trouvé.</li>
        </ul>
    </div>
</div>
