import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Button } from './Button';

export function ConfirmDialog({ open, title, children, confirmLabel = 'Confirmer', busy = false, onCancel, onConfirm }: { open: boolean; title: string; children: ReactNode; confirmLabel?: string; busy?: boolean; onCancel: () => void; onConfirm: () => void }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const cancel = useRef<HTMLButtonElement>(null);
    const titleId = useId();
    const descriptionId = useId();
    useEffect(() => {
        const element = dialog.current;
        if (!open || !element) return;
        const previous = document.activeElement;
        element.showModal();
        cancel.current?.focus();
        return () => {
            element.close();
            if (previous instanceof HTMLElement && previous.isConnected) previous.focus();
        };
    }, [open]);
    return <dialog ref={dialog} className="confirm-dialog" aria-labelledby={titleId} aria-describedby={descriptionId} tabIndex={-1} onKeyDown={(event) => {
        if (event.key !== 'Tab') return;
        const controls = Array.from(dialog.current?.querySelectorAll<HTMLElement>('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') ?? []).filter((element) => element.getClientRects().length > 0);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (!first) {
            event.preventDefault();
            dialog.current?.focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }} onCancel={(event) => { event.preventDefault(); if (!busy) onCancel(); }}><h2 id={titleId}>{title}</h2><div id={descriptionId} className="dialog-description">{children}</div><div className="dialog-actions"><button ref={cancel} className="button button-secondary" type="button" disabled={busy} onClick={onCancel}>Annuler</button><Button variant="danger" busy={busy} onClick={onConfirm}>{confirmLabel}</Button></div></dialog>;
}
