// Service worker réel (public/sw.js), chargé dans un environnement simulé : on observe ce qu'il met en cache et ce qu'il laisse passer.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const ORIGIN = 'https://campus.example.test';
const source = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');

/** Charge le service worker avec un réseau et des caches simulés. `online` peut être changé en cours de test. */
function load({ online = true, existingCaches = [] } = {}) {
    const listeners = {};
    const stores = new Map(existingCaches.map((name) => [name, new Map()]));
    const log = { puts: [], fetched: [], skipWaiting: 0, claimed: 0, deleted: [], shown: [] };
    const state = { online };
    const cacheApi = (name) => ({
        addAll: async (urls) => { urls.forEach((url) => { stores.get(name).set(new URL(url, ORIGIN).href, { body: 'public:' + url }); log.puts.push(new URL(url, ORIGIN).href); }); },
        put: async (request, response) => { stores.get(name).set(request.url, response); log.puts.push(request.url); },
        match: async (request) => stores.get(name).get(typeof request === 'string' ? new URL(request, ORIGIN).href : request.url),
    });
    const caches = {
        open: async (name) => { if (!stores.has(name)) stores.set(name, new Map()); return cacheApi(name); },
        keys: async () => [...stores.keys()],
        delete: async (name) => { log.deleted.push(name); return stores.delete(name); },
        match: async (request, options) => {
            const names = options?.cacheName ? [options.cacheName] : [...stores.keys()];
            for (const name of names) {
                const hit = stores.get(name)?.get(typeof request === 'string' ? new URL(request, ORIGIN).href : request.url);
                if (hit) return hit;
            }

            return undefined;
        },
    };
    const self = {
        location: { origin: ORIGIN },
        addEventListener: (type, handler) => { listeners[type] = handler; },
        skipWaiting: () => { log.skipWaiting++; },
        clients: { claim: async () => { log.claimed++; }, matchAll: async () => [], openWindow: async () => null },
        registration: { showNotification: async (title, options) => { log.shown.push({ title, ...options }); } },
    };
    const fetch = async (request) => {
        log.fetched.push(request.url);
        if (!state.online) throw new TypeError('Réseau coupé');

        return { ok: true, type: 'basic', url: request.url, body: 'réseau:' + request.url, clone() { return this; } };
    };
    vm.runInNewContext(source, { self, caches, fetch, URL, Promise, console });
    const dispatch = async (type, properties = {}) => {
        let responded; let waited;
        listeners[type]({ ...properties, respondWith: (promise) => { responded = promise; }, waitUntil: (promise) => { waited = promise; } });
        await waited;

        return { handled: responded !== undefined, response: await responded };
    };
    const request = (path, { method = 'GET', mode = 'cors', inertia = false, origin = ORIGIN } = {}) => ({
        method, mode, url: new URL(path, origin).href, headers: { has: (name) => inertia && name === 'X-Inertia' },
    });

    return { self, log, state, stores, dispatch, request, campus: self.__campus };
}

