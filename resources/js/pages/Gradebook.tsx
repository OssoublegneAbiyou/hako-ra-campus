import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { Pagination } from '../components/ui/Collection';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, SelectField, TextField } from '../components/ui/Fields';

type Assessment = { id: string; title: string; group: string | null; subject: string | null; period: string | null; periodId: number; scale: string; coefficient: string; assessedOn: string; status: string; statusLabel: string; version: number };
type Learner = { userId: number; name: string; identifier: string | null; enrolled: boolean; status: string; value: string };
type Props = {
    assignments: { groupId: number; subjectId: number; label: string }[]; periods: { id: number; name: string; startsOn: string; endsOn: string }[]; assessments: Assessment[];
    pagination: { page: number; total: number; previous: string | null; next: string | null }; selected: (Assessment & { roster: Learner[] }) | null;
    statuses: Record<string, string>; missingLabel: string; canWrite: boolean; today: string;
};

const newKey = () => crypto.randomUUID();
const day = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeZone: 'UTC' });

function Selected({ item, props }: { item: NonNullable<Props['selected']>; props: Props }) {
    const published = item.status === 'published';
    const closed = item.status === 'withdrawn';
    const [rows, setRows] = useState(item.roster.map((learner) => ({ ...learner })));
    const [reason, setReason] = useState('');
    const [withdrawing, setWithdrawing] = useState(false);
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;
    const meta = useForm({ expected_version: item.version, period_id: item.periodId, title: item.title, scale: item.scale, coefficient: item.coefficient, assessed_on: item.assessedOn, reason: '' });
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    const saveResults = (event: FormEvent) => {
        event.preventDefault();
        // Seules les lignes renseignées sont envoyées : une ligne laissée « note manquante » ne devient jamais zéro.
        const results = rows.filter((row) => row.status !== 'missing').map((row) => ({ user_id: row.userId, status: row.status, value: row.status === 'graded' ? row.value : null }));
        router.put(`/notes/evaluations/${item.id}/resultats`, { expected_version: item.version, reason, results }, options);
    };
    const update = (index: number, change: Partial<Learner>) => setRows(rows.map((row, position) => position === index ? { ...row, ...change } : row));
    return <section className="surface" aria-labelledby="selected-title">
        <h2 id="selected-title">{item.title}</h2>
        <p className="item-meta">{item.group} · {item.subject} · {item.period} · {day.format(new Date(item.assessedOn))} · sur {item.scale} · coefficient {item.coefficient} · {item.statusLabel}</p>
        {closed && <StateMessage title="Évaluation retirée">Elle n’est plus visible des apprenants et ne compte plus dans les moyennes indicatives. L’historique des résultats est conservé.</StateMessage>}
        {published && <StateMessage title="Évaluation publiée">Toute correction exige un motif, est journalisée et informe les apprenants concernés.</StateMessage>}
        {Object.keys(errors).length > 0 && <ErrorSummary errors={Object.entries(errors).map(([id, message]) => ({ id, message: String(message) }))} />}

        {props.canWrite && !closed && <>
            <form onSubmit={saveResults}>
                <h3>Résultats</h3>
                <p className="section-description">Laissez « {props.missingLabel} » tant qu’aucun résultat n’est connu. Absence, dispense et non rendu ne valent jamais zéro ; zéro est une vraie note.</p>
                <ul className="plain-list">{rows.map((row, index) => <li key={row.userId}>
                    <strong>{row.name}</strong>{row.identifier && <span className="item-meta"> · {row.identifier}</span>}{!row.enrolled && <span className="item-meta"> · n’est plus inscrit au groupe</span>}
                    <div className="filter-bar">
                        <SelectField label={`État — ${row.name}`} value={row.status} onChange={(event) => update(index, { status: event.target.value })}>
                            <option value="missing">{props.missingLabel}</option>{Object.entries(props.statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </SelectField>
                        {row.status === 'graded' && <TextField label={`Note sur ${item.scale} — ${row.name}`} inputMode="decimal" value={row.value} error={errors[`results.${index}.value`]} onChange={(event) => update(index, { value: event.target.value })} />}
                    </div>
                </li>)}</ul>
                {rows.length === 0 && <StateMessage kind="empty" title="Aucun apprenant inscrit dans ce groupe" />}
                {published && <TextField label="Motif de la correction" required maxLength={500} value={reason} error={errors.reason} onChange={(event) => setReason(event.target.value)} />}
                <div className="form-actions"><Button type="submit" busy={busy}>{published ? 'Enregistrer la correction' : 'Enregistrer les résultats'}</Button>
                    {!published && <Button variant="secondary" busy={busy} onClick={() => router.post(`/notes/evaluations/${item.id}/publication`, { expected_version: item.version }, options)}>Publier aux apprenants</Button>}
                    <Button variant="danger" onClick={() => setWithdrawing(true)}>Retirer l’évaluation</Button></div>
            </form>

            <form onSubmit={(event) => { event.preventDefault(); meta.patch(`/notes/evaluations/${item.id}`, { preserveScroll: true }); }} className="form-narrow">
                <h3>Barème, coefficient, date</h3>
                <TextField label="Intitulé" required maxLength={255} value={meta.data.title} error={meta.errors.title} onChange={(event) => meta.setData('title', event.target.value)} />
                <SelectField label="Période" value={meta.data.period_id} error={meta.errors.period_id} onChange={(event) => meta.setData('period_id', Number(event.target.value))}>
                    {props.periods.map((period) => <option key={period.id} value={period.id}>{period.name}</option>)}
                </SelectField>
                <TextField label="Date" type="date" required value={meta.data.assessed_on} error={meta.errors.assessed_on} onChange={(event) => meta.setData('assessed_on', event.target.value)} />
                <TextField label="Barème" required inputMode="decimal" value={meta.data.scale} error={meta.errors.scale} onChange={(event) => meta.setData('scale', event.target.value)} />
                <TextField label="Coefficient" required inputMode="decimal" value={meta.data.coefficient} error={meta.errors.coefficient} onChange={(event) => meta.setData('coefficient', event.target.value)} />
                {published && <TextField label="Motif de la correction" required maxLength={500} value={meta.data.reason} error={meta.errors.reason} onChange={(event) => meta.setData('reason', event.target.value)} />}
                <div className="form-actions"><Button type="submit" variant="secondary" busy={meta.processing}>Enregistrer</Button></div>
            </form>
        </>}
        {(!props.canWrite || closed) && <ul className="plain-list">{item.roster.map((learner) => <li key={learner.userId}><strong>{learner.name}</strong><p className="item-meta">{learner.status === 'graded' ? `${learner.value} / ${item.scale}` : learner.status === 'missing' ? props.missingLabel : props.statuses[learner.status]}</p></li>)}</ul>}
        <ConfirmDialog open={withdrawing} title="Retirer cette évaluation ?" confirmLabel="Retirer l’évaluation" busy={busy} onCancel={() => setWithdrawing(false)}
            onConfirm={() => router.delete(`/notes/evaluations/${item.id}`, { data: { expected_version: item.version, reason }, ...options, onSuccess: () => setWithdrawing(false) })}>
            L’évaluation « {item.title} » ne sera plus visible des apprenants et ne comptera plus dans leurs moyennes indicatives. Elle ne pourra pas être publiée de nouveau.
            {published && ' Indiquez d’abord le motif dans le champ « Motif de la correction ».'}
        </ConfirmDialog>
    </section>;
}

export default function Gradebook(props: Props) {
    const { assignments, periods, assessments, pagination, selected, canWrite, today } = props;
    const create = useForm({ client_key: newKey(), assignment: assignments[0] ? `${assignments[0].groupId}:${assignments[0].subjectId}` : '', period_id: periods[0]?.id ?? ('' as number | ''), title: '', scale: '20', coefficient: '1', assessed_on: today });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const [group, subject] = create.data.assignment.split(':').map(Number);
        create.transform((data) => ({ client_key: data.client_key, group_id: group, subject_id: subject, period_id: data.period_id, title: data.title, scale: data.scale, coefficient: data.coefficient, assessed_on: data.assessed_on }));
        create.post('/notes/evaluations', { preserveScroll: true, onSuccess: () => { create.reset('title'); create.setData('client_key', newKey()); } });
    };
    return (
        <CampusLayout active="grades" title="Évaluations">
            <Head title="Évaluations" />
            <div className="page-heading"><p className="eyebrow">ENSEIGNEMENT</p><h1>Évaluations de mes groupes</h1><p className="page-intro">Créez une évaluation pour un groupe et une matière qui vous sont affectés, saisissez les résultats, puis publiez. Chaque apprenant ne voit que son propre résultat.</p></div>
            {!canWrite && <StateMessage title="Saisie indisponible">La saisie et la publication des résultats exigent une vérification renforcée du compte, qui n’est pas encore disponible.</StateMessage>}

            {selected && <Selected key={`${selected.id}-${selected.version}`} item={selected} props={props} />}

            <section className="surface" aria-labelledby="list-title">
                <h2 id="list-title">Évaluations</h2>
                {assessments.length === 0 ? <StateMessage kind="empty" title="Aucune évaluation" />
                    : <ul className="plain-list">{assessments.map((item) => <li key={item.id}>
                        <strong>{item.title}</strong>
                        <p className="item-meta">{item.group} · {item.subject} · {item.period} · {day.format(new Date(item.assessedOn))} · sur {item.scale} · coefficient {item.coefficient} · {item.statusLabel}</p>
                        <Link className="text-link" href={`/notes/evaluations?evaluation=${item.id}`} preserveScroll>Ouvrir</Link>
                    </li>)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>

            {canWrite && <section className="surface" aria-labelledby="create-title">
                <h2 id="create-title">Nouvelle évaluation</h2>
                {assignments.length === 0 || periods.length === 0 ? <StateMessage kind="empty" title="Création impossible">Il faut une affectation en cours et une période définie pour l’année.</StateMessage>
                    : <form onSubmit={submit} className="form-narrow">
                        <ErrorSummary errors={Object.entries(create.errors).map(([id, message]) => ({ id, message: String(message) }))} />
                        <SelectField label="Groupe et matière" value={create.data.assignment} onChange={(event) => create.setData('assignment', event.target.value)}>
                            {assignments.map((pair) => <option key={`${pair.groupId}:${pair.subjectId}`} value={`${pair.groupId}:${pair.subjectId}`}>{pair.label}</option>)}
                        </SelectField>
                        <SelectField label="Période" value={create.data.period_id} error={create.errors.period_id} onChange={(event) => create.setData('period_id', Number(event.target.value))}>
                            {periods.map((period) => <option key={period.id} value={period.id}>{period.name}</option>)}
                        </SelectField>
                        <TextField label="Intitulé" required maxLength={255} value={create.data.title} error={create.errors.title} onChange={(event) => create.setData('title', event.target.value)} />
                        <TextField label="Date" type="date" required value={create.data.assessed_on} error={create.errors.assessed_on} onChange={(event) => create.setData('assessed_on', event.target.value)} />
                        <TextField label="Barème" required inputMode="decimal" value={create.data.scale} error={create.errors.scale} hint="Strictement positif." onChange={(event) => create.setData('scale', event.target.value)} />
                        <TextField label="Coefficient" required inputMode="decimal" value={create.data.coefficient} error={create.errors.coefficient} onChange={(event) => create.setData('coefficient', event.target.value)} />
                        <div className="form-actions"><Button type="submit" busy={create.processing}>Créer en brouillon</Button></div>
                    </form>}
            </section>}
            <div className="inline-links"><Link className="text-link" href="/notes">Mes notes</Link><Link className="text-link" href="/taches/devoirs">Devoirs de mes groupes</Link></div>
        </CampusLayout>
    );
}
