import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { Pagination } from '../components/ui/Collection';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, SelectField, TextAreaField, TextField } from '../components/ui/Fields';
import { AttachmentList, type AttachmentItem, type AttachmentLimits } from '../components/AttachmentList';

type Item = { id: string; title: string; description: string | null; group: string | null; subject: string | null; dueAt: string | null; dueLocal: string | null; priority: string; version: number; purgeAt: string | null; attachments?: AttachmentItem[] };
type Props = {
    assignments: { groupId: number; subjectId: number; label: string }[]; homework: Item[]; withdrawn: Item[];
    pagination: { page: number; total: number; previous: string | null; next: string | null }; priorities: Record<string, string>; timezone: string; canWrite: boolean; trashDays: number; attachmentLimits: AttachmentLimits;
};

const newKey = () => crypto.randomUUID();

function HomeworkItem({ item, props }: { item: Item; props: Props }) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ expected_version: item.version, title: item.title, description: item.description ?? '', due_at: item.dueLocal ?? '', priority: item.priority });
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.timezone });
    const save = (event: FormEvent) => { event.preventDefault(); form.patch(`/taches/devoirs/${item.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); };
    return <li>
        <strong>{item.title}</strong>
        <p className="item-meta">{item.group} · {item.subject} · priorité {props.priorities[item.priority].toLowerCase()} · {item.dueAt ? `date officielle : ${when.format(new Date(item.dueAt))}` : 'sans échéance'}</p>
        {item.description && <p>{item.description}</p>}
        <AttachmentList items={item.attachments ?? []} uploadUrl={`/taches/${item.id}/pieces-jointes`} limits={props.attachmentLimits} canUpload={props.canWrite} shared label="Fichiers partagés avec le groupe" />
        {props.canWrite && <div className="form-actions"><Button variant="secondary" onClick={() => setEditing(!editing)}>Modifier</Button><Button variant="danger" onClick={() => setConfirming(true)}>Retirer</Button></div>}
        {editing && <form onSubmit={save} className="form-narrow">
            <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
            <TextField label="Titre" required maxLength={255} value={form.data.title} error={form.errors.title} onChange={(event) => form.setData('title', event.target.value)} />
            <TextAreaField label="Consigne (facultative)" rows={3} value={form.data.description} error={form.errors.description} onChange={(event) => form.setData('description', event.target.value)} />
            <TextField label="Date officielle (facultative)" type="datetime-local" hint={`Fuseau de l’établissement : ${props.timezone}`} value={form.data.due_at} error={form.errors.due_at} onChange={(event) => form.setData('due_at', event.target.value)} />
            <SelectField label="Priorité" value={form.data.priority} error={form.errors.priority} onChange={(event) => form.setData('priority', event.target.value)}>
                {Object.entries(props.priorities).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </SelectField>
            <p className="field-hint">L’avancement de chaque apprenant est conservé. Les apprenants du groupe seront informés du changement.</p>
            <div className="form-actions"><Button type="submit" busy={form.processing}>Enregistrer</Button><Button variant="secondary" onClick={() => setEditing(false)}>Annuler</Button></div>
        </form>}
        <ConfirmDialog open={confirming} title="Retirer ce devoir ?" confirmLabel="Retirer le devoir" onCancel={() => setConfirming(false)}
            onConfirm={() => router.delete(`/taches/devoirs/${item.id}`, { data: { expected_version: item.version }, preserveScroll: true, onSuccess: () => setConfirming(false) })}>
            Le devoir « {item.title} » ne sera plus visible par le groupe. Vous pourrez le rétablir pendant {props.trashDays} jours, avec l’avancement des apprenants.
        </ConfirmDialog>
    </li>;
}

export default function Homework(props: Props) {
    const { assignments, homework, withdrawn, pagination, priorities, timezone, canWrite, trashDays } = props;
    const errors = usePage().props.errors as Record<string, string>;
    const create = useForm({ client_key: newKey(), assignment: assignments[0] ? `${assignments[0].groupId}:${assignments[0].subjectId}` : '', title: '', description: '', due_at: '', priority: 'normal' });
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const [group, subject] = create.data.assignment.split(':').map(Number);
        create.transform((data) => ({ client_key: data.client_key, group_id: group, subject_id: subject, title: data.title, description: data.description, due_at: data.due_at, priority: data.priority }));
        create.post('/taches/devoirs', { preserveScroll: true, onSuccess: () => { create.reset('title', 'description', 'due_at'); create.setData('client_key', newKey()); } });
    };
    return (
        <CampusLayout active="tasks" title="Devoirs">
            <Head title="Devoirs" />
            <div className="page-heading"><p className="eyebrow">ENSEIGNEMENT</p><h1>Devoirs de mes groupes</h1><p className="page-intro">Publiez un devoir pour un groupe et une matière qui vous sont affectés. Vous ne voyez pas l’avancement individuel des apprenants.</p></div>
            {errors.reference && <StateMessage kind="error" title="Action non enregistrée">{errors.reference}</StateMessage>}
            {!canWrite && <StateMessage title="Publication indisponible">La publication de devoirs exige une vérification renforcée du compte, qui n’est pas encore disponible.</StateMessage>}

            {canWrite && <section className="surface" aria-labelledby="publish-title">
                <h2 id="publish-title">Publier un devoir</h2>
                {assignments.length === 0 ? <StateMessage kind="empty" title="Aucune affectation en cours">Vous ne pouvez publier que pour un groupe et une matière qui vous sont affectés.</StateMessage>
                    : <form onSubmit={submit} className="form-narrow">
                        <ErrorSummary errors={Object.entries(create.errors).map(([id, message]) => ({ id, message: String(message) }))} />
                        <SelectField label="Groupe et matière" value={create.data.assignment} onChange={(event) => create.setData('assignment', event.target.value)}>
                            {assignments.map((pair) => <option key={`${pair.groupId}:${pair.subjectId}`} value={`${pair.groupId}:${pair.subjectId}`}>{pair.label}</option>)}
                        </SelectField>
                        <TextField label="Titre" required maxLength={255} value={create.data.title} error={create.errors.title} onChange={(event) => create.setData('title', event.target.value)} />
                        <TextAreaField label="Consigne (facultative)" rows={3} value={create.data.description} error={create.errors.description} onChange={(event) => create.setData('description', event.target.value)} />
                        <TextField label="Date officielle (facultative)" type="datetime-local" hint={`Fuseau de l’établissement : ${timezone}`} value={create.data.due_at} error={create.errors.due_at} onChange={(event) => create.setData('due_at', event.target.value)} />
                        <SelectField label="Priorité" value={create.data.priority} error={create.errors.priority} onChange={(event) => create.setData('priority', event.target.value)}>
                            {Object.entries(priorities).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </SelectField>
                        <div className="form-actions"><Button type="submit" busy={create.processing}>Publier le devoir</Button></div>
                    </form>}
            </section>}

            <section className="surface" aria-labelledby="list-title">
                <h2 id="list-title">Devoirs publiés</h2>
                {homework.length === 0 ? <StateMessage kind="empty" title="Aucun devoir publié" />
                    : <ul className="plain-list">{homework.map((item) => <HomeworkItem key={`${item.id}-${item.version}`} item={item} props={props} />)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>

            {withdrawn.length > 0 && <section className="surface" aria-labelledby="withdrawn-title">
                <h2 id="withdrawn-title">Devoirs retirés</h2>
                <p className="section-description">Rétablissement possible pendant {trashDays} jours.</p>
                <ul className="plain-list">{withdrawn.map((item) => <li key={item.id}>
                    <strong>{item.title}</strong>
                    <p className="item-meta">{item.group} · {item.subject} · suppression définitive le {item.purgeAt && when.format(new Date(item.purgeAt))}</p>
                    {canWrite && <Button variant="secondary" onClick={() => router.post(`/taches/devoirs/${item.id}/retablissement`, {}, { preserveScroll: true })}>Rétablir</Button>}
                </li>)}</ul>
            </section>}
            <div className="inline-links"><Link className="text-link" href="/taches">Mes tâches</Link><Link className="text-link" href="/planning">Planning</Link></div>
        </CampusLayout>
    );
}
