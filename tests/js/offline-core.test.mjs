// Cœur réel de la file hors connexion (public/offline-core.js), exécuté tel quel avec un stockage en mémoire et un serveur simulé
// qui applique les mêmes règles que le vrai : une opération par compte et par identifiant, version attendue, accusé rejoué.
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/offline-core.js', import.meta.url), 'utf8');
const ACCOUNT = 'a'.repeat(32);
const OTHER = 'b'.repeat(32);
const TASK = '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0001';
const COURSE = '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0002';

function core() {
    const self = {};
    vm.runInNewContext(source, { self, TextEncoder });

    return self.CampusOffline;
}

/** Copie simple : les objets viennent d'un autre contexte d'exécution que le test. */
const plain = (value) => JSON.parse(JSON.stringify(value));

/** Serveur simulé : registre d'idempotence par opération, objets versionnés, pannes commandées. */
function server() {
    const state = { ledger: new Map(), objects: new Map(), requests: [], failures: [], created: 0, refreshes: 0 };
    const json = (status, body) => ({ status, redirected: false, headers: { get: (name) => (name === 'Content-Type' ? 'application/json' : null) }, json: async () => body });
    const answer = (operation) => {
        const known = state.ledger.get(operation.operation_id);
        if (known) {
            return known.fingerprint === operation.fingerprint ? json(known.status, { ...known.body, replayed: true })
                : json(409, { operation_id: operation.operation_id, outcome: 'conflict', code: 'IDEMPOTENCY_KEY_REUSED', result: null, current: null, replayed: false });
        }
        const id = operation.type === 'task.create' ? 'task-' + ++state.created : operation.target_id;
        const current = state.objects.get(id);
        let status = 200;
        let body;
        if (operation.type !== 'task.create' && state.revoked?.includes(id)) {
            [status, body] = [422, { outcome: 'rejected', code: 'PARENT_UNAVAILABLE', message: 'Indisponible.', result: null, current: null, errors: {} }];
        } else if (operation.type !== 'task.create' && (current?.version ?? 0) !== operation.base_version) {
            [status, body] = [409, { outcome: 'conflict', code: 'VERSION_CONFLICT', message: 'Modifié ailleurs.', result: null, current: current ? { version: current.version, ...current.payload } : null, errors: {} }];
        } else {
            const version = (current?.version ?? 0) + 1;
            state.objects.set(id, { version, payload: operation.payload });
            body = { outcome: 'applied', code: 'APPLIED', message: 'Enregistré.', result: { id, version, status: operation.payload.status ?? 'todo' }, current: null, errors: {} };
        }
        body = { operation_id: operation.operation_id, replayed: false, ...body };
        state.ledger.set(operation.operation_id, { fingerprint: operation.fingerprint, status, body });

        return json(status, body);
    };
    const fetch = async (url, options) => {
        if (url === '/hors-ligne/instantane') { state.refreshes++; return json(200, {}); }
        const operation = JSON.parse(options.body);
        state.requests.push({ url, operation, headers: options.headers });
        const failure = state.failures.shift();
        if (failure === 'network') throw new TypeError('Réseau coupé');
        if (failure === 'lost-answer') { answer(operation); throw new TypeError('Réponse perdue'); }
        if (failure === 'account') return json(403, { code: 'ACCOUNT_MISMATCH' });
        if (typeof failure === 'number') return failure === 302 ? { status: 200, redirected: true, headers: { get: () => 'text/html' } } : json(failure, { code: 'ERREUR' });

        return answer(operation);
    };

    return { state, fetch };
}

let devices = 0;

function device(api, backend, stored) {
    let counter = 0;
    const number = String(++devices).padStart(4, '0');
    let clock = Date.parse('2026-11-16T08:00:00Z');
    const store = stored ?? api.memoryStore();
    const outbox = api.createOutbox({ store, fetch: backend.fetch, crypto: webcrypto, csrf: () => 'jeton-fictif',
        uuid: () => `00000000-${number}-4000-8000-${String(++counter).padStart(12, '0')}`, now: () => new Date(clock += 1000).toISOString() });

    return { outbox, store, advance: (ms) => { clock += ms; } };
}

const task = (fields = {}) => ({ title: 'Tâche fictive hors connexion', description: '', due_at: '', priority: 'normal', ...fields });

