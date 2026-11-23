import { accepts, decide, initialState, synced, type DeviceState } from './policy';

// Stockage privé de lecture hors connexion. Tout vit dans IndexedDB et localStorage, sous des noms réservés ;
// rien de privé n'est jamais confié au cache du service worker.

const STATE_KEY = 'campus.offline.state';
const DATABASE = 'campus-private';
const MAX_CONSULTED = 20;

export type Consulted = { id: string; title: string; organizer: string; deadline: string | null; at: string };

export function readState(): DeviceState {
    try {
        const parsed = JSON.parse(window.localStorage.getItem(STATE_KEY) ?? 'null') as DeviceState | null;

        return parsed && (parsed.mode === 'personal' || parsed.mode === 'shared') ? parsed : initialState();
    } catch {
        return initialState();
    }
}

function writeState(state: DeviceState): void {
    try { window.localStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch { /* stockage refusé : l'appareil reste « partagé » */ }
}

/** Ouverture de la base par le cœur commun (public/offline-core.js), seul à connaître le schéma et ses évolutions. */
function open(): Promise<IDBDatabase> {
    const core = window.CampusOffline;

    return core ? core.openDatabase(window.indexedDB) : Promise.reject(new Error('Stockage indisponible.'));
}

function done(transaction: IDBTransaction): Promise<void> {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onerror = transaction.onabort = () => reject(transaction.error ?? new Error('Écriture refusée.'));
    });
}

/**
 * Efface tout ce que Campus a pu conserver de privé sur cet appareil : base IndexedDB, réglages locaux, caches qui ne sont pas
 * la coque publique, et l'abonnement aux notifications du navigateur de cet appareil. Appelé à la déconnexion, au changement de compte,
 * à l'expiration, et quand la personne repasse en « appareil partagé ».
 */
export async function purgeAll(options: { unsubscribePush?: boolean } = {}): Promise<void> {
    try {
        Object.keys(window.localStorage).filter((key) => key.startsWith('campus.offline.')).forEach((key) => window.localStorage.removeItem(key));
    } catch { /* rien à effacer */ }
    if ('indexedDB' in window) {
        await new Promise<void>((resolve) => {
            const request = window.indexedDB.deleteDatabase(DATABASE);
            request.onsuccess = request.onerror = request.onblocked = () => resolve();
        });
    }
    if ('caches' in window) {
        const names = await window.caches.keys();
        await Promise.all(names.filter((name) => !name.startsWith('campus-shell-') && !name.startsWith('campus-static-')).map((name) => window.caches.delete(name)));
    }
    if (options.unsubscribePush && 'serviceWorker' in navigator) {
        try {
            const registration = await navigator.serviceWorker.getRegistration();
            const subscription = await registration?.pushManager?.getSubscription();
            await subscription?.unsubscribe();
        } catch { /* aucun abonnement local */ }
    }
}

/**
 * Efface les copies de lecture trop anciennes sans toucher aux brouillons : ce que la personne a écrit et qui n'est pas encore
 * sur le serveur n'est jamais supprimé sans sa décision. L'appareil reste personnel, sans date de synchronisation.
 */
async function purgeReadCopies(): Promise<void> {
    const state = readState();
    try { await window.CampusOffline?.clearReadCopies(window.indexedDB); } catch { /* base absente : rien à effacer */ }
    writeState({ ...state, lastSyncAt: null });
}

async function hasDrafts(accountKey: string | null): Promise<boolean> {
    if (accountKey === null) return false;
    try {
        return ((await window.CampusOffline?.browserOutbox()?.unsynced(accountKey)) ?? []).length > 0;
    } catch {
        return false;
    }
}

/** Choix explicite de la personne : cet appareil est personnel, pour ce compte. */
export function choosePersonal(accountKey: string, ttlDays: number): void {
    writeState({ mode: 'personal', accountKey, lastSyncAt: null, ttlDays });
}

/** Demande l'instantané au serveur et l'enregistre, seulement s'il appartient au compte qui a fait le choix. Renvoie la date de synchronisation. */
export async function synchronize(): Promise<string | null> {
    const state = readState();
    if (state.mode !== 'personal' || state.accountKey === null) return null;
    const response = await fetch('/hors-ligne/instantane', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
    // Session fermée ou compte suspendu : le serveur renvoie vers la connexion, pas un instantané. Rien n'est enregistré.
    if (!response.ok || response.redirected || !(response.headers.get('Content-Type') ?? '').includes('application/json')) return null;
    const snapshot = await response.json() as { accountKey?: unknown; generatedAt: string; ttlDays: number };
    if (!accepts(state, snapshot.accountKey)) {
        await purgeAll();

        return null;
    }
    const database = await open();
    const transaction = database.transaction('snapshot', 'readwrite');
    transaction.objectStore('snapshot').put(snapshot, 'current');
    await done(transaction);
    database.close();
    writeState(synced(state.accountKey, snapshot.generatedAt, snapshot.ttlDays));

    return snapshot.generatedAt;
}

/** Retient une fiche du catalogue public consultée, sur appareil personnel seulement, pour la relire hors connexion. */
export async function rememberConsulted(accountKey: string | null, item: Omit<Consulted, 'at'>): Promise<void> {
    const state = readState();
    if (state.mode !== 'personal' || accountKey === null || state.accountKey !== accountKey || !('indexedDB' in window)) return;
    try {
        const database = await open();
        const transaction = database.transaction('consulted', 'readwrite');
        const store = transaction.objectStore('consulted');
        store.put({ ...item, at: new Date().toISOString() });
        const all = store.getAll();
        all.onsuccess = () => {
            (all.result as Consulted[]).sort((a, b) => b.at.localeCompare(a.at)).slice(MAX_CONSULTED).forEach((old) => store.delete(old.id));
        };
        await done(transaction);
        database.close();
    } catch { /* lecture hors connexion indisponible : sans conséquence pour la navigation */ }
}

/**
 * À chaque ouverture de l'application : applique les règles de conservation, puis resynchronise si l'appareil est personnel,
 * que le même compte est connecté et que le réseau répond. Les droits sont ainsi réévalués par le serveur à chaque retour en ligne.
 */
export async function reconcile(currentAccountKey: string | null): Promise<DeviceState> {
    if (!('indexedDB' in window)) return initialState();
    const stored = readState();
    const decision = decide(stored, currentAccountKey, Date.now(), navigator.onLine);
    if (decision.purge && decision.reason === 'expired' && await hasDrafts(stored.accountKey)) {
        // Copie de lecture périmée, mais des brouillons attendent : seule la copie est effacée.
        await purgeReadCopies();
        if (currentAccountKey !== null && navigator.onLine) {
            try { await synchronize(); } catch { /* réseau instable */ }
        }
    } else if (decision.purge) {
        // Changement de compte, appareil partagé ou copie périmée sans brouillon : tout est effacé, brouillons du compte précédent compris.
        await purgeAll({ unsubscribePush: decision.reason === 'account-changed' });
    } else if (decision.sync) {
        try { await synchronize(); } catch { /* réseau instable : l'instantané précédent reste, avec sa date */ }
    }

    return readState();
}
