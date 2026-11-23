export type Deadline = {
    kind: 'instant' | 'date' | 'rolling' | 'unknown'; kindLabel: string; state: 'open' | 'last_day' | 'closed' | 'rolling' | 'unknown'; instantUtc: string | null; timezone: string | null;
    localInput: string | null; date: string | null; text: string; rule: string | null; closesAt: string | null; previous: { text: string; changedAt: string } | null;
};

const STATE: Record<Deadline['state'], string> = {
    open: 'Ouverte', last_day: 'Dernier jour possible — heure exacte non communiquée', closed: 'Clôturée', rolling: 'Clôture continue', unknown: 'Date limite non communiquée',
};

export function DeadlineState({ deadline }: { deadline: Deadline }) {
    return <span className="subtle-badge">{STATE[deadline.state]}</span>;
}

/** Date limite avec sa précision d'origine. Pour un instant, l'heure du fuseau de l'organisateur est affichée d'abord ; l'équivalent local du lecteur vient ensuite, clairement distingué. */
export function DeadlineDetail({ deadline }: { deadline: Deadline }) {
    const local = deadline.instantUtc ? new Intl.DateTimeFormat('fr', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }).format(new Date(deadline.instantUtc)) : null;
    return <div>
        <p><strong>Date limite : {deadline.text}</strong> <DeadlineState deadline={deadline} /></p>
        {local && <p className="item-meta">Soit le {local} sur cet appareil. L’heure officielle est celle du fuseau indiqué par l’organisateur ({deadline.timezone}).</p>}
        {deadline.rule && <p className="item-meta">{deadline.rule}</p>}
        {deadline.kind === 'rolling' && <p className="item-meta">L’organisateur n’annonce pas de date limite. Campus n’en invente pas : vérifiez sur la source.</p>}
        {deadline.kind === 'unknown' && <p className="item-meta">L’organisateur n’a pas communiqué de date limite. Campus n’en invente pas : vérifiez sur la source.</p>}
        {deadline.previous && <p className="item-meta">Date modifiée le {new Intl.DateTimeFormat('fr', { dateStyle: 'medium' }).format(new Date(deadline.previous.changedAt))}. Ancienne date : {deadline.previous.text}.</p>}
    </div>;
}