test('l’empreinte est la même forme canonique que celle du serveur', async () => {
    const api = core();
    // Même contenu et même empreinte que dans tests/Feature/SyncTest.php.
    const payload = api.normalize('task.create', { title: '  Révision « fictive » d’été / n°1 😀 ', description: 'Ligne 1\nLigne 2\t"citée" \\ fin suite', due_at: '2026-11-20T18:00', priority: 'high' });
    assert.deepEqual(plain(payload), { title: 'Révision « fictive » d’été / n°1 😀', description: 'Ligne 1\nLigne 2\t"citée" \\ fin suite', due_at: '2026-11-20T18:00', priority: 'high', personal_subject_id: null, source_occurrence_id: null });
    assert.equal(await api.fingerprint({ type: 'task.create', targetId: null, baseVersion: 0, payload }, webcrypto), '8af8d43334d47b3fa776767f4bc536df26e25532c3cd30fc7428b3371b878993');
    assert.equal(api.canonical({ b: 1, a: [null, true, 'é/"'] }), '{"a":[null,true,"é/\\""],"b":1}');
    assert.throws(() => api.canonical({ a: 1.5 }));
    // Seuls les champs attendus partent : un champ d'établissement glissé dans la saisie est ignoré.
    assert.deepEqual(Object.keys(api.normalize('task.update', { title: 'x', group_id: 4, scope: 'group', institution_id: 1 })).sort(), ['description', 'due_at', 'personal_subject_id', 'priority', 'status', 'title']);
});

test('R57, R23 — réseau oscillant et envoi répété : une seule création, la même opération renvoyée à l’identique', async () => {
    const api = core();
    const backend = server();
    const { outbox } = device(api, backend);
    const { operation, errors } = await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task(), label: '  Tâche fictive hors connexion  ' });
    assert.deepEqual([plain(errors), operation.state, operation.fingerprint, operation.context, operation.label], [[], 'local', null, 'personal', 'Tâche fictive hors connexion']);

    // Premier essai : réseau coupé. Deuxième : le serveur enregistre mais la réponse se perd. Troisième : erreur du serveur. Quatrième : réponse reçue.
    backend.state.failures.push('network', 'lost-answer', 503);
    const states = [];
    for (let attempt = 0; attempt < 4; attempt++) {
        const summary = await outbox.synchronize(ACCOUNT);
        const [current] = await outbox.list(ACCOUNT);
        states.push([current.state, current.attempts, summary.stopped]);
    }
    assert.deepEqual(states, [['pending', 1, 'network'], ['pending', 2, 'network'], ['pending', 3, 'server'], ['synced', 4, null]]);
    // Quatre envois, strictement identiques ; une seule tâche côté serveur ; le dernier accusé est un accusé rejoué.
    assert.equal(backend.state.requests.length, 4);
    assert.equal(new Set(backend.state.requests.map((request) => JSON.stringify(request.operation))).size, 1);
    assert.deepEqual([backend.state.created, backend.state.ledger.size], [1, 1]);
    const [synced] = await outbox.list(ACCOUNT);
    assert.deepEqual(plain(synced.ack.result), { id: 'task-1', version: 1, status: 'todo' });
    assert.equal(synced.ack.replayed, true);
    // Chaque opération porte identifiant, compte, contexte, type, empreinte et version connue ; le jeton de formulaire accompagne l'envoi.
    const sent = backend.state.requests[0];
    assert.deepEqual(Object.keys(sent.operation).sort(), ['account_key', 'base_version', 'context', 'fingerprint', 'operation_id', 'payload', 'target_id', 'type']);
    assert.deepEqual([sent.url, sent.operation.account_key, sent.operation.context, sent.operation.base_version, sent.headers['X-XSRF-TOKEN']], ['/synchronisation/v1/operations', ACCOUNT, 'personal', 0, 'jeton-fictif']);
    assert.match(sent.operation.fingerprint, /^[0-9a-f]{64}$/);
    // Une fois synchronisée, l'opération n'est plus jamais renvoyée.
    await outbox.synchronize(ACCOUNT);
    assert.equal(backend.state.requests.length, 4);
});

