import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { Pagination } from '../components/ui/Collection';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { ProvenanceBadge, StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, SelectField, TextAreaField, TextField } from '../components/ui/Fields';
import { AttachmentList, type AttachmentItem, type AttachmentLimits } from '../components/AttachmentList';
import { OutboxPanel } from '../components/OutboxPanel';
import { canDraft, saveDraft } from '../offline/outbox';
import { MoreOptions } from '../components/ui/MoreOptions';
import { Icon } from '../components/Icon';
import { DueFields } from '../components/DueFields';
import { useUnsavedWarning } from '../components/ui/useUnsavedWarning';
import type { CampusProps } from '../types';

type Step = { id: number; title: string; done: boolean };
type Task = {
    id: string; collective: boolean; title: string; description: string | null; subject: string | null; personalSubjectId: number | null; group: string | null;
    dueAt: string | null; dueLocal: string | null; personalTargetAt: string | null; personalTargetLocal: string | null; priority: string; status: string;
    late: boolean; version: number; sourceOccurrenceId: string | null; steps: Step[]; nextStep: string | null; deletedAt: string | null; purgeAt: string | null; attachments: AttachmentItem[];
};
type Filters = { statut: string; priorite: string; matiere: string; du: string; au: string; vue: 'liste' | 'corbeille' };
type Props = {
    tasks: Task[]; undated: Task[]; pagination: { page: number; total: number; previous: string | null; next: string | null }; filters: Filters;
    subjects: { value: string; label: string }[]; personalSubjects: { id: number; name: string }[]; statuses: Record<string, string>; priorities: Record<string, string>;
    timezone: string; trashDays: number; sourceCourse: { id: string; title: string } | null; teaching: boolean; attachmentLimits: AttachmentLimits;
};

const newKey = () => crypto.randomUUID();

/** Jour local AAAA-MM-JJ d'un instant, dans le fuseau du compte. */
function dayKey(instant: Date, timezone: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(instant);
}

