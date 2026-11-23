// Service worker de HAKO-RA Campus : coque publique hors ligne et notifications du navigateur.
//
// Règle centrale : ce fichier ne conserve QUE des fichiers publics, identiques pour tout le monde.
// Aucune page, aucune réponse Inertia, aucune route d'administration, aucun export, aucune pièce jointe,
// aucune réponse d'API n'est jamais mise en cache ici. Les données privées de lecture hors connexion vivent
// dans IndexedDB, sous le contrôle de la page et du choix « appareil personnel », jamais dans ce cache.

// À incrémenter quand un fichier de la coque change. Les fichiers de /build/assets/ portent déjà une empreinte dans leur nom.
const VERSION = 'v2';
const SHELL_CACHE = `campus-shell-${VERSION}`;
const STATIC_CACHE = `campus-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';
const SHELL_FILES = [OFFLINE_URL, '/offline-core.js', '/offline.js', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png'];

/**
 * Décide du traitement d'une requête :
 *  - « shell »    : fichier public de la coque, servi depuis le cache ;
 *  - « static »   : fichier de build public à empreinte, mis en cache à la première lecture ;
 *  - « navigate » : page demandée au réseau, jamais conservée ; hors connexion, la page neutre est affichée ;
 *  - « network »  : le service worker ne touche à rien.
 */
function strategyFor(method, url, mode, hasInertiaHeader, origin) {
    const target = new URL(url, origin);
    if (method !== 'GET' || target.origin !== origin || hasInertiaHeader) {
        return 'network';
    }
    if (target.search === '' && SHELL_FILES.includes(target.pathname)) {
        return 'shell';
    }
    if (target.pathname.startsWith('/build/assets/')) {
        return 'static';
    }

    return mode === 'navigate' ? 'navigate' : 'network';
}

self.addEventListener('install', (event) => {
    // Pas de skipWaiting ici : une nouvelle version attend que la personne l'accepte, pour ne jamais recharger une page en cours de saisie.
    event.waitUntil(caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_FILES)));
});

self.addEventListener('activate', (event) => {
    // Tout cache qui n'est pas l'un des deux caches publics de cette version est supprimé, quel que soit son nom.
    event.waitUntil(caches.keys()
        .then((names) => Promise.all(names.filter((name) => name !== SHELL_CACHE && name !== STATIC_CACHE).map((name) => caches.delete(name))))
        .then(() => self.clients.claim()));
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const strategy = strategyFor(request.method, request.url, request.mode, request.headers.has('X-Inertia'), self.location.origin);
    if (strategy === 'shell') {
        event.respondWith(caches.match(request, { cacheName: SHELL_CACHE }).then((cached) => cached || fetch(request)));
    } else if (strategy === 'static') {
        event.respondWith(caches.open(STATIC_CACHE).then((cache) => cache.match(request).then((cached) => cached || fetch(request).then((response) => {
            if (response.ok && response.type === 'basic') {
                cache.put(request, response.clone());
            }

            return response;
        }))));
    } else if (strategy === 'navigate') {
        // Réseau d'abord, sans rien conserver. Hors connexion : page neutre, qui ne contient aucune donnée.
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL, { cacheName: SHELL_CACHE })));
    }
});

// Notifications du navigateur (lot 13). Le serveur n'envoie aucun contenu : le message affiché est toujours générique,
// y compris sur un écran verrouillé.
self.addEventListener('push', (event) => {
    event.waitUntil(self.registration.showNotification('HAKO-RA Campus', {
        body: 'Vous avez une nouvelle notification. Ouvrez Campus pour la lire.',
        tag: 'campus-notification',
        renotify: false,
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

        return open ? open.focus().then((client) => client.navigate('/notifications')) : self.clients.openWindow('/notifications');
    }));
});

// Exposé pour les tests automatiques seulement.
self.__campus = { strategyFor, VERSION, SHELL_CACHE, STATIC_CACHE, SHELL_FILES, OFFLINE_URL };
