export type QuickAddKind = 'course' | 'task' | 'grade';

export type QuickAddRequest = { kind: QuickAddKind; due?: string };

const EVENT = 'campus:ajout-rapide';

/** Ouvre le panneau d'ajout rapide de la mise en page, depuis n'importe quel écran, sur l'onglet voulu ; « due » préremplit l'échéance (AAAA-MM-JJTHH:MM). */
export function openQuickAdd(kind: QuickAddKind, due?: string): void {
    window.dispatchEvent(new CustomEvent<QuickAddRequest>(EVENT, { detail: { kind, due } }));
}

/** Écoute les demandes d'ouverture ; renvoie la fonction qui arrête l'écoute. */
export function onQuickAdd(listener: (request: QuickAddRequest) => void): () => void {
    const handle = (event: Event) => listener((event as CustomEvent<QuickAddRequest>).detail);
    window.addEventListener(EVENT, handle);

    return () => window.removeEventListener(EVENT, handle);
}

/** Date locale AAAA-MM-JJ décalée d'un nombre de jours, sans passer par le fuseau du navigateur. */
export function shiftDate(date: string, days: number): string {
    const moved = new Date(date + 'T12:00:00Z');
    moved.setUTCDate(moved.getUTCDate() + days);

    return moved.toISOString().slice(0, 10);
}

/** Jour de la semaine d'une date locale, de 1 (lundi) à 7 (dimanche). */
export function isoWeekday(date: string): number {
    return new Date(date + 'T12:00:00Z').getUTCDay() || 7;
}
