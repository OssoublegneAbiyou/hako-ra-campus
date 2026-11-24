import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { DueFields } from './DueFields';
import { Icon } from './Icon';
import { isoWeekday, shiftDate, type QuickAddKind } from './quickAddEvents';
import { Button } from './ui/Button';
import { StateMessage } from './ui/Feedback';
import { SelectField, TextField } from './ui/Fields';
import type { CampusProps, QuickAddData } from '../types';

const KINDS: [QuickAddKind, string, string][] = [['course', 'Cours', 'Ajouter le cours'], ['task', 'Tâche', 'Ajouter la tâche'], ['grade', 'Note', 'Ajouter la note']];
const DAYS: [string, string][] = [['L', 'Lundi'], ['M', 'Mardi'], ['M', 'Mercredi'], ['J', 'Jeudi'], ['V', 'Vendredi'], ['S', 'Samedi'], ['D', 'Dimanche']];
/** Un cours hebdomadaire ajouté ici court sur 26 semaines ; la date de fin se change ensuite dans le planning. */
const WEEKLY_SPAN_DAYS = 181;

type Errors = Record<string, string>;

/**
 * Ajout rapide d'un cours, d'une tâche ou d'une note dans son espace individuel : l'essentiel seulement, le reste dans les écrans complets.
 * Chaque envoi passe par la route habituelle de l'écran concerné, avec les mêmes contrôles ; une clé de demande évite tout doublon.
 */
export function QuickAdd({ open, kind, due, onKind, onClose }: { open: boolean; kind: QuickAddKind; due?: string; onKind: (kind: QuickAddKind) => void; onClose: () => void }) {
    const { quickAdd, campus } = usePage<CampusProps>().props;
    const dialog = useRef<HTMLDialogElement>(null);
    const membershipId = campus.context.membershipId;
    useEffect(() => {
        const element = dialog.current;
        if (!open || !element) return;
        const previous = document.activeElement;
        element.showModal();

        return () => {
            element.close();
            if (previous instanceof HTMLElement && previous.isConnected) previous.focus();
        };
    }, [open]);
    // Matières, période et fuseau du compte : demandés à l'ouverture, et de nouveau si le contexte change.
    useEffect(() => {
        if (open) router.reload({ only: ['quickAdd'] });
    }, [open, membershipId]);

    return <dialog ref={dialog} className="quick-add" aria-labelledby="quick-add-title" onCancel={(event) => { event.preventDefault(); onClose(); }}>
        <div className="quick-add-handle" aria-hidden="true" />
        <div className="quick-add-header"><h2 id="quick-add-title">Ajouter</h2><button type="button" className="icon-button" aria-label="Fermer" onClick={onClose}><Icon name="close" strokeWidth={2.5} /></button></div>
        <div className="segmented" role="group" aria-label="Que veux-tu ajouter ?">{KINDS.map(([value, label]) => <button type="button" key={value} aria-pressed={kind === value} onClick={() => onKind(value)}>{label}</button>)}</div>
        {open && (quickAdd
            ? <QuickAddForm key={`${kind}-${due ?? ''}`} kind={kind} due={due} data={quickAdd} onClose={onClose} />
            : <StateMessage kind="loading" title="Chargement…" />)}
    </dialog>;
}

