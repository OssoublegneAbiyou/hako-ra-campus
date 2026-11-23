import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { AttachmentList, type AttachmentItem, type AttachmentLimits } from '../components/AttachmentList';
import { Button } from '../components/ui/Button';
import { CheckboxField, SelectField, TextAreaField, TextField } from '../components/ui/Fields';
import { ProvenanceBadge, StateMessage } from '../components/ui/Feedback';
import { OutboxPanel } from '../components/OutboxPanel';
import { useUnsavedWarning } from '../components/ui/useUnsavedWarning';
import { canDraft, saveDraft } from '../offline/outbox';
import type { CampusProps } from '../types';

type Course = { title: string; startsAt: string; endsAt: string; timezone: string; cancelled: boolean; provenance: 'personal' | 'institutional' | null; stillAccessible: boolean };
type Existing = { version: number; status: string; statusLabel: string; summary: string | null; workToDo: string | null; pointsToReview: string | null; understanding: number | null; task: { title: string } | null; updatedAt: string };
type Props = { occurrenceId: string; course: Course; checkIn: Existing | null; canWrite: boolean; notStarted: boolean; understandingLabels: Record<string, string>; attachments: AttachmentItem[]; attachmentLimits: AttachmentLimits };
type Action = 'save' | 'nothing' | 'defer' | 'ignore';