test('un brouillon jamais envoyé se remplace ; un brouillon déjà tenté ne change plus et la saisie suivante part après lui', async () => {
    const api = core();
    const backend = server();
    const { outbox } = device(api, backend);
    const first = (await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task() })).operation;
    // Modification de la tâche pas encore créée, hors connexion : même brouillon, toujours une création.
    const edited = (await outbox.draft({ accountKey: ACCOUNT, type: 'task.update', targetOperation: first.id, fields: task({ title: 'Titre corrigé hors connexion', status: 'todo' }) })).operation;
    assert.deepEqual([edited.id, edited.type, edited.payload.title, (await outbox.list(ACCOUNT)).length], [first.id, 'task.create', 'Titre corrigé hors connexion', 1]);

    // L'envoi part, la réponse se perd : l'opération est figée.
    backend.state.failures.push('lost-answer');
    await outbox.synchronize(ACCOUNT);
    const frozen = plain((await outbox.list(ACCOUNT))[0]);
    const next = (await outbox.draft({ accountKey: ACCOUNT, type: 'task.update', targetOperation: first.id, fields: task({ title: 'Deuxième saisie', status: 'in_progress' }) })).operation;
    const queue = plain(await outbox.list(ACCOUNT));
    assert.deepEqual(queue.map((operation) => [operation.id === first.id, operation.type, operation.state, operation.after]), [[true, 'task.create', 'pending', null], [false, 'task.update', 'local', first.id]]);
    assert.deepEqual(queue[0], frozen);
    assert.equal(next.targetId, null);

    // Au retour du réseau : la création est rejouée, puis la modification part sur la tâche et la version qu'elle a produites.
    const summary = await outbox.synchronize(ACCOUNT);
    assert.deepEqual([summary.synced, summary.remaining, backend.state.created], [2, 0, 1]);
    const update = backend.state.requests.at(-1).operation;
    assert.deepEqual([update.type, update.target_id, update.base_version, update.payload.status], ['task.update', 'task-1', 1, 'in_progress']);
    assert.deepEqual(plain(backend.state.objects.get('task-1')).version, 2);
    // Erreurs visibles avant toute mise en file.
    assert.deepEqual(plain((await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task({ title: '   ' }) })).errors), ['Le titre est obligatoire.']);
    assert.deepEqual(plain((await outbox.draft({ accountKey: ACCOUNT, type: 'check_in.save', targetId: COURSE, baseVersion: 0, fields: {} })).errors), ['Renseignez au moins un champ.']);
});

test('R45 — deux appareils modifient la même tâche : conflit visible, brouillon gardé, jamais de « dernier arrivé gagne »', async () => {
    const api = core();
    const backend = server();
    backend.state.objects.set(TASK, { version: 1, payload: api.normalize('task.update', task({ title: 'Titre initial', status: 'todo' })) });
    const first = device(api, backend);
    const second = device(api, backend);
    await first.outbox.draft({ accountKey: ACCOUNT, type: 'task.update', targetId: TASK, baseVersion: 1, fields: task({ title: 'Titre de l’appareil A', status: 'todo' }) });
    await second.outbox.draft({ accountKey: ACCOUNT, type: 'task.update', targetId: TASK, baseVersion: 1, fields: task({ title: 'Titre de l’appareil B', description: 'Note fictive de B', status: 'in_progress' }) });
    assert.equal((await first.outbox.synchronize(ACCOUNT)).synced, 1);
    const summary = await second.outbox.synchronize(ACCOUNT);
    assert.deepEqual([summary.conflicts, summary.synced, summary.remaining], [1, 0, 0]);
    const [conflict] = await second.outbox.list(ACCOUNT);
    assert.deepEqual([conflict.state, conflict.payload.title, conflict.ack.current.title, conflict.ack.current.version], ['conflict', 'Titre de l’appareil B', 'Titre de l’appareil A', 2]);
    assert.deepEqual(plain(api.differences(conflict)), [
        { field: 'title', label: 'Titre', mine: 'Titre de l’appareil B', theirs: 'Titre de l’appareil A' },
        { field: 'description', label: 'Description', mine: 'Note fictive de B', theirs: null },
        { field: 'status', label: 'État', mine: 'in_progress', theirs: 'todo' },
    ]);
    // Rien n'est écrasé, et resynchroniser ne renvoie pas le brouillon en conflit de lui-même.
    const before = backend.state.requests.length;
    await second.outbox.synchronize(ACCOUNT);
    assert.deepEqual([backend.state.requests.length, backend.state.objects.get(TASK).payload.title, backend.state.objects.get(TASK).version], [before, 'Titre de l’appareil A', 2]);

    // Décision explicite « appliquer mon brouillon » : nouvelle opération, fondée sur la version que la personne vient de voir.
    const mine = await second.outbox.resolve(conflict.id, 'mine');
    assert.deepEqual([mine.id !== conflict.id, mine.baseVersion, mine.state, mine.fingerprint, mine.replaces], [true, 2, 'local', null, conflict.id]);
    assert.deepEqual([(await second.outbox.synchronize(ACCOUNT)).synced, backend.state.objects.get(TASK).payload.title, backend.state.objects.get(TASK).version], [1, 'Titre de l’appareil B', 3]);

    // Décision explicite « garder la version du serveur » : le brouillon est abandonné, rien ne part.
    await first.outbox.draft({ accountKey: ACCOUNT, type: 'task.update', targetId: TASK, baseVersion: 2, fields: task({ title: 'Brouillon périmé de A', status: 'todo' }) });
    await first.outbox.synchronize(ACCOUNT);
    const stale = (await first.outbox.list(ACCOUNT)).find((operation) => operation.state === 'conflict');
    const requests = backend.state.requests.length;
    assert.equal(await first.outbox.resolve(stale.id, 'server'), null);
    await first.outbox.synchronize(ACCOUNT);
    assert.deepEqual([(await first.outbox.unsynced(ACCOUNT)).length, backend.state.requests.length, backend.state.objects.get(TASK).payload.title], [0, requests, 'Titre de l’appareil B']);
});