test('seuls des fichiers publics de la coque sont préchargés, et l’installation n’active pas la nouvelle version de force', async () => {
    const worker = load();
    await worker.dispatch('install');
    // Copie du tableau : il vient d'un autre contexte d'exécution que le test.
    assert.deepEqual([...worker.campus.SHELL_FILES], ['/offline.html', '/offline-core.js', '/offline.js', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png']);
    assert.deepEqual([...worker.stores.keys()], [worker.campus.SHELL_CACHE]);
    assert.deepEqual(worker.log.puts, [...worker.campus.SHELL_FILES].map((path) => ORIGIN + path));
    // Pas de rechargement forcé : la nouvelle version attend le choix de la personne.
    assert.equal(worker.log.skipWaiting, 0);
    await worker.dispatch('message', { data: { type: 'AUTRE' } });
    await worker.dispatch('message', { data: null });
    assert.equal(worker.log.skipWaiting, 0);
    await worker.dispatch('message', { data: { type: 'SKIP_WAITING' } });
    assert.equal(worker.log.skipWaiting, 1);
});

test('aucune page, aucune réponse Inertia, aucune route privée n’est jamais mise en cache', async () => {
    const worker = load();
    await worker.dispatch('install');
    const before = worker.log.puts.length;
    const privatePaths = ['/', '/planning', '/taches', '/notes', '/notes/evaluations', '/retours', '/profil', '/opportunites/suivi', '/opportunites/alertes', '/notifications',
        '/administration/etablissement', '/administration/plateforme', '/administration/catalogue', '/administration/etablissement/membres/export',
        '/pieces-jointes/0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000', '/planning/import/modele', '/hors-ligne/instantane', '/connexion', '/reinitialisation/jeton-fictif', '/invitation/jeton-fictif-de-vingt-caracteres'];
    for (const path of privatePaths) {
        // Navigation classique : réseau, sans conservation.
        const page = await worker.dispatch('fetch', { request: worker.request(path, { mode: 'navigate' }) });
        assert.equal(page.handled, true, path);
        assert.equal(page.response.body, 'réseau:' + ORIGIN + path, path);
        // Requête Inertia ou appel de données : le service worker ne s'en mêle pas du tout.
        assert.equal(worker.campus.strategyFor('GET', ORIGIN + path, 'cors', true, ORIGIN), 'network', path);
        assert.equal((await worker.dispatch('fetch', { request: worker.request(path, { inertia: true }) })).handled, false, path);
        assert.equal((await worker.dispatch('fetch', { request: worker.request(path) })).handled, false, path);
        // Écritures : jamais interceptées.
        for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
            assert.equal((await worker.dispatch('fetch', { request: worker.request(path, { method, mode: 'navigate' }) })).handled, false, method + ' ' + path);
        }
    }
    // Autre origine (source officielle d'une fiche, service de push) : jamais interceptée.
    assert.equal((await worker.dispatch('fetch', { request: worker.request('/alpha', { origin: 'https://example.test', mode: 'navigate' }) })).handled, false);
    assert.equal(worker.log.puts.length, before, 'rien de plus n’a été écrit dans un cache');
    assert.deepEqual([...worker.stores.keys()], [worker.campus.SHELL_CACHE]);
    for (const url of worker.stores.get(worker.campus.SHELL_CACHE).keys()) {
        assert.ok(worker.campus.SHELL_FILES.includes(new URL(url).pathname), url);
    }
});

test('seuls les fichiers de build à empreinte sont conservés à la volée', async () => {
    const worker = load();
    await worker.dispatch('install');
    const asset = '/build/assets/app-AbCd1234.js';
    const first = await worker.dispatch('fetch', { request: worker.request(asset) });
    assert.equal(first.response.body, 'réseau:' + ORIGIN + asset);
    assert.deepEqual([...worker.stores.get(worker.campus.STATIC_CACHE).keys()], [ORIGIN + asset]);
    // Deuxième lecture : servie depuis le cache, même réseau coupé.
    worker.state.online = false;
    const fetched = worker.log.fetched.length;
    const second = await worker.dispatch('fetch', { request: worker.request(asset) });
    assert.equal(second.response.body, 'réseau:' + ORIGIN + asset);
    assert.equal(worker.log.fetched.length, fetched);
    // Un fichier de la coque demandé avec des paramètres n'est pas confondu avec la coque.
    assert.equal(worker.campus.strategyFor('GET', ORIGIN + '/offline.html?compte=1', 'navigate', false, ORIGIN), 'navigate');
    assert.equal(worker.campus.strategyFor('GET', ORIGIN + '/storage/fichier.pdf', 'no-cors', false, ORIGIN), 'network');
    assert.equal(worker.campus.strategyFor('GET', ORIGIN + '/build/manifest.json', 'cors', false, ORIGIN), 'network');
});

test('R44 — réseau coupé : une page neutre remplace toute navigation, sans aucune donnée', async () => {
    const worker = load({ online: false });
    worker.state.online = true;
    await worker.dispatch('install');
    worker.state.online = false;
    for (const path of ['/', '/planning', '/notes', '/administration/etablissement', '/pieces-jointes/0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000']) {
        const page = await worker.dispatch('fetch', { request: worker.request(path, { mode: 'navigate' }) });
        assert.equal(page.response.body, 'public:/offline.html', path);
    }
    // Les appels de données échouent normalement : rien n'est inventé ni resservi.
    assert.equal((await worker.dispatch('fetch', { request: worker.request('/hors-ligne/instantane') })).handled, false);
    // La page neutre elle-même ne contient aucune donnée de compte.
    const page = readFileSync(new URL('../../public/offline.html', import.meta.url), 'utf8');
    assert.match(page, /Vous êtes hors connexion/);
    assert.match(page, /Aucune donnée n’est enregistrée sur cet appareil/);
    assert.doesNotMatch(page, /fetch\(|XMLHttpRequest|csrf|token/i);
});

test('une nouvelle version supprime tout cache qui n’est pas l’un de ses deux caches publics', async () => {
    const worker = load({ existingCaches: ['campus-shell-v0', 'campus-static-v0', 'campus-private-compte-a', 'pages', 'inertia-html'] });
    await worker.dispatch('install');
    await worker.dispatch('activate');
    assert.deepEqual(worker.log.deleted.sort(), ['campus-private-compte-a', 'campus-shell-v0', 'campus-static-v0', 'inertia-html', 'pages']);
    assert.deepEqual([...worker.stores.keys()], [worker.campus.SHELL_CACHE]);
    assert.equal(worker.log.claimed, 1);
});

test('la notification du navigateur est générique : le contenu reçu n’est jamais lu', async () => {
    const worker = load();
    await worker.dispatch('push', { data: { json: () => { throw new Error('le contenu ne doit pas être lu'); }, text: () => { throw new Error('le contenu ne doit pas être lu'); } } });
    assert.deepEqual(worker.log.shown, [{ title: 'HAKO-RA Campus', body: 'Vous avez une nouvelle notification. Ouvrez Campus pour la lire.', tag: 'campus-notification', renotify: false }]);
});
