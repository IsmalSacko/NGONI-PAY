{{-- Commun à toutes les pages : icônes du site, couleur du navigateur et polices.
     Hors vitrine (indexer=false), les moteurs de recherche n'indexent pas. --}}
@props(['indexer' => false, 'icones' => true])
@unless ($indexer)
    <meta name="robots" content="noindex, nofollow">
@endunless
{{-- Search Console : la balise de vérification de Google, sur les pages publiques. --}}
@if ($indexer && config('ecaisse.google_site_verification'))
    <meta name="google-site-verification" content="{{ config('ecaisse.google_site_verification') }}">
@endif
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#0F2A5C">
{{-- Installable comme une application (écran d'accueil, plein écran). --}}
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Ngoni Caisse">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<script>
    // Service worker : page « hors connexion » (voir public/sw.js).
    if ('serviceWorker' in navigator) {
        addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
    // « Installer l'app » n'apparaît que si le navigateur le propose, et
    // disparaît une fois l'application installée.
    addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        window.ngoniInstallation = e;
        document.querySelectorAll('[data-installer]').forEach((b) => b.hidden = false);
    });
    addEventListener('appinstalled', () => document.querySelectorAll('[data-installer]').forEach((b) => b.hidden = true));
    addEventListener('click', async (e) => {
        const bouton = e.target.closest('[data-installer]');
        if (!bouton || !window.ngoniInstallation) return;
        window.ngoniInstallation.prompt();
        await window.ngoniInstallation.userChoice;
        window.ngoniInstallation = null;
        document.querySelectorAll('[data-installer]').forEach((b) => b.hidden = true);
    });
</script>
{{-- Nunito Sans et Poppins sont servies par le site (resources/css/app.css). --}}
{{-- Les mêmes pictogrammes que l'application (Material, arrondis) : 3 Mo chez
     Google, demandés une fois la page chargée — une feuille de Google qui
     n'arrive pas ne fige ni l'affichage ni la fin du chargement. Pas dans la
     console, qui dessine les siens. --}}
@if ($icones)
<script>
    addEventListener('load', () => {
        const l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400..600,0..1,0&display=block';
        document.head.appendChild(l);
    });
</script>
@endif