export default function CheckIn({ occurrenceId, course, checkIn, canWrite, notStarted, understandingLabels, attachments, attachmentLimits }: Props) {
    const form = useForm({
        action: 'save' as Action, expected_version: checkIn?.version ?? 0, summary: checkIn?.summary ?? '', work_to_do: checkIn?.workToDo ?? '',
        points_to_review: checkIn?.pointsToReview ?? '', understanding: checkIn?.understanding ? String(checkIn.understanding) : '', create_task: false, task_title: '',
    });
    const errors = form.errors as Record<string, string>;
    const filled = checkIn !== null && ['completed', 'nothing'].includes(checkIn.status);
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'full', timeStyle: 'short', timeZone: course.timezone });
    const accountKey = usePage<CampusProps>().props.auth.accountKey ?? null;
    const [notice, setNotice] = useState<string | null>(null);
    const [kept, setKept] = useState(false);
    const dirty = form.isDirty && !form.processing && !form.wasSuccessful && !kept;
    useUnsavedWarning(dirty, 'Votre retour sur ce cours');
    // Sans réseau : le retour s'enregistre comme brouillon sur un appareil personnel, à partir de la version affichée ici.
    // Les autres choix (rien à signaler, plus tard, ignorer) et la création d'une tâche liée se font en ligne.
    async function keep(action: Action) {
        if (action !== 'save') { setNotice('Pas de réseau : ce choix s’enregistre en ligne. Votre saisie reste affichée sur cet écran.'); return; }
        if (!canDraft(accountKey)) { setNotice('Pas de réseau : le retour n’est pas enregistré, et cet appareil ne conserve pas de brouillon. Votre saisie reste affichée : renvoyez-la quand le réseau sera revenu.'); return; }
        const { operation, errors } = await saveDraft({ accountKey, type: 'check_in.save', targetId: occurrenceId, baseVersion: checkIn?.version ?? 0, label: course.title,
            fields: { summary: form.data.summary, work_to_do: form.data.work_to_do, points_to_review: form.data.points_to_review, understanding: form.data.understanding } });
        setKept(operation !== null);
        setNotice(operation ? `Retour enregistré comme brouillon sur cet appareil. Il sera envoyé au retour du réseau.${form.data.create_task ? ' La tâche liée devra être créée en ligne.' : ''}` : errors.join(' '));
    }
    function send(action: Action) {
        setNotice(null);
        if (!navigator.onLine) { void keep(action); return; }
        form.transform((data) => ({ ...data, action, understanding: data.understanding === '' ? null : Number(data.understanding) }));
        form.put(`/planning/occurrences/${occurrenceId}/retour`, { preserveScroll: true, onNetworkError: () => { void keep(action); return false; } });
    }
    function submit(event: FormEvent) {
        event.preventDefault();
        send('save');
    }
    return (
        <CampusLayout active="planning" title="Retour après cours">
            <Head title="Retour après cours" />
            <div className="page-heading"><p className="eyebrow">RETOUR PRIVÉ APRÈS COURS</p><h1>{course.title}</h1><p className="page-intro">{when.format(new Date(course.startsAt))} – {new Intl.DateTimeFormat('fr', { timeStyle: 'short', timeZone: course.timezone }).format(new Date(course.endsAt))}. {course.provenance && <ProvenanceBadge source={course.provenance} />}</p></div>
            <StateMessage title="Ce retour est privé">Vous seul pouvez le lire et le modifier. Ni vos enseignants, ni l’administration de votre établissement, ni la plateforme n’y ont accès. Tous les champs sont facultatifs et rien n’est noté ni classé.</StateMessage>
            {course.cancelled && <StateMessage kind="info" title="Ce cours a été annulé">Aucun retour n’est attendu pour un cours annulé.</StateMessage>}
            {!course.stillAccessible && <StateMessage kind="info" title="Vous n’avez plus accès à ce cours">Votre retour reste dans votre espace personnel.</StateMessage>}
            {notStarted && <StateMessage kind="info" title="Le retour s’ouvre au début du cours" action={<Link className="text-link" href="/planning">Retour au planning</Link>} />}
            {(errors.reference || errors.expected_version) && <StateMessage kind="error" title="Retour non enregistré">{errors.reference ?? errors.expected_version} Votre saisie est conservée sur cet écran.</StateMessage>}
            {notice && <StateMessage kind="offline" title="Hors connexion">{notice}</StateMessage>}
            <OutboxPanel accountKey={accountKey} compact onSynced={() => router.reload()} />
            {checkIn && <p className="item-meta" role="status">État actuel : {checkIn.statusLabel} · dernière modification le {new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short', timeZone: course.timezone }).format(new Date(checkIn.updatedAt))}.{checkIn.task && ` Tâche liée : « ${checkIn.task.title} ».`}</p>}

            {canWrite && <section className="surface" aria-labelledby="form-title">
                <h2 id="form-title">{filled ? 'Modifier mon retour' : 'Mon retour sur ce cours'}</h2>
                <form onSubmit={submit} noValidate className="form-narrow">
                    <TextAreaField label="Résumé personnel du cours" hint="Ce que vous retenez, avec vos mots." rows={3} maxLength={2000} value={form.data.summary} onChange={(event) => form.setData('summary', event.target.value)} error={errors.summary} />
                    <TextAreaField label="Travail à effectuer" rows={2} maxLength={2000} value={form.data.work_to_do} onChange={(event) => form.setData('work_to_do', event.target.value)} error={errors.work_to_do} />
                    {!checkIn?.task && <>
                        <CheckboxField label="Créer une tâche à partir de ce travail" hint="La tâche est rattachée à ce cours. Elle n’est créée qu’une fois." checked={form.data.create_task} onChange={(event) => form.setData('create_task', event.target.checked)} />
                        {form.data.create_task && <TextField label="Titre de la tâche (facultatif)" hint="Par défaut, la première ligne du travail à effectuer." maxLength={255} value={form.data.task_title} onChange={(event) => form.setData('task_title', event.target.value)} error={errors.task_title} />}
                    </>}
                    <TextAreaField label="Points à revoir" rows={2} maxLength={2000} value={form.data.points_to_review} onChange={(event) => form.setData('points_to_review', event.target.value)} error={errors.points_to_review} />
                    <SelectField label="Mon niveau de compréhension" hint="Pour vous seul : il n’est transmis à personne." value={form.data.understanding} onChange={(event) => form.setData('understanding', event.target.value)} error={errors.understanding}><option value="">Non renseigné</option>{Object.entries(understandingLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</SelectField>
                    <div className="form-actions">
                        <Button type="submit" busy={form.processing}>Enregistrer mon retour</Button>
                        <Button variant="secondary" disabled={form.processing} onClick={() => send('nothing')}>Rien à signaler</Button>
                    </div>
                    {!filled && <div className="form-actions">
                        <Button variant="secondary" disabled={form.processing} onClick={() => send('defer')}>Plus tard</Button>
                        <Button variant="secondary" disabled={form.processing} onClick={() => send('ignore')}>Ignorer ce cours</Button>
                    </div>}
                </form>
            </section>}
            {checkIn && <section className="surface" aria-labelledby="files-title"><h2 id="files-title">Pièces jointes privées</h2><AttachmentList items={attachments} uploadUrl={`/planning/occurrences/${occurrenceId}/retour/pieces-jointes`} limits={attachmentLimits} canUpload label="Photos ou documents de ce cours, visibles par vous seul" /></section>}
            <div className="inline-links"><Link className="text-link" href="/retours">Mes retours</Link><Link className="text-link" href="/planning">Planning</Link></div>
        </CampusLayout>
    );
}
