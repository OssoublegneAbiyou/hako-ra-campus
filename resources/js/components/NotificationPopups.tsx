import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { Icon } from './Icon';

type Popup = { id: number; title: string; body: string | null };

const EVERY_MS = 30_000;
const storageKey = (accountKey: string) => `campus.popups.${accountKey}`;
const remembered = (accountKey: string): number | null => {
    try { const value = sessionStorage.getItem(storageKey(accountKey)); return value === null ? null : Number(value); } catch { return null; }
};
const remember = (accountKey: string, id: number) => {
    try { sessionStorage.setItem(storageKey(accountKey), String(id)); } catch { /* stockage indisponible : la prochaine page repart des quinze dernières minutes */ }
};
// Les fenêtres encore ouvertes survivent au changement de page : la mise en page est recréée à chaque écran.
const openKey = (accountKey: string) => `campus.popups.open.${accountKey}`;
const stillOpen = (accountKey: string | null): Popup[] => {
    if (!accountKey) return [];
    try { return JSON.parse(sessionStorage.getItem(openKey(accountKey)) ?? '[]') as Popup[]; } catch { return []; }
};
const keepOpen = (accountKey: string | null, popups: Popup[]) => {
    if (!accountKey) return;
    try { sessionStorage.setItem(openKey(accountKey), JSON.stringify(popups)); } catch { /* stockage indisponible */ }
};

/**
 * Fenêtres surgissantes : toutes les trente secondes, tant que l'onglet est visible, Campus demande les rappels arrivés depuis la dernière fois
 * (fin d'un cours, tâche à venir…) et les affiche en bas de l'écran. Si l'onglet est caché et que la personne a autorisé les notifications du
 * navigateur, une notification système générique s'affiche aussi. Rien n'est marqué comme lu sans geste de la personne.
 */
export function NotificationPopups({ accountKey }: { accountKey: string | null }) {
    const [popups, setPopupsState] = useState<Popup[]>(() => stillOpen(accountKey));
    const setPopups = useCallback((update: (current: Popup[]) => Popup[]) => setPopupsState((current) => { const next = update(current); keepOpen(accountKey, next); return next; }), [accountKey]);
    useEffect(() => {
        if (!accountKey) return;
        let alive = true;
        const check = async (hidden: boolean) => {
            if (!navigator.onLine) return;
            const after = remembered(accountKey);
            try {
                const response = await fetch(`/notifications/recentes${after === null ? '' : `?apres=${after}`}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok || !alive) return;
                const data = await response.json() as { items: Popup[]; latest: number };
                remember(accountKey, Math.max(after ?? 0, data.latest));
                if (data.items.length === 0) return;
                setPopups((current) => [...current, ...data.items.filter((item) => !current.some((shown) => shown.id === item.id))].slice(-3));
                if (hidden && 'Notification' in window && Notification.permission === 'granted' && 'serviceWorker' in navigator) {
                    const registration = await navigator.serviceWorker.ready;
                    // Message générique, comme pour les notifications envoyées appareil fermé : rien de privé sur un écran verrouillé.
                    await registration.showNotification('HAKO-RA Campus', { body: data.items.length > 1 ? `${data.items.length} nouveaux rappels. Ouvrez Campus pour les lire.` : 'Nouveau rappel. Ouvrez Campus pour le lire.', tag: 'campus-notification' });
                }
            } catch { /* réseau indisponible : nouvel essai au prochain tour */ }
        };
        void check(document.visibilityState === 'hidden');
        const timer = window.setInterval(() => void check(document.visibilityState === 'hidden'), EVERY_MS);

        return () => { alive = false; window.clearInterval(timer); };
    }, [accountKey, setPopups]);
    const close = (id: number) => setPopups((current) => current.filter((popup) => popup.id !== id));

    if (popups.length === 0) return null;

    return <section className="popup-stack" aria-label="Nouveaux rappels">{popups.map((popup) => <div key={popup.id} className="popup" role="status">
        <span className="popup-icon" aria-hidden="true"><Icon name="bell" size={20} /></span>
        <div className="popup-text"><strong>{popup.title}</strong>{popup.body && <span>{popup.body}</span>}
            <div className="popup-actions"><button type="button" className="button button-primary" onClick={() => { close(popup.id); router.post(`/notifications/${popup.id}/lecture`); }}>Ouvrir</button>
                <button type="button" className="button button-secondary" onClick={() => close(popup.id)}>Plus tard</button></div>
        </div>
        <button type="button" className="icon-button popup-close" aria-label={`Fermer : ${popup.title}`} onClick={() => close(popup.id)}><Icon name="close" size={18} /></button>
    </div>)}</section>;
}
