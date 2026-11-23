// Cœur du stockage hors connexion de HAKO-RA Campus : base locale, file des brouillons et synchronisation.
//
// Ce fichier est public et ne contient aucune donnée. Il est chargé à la fois par l'application et par la page hors ligne,
// pour qu'une seule mise en œuvre décide de ce qui est écrit, envoyé, rejoué ou gardé en conflit.
//
// Règles :
//  - seuls des brouillons personnels existent ici (tâche personnelle, retour privé après cours) ; rien d'institutionnel ;
//  - chaque opération porte un identifiant unique, le compte, le contexte, le type, l'empreinte du contenu et la version connue ;
//  - une opération déjà tentée n'est plus jamais modifiée : la renvoyer est sans effet côté serveur ;
//  - un conflit ou un refus garde le brouillon sur l'appareil jusqu'à la décision de son propriétaire ;
//  - aucune synchronisation en arrière-plan n'est requise : l'envoi se fait à l'ouverture, au retour du réseau, ou sur demande.
(function (root) {
    'use strict';

    const DATABASE = 'campus-private';
    const DB_VERSION = 2;
    const OPERATION_SCHEMA = 1;
    const ENDPOINT = '/synchronisation/v1/operations';
    const REFRESH_URL = '/hors-ligne/instantane';
    const KEEP_SYNCED_MS = 86_400_000;
    const OPEN_STATES = ['local', 'pending'];
    const STATE_LABELS = {
        local: 'Enregistré sur cet appareil',
        pending: 'En attente d’envoi',
        synced: 'Synchronisé',
        refused: 'Refusé',
        conflict: 'Conflit',
    };
    const FIELDS = {
        'task.create': ['title', 'description', 'due_at', 'priority', 'personal_subject_id', 'source_occurrence_id'],
        'task.update': ['title', 'description', 'due_at', 'priority', 'personal_subject_id', 'status'],
        'check_in.save': ['summary', 'work_to_do', 'points_to_review', 'understanding'],
    };
    const FIELD_LABELS = {
        title: 'Titre', description: 'Description', due_at: 'Échéance', priority: 'Priorité', personal_subject_id: 'Matière', status: 'État',
        summary: 'Résumé personnel', work_to_do: 'Travail à effectuer', points_to_review: 'Points à revoir', understanding: 'Niveau de compréhension',
    };

    /** Forme canonique : clés triées, aucune espace. Le serveur calcule la même (App\Support\OperationFingerprint). */
    function canonical(value) {
        if (value === null || value === undefined) return 'null';
        if (typeof value === 'boolean') return value ? 'true' : 'false';
        if (typeof value === 'number') {
            if (!Number.isInteger(value)) throw new Error('Nombre non pris en charge.');

            return String(value);
        }
        if (typeof value === 'string') return JSON.stringify(value);
        if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
        if (typeof value === 'object') {
            return '{' + Object.keys(value).sort().map((key) => JSON.stringify(key) + ':' + canonical(value[key])).join(',') + '}';
        }
        throw new Error('Valeur non prise en charge.');
    }

    async function fingerprint(operation, cryptoApi) {
        const text = canonical({ type: operation.type, target_id: operation.targetId || null, base_version: operation.baseVersion, payload: operation.payload });
        const digest = await cryptoApi.subtle.digest('SHA-256', new TextEncoder().encode(text));

        return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function text(value) {
        const trimmed = typeof value === 'string' ? value.trim() : '';

        return trimmed === '' ? null : trimmed;
    }

    function whole(value) {
        const number = typeof value === 'number' ? value : (typeof value === 'string' && value.trim() !== '' ? Number(value) : NaN);

        return Number.isInteger(number) ? number : null;
    }

    /** Contenu envoyé : exactement les champs attendus pour ce type, nettoyés. Tout autre champ est ignoré. */
    function normalize(type, fields) {
        const source = fields || {};
        if (type === 'check_in.save') {
            return { summary: text(source.summary), work_to_do: text(source.work_to_do), points_to_review: text(source.points_to_review), understanding: whole(source.understanding) };
        }
        const task = { title: text(source.title) || '', description: text(source.description), due_at: text(source.due_at), priority: text(source.priority) || 'normal', personal_subject_id: whole(source.personal_subject_id) };
        if (type === 'task.create') return Object.assign(task, { source_occurrence_id: text(source.source_occurrence_id) });
        if (type === 'task.update') return Object.assign(task, { status: text(source.status) || 'todo' });
        throw new Error('Type d’opération inconnu.');
    }

    /** Erreurs visibles avant tout envoi, pour ne pas mettre en file un brouillon que le serveur refusera à coup sûr. */
    function problems(type, payload) {
        const found = [];
        if (type === 'check_in.save') {
            if (FIELDS[type].every((field) => payload[field] === null)) found.push('Renseignez au moins un champ.');
            ['summary', 'work_to_do', 'points_to_review'].forEach((field) => { if (payload[field] && payload[field].length > 2000) found.push(FIELD_LABELS[field] + ' : 2 000 caractères au plus.'); });
            if (payload.understanding !== null && (payload.understanding < 1 || payload.understanding > 4)) found.push('Niveau de compréhension invalide.');

            return found;
        }
        if (!payload.title) found.push('Le titre est obligatoire.');
        if (payload.title.length > 255) found.push('Titre : 255 caractères au plus.');
        if (payload.description && payload.description.length > 5000) found.push('Description : 5 000 caractères au plus.');
        if (payload.due_at && !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(payload.due_at)) found.push('Échéance invalide.');

        return found;
    }

    /** Différences utiles entre le brouillon et la version actuelle du serveur. */
    function differences(operation) {
        const current = operation.ack && operation.ack.current;
        if (!current) return [];

        return FIELDS[operation.type].filter((field) => field in current && (operation.payload[field] === undefined ? null : operation.payload[field]) !== current[field])
            .map((field) => ({ field, label: FIELD_LABELS[field] || field, mine: operation.payload[field] === undefined ? null : operation.payload[field], theirs: current[field] }));
    }

    /** Création et évolution du schéma local. Aucune étape ne supprime un magasin : les brouillons en attente survivent à une mise à jour. */
    function migrate(database, oldVersion) {
        if (oldVersion < 1) {
            database.createObjectStore('snapshot');
            database.createObjectStore('consulted', { keyPath: 'id' });
        }
        if (oldVersion < 2 && !database.objectStoreNames.contains('outbox')) {
            database.createObjectStore('outbox', { keyPath: 'id' });
        }
    }

    function openDatabase(indexedDB) {
        return new Promise((resolve, reject) => {
            const ready = (database) => { database.onversionchange = () => database.close(); resolve(database); };
            const request = indexedDB.open(DATABASE, DB_VERSION);
            request.onupgradeneeded = (event) => migrate(request.result, event.oldVersion);
            request.onsuccess = () => ready(request.result);
            request.onerror = (event) => {
                if (!request.error || request.error.name !== 'VersionError') { reject(request.error || new Error('Stockage indisponible.')); return; }
                // Repli : une version plus récente de Campus a déjà fait évoluer la base (autre onglet à jour). On l'ouvre telle quelle
                // plutôt que de la recréer, pour ne rien perdre de ce qui attend d'être envoyé.
                if (event && event.preventDefault) event.preventDefault();
                const fallback = indexedDB.open(DATABASE);
                fallback.onsuccess = () => {
                    if (fallback.result.objectStoreNames.contains('outbox')) { ready(fallback.result); return; }
                    fallback.result.close();
                    reject(new Error('Stockage local d’une version inconnue.'));
                };
                fallback.onerror = () => reject(fallback.error || new Error('Stockage indisponible.'));
            };
        });
    }

    function transaction(indexedDB, stores, mode, work) {
        return openDatabase(indexedDB).then((database) => new Promise((resolve, reject) => {
            let result;
            const running = database.transaction(stores, mode);
            work(running, (value) => { result = value; });
            running.oncomplete = () => { database.close(); resolve(result); };
            running.onerror = running.onabort = () => { database.close(); reject(running.error || new Error('Écriture refusée.')); };
        }));
    }

    /** File des opérations dans IndexedDB. */
    function indexedStore(indexedDB) {
        return {
            all: () => transaction(indexedDB, 'outbox', 'readonly', (running, done) => { running.objectStore('outbox').getAll().onsuccess = (event) => done(event.target.result || []); }),
            put: (operation) => transaction(indexedDB, 'outbox', 'readwrite', (running) => { running.objectStore('outbox').put(operation); }),
            remove: (id) => transaction(indexedDB, 'outbox', 'readwrite', (running) => { running.objectStore('outbox').delete(id); }),
        };
    }

    /** File en mémoire, pour les tests automatiques. */
    function memoryStore(initial) {
        const rows = new Map((initial || []).map((operation) => [operation.id, JSON.parse(JSON.stringify(operation))]));

        return {
            all: async () => Array.from(rows.values()).map((operation) => JSON.parse(JSON.stringify(operation))),
            put: async (operation) => { rows.set(operation.id, JSON.parse(JSON.stringify(operation))); },
            remove: async (id) => { rows.delete(id); },
        };
    }

    /** Efface les copies de lecture (planning, tâches, notes, opportunités) sans toucher aux brouillons en attente. */
    function clearReadCopies(indexedDB) {
        return transaction(indexedDB, ['snapshot', 'consulted'], 'readwrite', (running) => { running.objectStore('snapshot').clear(); running.objectStore('consulted').clear(); });
    }

    function deleteEverything(indexedDB) {
        return new Promise((resolve) => {
            const request = indexedDB.deleteDatabase(DATABASE);
            request.onsuccess = request.onerror = request.onblocked = () => resolve();
        });
    }

    function ordered(operations) {
        return operations.slice().sort((a, b) => (a.sequence - b.sequence) || String(a.id).localeCompare(String(b.id)));
    }

    function supported(operation) {
        return (operation.schema || 1) <= OPERATION_SCHEMA;
    }

    /**
     * File des brouillons d'un appareil. `environment` fournit le stockage, le réseau et l'horloge :
     * { store, fetch, crypto, now(), uuid(), csrf(), onChange() }.
     */
    function createOutbox(environment) {
        const store = environment.store;
        const changed = () => { if (environment.onChange) environment.onChange(); };
        let running = null;

        async function list(accountKey) {
            return ordered((await store.all()).filter((operation) => operation.accountKey === accountKey));
        }

        async function unsynced(accountKey) {
            return (await list(accountKey)).filter((operation) => operation.state !== 'synced');
        }

        async function nextSequence() {
            return (await store.all()).reduce((highest, operation) => Math.max(highest, operation.sequence || 0), 0) + 1;
        }

        /**
         * Écrit ou remplace un brouillon. `input` : { accountKey, type, targetId, targetOperation, baseVersion, fields, label, id }.
         * Un brouillon jamais envoyé pour le même élément est remplacé ; un brouillon déjà tenté n'est jamais modifié :
         * la nouvelle saisie devient une opération suivante, envoyée après lui à partir de la version qu'il aura produite.
         */
        async function draft(input) {
            const payload = normalize(input.type, input.fields);
            const errors = problems(input.type, payload);
            if (errors.length > 0) return { operation: null, errors };
            const all = await list(input.accountKey);
            const id = input.id || environment.uuid();
            const origin = input.targetOperation ? all.find((operation) => operation.id === input.targetOperation) : null;
            const resolvedTarget = origin && origin.state === 'synced' && origin.ack && origin.ack.result ? origin.ack.result.id : (input.targetId || null);
            const subject = input.type === 'check_in.save' ? 'check-in:' + resolvedTarget
                : resolvedTarget ? 'task:' + resolvedTarget : origin ? origin.subject : 'task-operation:' + id;
            const open = all.filter((operation) => operation.subject === subject && OPEN_STATES.includes(operation.state));
            const last = open[open.length - 1] || null;
            const now = environment.now();
            if (last && last.state === 'local' && last.attempts === 0) {
                // Même élément, rien n'est encore parti : la saisie remplace le brouillon. Une création garde son type, l'état d'une tâche pas encore créée ne s'envoie pas.
                const merged = last.type === input.type ? payload : last.type === 'task.create' && input.type === 'task.update' && payload.status === 'todo'
                    ? normalize('task.create', Object.assign({}, payload, { source_occurrence_id: last.payload.source_occurrence_id })) : null;
                if (merged) {
                    Object.assign(last, { payload: merged, label: text(input.label) || last.label, updatedAt: now });
                    await store.put(last);
                    changed();

                    return { operation: last, errors: [] };
                }
            }
            const follows = last || (origin && origin.state !== 'synced' ? origin : null);
            const operation = {
                schema: OPERATION_SCHEMA, id, accountKey: input.accountKey, context: 'personal', type: input.type, subject,
                targetId: resolvedTarget, after: follows ? follows.id : null,
                baseVersion: origin && origin.state === 'synced' && origin.ack && origin.ack.result ? origin.ack.result.version : (whole(input.baseVersion) || 0),
                payload, label: text(input.label) || payload.title || 'Brouillon', fingerprint: null, state: 'local', attempts: 0,
                sequence: await nextSequence(), createdAt: now, updatedAt: now, lastTriedAt: null, ack: null,
            };
            await store.put(operation);
            changed();

            return { operation, errors: [] };
        }

        /** Abandon explicite d'un brouillon par son propriétaire. */
        async function discard(id) {
            await store.remove(id);
            changed();
        }

        /**
         * Décision du propriétaire sur un conflit. « mine » : son brouillon est renvoyé comme une nouvelle opération, à partir de la version
         * du serveur qu'il vient de voir. « server » : il garde la version du serveur et abandonne son brouillon. Jamais de choix automatique.
         */
        async function resolve(id, choice) {
            const operation = (await store.all()).find((candidate) => candidate.id === id);
            if (!operation || operation.state !== 'conflict') return null;
            if (choice === 'server') { await discard(id); return null; }
            const current = operation.ack && operation.ack.current;
            if (choice !== 'mine' || operation.type === 'task.create' || (!current && operation.type !== 'check_in.save')) return null;
            const now = environment.now();
            const next = Object.assign({}, operation, { id: environment.uuid(), baseVersion: current ? current.version : 0, after: null, fingerprint: null, state: 'local', attempts: 0,
                sequence: await nextSequence(), createdAt: now, updatedAt: now, lastTriedAt: null, ack: null, replaces: operation.id });
            await store.put(next);
            await store.remove(id);
            changed();

            return next;
        }

        /** Nouvelle tentative demandée par le propriétaire après un refus : même contenu, nouvelle opération. */
        async function retry(id) {
            const operation = (await store.all()).find((candidate) => candidate.id === id);
            if (!operation || operation.state !== 'refused') return null;
            const now = environment.now();
            const next = Object.assign({}, operation, { id: environment.uuid(), fingerprint: null, state: 'local', attempts: 0, after: null,
                sequence: await nextSequence(), createdAt: now, updatedAt: now, lastTriedAt: null, ack: null, replaces: operation.id });
            if (next.subject === 'task-operation:' + operation.id) next.subject = 'task-operation:' + next.id;
            await store.put(next);
            await store.remove(id);
            changed();

            return next;
        }

        function envelope(operation) {
            return { operation_id: operation.id, account_key: operation.accountKey, context: operation.context, type: operation.type, target_id: operation.targetId || null,
                base_version: operation.baseVersion, fingerprint: operation.fingerprint, payload: operation.payload };
        }

        function post(operation) {
            const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            const token = environment.csrf ? environment.csrf() : null;
            if (token) headers['X-XSRF-TOKEN'] = token;

            return environment.fetch(ENDPOINT, { method: 'POST', headers, body: JSON.stringify(envelope(operation)), credentials: 'same-origin', cache: 'no-store' });
        }

        /** Lit la réponse : résultat définitif (« settled ») ou raison de s'arrêter sans rien conclure (« stop »). */
        async function read(response) {
            const json = (response.headers.get('Content-Type') || '').includes('application/json');
            if (response.redirected || response.status === 401 || response.status === 419) return { stop: 'session' };
            if (response.status === 429) return { stop: 'rate' };
            if (!json || ![200, 403, 409, 413, 422].includes(response.status)) return { stop: response.status >= 500 ? 'server' : 'session' };
            const body = await response.json();
            // Refus global (brouillon d'un autre compte, compte non vérifié…) : ce n'est pas un jugement sur ce brouillon, rien n'est conclu.
            if (response.status === 403) return { stop: body.code === 'ACCOUNT_MISMATCH' ? 'account' : 'session' };
            if (response.status === 413) return { settled: 'refused', body: Object.assign({}, body, { message: 'Ce brouillon est trop volumineux pour être envoyé.' }) };
            const outcome = body.outcome || (response.status === 200 ? 'applied' : response.status === 409 ? 'conflict' : 'rejected');

            return { settled: outcome === 'applied' ? 'synced' : outcome === 'conflict' ? 'conflict' : 'refused', body };
        }

        async function run(accountKey) {
            const summary = { sent: 0, synced: 0, conflicts: 0, refused: 0, remaining: 0, stopped: null };
            let refreshed = false;
            for (const queued of (await list(accountKey)).filter((operation) => OPEN_STATES.includes(operation.state))) {
                // Relu à chaque tour : une opération précédente vient peut-être d'aboutir.
                const all = await store.all();
                const operation = all.find((candidate) => candidate.id === queued.id);
                if (!operation || !OPEN_STATES.includes(operation.state) || !supported(operation)) continue;
                if (operation.after && operation.attempts === 0) {
                    const previous = all.find((candidate) => candidate.id === operation.after);
                    if (previous && OPEN_STATES.includes(previous.state)) continue;
                    if (!previous || previous.state !== 'synced' || !previous.ack || !previous.ack.result) {
                        // L'envoi dont celui-ci dépend n'a pas abouti : le brouillon est gardé, pas envoyé sur une base inconnue.
                        Object.assign(operation, { state: 'refused', updatedAt: environment.now(), ack: { code: 'DEPENDENCY_FAILED', at: environment.now(), result: null, current: null, errors: {},
                            message: 'L’envoi précédent pour le même élément n’a pas abouti. Ce brouillon reste sur cet appareil.' } });
                        await store.put(operation);
                        summary.refused++;
                        continue;
                    }
                    if (operation.type !== 'check_in.save') {
                        operation.targetId = previous.ack.result.id;
                        operation.subject = 'task:' + operation.targetId;
                        if (operation.type === 'task.create') operation.type = 'task.update';
                    }
                    operation.baseVersion = previous.ack.result.version;
                }
                if (operation.type !== 'task.create' && !operation.targetId) continue;
                // À partir d'ici l'opération est figée : son empreinte est calculée une fois, et elle sera renvoyée à l'identique.
                if (!operation.fingerprint) operation.fingerprint = await fingerprint(operation, environment.crypto);
                Object.assign(operation, { state: 'pending', attempts: operation.attempts + 1, lastTriedAt: environment.now() });
                await store.put(operation);
                let outcome;
                try {
                    outcome = await read(await post(operation));
                    if (outcome.stop === 'session' && !refreshed) {
                        // Jeton de formulaire peut-être périmé : il est renouvelé une fois, puis la même opération est renvoyée.
                        refreshed = true;
                        await environment.fetch(REFRESH_URL, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                        outcome = await read(await post(operation));
                    }
                } catch (error) {
                    outcome = { stop: 'network' };
                }
                summary.sent++;
                if (outcome.stop) { summary.stopped = outcome.stop; break; }
                const body = outcome.body || {};
                Object.assign(operation, { state: outcome.settled, updatedAt: environment.now(), ack: { code: body.code || null, message: body.message || null, result: body.result || null,
                    current: body.current || null, errors: body.errors || {}, replayed: body.replayed === true, at: environment.now() } });
                await store.put(operation);
                summary[outcome.settled === 'synced' ? 'synced' : outcome.settled === 'conflict' ? 'conflicts' : 'refused']++;
            }
            await prune(accountKey);
            summary.remaining = (await list(accountKey)).filter((operation) => OPEN_STATES.includes(operation.state)).length;
            changed();

            return summary;
        }

        /** Envoie ce qui attend, dans l'ordre d'écriture. Un seul passage à la fois ; un échec de réseau laisse tout en attente. */
        function synchronize(accountKey) {
            if (!running) running = run(accountKey).finally(() => { running = null; });

            return running;
        }

        /** Retire les opérations synchronisées depuis plus d'un jour dont plus rien ne dépend. Jamais un brouillon non synchronisé. */
        async function prune(accountKey) {
            const all = await list(accountKey);
            const needed = new Set(all.filter((operation) => operation.state !== 'synced' && operation.after).map((operation) => operation.after));
            const limit = Date.parse(environment.now()) - KEEP_SYNCED_MS;
            for (const operation of all) {
                if (operation.state === 'synced' && !needed.has(operation.id) && Date.parse((operation.ack && operation.ack.at) || operation.updatedAt) < limit) await store.remove(operation.id);
            }
        }

        return { list, unsynced, draft, discard, resolve, retry, synchronize, prune };
    }

    function cookie(name) {
        const match = ('; ' + root.document.cookie).split('; ' + name + '=');

        return match.length === 2 ? decodeURIComponent(match[1].split(';')[0]) : null;
    }

    function uuid() {
        if (root.crypto.randomUUID) return root.crypto.randomUUID();
        const bytes = root.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes).map((byte) => byte.toString(16).padStart(2, '0')).join('');

        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    const listeners = new Set();
    let shared = null;

    /** File de ce navigateur. Indisponible (null) si le navigateur ne fournit pas le stockage ou le calcul d'empreinte. */
    function browserOutbox() {
        if (shared) return shared;
        if (!root.indexedDB || !root.crypto || !root.crypto.subtle || !root.fetch) return null;
        shared = createOutbox({
            store: indexedStore(root.indexedDB), fetch: (url, options) => root.fetch(url, options), crypto: root.crypto, uuid,
            now: () => new Date().toISOString(), csrf: () => cookie('XSRF-TOKEN'), onChange: () => listeners.forEach((listener) => listener()),
        });

        return shared;
    }

    function subscribe(listener) {
        listeners.add(listener);

        return () => listeners.delete(listener);
    }

    root.CampusOffline = {
        DATABASE, DB_VERSION, OPERATION_SCHEMA, ENDPOINT, STATE_LABELS, FIELD_LABELS, FIELDS,
        canonical, fingerprint, normalize, problems, differences, migrate, openDatabase, indexedStore, memoryStore, clearReadCopies, deleteEverything,
        createOutbox, browserOutbox, subscribe, supported,
    };
})(typeof self !== 'undefined' ? self : globalThis);
