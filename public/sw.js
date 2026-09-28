// Service worker du site installé (back-office et console).
//
// Volontairement minimal : il ne garde que la page « hors connexion », et ne
// sert jamais une page ni une donnée depuis un cache — un chiffre d'hier
// affiché comme celui d'aujourd'hui serait pire qu'un écran qui dit « pas de
// réseau ». Les requêtes Livewire, l'API et les fichiers passent tels quels.
const CACHE = 'ngoni-hors-ligne-v1';
const HORS_LIGNE = '/hors-ligne.html';

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([HORS_LIGNE, '/icone-192.png'])));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((cles) => Promise.all(cles.filter((cle) => cle !== CACHE).map((cle) => caches.delete(cle))))
      .then(() => self.clients.claim()),
  );
});

// Seules les navigations (ouvrir une page) sont interceptées : réseau d'abord,
// la page « hors connexion » si le réseau ne répond pas.
self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return;
  event.respondWith(fetch(event.request).catch(() => caches.match(HORS_LIGNE)));
});
