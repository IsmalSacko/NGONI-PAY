{{-- Commun à toutes les pages : icônes du site, couleur du navigateur et polices.
     Hors vitrine (indexer=false), les moteurs de recherche n'indexent pas. --}}
@props(['indexer' => false])
@unless ($indexer)
    <meta name="robots" content="noindex, nofollow">
@endunless
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700;6..12,800&family=Poppins:wght@600;700;800&display=swap">
{{-- Les mêmes pictogrammes que l'application (Material, arrondis). --}}
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400..600,0..1,0&display=block">
