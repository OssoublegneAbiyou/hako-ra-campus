import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { Pagination } from '../components/ui/Collection';
import { CheckboxField, SelectField, TextAreaField } from '../components/ui/Fields';
import { StateMessage } from '../components/ui/Feedback';

type Pager = { page: number; total: number; previous: string | null; next: string | null };
type Batch = { id: string; name: string; status: string; statusLabel: string; author: string | null; createdAt: string; totalRows: number; processedRows: number; counts: Record<string, number | Record<string, number>>; failure: string | null; rawPurged: boolean };
type Issue = { id: number; sheet: string; line: number | null; column: string | null; severity: 'error' | 'conflict' | 'warning'; severityLabel: string; message: string; value: string | null };
type Proposal = { line: number; label: string; candidates: { id: string; label: string }[] };
type Selected = Batch & { appliedProgress: number | null; proposals: Proposal[]; issues: Issue[]; issuesPagination: Pager };
type Props = {
    institution: string; year: string; timezone: string; limits: { megabytes: number; rows: number; retentionDays: number };
    columns: { letter: string; name: string; help: string }[]; batches: Batch[]; pagination: Pager; selected: Selected | null;
};
const count = (batch: Batch, key: string) => (typeof batch.counts[key] === 'number' ? (batch.counts[key] as number) : 0);

