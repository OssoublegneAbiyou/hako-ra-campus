// Règles de conservation des données privées sur l'appareil. Fonctions pures, sans navigateur ni réseau : elles sont testées telles quelles.
//
// Par défaut l'appareil est « partagé » : rien de privé n'y est conservé. Le stockage n'existe qu'après le choix « appareil personnel »,
// fait par un compte donné ; il ne survit ni à un changement de compte, ni à une déconnexion, ni à une trop longue période sans contrôle des droits.

export type DeviceState = { mode: 'shared' | 'personal'; accountKey: string | null; lastSyncAt: string | null; ttlDays: number };

export type Decision = { purge: boolean; sync: boolean; reason: 'shared-device' | 'account-changed' | 'expired' | 'up-to-date' | 'offline' | 'signed-out' };

export const DEFAULT_TTL_DAYS = 7;

export function initialState(): DeviceState {
    return { mode: 'shared', accountKey: null, lastSyncAt: null, ttlDays: DEFAULT_TTL_DAYS };
}

/** L'instantané est-il trop ancien pour être encore affiché sans nouveau contrôle des droits ? */
export function expired(lastSyncAt: string | null, now: number, ttlDays: number): boolean {
    if (lastSyncAt === null) return true;
    const synced = Date.parse(lastSyncAt);

    return Number.isNaN(synced) || now - synced > Math.max(1, ttlDays) * 86_400_000;
}

/**
 * À l'ouverture de l'application : faut-il effacer ce qui est stocké, et faut-il resynchroniser ?
 * `currentAccountKey` est le compte connecté maintenant (null si personne n'est connecté).
 */
export function decide(state: DeviceState | null, currentAccountKey: string | null, now: number, online: boolean): Decision {
    const stored = state ?? initialState();
    // Appareil partagé : rien ne doit rester, quoi qu'il arrive.
    if (stored.mode !== 'personal' || stored.accountKey === null) {
        return { purge: stored.accountKey !== null || stored.lastSyncAt !== null, sync: false, reason: 'shared-device' };
    }
    // Un autre compte s'est connecté sur cet appareil : les données du précédent sont effacées avant tout, et le choix « appareil personnel » ne se transmet pas.
    if (currentAccountKey !== null && currentAccountKey !== stored.accountKey) {
        return { purge: true, sync: false, reason: 'account-changed' };
    }
    // Trop longtemps sans vérification des droits : effacement à l'ouverture.
    if (stored.lastSyncAt !== null && expired(stored.lastSyncAt, now, stored.ttlDays)) {
        return { purge: true, sync: false, reason: 'expired' };
    }
    if (currentAccountKey === null) {
        return { purge: false, sync: false, reason: 'signed-out' };
    }

    return online ? { purge: false, sync: true, reason: 'up-to-date' } : { purge: false, sync: false, reason: 'offline' };
}

/** État après une synchronisation réussie de ce compte. */
export function synced(accountKey: string, generatedAt: string, ttlDays: number): DeviceState {
    return { mode: 'personal', accountKey, lastSyncAt: generatedAt, ttlDays: Math.max(1, ttlDays) };
}

/** La réponse du serveur appartient-elle bien au compte qui a fait le choix « appareil personnel » ? Sinon elle n'est pas enregistrée. */
export function accepts(state: DeviceState, snapshotAccountKey: unknown): boolean {
    return state.mode === 'personal' && typeof snapshotAccountKey === 'string' && snapshotAccountKey === state.accountKey;
}
