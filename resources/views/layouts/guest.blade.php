<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Ngoni Caisse' }}</title>
    <x-tete-commune />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-accent text-ink font-sans antialiased">
    <div class="min-h-screen flex items-center justify-center p-4 sm:p-6">
        <div class="w-full max-w-sm">
            <div class="flex flex-col items-center gap-3 mb-8 text-center">
                <a href="{{ route('vitrine') }}" class="text-2xl"><x-logo taille="w-14 h-14" :nom="true" /></a>
                <p class="text-sm font-semibold text-rail">La caisse moderne pour les commerçants en Afrique</p>
            </div>
            {{ $slot }}
        </div>
    </div>
    @livewireScripts
</body>
</html>