export default function ScheduleImport({ institution, year, timezone, limits, columns, batches, pagination, selected }: Props) {
    const upload = useForm<{ file: File | null; import_key: string }>({ file: null, import_key: crypto.randomUUID() });
    const [decisions, setDecisions] = useState<Record<number, string>>({});
    const [confirmConflicts, setConfirmConflicts] = useState(false);
    const [conflictReason, setConflictReason] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone });
    const running = selected !== null && ['uploaded', 'analyzing', 'applying'].includes(selected.status);
    useEffect(() => {
        if (!running) return;
        const timer = window.setInterval(() => router.reload({ only: ['selected', 'batches'] }), 3000);
        return () => window.clearInterval(timer);
    }, [running]);
    function submit(event: FormEvent) {
        event.preventDefault();
        upload.post('/planning/import', { forceFormData: true, onSuccess: () => upload.setData({ file: null, import_key: crypto.randomUUID() }) });
    }
    function apply() {
        if (!selected) return;
        setBusy(true);
        router.post(`/planning/import/${selected.id}/application`, { decisions, confirm_conflicts: confirmConflicts, conflict_reason: conflictReason }, {
            preserveScroll: true, onError: (received) => setErrors(received), onSuccess: () => setErrors({}), onFinish: () => { setBusy(false); setConfirming(false); },
        });
    }
    const conflicts = selected ? count(selected, 'conflicts') : 0;
    const undecided = selected ? selected.proposals.filter((proposal) => !decisions[proposal.line]).length : 0;
    const ready = selected?.status === 'valid' && undecided === 0 && (conflicts === 0 || (confirmConflicts && conflictReason.trim().length >= 10));
    const messages = [...Object.values(errors), ...Object.values(upload.errors)];
    return (
        <CampusLayout active="planning" title="Import du planning">
            <Head title="Import du planning" />
            <div className="page-heading"><p className="eyebrow">PLANNING INSTITUTIONNEL · {year.toUpperCase()}</p><h1>Import Excel du planning</h1><p className="page-intro">{institution}. Un fichier est d’abord analysé sans rien modifier, puis appliqué en entier au brouillon. Le planning publié ne change qu’après une publication.</p></div>
            {messages.length > 0 && <StateMessage kind="error" title="Action non effectuée">{messages.join(' ')}</StateMessage>}

            <section className="surface" aria-labelledby="upload-title">
                <h2 id="upload-title">Déposer un fichier</h2>
                <p className="section-description">Format .xlsx sans macros, {limits.megabytes} Mo et {limits.rows} lignes au maximum. Les heures sont lues dans le fuseau {timezone}. Dates au format AAAA-MM-JJ, heures au format HH:MM. Les groupes, matières et enseignants inconnus sont signalés, jamais créés. Le fichier déposé est supprimé après {limits.retentionDays} jours.</p>
                <div className="inline-links"><a className="text-link" href="/planning/import/modele">Télécharger le modèle Excel</a><Link className="text-link" href="/planning">Retour au brouillon</Link><Link className="text-link" href="/planning/publication">Prévisualiser et publier</Link></div>
                <form onSubmit={submit} className="form-narrow" style={{ marginTop: '1rem' }}>
                    <div className="field"><label htmlFor="import-file">Fichier Excel du planning</label><input id="import-file" className="input" type="file" accept=".xlsx" aria-invalid={!!upload.errors.file} aria-describedby={upload.errors.file ? 'import-file-error' : undefined} onChange={(event) => upload.setData('file', event.target.files?.[0] ?? null)} />{upload.errors.file && <p id="import-file-error" className="field-error">{upload.errors.file}</p>}</div>
                    {upload.progress && <p role="status" className="item-meta">Envoi : {upload.progress.percentage} %</p>}
                    <div className="form-actions"><Button type="submit" busy={upload.processing} disabled={!upload.data.file}>Déposer et analyser</Button></div>
                </form>
                <details style={{ marginTop: '1rem' }}><summary>Colonnes du modèle</summary><ul className="plain-list" style={{ marginTop: '.75rem' }}>{columns.map((column) => <li key={column.letter}><strong>{column.letter} · {column.name}</strong><p className="item-meta">{column.help}</p></li>)}</ul></details>
            </section>

            {selected && <section className="surface" aria-labelledby="batch-title">
                <h2 id="batch-title">Lot « {selected.name} »</h2>
                <dl className="detail-list">
                    <div><dt>État</dt><dd role="status">{selected.statusLabel}{selected.status === 'analyzing' && ` — ${selected.processedRows} ligne(s) sur ${selected.totalRows}`}{selected.status === 'applying' && selected.appliedProgress !== null && ` — ${selected.appliedProgress} ligne(s) sur ${selected.totalRows}`}</dd></div>
                    <div><dt>Déposé</dt><dd>{when.format(new Date(selected.createdAt))} par {selected.author ?? 'compte indisponible'}</dd></div>
                    {selected.totalRows > 0 && <div><dt>Résultat de la simulation</dt><dd>{selected.totalRows} ligne(s) : {count(selected, 'create')} à créer, {count(selected, 'update')} à mettre à jour, {count(selected, 'unchanged')} déjà présentes, {count(selected, 'match')} correspondance(s) à décider · {count(selected, 'errors')} erreur(s) bloquante(s), {conflicts} conflit(s), {count(selected, 'warnings')} avertissement(s)</dd></div>}
                </dl>
                {running && <StateMessage kind="loading" title="Traitement en cours">Cette page se met à jour toute seule. Le planning publié n’est pas modifié.</StateMessage>}
                {selected.failure && <StateMessage kind="error" title="Traitement interrompu">{selected.failure}</StateMessage>}
                {selected.status === 'invalid' && <StateMessage kind="error" title="Aucune ligne n’a été appliquée">Une seule erreur bloquante empêche l’application de tout le lot. Corrigez le fichier et déposez-le de nouveau, ou corrigez les référentiels puis relancez l’analyse.</StateMessage>}
                {selected.status === 'applied' && <StateMessage kind="success" title="Lot appliqué au brouillon" action={<Link className="button button-primary" href="/planning/publication">Prévisualiser et publier</Link>}>Les membres ne voient ces cours qu’après publication.</StateMessage>}
                <div className="form-actions">
                    {selected.issues.length > 0 && <a className="button button-secondary" href={`/planning/import/${selected.id}/rapport`}>Télécharger le rapport (CSV)</a>}
                    {['invalid', 'failed', 'valid'].includes(selected.status) && !selected.rawPurged && <Button variant="secondary" onClick={() => router.post(`/planning/import/${selected.id}/analyse`, {}, { preserveScroll: true, onError: (received) => setErrors(received) })}>Relancer l’analyse</Button>}
                </div>

                {selected.issues.length > 0 && <>
                    <h3 style={{ marginTop: '1.25rem' }}>Rapport</h3>
                    <ul className="plain-list" style={{ marginTop: '.75rem' }}>{selected.issues.map((issue) => <li key={issue.id}>
                        <strong>{issue.severityLabel} — feuille {issue.sheet}{issue.line !== null && `, ligne ${issue.line}`}{issue.column && `, colonne ${issue.column}`}</strong>
                        <p className="item-meta">{issue.message}{issue.value && <> Valeur lue : « {issue.value} ».</>}</p>
                    </li>)}</ul>
                    <Pagination currentPage={selected.issuesPagination.page} totalPages={selected.issuesPagination.total} previousHref={selected.issuesPagination.previous ?? undefined} nextHref={selected.issuesPagination.next ?? undefined} />
                </>}

                {selected.status === 'valid' && <div style={{ marginTop: '1.25rem' }}>
                    {selected.proposals.length > 0 && <>
                        <h3>Correspondances à décider</h3>
                        <p className="section-description">Ces lignes n’ont pas d’identifiant externe et ressemblent à un cours déjà présent. Choisissez pour chacune.</p>
                        {selected.proposals.map((proposal) => <SelectField key={proposal.line} label={`Ligne ${proposal.line} — ${proposal.label}`} value={decisions[proposal.line] ?? ''} onChange={(event) => setDecisions({ ...decisions, [proposal.line]: event.target.value })}>
                            <option value="">Choisir…</option>
                            {proposal.candidates.map((candidate) => <option key={candidate.id} value={`update:${candidate.id}`}>Mettre à jour le cours existant : {candidate.label}</option>)}
                            <option value="create">Créer un nouveau cours</option>
                        </SelectField>)}
                    </>}
                    {conflicts > 0 && <div className="form-narrow">
                        <CheckboxField label={`Je confirme les ${conflicts} chevauchement(s) signalé(s) dans le rapport.`} hint="Un doublon exact reste interdit." checked={confirmConflicts} onChange={(event) => setConfirmConflicts(event.target.checked)} />
                        <TextAreaField label="Motif de la confirmation" hint="Dix caractères minimum, conservé dans l’historique des cours." rows={2} value={conflictReason} onChange={(event) => setConflictReason(event.target.value)} error={errors.conflict_reason} />
                    </div>}
                    <div className="form-actions"><Button disabled={!ready} onClick={() => setConfirming(true)}>Appliquer ce lot au brouillon</Button></div>
                </div>}
            </section>}

            <section className="surface" aria-labelledby="batches-title">
                <h2 id="batches-title">Lots déposés</h2>
                {batches.length === 0
                    ? <StateMessage kind="empty" title="Aucun fichier déposé">Téléchargez le modèle, remplissez-le, puis déposez-le ici.</StateMessage>
                    : <ul className="plain-list">{batches.map((batch) => <li key={batch.id}>
                        <strong>{batch.name}</strong>
                        <p className="item-meta">{batch.statusLabel} · {batch.totalRows} ligne(s) · {when.format(new Date(batch.createdAt))} par {batch.author ?? 'compte indisponible'}{batch.rawPurged && ' · fichier brut supprimé'}</p>
                        <Link className="text-link" href={`/planning/import?batch=${batch.id}`}>Ouvrir le rapport</Link>
                    </li>)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>

            <ConfirmDialog open={confirming} busy={busy} title={`Appliquer « ${selected?.name ?? ''} » au brouillon de ${institution} ?`} confirmLabel="Appliquer au brouillon" onCancel={() => setConfirming(false)} onConfirm={apply}>
                {selected && <>{count(selected, 'create')} cours créés, {count(selected, 'update')} mis à jour, {selected.proposals.length} correspondance(s) décidée(s). Le lot s’applique en entier ou pas du tout. Rien n’est publié : vous pourrez prévisualiser puis publier ensuite.</>}
            </ConfirmDialog>
        </CampusLayout>
    );
}