test('R23 — deux appareils écrivent un retour pour le même cours : un seul retour, le second brouillon reste en conflit', async () => {
    const api = core();
    const backend = server();
    const first = device(api, backend);
    const second = device(api, backend);
    await first.outbox.draft({ accountKey: ACCOUNT, type: 'check_in.save', targetId: COURSE, baseVersion: 0, fields: { summary: 'Retour fictif de A', understanding: '3' }, label: 'Cours fictif' });
    await second.outbox.draft({ accountKey: ACCOUNT, type: 'check_in.save', targetId: COURSE, baseVersion: 0, fields: { summary: 'Retour fictif de B' }, label: 'Cours fictif' });
    await first.outbox.synchronize(ACCOUNT);
    await second.outbox.synchronize(ACCOUNT);
    const [conflict] = await second.outbox.list(ACCOUNT);
    assert.deepEqual([conflict.state, conflict.payload.summary, backend.state.objects.get(COURSE).version, backend.state.objects.get(COURSE).payload.summary], ['conflict', 'Retour fictif de B', 1, 'Retour fictif de A']);
    assert.deepEqual(plain(api.differences(conflict)).map((difference) => difference.field), ['summary', 'understanding']);
});

test('R48 — refus du serveur : le brouillon reste sur l’appareil, la suite n’est pas envoyée sur une base inconnue', async () => {
    const api = core();
    const backend = server();
    backend.state.revoked = [COURSE];
    const { outbox } = device(api, backend);
    await outbox.draft({ accountKey: ACCOUNT, type: 'check_in.save', targetId: COURSE, baseVersion: 0, fields: { summary: 'Brouillon privé fictif' }, label: 'Cours fictif' });
    const summary = await outbox.synchronize(ACCOUNT);
    const [refused] = await outbox.list(ACCOUNT);
    assert.deepEqual([summary.refused, refused.state, refused.ack.code, refused.payload.summary, backend.state.objects.has(COURSE)], [1, 'refused', 'PARENT_UNAVAILABLE', 'Brouillon privé fictif', false]);
    // Le refus n'est pas rejoué en boucle.
    const requests = backend.state.requests.length;
    await outbox.synchronize(ACCOUNT);
    assert.equal(backend.state.requests.length, requests);
    // Nouvelle tentative demandée par la personne : nouvelle opération, même contenu.
    backend.state.revoked = [];
    const again = await outbox.retry(refused.id);
    assert.deepEqual([again.id !== refused.id, again.state, again.payload.summary], [true, 'local', 'Brouillon privé fictif']);
    assert.equal((await outbox.synchronize(ACCOUNT)).synced, 1);

    // Une création refusée entraîne le refus local de la modification qui en dépendait, sans l'envoyer.
    const creation = (await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task() })).operation;
    backend.state.failures.push(422);
    await outbox.synchronize(ACCOUNT);
    await outbox.draft({ accountKey: ACCOUNT, type: 'task.update', targetOperation: creation.id, fields: task({ title: 'Suite fictive', status: 'done' }) });
    const sent = backend.state.requests.length;
    await outbox.synchronize(ACCOUNT);
    const states = plain(await outbox.list(ACCOUNT)).filter((operation) => operation.type !== 'check_in.save').map((operation) => [operation.state, operation.ack.code, operation.payload.title]);
    assert.deepEqual([plain(states), backend.state.requests.length], [[['refused', 'ERREUR', 'Tâche fictive hors connexion'], ['refused', 'DEPENDENCY_FAILED', 'Suite fictive']], sent]);
});

