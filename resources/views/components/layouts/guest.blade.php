@props(['title' => 'Connexion'])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — NGONI PAY Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-800 antialiased relative overflow-hidden">
    <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <div class="absolute -top-32 -left-32 h-96 w-96 rounded-full bg-indigo-600/30 blur-3xl"></div>
        <div class="absolute -bottom-32 -right-24 h-96 w-96 rounded-full bg-violet-600/20 blur-3xl"></div>
    </div>

    <div class="relative min-h-screen flex items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <div class="flex flex-col items-center gap-3 mb-8">
                <div class="h-12 w-12 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-500 flex items-center justify-center text-white font-bold text-lg shadow-lg shadow-indigo-900/40">
                    N
                </div>
                <div class="text-center">
                    <p class="text-white font-semibold">NGONI PAY</p>
                    <p class="text-slate-400 text-sm">Espace administration</p>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-2xl shadow-black/40 p-8 ring-1 ring-black/5">
                {{ $slot }}
            </div>

            <p class="text-center text-xs text-slate-500 mt-6">Accès réservé aux administrateurs NGONI PAY.</p>
        </div>
    </div>
</body>
</html>
