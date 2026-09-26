<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Ngoni Caisse' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-paper text-ink font-sans antialiased">
    <div class="min-h-screen flex items-center justify-center p-6">
        <div class="w-full max-w-sm">
            <div class="flex items-center gap-3 mb-8 justify-center">
                <span class="w-11 h-11 rounded-2xl bg-accent text-white flex items-center justify-center font-display font-extrabold text-xl">N</span>
                <span class="font-display font-extrabold text-2xl">Ngoni Caisse</span>
            </div>
            {{ $slot }}
        </div>
    </div>
    @livewireScripts
</body>
</html>