function QuickAddForm({ kind, due: presetDue, data, onClose }: { kind: QuickAddKind; due?: string; data: QuickAddData; onClose: () => void }) {
    const [key] = useState(() => crypto.randomUUID());
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [title, setTitle] = useState('');
    const [days, setDays] = useState<number[]>([isoWeekday(data.today)]);
    const [date, setDate] = useState(data.today);
    const [from, setFrom] = useState('08:00');
    const [until, setUntil] = useState('09:00');
    const [weekly, setWeekly] = useState(true);
    const [due, setDue] = useState(presetDue ?? '');
    const [subject, setSubject] = useState(data.subjects.length === 1 ? String(data.subjects[0].id) : '');
    const [value, setValue] = useState('');
    const [scale, setScale] = useState('20');
    const lastDay = shiftDate(data.today, WEEKLY_SPAN_DAYS);
    const send = (url: string, payload: Record<string, unknown>) => {
        setBusy(true);
        router.post(url, payload as Parameters<typeof router.post>[1], { preserveScroll: true, onSuccess: onClose, onError: (found) => setErrors(found as Errors), onFinish: () => setBusy(false) });
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        setErrors({});
        if (kind === 'task') {
            send('/taches', { client_key: key, title, due_at: due === '' ? null : due });
        } else if (kind === 'grade') {
            send('/notes', { client_key: key, title, personal_subject_id: subject === '' ? null : Number(subject), personal_period_id: data.periodId, status: 'graded', value, scale, coefficient: '1', assessed_on: data.today });
        } else {
            if (weekly && days.length === 0) { setErrors({ weekdays: 'Choisis au moins un jour.' }); return; }
            send('/planning/series', { request_key: key, title, kind: weekly ? 'weekly' : 'once', valid_from: weekly ? data.today : date, valid_until: weekly ? lastDay : date,
                start_time: from, end_time: until, timezone: data.timezone, weekdays: weekly ? days : [isoWeekday(date)] });
        }
    };
    const cta = KINDS.find(([found]) => found === kind)?.[2] ?? 'Ajouter';
    const overlap = Boolean(errors.confirm_conflicts || errors.conflict_reason || (errors.conflict ?? '').startsWith('Chevauchement'));
    const other = Object.entries(errors).filter(([name]) => !['title', 'start_time', 'end_time', 'weekdays', 'valid_from', 'due_at', 'personal_subject_id', 'value', 'scale', 'confirm_conflicts', 'conflict_reason'].includes(name) && !(name === 'conflict' && overlap)).map(([, message]) => message);

    if (kind === 'course' && !data.individual) {
        return <>
            <StateMessage title="Les cours de ton établissement viennent de son planning">Pour ajouter un cours à ton planning personnel, passe dans ton espace « Perso ».</StateMessage>
            <Button onClick={() => router.post('/contexte', { membership_id: null }, { preserveScroll: true })}>Passer en Perso</Button>
        </>;
    }
    if (kind === 'grade' && data.subjects.length === 0) {
        return <>
            <StateMessage title="Ajoute d’abord une matière">Une note se range dans une matière : crée la première, puis reviens ici.</StateMessage>
            <Link className="button button-primary" href="/etudes/referentiels">Ajouter une matière</Link>
        </>;
    }

    return <form onSubmit={submit} noValidate>
        {other.length > 0 && <StateMessage kind="error" title="Ça n’a pas été enregistré">{other.join(' ')}</StateMessage>}
        {overlap && <StateMessage kind="error" title="Ce cours en chevauche un autre" action={<Link className="text-link" href="/planning#series-form">Ouvrir le planning</Link>}>Change l’horaire, ou confirme le chevauchement depuis le planning.</StateMessage>}
        {kind === 'course' && <>
            <TextField label="Quel cours ?" required maxLength={255} value={title} error={errors.title} onChange={(event) => setTitle(event.target.value)} />
            {weekly
                ? <fieldset className="day-picker"><legend>Quel jour ?</legend><div>{DAYS.map(([short, name], index) => <button type="button" key={name} aria-label={name} aria-pressed={days.includes(index + 1)}
                    onClick={() => setDays(days.includes(index + 1) ? days.filter((day) => day !== index + 1) : [...days, index + 1])}>{short}</button>)}</div>{errors.weekdays && <p className="field-error" role="alert">{errors.weekdays}</p>}</fieldset>
                : <TextField label="Quel jour ?" type="date" required value={date} error={errors.valid_from} onChange={(event) => setDate(event.target.value)} />}
            <div className="quick-add-pair">
                <TextField label="De" type="time" required value={from} error={errors.start_time} onChange={(event) => setFrom(event.target.value)} />
                <TextField label="À" type="time" required value={until} error={errors.end_time} onChange={(event) => setUntil(event.target.value)} />
            </div>
            <div className="switch-row"><label htmlFor="quick-add-weekly">Chaque semaine{weekly && <small>Jusqu’au {new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(lastDay + 'T12:00:00Z'))}.</small>}</label><input id="quick-add-weekly" type="checkbox" checked={weekly} onChange={(event) => setWeekly(event.target.checked)} /></div>
            <Link className="text-link" href="/planning#series-form">Plus d’options : salle, matière, vacances</Link>
        </>}
        {kind === 'task' && <>
            <TextField label="Qu’est-ce que tu dois faire ?" required maxLength={255} placeholder="Exercices, fiche, révision" value={title} error={errors.title} onChange={(event) => setTitle(event.target.value)} />
            <DueFields value={due} today={data.today} error={errors.due_at} onChange={setDue} />
        </>}
        {kind === 'grade' && <>
            <SelectField label="Quelle matière ?" required value={subject} error={errors.personal_subject_id} onChange={(event) => setSubject(event.target.value)}>
                <option value="">Choisir</option>{data.subjects.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </SelectField>
            <TextField label="Quelle évaluation ?" required maxLength={255} placeholder="Contrôle, dictée, exposé" value={title} error={errors.title} onChange={(event) => setTitle(event.target.value)} />
            <div className="quick-add-pair">
                <TextField label="Ta note" inputMode="decimal" required placeholder="14" value={value} error={errors.value} onChange={(event) => setValue(event.target.value)} />
                <TextField label="Sur" inputMode="decimal" required value={scale} error={errors.scale} onChange={(event) => setScale(event.target.value)} />
            </div>
        </>}
        <Button type="submit" busy={busy}>{cta}</Button>
    </form>;
}
