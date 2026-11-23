import type { ReactNode } from 'react';

export type StateKind = 'info' | 'empty' | 'error' | 'offline' | 'session-expired' | 'success' | 'loading';
const symbols: Record<StateKind, string> = { info: 'i', empty: '○', error: '!', offline: '↯', 'session-expired': '!', success: '✓', loading: '…' };
export function StateMessage({ kind = 'info', title, children, action }: { kind?: StateKind; title: string; children?: ReactNode; action?: ReactNode }) {
    return <div className={`state-message state-${kind}`} role={kind === 'error' || kind === 'session-expired' ? 'alert' : 'status'} aria-busy={kind === 'loading' || undefined}><span className="state-symbol" aria-hidden="true">{kind === 'loading' ? <span className="spinner" /> : symbols[kind]}</span><div className="min-w-0"><p className="font-semibold">{title}</p>{children && <div className="state-description">{children}</div>}{action && <div className="mt-3">{action}</div>}</div></div>;
}
export function ProvenanceBadge({ source }: { source: 'personal' | 'institutional' }) {
    return <span className={`provenance provenance-${source}`}>{source === 'personal' ? 'Personnel' : 'Établissement'}</span>;
}
export function SuccessNotice({ message, onDismiss }: { message: string; onDismiss: () => void }) {
    return <div className="success-notice"><div role="status"><span aria-hidden="true">✓ </span>{message}</div><button type="button" onClick={onDismiss} aria-label="Fermer le message de succès">Fermer</button></div>;
}
export function LoadingState({ label = 'Chargement en cours…' }: { label?: string }) {
    return <StateMessage kind="loading" title={label}>Merci de patienter.</StateMessage>;
}
