import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, SelectField, TextField } from '../components/ui/Fields';
import { MoreOptions } from '../components/ui/MoreOptions';
import { useUnsavedWarning } from '../components/ui/useUnsavedWarning';

type Grade = { id: string; title: string; status: string; value: string | null; scale: string; coefficient: string; normalized: string | null; assessedOn: string; version: number | null; periodId: number | null; provenance: 'personal' | 'institutional'; retained: boolean };
type Subject = { id: number; name: string; coefficient: string | null; coefficientVersion: number | null; average: string | null; retained: number; total: number; watch: boolean; grades: Grade[] };
type Summary = { provenance: 'personal' | 'institutional'; subjects: Subject[]; threshold: string; general: { average: string | null; counted: number; total: number; missingCoefficients: number } };
type Props = {
    source: 'personnelle' | 'etablissement'; sources: { source: string; membershipId: number | null; label: string }[]; membershipId: number | null;
    periods: { value: string; label: string; current: boolean }[]; period: string | null; summary: Summary | null; statuses: Record<string, string>;
    personalSubjects: { id: number; name: string }[]; personalPeriods: { id: number; name: string; startsOn: string; endsOn: string }[]; today: string; teaching: boolean; administering: boolean;
};

const newKey = () => crypto.randomUUID();
const day = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeZone: 'UTC' });