function TaskItem({ task, props }: { task: Task; props: Props }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [stepTitle, setStepTitle] = useState('');
    const [stepKey, setStepKey] = useState(newKey);
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.timezone });
    const form = useForm({ expected_version: task.version, title: task.title, description: task.description ?? '', due_at: task.dueLocal ?? '', priority: task.priority,
        personal_subject_id: task.personalSubjectId ?? '', status: task.status, personal_target_at: task.personalTargetLocal ?? '' });
    const accountKey = usePage<CampusProps>().props.auth.accountKey ?? null;
    const [notice, setNotice] = useState<string | null>(null);
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    const known = { title: task.title, description: task.description, due_at: task.dueLocal, priority: task.priority, personal_subject_id: task.personalSubjectId, status: task.status };
    // Sans réseau : une tâche personnelle se modifie en brouillon sur un appareil personnel, à partir de la version affichée ici.
    // L'avancement d'un devoir de l'établissement s'enregistre en ligne uniquement.
    const keep = async (fields: Record<string, unknown>) => {
        setBusy(false);
        if (task.collective) { setNotice('Pas de réseau : l’avancement d’un devoir de l’établissement s’enregistre en ligne. Réessayez quand le réseau sera revenu.'); return; }
        if (!canDraft(accountKey)) { setNotice('Pas de réseau : rien n’a été enregistré, et cet appareil ne conserve pas de brouillon. Réessayez quand le réseau sera revenu.'); return; }
        const { operation, errors } = await saveDraft({ accountKey, type: 'task.update', targetId: task.id, baseVersion: task.version, fields, label: String(fields.title ?? task.title) });
        setNotice(operation ? 'Modification enregistrée sur cet appareil. Elle sera envoyée au retour du réseau.' : errors.join(' '));
        if (operation) setEditing(false);
    };
    const setStatus = (status: string) => {
        const offline = () => { void keep({ ...known, status }); return false; };
        if (!navigator.onLine) { offline(); return; }
        if (task.collective) router.put(`/taches/${task.id}/progression`, { expected_version: task.version, status, personal_target_at: task.personalTargetLocal }, { ...options, onNetworkError: offline });
        else router.patch(`/taches/${task.id}`, { expected_version: task.version, ...known, status }, { ...options, onNetworkError: offline });
    };
    const save = (event: FormEvent) => {
        event.preventDefault();
        const offline = () => { void keep({ title: form.data.title, description: form.data.description, due_at: form.data.due_at, priority: form.data.priority, personal_subject_id: form.data.personal_subject_id, status: form.data.status }); return false; };
        if (!navigator.onLine) { offline(); return; }
        const done = { preserveScroll: true, onSuccess: () => setEditing(false), onNetworkError: offline };
        if (task.collective) form.put(`/taches/${task.id}/progression`, done); else form.patch(`/taches/${task.id}`, done);
    };
    const addStep = (event: FormEvent) => {
        event.preventDefault();
        if (!stepTitle.trim()) return;
        router.post(`/taches/${task.id}/etapes`, { client_key: stepKey, title: stepTitle }, { ...options, onSuccess: () => { setStepTitle(''); setStepKey(newKey()); } });
    };

    const done = task.status === 'done';
    const time = new Intl.DateTimeFormat('fr', { hour: '2-digit', minute: '2-digit', timeZone: props.timezone });
    const short = new Intl.DateTimeFormat('fr', { weekday: 'short', day: 'numeric', month: 'short', timeZone: props.timezone });
    const due = task.dueAt ? new Date(task.dueAt) : null;
    const dueLabel = due === null ? null : dayKey(due, props.timezone) === dayKey(new Date(), props.timezone) ? `Aujourd’hui ${time.format(due)}` : `${short.format(due)} ${time.format(due)}`;
    const stepsDone = task.steps.filter((step) => step.done).length;

    return <li className={`task-row ${done ? 'is-done' : ''} ${open ? 'is-open' : ''}`}>
        <div className="task-line">
            <button type="button" className="todo-check" aria-pressed={done} disabled={busy} aria-label={done ? `Rouvrir « ${task.title} »` : `Marquer « ${task.title} » comme faite`} onClick={() => setStatus(done ? 'todo' : 'done')}><Icon name="check" strokeWidth={3} /></button>
            <button type="button" className="task-toggle" aria-expanded={open} onClick={() => setOpen(!open)}>
                <strong className="todo-title">{task.title}</strong>
                <span className="todo-meta">
                    {task.late ? <span className="pill pill-danger">En retard · {dueLabel}</span> : dueLabel && <span className={`pill ${dueLabel.startsWith('Aujourd') ? 'pill-warm' : 'pill-info'}`}>{dueLabel}</span>}
                    {task.status === 'in_progress' && <span className="pill pill-success">En cours</span>}
                    {task.collective && <ProvenanceBadge source="institutional" />}
                    <span>{[task.subject, task.priority === 'high' ? 'priorité haute' : null, task.steps.length > 0 ? `${stepsDone}/${task.steps.length} étape(s)` : null, task.attachments.length > 0 ? `${task.attachments.length} fichier(s)` : null].filter(Boolean).join(' · ')}</span>
                </span>
            </button>
        </div>
        {notice && <p role="status" className="task-notice"><strong>{notice}</strong></p>}
        {open && <div className="list-row-panel">
            {task.description && <p>{task.description}</p>}
            {(task.group || task.personalTargetAt || (task.collective && due)) && <p className="item-meta">
                {task.group && `${task.group}`}{task.collective && due && ` · date officielle : ${when.format(due)}`}{task.personalTargetAt && ` · mon objectif : ${when.format(new Date(task.personalTargetAt))}`}
            </p>}
            {task.nextStep && <p className="item-meta">Prochaine étape : {task.nextStep}</p>}
            <div className="form-actions">
                {task.status !== 'in_progress' && task.status !== 'done' && <Button variant="secondary" busy={busy} onClick={() => setStatus('in_progress')}>Commencer</Button>}
                {task.status !== 'done' && <Button busy={busy} onClick={() => setStatus('done')}>Terminer</Button>}
                {(task.status === 'done' || task.status === 'abandoned') && <Button variant="secondary" busy={busy} onClick={() => setStatus('todo')}>Rouvrir</Button>}
                <Button variant="secondary" onClick={() => setEditing(!editing)}>{task.collective ? 'Mon avancement et mon objectif' : 'Modifier'}</Button>
                {!task.collective && <Button variant="danger" onClick={() => setConfirming(true)}>Supprimer</Button>}
            </div>
            {task.steps.length > 0 && <ul className="plain-list">{task.steps.map((step) => <li key={step.id}>
                <label><input type="checkbox" checked={step.done} disabled={busy} onChange={(event) => router.patch(`/taches/${task.id}/etapes/${step.id}`, { done: event.target.checked }, options)} /> {step.title}</label>{' '}
                <button type="button" className="text-link" disabled={busy} onClick={() => router.delete(`/taches/${task.id}/etapes/${step.id}`, options)} aria-label={`Supprimer l’étape ${step.title}`}>Supprimer</button>
            </li>)}</ul>}
            <MoreOptions label={`Étapes et fichiers${task.attachments.length > 0 ? ` (${task.attachments.length} fichier${task.attachments.length > 1 ? 's' : ''})` : ''}`}>
                <form onSubmit={addStep} className="form-actions">
                    <TextField label="Ajouter une étape" value={stepTitle} maxLength={160} onChange={(event) => setStepTitle(event.target.value)} />
                    <Button type="submit" variant="secondary" busy={busy}>Ajouter l’étape</Button>
                </form>
                <AttachmentList items={task.attachments} uploadUrl={`/taches/${task.id}/pieces-jointes`} limits={props.attachmentLimits} canUpload label={task.collective ? 'Fichiers du devoir et mes fichiers personnels' : 'Pièces jointes'} />
            </MoreOptions>
        {editing && <form onSubmit={save} className="form-narrow">
            <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
            {!task.collective && <>
                <TextField label="Titre" required maxLength={255} value={form.data.title} error={form.errors.title} onChange={(event) => form.setData('title', event.target.value)} />
                <TextAreaField label="Description (facultative)" rows={3} value={form.data.description} error={form.errors.description} onChange={(event) => form.setData('description', event.target.value)} />
                <SelectField label="Matière (facultative)" value={form.data.personal_subject_id} error={form.errors.personal_subject_id} onChange={(event) => form.setData('personal_subject_id', event.target.value ? Number(event.target.value) : '')}>
                    <option value="">Aucune matière</option>{props.personalSubjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}
                </SelectField>
                <DueFields value={form.data.due_at} today={dayKey(new Date(), props.timezone)} error={form.errors.due_at} onChange={(value) => form.setData('due_at', value)} />
                <SelectField label="Priorité" value={form.data.priority} error={form.errors.priority} onChange={(event) => form.setData('priority', event.target.value)}>
                    {Object.entries(props.priorities).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </SelectField>
            </>}
            <SelectField label="État" value={form.data.status} error={form.errors.status} onChange={(event) => form.setData('status', event.target.value)}>
                {Object.entries(props.statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </SelectField>
            {task.collective && <TextField label="Mon objectif personnel (facultatif)" type="datetime-local" hint="La date officielle du devoir ne change pas : cet objectif n’est visible que par vous." value={form.data.personal_target_at} error={form.errors.personal_target_at} onChange={(event) => form.setData('personal_target_at', event.target.value)} />}
            <div className="form-actions"><Button type="submit" busy={form.processing}>Enregistrer</Button><Button variant="secondary" onClick={() => setEditing(false)}>Annuler</Button></div>
        </form>}
        </div>}
        <ConfirmDialog open={confirming} title="Supprimer cette tâche ?" confirmLabel="Placer dans la corbeille" busy={busy} onCancel={() => setConfirming(false)}
            onConfirm={() => router.delete(`/taches/${task.id}`, { data: { expected_version: task.version }, ...options, onSuccess: () => setConfirming(false) })}>
            La tâche « {task.title} » ira dans la corbeille. Vous pourrez la restaurer pendant {props.trashDays} jours, puis elle sera supprimée définitivement.
        </ConfirmDialog>
    </li>;
}

export default function Tasks(props: Props) {
    const { tasks, undated, pagination, filters, subjects, personalSubjects, statuses, priorities, timezone, trashDays, sourceCourse, teaching } = props;
    const errors = usePage().props.errors as Record<string, string>;
    const [filter, setFilter] = useState(filters);
    const create = useForm({ client_key: newKey(), title: '', description: '', personal_subject_id: '' as number | '', due_at: '', priority: 'normal', source_occurrence_id: sourceCourse?.id ?? null });
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone });
    const accountKey = usePage<CampusProps>().props.auth.accountKey ?? null;
    const [notice, setNotice] = useState<string | null>(null);
    useUnsavedWarning((create.data.title.trim() !== '' || create.data.description.trim() !== '') && !create.processing, 'Votre nouvelle tâche');
    // Sans réseau, ou si l'envoi échoue en route : la saisie n'est jamais perdue. Sur un appareil personnel elle devient un brouillon,
    // avec la même clé que le formulaire : si le serveur avait déjà reçu l'envoi, la synchronisation retrouvera la même tâche.
    const keepLocally = async () => {
        if (!canDraft(accountKey)) { setNotice('Pas de réseau : la tâche n’est pas encore enregistrée. Votre saisie reste dans le formulaire ; renvoyez-la quand le réseau sera revenu, elle ne sera créée qu’une fois.'); return; }
        const { operation, errors } = await saveDraft({ accountKey, type: 'task.create', id: create.data.client_key, fields: { ...create.data }, label: create.data.title });
        if (!operation) { setNotice(errors.join(' ')); return; }
        create.reset();
        create.setData('client_key', newKey());
        setNotice('Tâche enregistrée sur cet appareil. Elle sera envoyée au retour du réseau.');
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        setNotice(null);
        if (!navigator.onLine) { void keepLocally(); return; }
        // La clé reste la même tant que l'envoi n'a pas abouti : un double clic ne crée qu'une tâche.
        create.post('/taches', { preserveScroll: true, onSuccess: () => { create.reset(); create.setData('client_key', newKey()); }, onNetworkError: () => { void keepLocally(); return false; } });
    };
    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get('/taches', Object.fromEntries(Object.entries(filter).filter(([key, value]) => value !== '' && key !== 'vue')), { preserveScroll: true });
    };
    const trash = filters.vue === 'corbeille';
    const today = dayKey(new Date(), timezone);
    const plus = (days: number) => { const moved = new Date(today + 'T12:00:00Z'); moved.setUTCDate(moved.getUTCDate() + days); return moved.toISOString().slice(0, 10) + 'T18:00'; };
    // Les tâches datées sont rangées par moment : en retard, aujourd'hui, demain, cette semaine, plus tard.
    const groups: [string, string, Task[]][] = [['late', 'En retard', []], ['today', 'Aujourd’hui', []], ['tomorrow', 'Demain', []], ['week', 'Cette semaine', []], ['later', 'Plus tard', []]];
    const tomorrow = plus(1).slice(0, 10);
    const weekEnd = plus(7).slice(0, 10);
    for (const task of tasks) {
        const key = task.dueAt ? dayKey(new Date(task.dueAt), timezone) : today;
        const slot = task.late ? 0 : key <= today ? 1 : key === tomorrow ? 2 : key <= weekEnd ? 3 : 4;
        groups[slot][2].push(task);
    }

    return (
        <CampusLayout active="tasks" title="Tâches">
            <Head title="Tâches" />
            <div className="page-heading"><h1>Mes tâches</h1></div>
            {errors.reference && <StateMessage kind="error" title="Action non enregistrée">{errors.reference}</StateMessage>}
            {notice && <StateMessage kind="offline" title="Hors connexion">{notice}</StateMessage>}
            <OutboxPanel accountKey={accountKey} compact onSynced={() => router.reload()} />

            {trash ? <section className="surface" aria-labelledby="trash-title">
                <h2 id="trash-title">Corbeille</h2>
                <p className="section-description">Les tâches supprimées restent restaurables pendant {trashDays} jours.</p>
                {tasks.length === 0 ? <StateMessage kind="empty" title="La corbeille est vide" />
                    : <ul className="plain-list">{tasks.map((task) => <li key={task.id}>
                        <strong>{task.title}</strong>
                        <p className="item-meta">Suppression définitive le {task.purgeAt && when.format(new Date(task.purgeAt))}</p>
                        <Button variant="secondary" onClick={() => router.post(`/taches/${task.id}/restauration`, {}, { preserveScroll: true })}>Restaurer</Button>
                    </li>)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
                <div className="inline-links"><Link className="text-link" href="/taches">Retour à mes tâches</Link></div>
            </section> : <>
                <section className="surface" aria-labelledby="create-title">
                    <h2 id="create-title" className="sr-only">Ajouter une tâche</h2>
                    {sourceCourse && <p className="section-description">Tâche liée au cours « {sourceCourse.title} ».</p>}
                    <form onSubmit={submit} className="task-create">
                        <ErrorSummary errors={Object.entries(create.errors).map(([id, message]) => ({ id, message: String(message) }))} />
                        <TextField label="Titre" required maxLength={255} placeholder="Qu’est-ce que tu dois faire ?" value={create.data.title} error={create.errors.title} onChange={(event) => create.setData('title', event.target.value)} />
                        <DueFields value={create.data.due_at} today={today} error={create.errors.due_at} onChange={(value) => create.setData('due_at', value)} />
                        <MoreOptions label="Plus d’options : description, matière, priorité" reveal={Boolean(create.errors.description || create.errors.personal_subject_id || create.errors.priority)}>
                            <TextAreaField label="Description" rows={3} value={create.data.description} error={create.errors.description} onChange={(event) => create.setData('description', event.target.value)} />
                            {personalSubjects.length > 0 && <SelectField label="Matière" value={create.data.personal_subject_id} error={create.errors.personal_subject_id} onChange={(event) => create.setData('personal_subject_id', event.target.value ? Number(event.target.value) : '')}>
                                <option value="">Aucune matière</option>{personalSubjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}
                            </SelectField>}
                            <SelectField label="Priorité" value={create.data.priority} error={create.errors.priority} onChange={(event) => create.setData('priority', event.target.value)}>
                                {Object.entries(priorities).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </SelectField>
                        </MoreOptions>
                        <div className="form-actions"><Button type="submit" busy={create.processing}>Ajouter la tâche</Button></div>
                    </form>
                </section>

                {tasks.length === 0 && undated.length === 0 && <StateMessage kind="empty" title={filters.statut === 'open' ? 'Rien à faire pour l’instant' : 'Aucune tâche pour ces filtres'} />}
                {groups.filter(([, , items]) => items.length > 0).map(([key, label, items]) => <section className="task-group" key={key} aria-labelledby={`group-${key}`}>
                    <h2 id={`group-${key}`} className={`task-group-title ${key === 'late' ? 'is-late' : ''}`}>{label} <span>{items.length}</span></h2>
                    <ul className="task-list">{items.map((task) => <TaskItem key={`${task.id}-${task.version}-${task.status}`} task={task} props={props} />)}</ul>
                </section>)}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
                {undated.length > 0 && <section className="task-group" aria-labelledby="group-undated">
                    <h2 id="group-undated" className="task-group-title">Sans date <span>{undated.length}</span></h2>
                    <ul className="task-list">{undated.map((task) => <TaskItem key={`${task.id}-${task.version}-${task.status}`} task={task} props={props} />)}</ul>
                </section>}
                <section className="surface" aria-labelledby="filters-title">
                    <h2 id="filters-title" className="sr-only">Filtrer</h2>
                    <MoreOptions label="Filtrer mes tâches" reveal={filters.statut !== 'open' || filters.priorite !== '' || filters.matiere !== '' || filters.du !== '' || filters.au !== ''}><form onSubmit={applyFilters} className="filter-bar">
                        <SelectField label="État" value={filter.statut} onChange={(event) => setFilter({ ...filter, statut: event.target.value })}>
                            <option value="open">À faire et en cours</option><option value="all">Tous les états</option>
                            {Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </SelectField>
                        <SelectField label="Priorité" value={filter.priorite} onChange={(event) => setFilter({ ...filter, priorite: event.target.value })}>
                            <option value="">Toutes</option>{Object.entries(priorities).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </SelectField>
                        <SelectField label="Matière" value={filter.matiere} onChange={(event) => setFilter({ ...filter, matiere: event.target.value })}>
                            <option value="">Toutes</option>{subjects.map((subject) => <option key={subject.value} value={subject.value}>{subject.label}</option>)}
                        </SelectField>
                        <TextField label="Échéance du" type="date" value={filter.du} error={errors.du} onChange={(event) => setFilter({ ...filter, du: event.target.value })} />
                        <TextField label="au" type="date" value={filter.au} error={errors.au} onChange={(event) => setFilter({ ...filter, au: event.target.value })} />
                        <Button type="submit" variant="secondary">Appliquer</Button>
                        <Link className="text-link" href="/taches">Réinitialiser</Link>
                    </form></MoreOptions>
                </section>

                <div className="inline-links"><Link className="text-link" href="/taches?vue=corbeille">Corbeille</Link>{teaching && <Link className="text-link" href="/taches/devoirs">Devoirs de mes groupes</Link>}<Link className="text-link" href="/planning">Planning</Link></div>
            </>}
        </CampusLayout>
    );
}
