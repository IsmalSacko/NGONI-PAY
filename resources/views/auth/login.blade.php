<x-layouts.guest>
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Connexion</h1>
        <p class="text-sm text-slate-500 mt-0.5">Accédez à votre tableau de bord.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="login" class="block text-sm font-medium text-slate-700 mb-1.5">Email ou téléphone</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                    <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14c-5 0-8 2.5-8 5v1h16v-1c0-2.5-3-5-8-5Z" />
                    </svg>
                </span>
                <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                       placeholder="admin@ngonipay.com"
                       class="block w-full rounded-lg border border-slate-300 pl-10 pr-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                              focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition">
            </div>
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Mot de passe</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                    <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 11h14v10H5V11Zm3 0V7a4 4 0 1 1 8 0v4" />
                    </svg>
                </span>
                <input id="password" name="password" type="password" required
                       placeholder="••••••••"
                       class="block w-full rounded-lg border border-slate-300 pl-10 pr-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400
                              focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition">
            </div>
        </div>

        @error('login')
            <p class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ $message }}</p>
        @enderror

        <button type="submit"
                class="w-full rounded-lg bg-gradient-to-r from-violet-600 to-indigo-600 px-4 py-2.5 text-sm font-medium text-white
                       shadow-sm shadow-indigo-600/30 hover:from-violet-500 hover:to-indigo-500 transition">
            Se connecter
        </button>
    </form>
</x-layouts.guest>
