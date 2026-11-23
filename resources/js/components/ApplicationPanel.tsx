import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from './ui/Button';
import { ConfirmDialog } from './ui/ConfirmDialog';
import { ErrorSummary, SelectField, TextAreaField, TextField } from './ui/Fields';

export type Application = {
    opportunityId: string; version: number; status: string; statusLabel: string; statusChangedAt: string; personalDeadlineOn: string | null; note: string | null;
    reference: { title: string; organizer: string; campaign: string; category: string; deadline: string; sourceHost: string | null; verifiedAt: string | null; capturedAt: string };
    opportunityStatus: string; items: { id: number; label: string; done: boolean }[];
};

/** Démarche privée d'une fiche : état choisi par la personne, échéance personnelle, note courte, liste de pièces à cocher. Aucun fichier. */
export function ApplicationPanel({ opportunityId, application, statuses, canStart, maxItems = 20 }: { opportunityId: string; application: Application | null; statuses: Record<string, string>; canStart: boolean; maxItems?: number }) {
    const errors = usePage().props.errors as Record<string, string>;
    const form = useForm({ expected_version: application?.version ?? 0, status: application?.status ?? 'to_study', personal_deadline_on: application?.personalDeadlineOn ?? '', note: application?.note ?? '' });
    const [label, setLabel] = useState('');
    const [key, setKey] = useState(() => crypto.randomUUID());
    const [deleting, setDeleting] = useState(false);
    const base = `/opportunites/${opportunityId}/demarche`;
    const send = (data: typeof form.data) => ({ ...data, personal_deadline_on: data.personal_deadline_on || null, note: data.note || null });
    const submit = (event: FormEvent) => { event.preventDefault(); form.transform(send); form.put(base, { preserveScroll: true }); };
    const clearNote = () => { form.transform((data) => ({ ...send(data), note: null })); form.put(base, { preserveScroll: true, onSuccess: () => form.setData('note', '') }); };
    const addItem = (event: FormEvent) => {
        event.preventDefault();
        if (!label.trim()) return;
        router.post(`${base}/pieces`, { client_key: key, label }, { preserveScroll: true, onSuccess: () => { setLabel(''); setKey(crypto.randomUUID()); } });
    };
    if (!application && !canStart) return <p className="item-meta">Une démarche ne peut être commencée que sur une fiche publiée.</p>;
    return <div>
        <p className="item-meta">Votre démarche n’est visible que par vous. Son état ne change que lorsque vous le modifiez ici : ouvrir le site de l’organisateur ne l’indique jamais comme « envoyée ».</p>
        <form onSubmit={submit} className="form-narrow">
            <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
            {errors.reference && <p className="field-error" role="alert">{errors.reference}</p>}
            <SelectField label="État de ma démarche" value={form.data.status} error={form.errors.status} onChange={(event) => form.setData('status', event.target.value)}>
                {Object.entries(statuses).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </SelectField>
            <TextField label="Mon échéance personnelle (facultative)" type="date" hint="Distincte de la date limite officielle, qui reste celle de la fiche." value={form.data.personal_deadline_on} error={form.errors.personal_deadline_on} onChange={(event) => form.setData('personal_deadline_on', event.target.value)} />
            <TextAreaField label="Note privée (facultative, 500 caractères au plus)" hint="N’y inscrivez ni numéro de pièce d’identité ni information bancaire." rows={3} maxLength={500} value={form.data.note} error={form.errors.note} onChange={(event) => form.setData('note', event.target.value)} />
            <div className="form-actions">
                <Button type="submit" busy={form.processing}>{application ? 'Enregistrer ma démarche' : 'Commencer ma démarche'}</Button>
                {application?.note && <Button variant="secondary" busy={form.processing} onClick={clearNote}>Supprimer la note</Button>}
                {application && <Button variant="danger" onClick={() => setDeleting(true)}>Supprimer la démarche</Button>}
            </div>
        </form>
        {application && <>
            <p className="collection-label">Pièces à préparer</p>
            <p className="item-meta">Simple liste à cocher. Aucun document n’est déposé dans Campus : conservez vos pièces chez vous.</p>
            {application.items.length > 0 && <ul className="plain-list">{application.items.map((item) => <li key={item.id}>
                <label><input type="checkbox" checked={item.done} onChange={(event) => router.patch(`${base}/pieces/${item.id}`, { done: event.target.checked }, { preserveScroll: true })} /> {item.label}</label>{' '}
                <button type="button" className="text-link" onClick={() => router.delete(`${base}/pieces/${item.id}`, { preserveScroll: true })} aria-label={`Supprimer la ligne ${item.label}`}>Supprimer</button>
            </li>)}</ul>}
            {application.items.length < maxItems && <form onSubmit={addItem} className="form-actions">
                <TextField label="Ajouter une pièce à préparer" maxLength={160} value={label} error={errors.label} onChange={(event) => setLabel(event.target.value)} />
                <Button type="submit" variant="secondary">Ajouter</Button>
            </form>}
        </>}
        <ConfirmDialog open={deleting} title="Supprimer cette démarche ?" confirmLabel="Supprimer la démarche" onCancel={() => setDeleting(false)} onConfirm={() => router.delete(base, { preserveScroll: true, onFinish: () => setDeleting(false) })}>
            Votre état, votre échéance personnelle, votre note et votre liste de pièces seront supprimés définitivement. Le favori éventuel est conservé.
        </ConfirmDialog>
    </div>;
}
