import { useEffect, useState } from 'react';
import { readState } from './store';

// Accès typé à la file des brouillons hors connexion. La mise en œuvre est unique et vit dans public/offline-core.js,
// chargé par l'application et par la page hors ligne : ce fichier ne fait que la rendre utilisable depuis les écrans.

export type OperationState = 'local' | 'pending' | 'synced' | 'refused' | 'conflict';
export type OperationType = 'task.create' | 'task.update' | 'check_in.save';
export type Acknowledgement = {
    code: string | null; message: string | null; result: { id: string; version: number; status: string } | null;
    current: Record<string, string | number | null> | null; errors: Record<string, string[]>; replayed?: boolean; at: string;
};
export type Operation = {
    schema: number; id: string; accountKey: string; context: 'personal'; type: OperationType; subject: string; targetId: string | null; after: string | null; baseVersion: number;
    payload: Record<string, string | number | null>; label: string; state: OperationState; attempts: number; createdAt: string; updatedAt: string; lastTriedAt: string | null; ack: Acknowledgement | null;
};
export type Difference = { field: string; label: string; mine: string | number | null; theirs: string | number | null };
export type DraftInput = { accountKey: string; type: OperationType; targetId?: string | null; targetOperation?: string | null; baseVersion?: number; fields: Record<string, unknown>; label?: string; id?: string };
export type SyncSummary = { sent: number; synced: number; conflicts: number; refused: number; remaining: number; stopped: 'network' | 'session' | 'rate' | 'server' | 'account' | null };

type Outbox = {
    list: (accountKey: string) => Promise<Operation[]>;
    unsynced: (accountKey: string) => Promise<Operation[]>;
    draft: (input: DraftInput) => Promise<{ operation: Operation | null; errors: string[] }>;
    discard: (id: string) => Promise<void>;
    resolve: (id: string, choice: 'mine' | 'server') => Promise<Operation | null>;
    retry: (id: string) => Promise<Operation | null>;
    synchronize: (accountKey: string) => Promise<SyncSummary>;
};
type Core = {
    STATE_LABELS: Record<OperationState, string>;
    differences: (operation: Operation) => Difference[];
    supported: (operation: Operation) => boolean;
    openDatabase: (indexedDB: IDBFactory) => Promise<IDBDatabase>;
    clearReadCopies: (indexedDB: IDBFactory) => Promise<void>;
    browserOutbox: () => Outbox | null;
    subscribe: (listener: () => void) => () => void;
};

declare global {
    interface Window { CampusOffline?: Core }
}

export function core(): Core | null {
    return typeof window === 'undefined' ? null : window.CampusOffline ?? null;
}

function outbox(): Outbox | null {
    return core()?.browserOutbox() ?? null;
}

export const STATE_LABELS: Record<OperationState, string> = {
    local: 'Enregistré sur cet appareil', pending: 'En attente d’envoi', synced: 'Synchronisé', refused: 'Refusé', conflict: 'Conflit',
};

/** Un brouillon ne peut être écrit que sur un appareil déclaré personnel par ce compte : rien n'est stocké sur un appareil partagé. */
export function canDraft(accountKey: string | null): accountKey is string {
    const state = readState();

    return accountKey !== null && state.mode === 'personal' && state.accountKey === accountKey && outbox() !== null;
}

export async function saveDraft(input: DraftInput): Promise<{ operation: Operation | null; errors: string[] }> {
    const queue = outbox();
    if (!queue || !canDraft(input.accountKey)) return { operation: null, errors: ['Cet appareil ne conserve pas de brouillon. Réessayez quand le réseau sera revenu.'] };
    try {
        return await queue.draft(input);
    } catch {
        return { operation: null, errors: ['Ce navigateur a refusé l’enregistrement du brouillon.'] };
    }
}

export async function listOperations(accountKey: string | null): Promise<Operation[]> {
    const queue = outbox();
    if (!queue || accountKey === null) return [];
    try { return await queue.list(accountKey); } catch { return []; }
}

export async function unsyncedOperations(accountKey: string | null): Promise<Operation[]> {
    return (await listOperations(accountKey)).filter((operation) => operation.state !== 'synced');
}

/** Envoie ce qui attend. Sans effet hors connexion, sans compte, ou si la file est vide ; ne dépend d'aucune synchronisation en arrière-plan. */
export async function synchronizeDrafts(accountKey: string | null): Promise<SyncSummary | null> {
    const queue = outbox();
    if (!queue || accountKey === null || !navigator.onLine) return null;
    try {
        if (!(await queue.list(accountKey)).some((operation) => operation.state === 'local' || operation.state === 'pending')) return null;

        return await queue.synchronize(accountKey);
    } catch {
        return null;
    }
}

export async function discardDraft(id: string): Promise<void> {
    await outbox()?.discard(id);
}

export async function resolveConflict(id: string, choice: 'mine' | 'server'): Promise<void> {
    await outbox()?.resolve(id, choice);
}

export async function retryDraft(id: string): Promise<void> {
    await outbox()?.retry(id);
}

export function differences(operation: Operation): Difference[] {
    return core()?.differences(operation) ?? [];
}

export function isSupported(operation: Operation): boolean {
    return core()?.supported(operation) ?? true;
}

export function subscribeOutbox(listener: () => void): () => void {
    return core()?.subscribe(listener) ?? (() => undefined);
}

/** Opérations de ce compte sur cet appareil, tenues à jour à chaque écriture et à chaque retour sur l'onglet. */
export function useOutbox(accountKey: string | null): Operation[] {
    const [operations, setOperations] = useState<Operation[]>([]);
    useEffect(() => {
        let alive = true;
        const load = () => { void listOperations(accountKey).then((list) => { if (alive) setOperations(list); }); };
        load();
        const stop = subscribeOutbox(load);
        window.addEventListener('focus', load);

        return () => {
            alive = false;
            stop();
            window.removeEventListener('focus', load);
        };
    }, [accountKey]);

    return operations;
}

export function messageFor(summary: SyncSummary | null): string {
    if (summary === null) return navigator.onLine ? 'Rien à envoyer.' : 'Pas de réseau : vos brouillons restent sur cet appareil.';
    const parts = [];
    if (summary.synced > 0) parts.push(`${summary.synced} envoi(s) synchronisé(s)`);
    if (summary.conflicts > 0) parts.push(`${summary.conflicts} conflit(s) à examiner`);
    if (summary.refused > 0) parts.push(`${summary.refused} refus`);
    if (summary.remaining > 0) parts.push(`${summary.remaining} encore en attente`);
    const reason = { network: 'Le réseau a été interrompu.', session: 'Votre session doit être rouverte : reconnectez-vous.', rate: 'Trop de demandes : réessayez dans une minute.',
        server: 'Le serveur n’a pas répondu correctement.', account: 'Ces brouillons appartiennent à un autre compte.' };

    return [parts.length > 0 ? parts.join(', ') + '.' : 'Rien n’a pu être envoyé.', summary.stopped ? reason[summary.stopped] : ''].join(' ').trim();
}