function GradeForm({ props, grade, subjectId, onDone }: { props: Props; grade?: Grade; subjectId?: number; onDone?: () => void }) {
    const form = useForm({
        client_key: newKey(), expected_version: grade?.version ?? 0, title: grade?.title ?? '', personal_subject_id: subjectId ?? props.personalSubjects[0]?.id ?? ('' as number | ''),
        personal_period_id: (grade ? grade.periodId : props.period && props.period !== 'aucune' ? Number(props.period) : null) as number | null,
        status: grade?.status ?? 'graded', value: grade?.value ?? '', scale: grade?.scale ?? '20', coefficient: grade?.coefficient ?? '1', assessed_on: grade?.assessedOn ?? props.today,
    });
    useUnsavedWarning((form.data.title.trim() !== (grade?.title ?? '') || form.data.value !== (grade?.value ?? '')) && !form.processing, 'Votre note');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, value: data.status === 'graded' ? data.value : null }));
        const options = { preserveScroll: true, onSuccess: () => { if (!grade) { form.reset(); form.setData('client_key', newKey()); } onDone?.(); } };
        if (grade) form.patch(`/notes/${grade.id}`, options); else form.post('/notes', options);
    };
    return <form onSubmit={submit} className="form-narrow">
        <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
        <SelectField label="Matière" required value={form.data.personal_subject_id} error={form.errors.personal_subject_id} onChange={(event) => form.setData('personal_subject_id', Number(event.target.value))}>
            {props.personalSubjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}
        </SelectField>
        <TextField label="Intitulé" required maxLength={255} placeholder="Contrôle, dictée, exposé" value={form.data.title} error={form.errors.title} onChange={(event) => form.setData('title', event.target.value)} />
        {form.data.status === 'graded' && <TextField label="Note" required inputMode="decimal" value={form.data.value} error={form.errors.value} onChange={(event) => form.setData('value', event.target.value)} />}
        <TextField label="Barème" required inputMode="decimal" value={form.data.scale} error={form.errors.scale} onChange={(event) => form.setData('scale', event.target.value)} />
        <MoreOptions label="Plus d’options : date, période, coefficient, absence ou dispense" reveal={Boolean(grade) || Boolean(form.errors.personal_period_id || form.errors.assessed_on || form.errors.status || form.errors.coefficient)}>
        <SelectField label="Période (facultative)" value={form.data.personal_period_id ?? ''} error={form.errors.personal_period_id} onChange={(event) => form.setData('personal_period_id', event.target.value ? Number(event.target.value) : null)}>
            <option value="">Sans période</option>{props.personalPeriods.map((period) => <option key={period.id} value={period.id}>{period.name}</option>)}
        </SelectField>
        <TextField label="Date" type="date" required value={form.data.assessed_on} error={form.errors.assessed_on} onChange={(event) => form.setData('assessed_on', event.target.value)} />
        <SelectField label="État" value={form.data.status} error={form.errors.status} hint="À venir, absence, dispense et non rendu ne valent jamais zéro." onChange={(event) => form.setData('status', event.target.value)}>
            {Object.entries(props.statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </SelectField>
        <TextField label="Coefficient de l’évaluation" required inputMode="decimal" value={form.data.coefficient} error={form.errors.coefficient} onChange={(event) => form.setData('coefficient', event.target.value)} />
        </MoreOptions>
        <div className="form-actions"><Button type="submit" busy={form.processing}>{grade ? 'Enregistrer la correction' : 'Ajouter la note'}</Button>{onDone && <Button variant="secondary" onClick={onDone}>Annuler</Button>}</div>
    </form>;
}

/** Une note dans la liste d'une matière : la toucher fait apparaître « Corriger » et « Supprimer ». */
function GradeRow({ grade, subjectId, props, onDelete }: { grade: Grade; subjectId: number; props: Props; onDelete: (grade: Grade) => void }) {
    const personal = props.source === 'personnelle';
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const content = <>
        <span className="row-main"><span className="row-title">{grade.title}</span><span className="item-meta">{day.format(new Date(grade.assessedOn))}{grade.coefficient !== '1' && ` · coef. ${grade.coefficient}`}</span></span>
        <span className="grade-value">{grade.retained ? <>{grade.value}<small> / {grade.scale}</small></> : <small>{props.statuses[grade.status]}</small>}</span>
    </>;

    return <li className={open ? 'is-open' : ''}>
        {personal ? <button type="button" className="list-row" aria-expanded={open} onClick={() => { setOpen(!open); setEditing(false); }}>{content}</button> : <div className="list-row">{content}</div>}
        {open && <div className="list-row-panel">
            <p className="item-meta">{grade.retained ? `Soit ${grade.normalized} / 20.` : 'Non comptée : elle ne vaut pas zéro.'}</p>
            {!editing && <div className="form-actions"><Button variant="secondary" onClick={() => setEditing(true)}>Corriger</Button><Button variant="danger" onClick={() => onDelete(grade)}>Supprimer</Button></div>}
            {editing && <GradeForm props={props} grade={grade} subjectId={subjectId} onDone={() => { setEditing(false); setOpen(false); }} />}
        </div>}
    </li>;
}

/** Une matière : une ligne avec sa moyenne et son coefficient. La toucher affiche ses notes et « Modifier » pour le coefficient. */
function SubjectRow({ subject, props }: { subject: Subject; props: Props }) {
    const personal = props.source === 'personnelle';
    const [open, setOpen] = useState(false);
    const [editingCoefficient, setEditingCoefficient] = useState(false);
    const [coefficient, setCoefficient] = useState(subject.coefficient ?? '');
    const [deleting, setDeleting] = useState<Grade | null>(null);
    const canSetCoefficient = personal && subject.coefficientVersion !== null;
    const saveCoefficient = (event: FormEvent) => {
        event.preventDefault();
        router.patch(`/notes/matieres/${subject.id}/coefficient`, { expected_version: subject.coefficientVersion, coefficient }, { preserveScroll: true, onSuccess: () => setEditingCoefficient(false) });
    };

    return <li className={`subject-item ${open ? 'is-open' : ''}`}>
        <section aria-labelledby={`subject-${subject.id}`}>
            <h3 className="list-heading"><button type="button" className="list-row" aria-expanded={open} onClick={() => setOpen(!open)}>
                <span className="row-main">
                    <span id={`subject-${subject.id}`} className="row-title">{subject.name}</span>
                    <span className="item-meta">{subject.total === 0 ? 'Aucune note' : `${subject.total} note${subject.total > 1 ? 's' : ''}`} · coef. {subject.coefficient ?? 'non défini'}{subject.watch && ' · à surveiller'}</span>
                </span>
                <span className="grade-value">{subject.average ?? '—'}{subject.average !== null && <small> / 20</small>}</span>
            </button></h3>
            {open && <div className="list-row-panel">
                {canSetCoefficient && (editingCoefficient
                    ? <form className="form-actions" onSubmit={saveCoefficient}><TextField label="Coefficient de la matière" inputMode="decimal" value={coefficient} onChange={(event) => setCoefficient(event.target.value)} /><Button type="submit">Enregistrer</Button><Button variant="secondary" onClick={() => { setCoefficient(subject.coefficient ?? ''); setEditingCoefficient(false); }}>Annuler</Button></form>
                    : <div className="form-actions"><Button variant="secondary" onClick={() => setEditingCoefficient(true)}>Modifier le coefficient ({subject.coefficient})</Button></div>)}
                {subject.watch && <p className="item-meta">Moyenne sous {props.summary?.threshold} / 20 : matière à surveiller.</p>}
                {subject.grades.length === 0 ? <p className="item-meta">Aucune note pour cette période.</p>
                    : <ul className="row-stack">{subject.grades.map((grade) => <GradeRow key={`${grade.id}-${grade.version}`} grade={grade} subjectId={subject.id} props={props} onDelete={setDeleting} />)}</ul>}
            </div>}
        </section>
        <ConfirmDialog open={deleting !== null} title="Supprimer cette note ?" confirmLabel="Supprimer la note" onCancel={() => setDeleting(null)}
            onConfirm={() => deleting && router.delete(`/notes/${deleting.id}`, { data: { expected_version: deleting.version }, preserveScroll: true, onFinish: () => setDeleting(null) })}>
            La note « {deleting?.title} » sera supprimée et la moyenne recalculée.
        </ConfirmDialog>
    </li>;
}

export default function Grades(props: Props) {
    const { source, sources, membershipId, periods, period, summary, personalSubjects, teaching, administering } = props;
    const errors = usePage().props.errors as Record<string, string>;
    const personal = source === 'personnelle';
    const go = (next: { source: string; membershipId: number | null }, nextPeriod?: string) => router.get('/notes', {
        source: next.source, ...(next.membershipId ? { appartenance: next.membershipId } : {}), ...(nextPeriod ? { periode: nextPeriod } : {}) }, { preserveScroll: true });
    const current = sources.find((one) => one.source === source && one.membershipId === membershipId) ?? sources[0];
    return (
        <CampusLayout active="grades" title="Notes">
            <Head title="Notes" />
            <div className="page-heading"><h1>Mes notes</h1></div>
            {errors.reference && <StateMessage kind="error" title="Action non enregistrée">{errors.reference}</StateMessage>}

            <div className="filter-bar grades-filters">
                {sources.length > 1 && <SelectField label="Notes affichées" value={`${current.source}:${current.membershipId ?? ''}`} onChange={(event) => { const [nextSource, id] = event.target.value.split(':'); go({ source: nextSource, membershipId: id ? Number(id) : null }); }}>
                    {sources.map((one) => <option key={`${one.source}:${one.membershipId ?? ''}`} value={`${one.source}:${one.membershipId ?? ''}`}>{one.label}</option>)}
                </SelectField>}
                {periods.length > 0 && <SelectField label="Période" value={period ?? ''} onChange={(event) => go(current, event.target.value)}>
                    {periods.map((one) => <option key={one.value} value={one.value}>{one.label}</option>)}
                </SelectField>}
            </div>

            {summary !== null && <section className="average-card" aria-labelledby="general-title">
                <div className="average-top"><div>
                    <h2 id="general-title" className="section-kicker">Moyenne générale</h2>
                    {summary.general.average !== null
                        ? <span className="average-value">{summary.general.average}<small> / 20</small></span>
                        : <span className="item-meta">{summary.general.missingCoefficients > 0 ? `Pas encore calculée : ${summary.general.missingCoefficients} matière(s) sans coefficient.` : 'Pas encore calculée : aucune note chiffrée.'}</span>}
                </div></div>
                <p className="item-meta">Moyenne indicative sur {summary.general.counted} matière(s) notée(s) sur {summary.general.total}. {personal ? 'Vos notes sont privées.' : 'Notes publiées par vos enseignants : en cas d’erreur, adressez-vous à eux.'}</p>
                <details className="more-options"><summary>Comment est-elle calculée ?</summary><p className="item-meta">Chaque note est ramenée sur 20 et pondérée par son coefficient ; chaque moyenne de matière est pondérée par le coefficient de la matière. Une matière sans note ne compte pas ; une absence ou une dispense ne vaut jamais zéro. L’arrondi n’intervient qu’à l’affichage. Vos notes personnelles et celles d’un établissement ne sont jamais additionnées.</p></details>
            </section>}

            {personal && <section className="add-grade-section" aria-labelledby="add-title">
                <h2 id="add-title" className="sr-only">Ajouter une note</h2>
                {personalSubjects.length === 0 ? <StateMessage kind="empty" title="Ajoutez d’abord une matière" action={<Link className="button button-primary" href="/etudes/referentiels">Ajouter une matière</Link>}>Une note se range dans une matière.</StateMessage>
                    : <details className="more-options add-grade"><summary>+ Ajouter une note</summary><GradeForm props={props} /></details>}
            </section>}

            {summary === null ? <StateMessage kind="empty" title="Aucun résultat publié">Les résultats apparaîtront ici dès qu’un enseignant les publiera.</StateMessage>
                : summary.subjects.length === 0 ? <StateMessage kind="empty" title="Aucune matière">{personal ? 'Créez d’abord une matière.' : 'Aucune évaluation publiée pour cette période.'}</StateMessage>
                    : <section className="surface" aria-labelledby="subjects-title">
                        <div className="card-heading"><h2 id="subjects-title">Par matière</h2><span className="item-meta">Touchez une matière pour voir ses notes</span></div>
                        <ul className="row-stack subject-list">{summary.subjects.map((subject) => <SubjectRow key={`${subject.id}-${subject.coefficient}-${subject.grades.map((grade) => grade.version).join('.')}`} subject={subject} props={props} />)}</ul>
                    </section>}

            <div className="inline-links">
                {teaching && <Link className="text-link" href="/notes/evaluations">Évaluations de mes groupes</Link>}
                {administering && <Link className="text-link" href="/notes/coefficients">Coefficients des matières</Link>}
                <Link className="text-link" href="/etudes/referentiels">Matières et périodes</Link>
            </div>
        </CampusLayout>
    );
}
