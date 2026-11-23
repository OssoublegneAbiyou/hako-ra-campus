import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { ProvenanceBadge, StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, SelectField, TextField } from '../components/ui/Fields';
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
        <TextField label="Intitulé" required maxLength={255} value={form.data.title} error={form.errors.title} onChange={(event) => form.setData('title', event.target.value)} />
        <SelectField label="Matière" required value={form.data.personal_subject_id} error={form.errors.personal_subject_id} onChange={(event) => form.setData('personal_subject_id', Number(event.target.value))}>
            {props.personalSubjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}
        </SelectField>
        <SelectField label="Période (facultative)" value={form.data.personal_period_id ?? ''} error={form.errors.personal_period_id} onChange={(event) => form.setData('personal_period_id', event.target.value ? Number(event.target.value) : null)}>
            <option value="">Sans période</option>{props.personalPeriods.map((period) => <option key={period.id} value={period.id}>{period.name}</option>)}
        </SelectField>
        <TextField label="Date" type="date" required value={form.data.assessed_on} error={form.errors.assessed_on} onChange={(event) => form.setData('assessed_on', event.target.value)} />
        <SelectField label="État" value={form.data.status} error={form.errors.status} hint="À venir, absence, dispense et non rendu ne valent jamais zéro." onChange={(event) => form.setData('status', event.target.value)}>
            {Object.entries(props.statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </SelectField>
        {form.data.status === 'graded' && <TextField label="Note" required inputMode="decimal" value={form.data.value} error={form.errors.value} hint="Entre zéro et le barème. Zéro est une vraie note." onChange={(event) => form.setData('value', event.target.value)} />}
        <TextField label="Barème" required inputMode="decimal" value={form.data.scale} error={form.errors.scale} hint="Strictement positif, par exemple 20 ou 60." onChange={(event) => form.setData('scale', event.target.value)} />
        <TextField label="Coefficient de l’évaluation" required inputMode="decimal" value={form.data.coefficient} error={form.errors.coefficient} onChange={(event) => form.setData('coefficient', event.target.value)} />
        <div className="form-actions"><Button type="submit" busy={form.processing}>{grade ? 'Enregistrer la correction' : 'Ajouter la note'}</Button>{onDone && <Button variant="secondary" onClick={onDone}>Annuler</Button>}</div>
    </form>;
}

function SubjectBlock({ subject, props }: { subject: Subject; props: Props }) {
    const personal = props.source === 'personnelle';
    const [editing, setEditing] = useState<string | null>(null);
    const [deleting, setDeleting] = useState<Grade | null>(null);
    const [coefficient, setCoefficient] = useState(subject.coefficient ?? '');
    const threshold = props.summary?.threshold;
    return <section className="surface" aria-labelledby={`subject-${subject.id}`}>
        <h2 id={`subject-${subject.id}`}>{subject.name}</h2>
        <p className="item-meta">
            {subject.average !== null ? <>Moyenne indicative : <strong>{subject.average} / 20</strong> · calculée sur {subject.retained} note(s) retenue(s) parmi {subject.total} évaluation(s)</> : <>Aucune moyenne : aucune note chiffrée parmi {subject.total} évaluation(s). Cette matière n’abaisse pas la moyenne générale.</>}
            {' · '}{subject.coefficient !== null ? `coefficient de matière ${subject.coefficient}` : 'coefficient de matière non défini par l’établissement'}
        </p>
        {subject.watch && <StateMessage title="Matière à surveiller">Repère simple : la moyenne indicative est inférieure à {threshold} / 20 sur {subject.retained} note(s). Ce repère ne dit rien d’autre.</StateMessage>}
        {personal && subject.coefficientVersion !== null && <form className="form-actions" onSubmit={(event) => { event.preventDefault(); router.patch(`/notes/matieres/${subject.id}/coefficient`, { expected_version: subject.coefficientVersion, coefficient }, { preserveScroll: true }); }}>
            <TextField label="Coefficient de la matière" inputMode="decimal" value={coefficient} onChange={(event) => setCoefficient(event.target.value)} /><Button type="submit" variant="secondary">Enregistrer le coefficient</Button>
        </form>}
        {subject.grades.length === 0 ? <p className="item-meta">Aucune évaluation pour cette période.</p> : <ul className="plain-list">{subject.grades.map((grade) => <li key={grade.id}>
            <strong>{grade.title}</strong> <ProvenanceBadge source={grade.provenance} />
            <p className="item-meta">
                {day.format(new Date(grade.assessedOn))} · {grade.retained ? `${grade.value} / ${grade.scale} (soit ${grade.normalized} / 20)` : `${props.statuses[grade.status]} — non comptée, ne vaut pas zéro`} · coefficient {grade.coefficient}
            </p>
            {personal && <div className="form-actions"><Button variant="secondary" onClick={() => setEditing(editing === grade.id ? null : grade.id)}>Corriger</Button><Button variant="danger" onClick={() => setDeleting(grade)}>Supprimer</Button></div>}
            {personal && editing === grade.id && <GradeForm props={props} grade={grade} subjectId={subject.id} onDone={() => setEditing(null)} />}
        </li>)}</ul>}
        <ConfirmDialog open={deleting !== null} title="Supprimer cette note ?" confirmLabel="Supprimer la note" onCancel={() => setDeleting(null)}
            onConfirm={() => deleting && router.delete(`/notes/${deleting.id}`, { data: { expected_version: deleting.version }, preserveScroll: true, onFinish: () => setDeleting(null) })}>
            La note « {deleting?.title} » sera supprimée définitivement et les moyennes indicatives seront recalculées.
        </ConfirmDialog>
    </section>;
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
            <div className="page-heading"><p className="eyebrow">ESPACE D’ÉTUDES</p><h1>Mes notes</h1><p className="page-intro">Des moyennes indicatives, expliquées, calculées sur une seule source à la fois. Ce n’est pas un bulletin officiel.</p></div>
            {errors.reference && <StateMessage kind="error" title="Action non enregistrée">{errors.reference}</StateMessage>}

            <section className="surface" aria-labelledby="source-title">
                <h2 id="source-title">Source affichée</h2>
                <p className="section-description">Vos notes personnelles et les résultats publiés par un établissement ne sont jamais additionnés : une même évaluation ne peut pas compter deux fois.</p>
                <div className="filter-bar">
                    <SelectField label="Source" value={`${current.source}:${current.membershipId ?? ''}`} onChange={(event) => { const [nextSource, id] = event.target.value.split(':'); go({ source: nextSource, membershipId: id ? Number(id) : null }); }}>
                        {sources.map((one) => <option key={`${one.source}:${one.membershipId ?? ''}`} value={`${one.source}:${one.membershipId ?? ''}`}>{one.label}</option>)}
                    </SelectField>
                    {periods.length > 0 && <SelectField label="Période" value={period ?? ''} onChange={(event) => go(current, event.target.value)}>
                        {periods.map((one) => <option key={one.value} value={one.value}>{one.label}</option>)}
                    </SelectField>}
                </div>
                <p className="item-meta">{personal ? 'Notes saisies par vous, privées : ni enseignant ni administration ne les lisent.' : 'Résultats saisis et publiés par vos enseignants. Vous ne pouvez pas les modifier ; en cas d’erreur, adressez-vous à l’enseignant.'}</p>
            </section>

            {summary === null ? <StateMessage kind="empty" title="Aucun résultat publié">Les résultats apparaîtront ici dès qu’un enseignant les publiera.</StateMessage> : <>
                <section className="surface" aria-labelledby="general-title">
                    <h2 id="general-title">Moyenne générale indicative de la période</h2>
                    {summary.general.average !== null
                        ? <p><strong>{summary.general.average} / 20</strong> — calculée sur {summary.general.counted} matière(s) disposant d’une moyenne parmi {summary.general.total}.</p>
                        : <p>{summary.general.missingCoefficients > 0
                            ? `Non calculée : le coefficient de ${summary.general.missingCoefficients} matière(s) n’est pas défini par l’établissement.`
                            : 'Non calculée : aucune matière ne dispose encore d’une note chiffrée.'}</p>}
                    <p className="item-meta">Calcul : chaque note est ramenée sur 20, pondérée par le coefficient de l’évaluation, puis chaque moyenne de matière par le coefficient de la matière. Les matières sans note ne comptent pas. L’arrondi à deux décimales n’intervient qu’à l’affichage. Aucune moyenne annuelle n’est calculée.</p>
                </section>
                {summary.subjects.map((subject) => <SubjectBlock key={`${subject.id}-${subject.coefficient}-${subject.grades.map((grade) => grade.version).join('.')}`} subject={subject} props={props} />)}
                {summary.subjects.length === 0 && <StateMessage kind="empty" title="Aucune matière">{personal ? 'Créez d’abord une matière personnelle dans vos référentiels.' : 'Aucune évaluation publiée pour cette période.'}</StateMessage>}
            </>}

            {personal && <section className="surface" aria-labelledby="add-title">
                <h2 id="add-title">Ajouter une note personnelle</h2>
                {personalSubjects.length === 0 ? <StateMessage kind="empty" title="Aucune matière personnelle" action={<Link className="button button-secondary" href="/etudes/referentiels">Ouvrir mes référentiels</Link>}>Une note se rattache à une de vos matières.</StateMessage>
                    : <GradeForm props={props} />}
            </section>}
            <div className="inline-links">
                {teaching && <Link className="text-link" href="/notes/evaluations">Évaluations de mes groupes</Link>}
                {administering && <Link className="text-link" href="/notes/coefficients">Coefficients des matières</Link>}
                <Link className="text-link" href="/etudes/referentiels">Référentiels</Link>
            </div>
        </CampusLayout>
    );
}