test('session fermée, trop de demandes ou autre compte : rien n’est conclu, tout reste en attente', async () => {
    const api = core();
    const backend = server();
    const { outbox } = device(api, backend);
    await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task({ title: 'Première tâche fictive' }) });
    await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task({ title: 'Seconde tâche fictive' }) });
    // Brouillon d'un autre compte resté dans le stockage : jamais listé, jamais envoyé avec ce compte.
    await outbox.draft({ accountKey: OTHER, type: 'task.create', fields: task({ title: 'Tâche fictive d’un autre compte' }) });

    // Jeton périmé (419) : renouvelé une fois, puis la même opération repart et aboutit.
    backend.state.failures.push(419);
    assert.deepEqual([(await outbox.synchronize(ACCOUNT)).synced, backend.state.refreshes, backend.state.created], [2, 1, 2]);
    assert.equal(backend.state.requests[0].operation.operation_id, backend.state.requests[1].operation.operation_id);

    for (const [failures, stopped] of [[[401, 401], 'session'], [[302, 302], 'session'], [[429], 'rate'], [[403, 403], 'session'], [['account'], 'account']]) {
        await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task({ title: 'Tâche fictive ' + stopped + failures[0] }) });
        await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task({ title: 'Tâche fictive suivante ' + failures[0] }) });
        backend.state.failures.push(...failures);
        const before = backend.state.created;
        const summary = await outbox.synchronize(ACCOUNT);
        // L'envoi s'arrête à la première opération : la suivante n'est même pas tentée.
        assert.deepEqual([summary.stopped, summary.remaining, backend.state.created], [stopped, 2, before], String(failures));
        assert.deepEqual(plain(await outbox.unsynced(ACCOUNT)).map((operation) => operation.state), ['pending', 'local']);
        assert.equal((await outbox.synchronize(ACCOUNT)).remaining, 0);
    }
    assert.equal(backend.state.requests.every((request) => request.operation.account_key === ACCOUNT), true);
    assert.deepEqual(plain(await outbox.list(OTHER)).map((operation) => [operation.state, operation.attempts]), [['local', 0]]);
});

test('R46 — ce qui reste à décider avant une déconnexion ; les envois anciens sont retirés, jamais un brouillon', async () => {
    const api = core();
    const backend = server();
    const { outbox, advance } = device(api, backend);
    await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task({ title: 'Tâche fictive synchronisée' }) });
    await outbox.synchronize(ACCOUNT);
    backend.state.revoked = [COURSE];
    await outbox.draft({ accountKey: ACCOUNT, type: 'check_in.save', targetId: COURSE, baseVersion: 0, fields: { summary: 'Brouillon fictif refusé' } });
    await outbox.synchronize(ACCOUNT);
    backend.state.failures.push('network');
    await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task({ title: 'Tâche fictive en attente' }) });
    await outbox.synchronize(ACCOUNT);
    await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task({ title: 'Tâche fictive locale' }) });
    assert.deepEqual(plain(await outbox.list(ACCOUNT)).map((operation) => operation.state), ['synced', 'refused', 'pending', 'local']);
    // Avant de se déconnecter : trois brouillons ne sont pas sur le serveur.
    assert.deepEqual(plain(await outbox.unsynced(ACCOUNT)).map((operation) => operation.label), ['Brouillon', 'Tâche fictive en attente', 'Tâche fictive locale']);
    // Deux jours plus tard : l'envoi synchronisé est retiré de la liste, les brouillons sont tous là.
    advance(2 * 86_400_000);
    await outbox.prune(ACCOUNT);
    assert.deepEqual(plain(await outbox.list(ACCOUNT)).map((operation) => operation.state), ['refused', 'pending', 'local']);
    // Abandon explicite : le brouillon disparaît de l'appareil et ne part plus.
    const [refused] = await outbox.list(ACCOUNT);
    await outbox.discard(refused.id);
    assert.equal((await outbox.unsynced(ACCOUNT)).length, 2);
});

