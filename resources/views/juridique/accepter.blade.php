{{-- Back-office : la nouvelle version des conditions, à accepter avant de continuer. --}}
<x-juridique.page titre="Nos conditions évoluent" actif="conditions" :canonique="route('conditions')">

<p>Bonjour {{ auth()->user()->name }}. Pour continuer à utiliser Ngoni Caisse, lisez et acceptez nos conditions d’utilisation et notre politique de confidentialité. Elles précisent vos droits, nos engagements, les abonnements et l’offre à vie.</p>
<ul>
    <li><a href="{{ route('conditions') }}" target="_blank" rel="noopener">Conditions générales d’utilisation et de vente</a></li>
    <li><a href="{{ route('confidentialite') }}" target="_blank" rel="noopener">Politique de confidentialité</a></li>
</ul>

<form method="POST" action="{{ route('conditions.accepter') }}" class="mt-6">
    @csrf
    <label class="flex items-start gap-3 rounded-2xl bg-jaune-doux p-4 cursor-pointer">
        <input type="checkbox" name="conditions_acceptees" value="1" class="mt-1 w-5 h-5 accent-[var(--color-succes)]">
        <span>J’ai lu et j’accepte les conditions d’utilisation et la politique de confidentialité de Ngoni Caisse.</span>
    </label>
    @error('conditions_acceptees')<p class="mt-2 font-bold text-danger-fg">{{ $message }}</p>@enderror
    <button type="submit" class="mt-5 w-full h-12 rounded-xl bg-accent text-white font-bold hover:bg-accent-dark">J’accepte et je continue</button>
</form>
<form method="POST" action="{{ route('deconnexion') }}" class="mt-3 text-center">
    @csrf
    <button type="submit" class="text-sm font-bold text-accent underline">Se déconnecter</button>
</form>

</x-juridique.page>