test('R56 — mise à jour : le schéma local évolue sans supprimer la file, et le service worker ne touche jamais au stockage privé', async () => {
    const api = core();
    const database = (names) => {
        const stores = [...names];
        const log = [];

        return { log, stores, objectStoreNames: { contains: (name) => stores.includes(name) }, createObjectStore: (name) => { stores.push(name); log.push('create:' + name); },
            deleteObjectStore: (name) => { log.push('delete:' + name); } };
    };
    // Appareil du lot 19 (version 1) : seule la file est ajoutée ; rien n'est recréé ni supprimé.
    const fromOne = database(['snapshot', 'consulted']);
    api.migrate(fromOne, 1);
    assert.deepEqual([fromOne.log, fromOne.stores], [['create:outbox'], ['snapshot', 'consulted', 'outbox']]);
    const fresh = database([]);
    api.migrate(fresh, 0);
    assert.deepEqual(fresh.log, ['create:snapshot', 'create:consulted', 'create:outbox']);
    const current = database(['snapshot', 'consulted', 'outbox']);
    api.migrate(current, 2);
    assert.deepEqual(current.log, []);

    // Repli : une page ancienne ouvre une base déjà migrée par une version plus récente — elle l'utilise telle quelle au lieu de la recréer.
    const opened = [];
    const newer = { objectStoreNames: { contains: (name) => name === 'outbox' }, close: () => opened.push('close') };
    const indexedDB = { open: (name, version) => {
        const request = {};
        opened.push(version === undefined ? 'open:current' : 'open:' + version);
        queueMicrotask(() => {
            if (version !== undefined) { request.error = { name: 'VersionError' }; request.onerror({ preventDefault: () => {} }); } else { request.result = newer; request.onsuccess(); }
        });

        return request;
    }, deleteDatabase: () => { opened.push('delete'); } };
    assert.equal(await api.openDatabase(indexedDB), newer);
    assert.deepEqual(opened, ['open:2', 'open:current']);

    // Une opération écrite par une version plus récente de Campus est conservée telle quelle : ni envoyée, ni supprimée.
    const backend = server();
    const store = api.memoryStore([{ schema: 99, id: 'futur', accountKey: ACCOUNT, context: 'personal', type: 'task.create', subject: 'task-operation:futur', targetId: null, after: null, baseVersion: 0,
        payload: { titre_futur: 'Brouillon fictif d’une version future' }, label: 'Brouillon futur', fingerprint: null, state: 'local', attempts: 0, sequence: 1, createdAt: '2026-11-16T08:00:00.000Z', updatedAt: '2026-11-16T08:00:00.000Z', ack: null }]);
    const { outbox } = device(api, backend, store);
    await outbox.draft({ accountKey: ACCOUNT, type: 'task.create', fields: task() });
    const summary = await outbox.synchronize(ACCOUNT);
    const queue = plain(await outbox.list(ACCOUNT));
    assert.deepEqual([summary.synced, backend.state.requests.length, queue.map((operation) => [operation.id === 'futur', operation.state, api.supported(operation)])], [1, 1, [[true, 'local', false], [false, 'synced', true]]]);
    assert.equal(queue[0].payload.titre_futur, 'Brouillon fictif d’une version future');

    // Le service worker ne connaît que les caches publics : sa mise à jour ne peut pas effacer une opération en attente.
    const worker = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
    assert.doesNotMatch(worker, /indexedDB|deleteDatabase|localStorage|campus-private/);
    assert.match(worker, /'\/offline-core\.js'/);
    // Aucune dépendance à la synchronisation en arrière-plan du navigateur.
    assert.doesNotMatch(worker + source, /SyncManager|registration\.sync|addEventListener\('sync'/);
});
