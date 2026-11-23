import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { Pagination } from '../components/ui/Collection';
import { SelectField, TextAreaField, TextField } from '../components/ui/Fields';
import { StateMessage } from '../components/ui/Feedback';

type State = { title: string; startsAt: string; endsAt: string; status: string; room: string | null; teacher: string | null; groups: string[] };
type Change = { id: string; change: string; before: State | null; after: State };
type Pager = { page: number; total: number; previous: string | null; next: string | null };
type Version = { version: number; kind: 'publish' | 'rollback'; sourceVersion: number | null; publishedAt: string; author: string | null; reason: string; summary: Record<string, number> };
type Report = { id: number; teacher: string; reason: string; title: string; startsAt: string; draftStatus: string };
type Props = {
    institution: string; year: string; timezone: string; current: Version | null;
    preview: { hash: string; blocked: string | null; total: number; counts: Record<string, number>; changes: Change[]; pagination: Pager };
    versions: Version[]; versionsPagination: Pager; detail: (Version & { changes: Change[]; pagination: Pager }) | null;
    reports: Report[]; changeLabels: Record<string, string>;
};
const statusLabels: Record<string, string> = { active: 'prévu', cancelled: 'annulé', calendar_excluded: 'retiré du calendrier' };

export default function SchedulePublication({ institution, year, timezone, current, preview, versions, versionsPagination, detail, reports, changeLabels }: Props) {
    const [reason, setReason] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [target, setTarget] = useState<Version | null>(null);
    const [rollbackReason, setRollbackReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [requestKey, setRequestKey] = useState(() => crypto.randomUUID());
    const [decisions, setDecisions] = useState<Record<number, { decision: string; note: string }>>({});
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone });
    const describe = (state: State) => `${when.format(new Date(state.startsAt))} – ${new Intl.DateTimeFormat('fr', { timeStyle: 'short', timeZone: timezone }).format(new Date(state.endsAt))} · ${statusLabels[state.status] ?? state.status}${state.room ? ` · salle ${state.room}` : ''}${state.teacher ? ` · ${state.teacher}` : ''}${state.groups.length ? ` · ${state.groups.join(', ')}` : ''}`;
    const summary = (counts: Record<string, number>) => Object.entries(counts).map(([key, count]) => `${count} ${(changeLabels[key] ?? key).toLowerCase()}`).join(', ') || 'aucune différence';
    const finish = { preserveScroll: true, onError: (received: Record<string, string>) => setErrors(received), onSuccess: () => { setErrors({}); setReason(''); setRollbackReason(''); setRequestKey(crypto.randomUUID()); }, onFinish: () => { setBusy(false); setConfirming(false); setTarget(null); } };
    function publish() {
        setBusy(true);
        router.post('/planning/publication', { request_key: requestKey, expected_version: current?.version ?? 0, preview_hash: preview.hash, reason }, finish);
    }
    function rollback() {
        if (!target || !current) return;
        setBusy(true);
        router.post(`/planning/publication/${target.version}/retour`, { request_key: requestKey, expected_version: current.version, reason: rollbackReason }, finish);
    }
    const list = (changes: Change[]) => <ul className="plain-list">{changes.map((change) => <li key={change.id}>
        <strong>{changeLabels[change.change] ?? change.change} — {change.after.title}</strong>
        {change.before && <p className="item-meta">Avant : {describe(change.before)}</p>}
        <p className="item-meta">{change.before ? 'Après' : 'Publié'} : {describe(change.after)}</p>
    </li>)}</ul>;
    const pager = (value: Pager) => <Pagination currentPage={value.page} totalPages={value.total} previousHref={value.previous ?? undefined} nextHref={value.next ?? undefined} />;
    const messages = Object.values(errors);
    return (
        <CampusLayout active="planning" title="Publication du planning">
            <Head title="Publication du planning" />
            <div className="page-heading"><p className="eyebrow">PLANNING INSTITUTIONNEL · {year.toUpperCase()}</p><h1>Publication du planning</h1><p className="page-intro">{institution}. Le brouillon n’est vu que par l’administration et les enseignants concernés. Chaque publication crée une version figée ; les heures sont affichées dans {timezone}.</p></div>
            {messages.length > 0 && <StateMessage kind="error" title="Action non effectuée">{messages.join(' ')}</StateMessage>}

            <section className="surface" aria-labelledby="current-title">
                <h2 id="current-title">Version en vigueur</h2>
                {current
                    ? <dl className="detail-list"><div><dt>Version</dt><dd>{current.version}{current.kind === 'rollback' && ` — retour à la version ${current.sourceVersion}`}</dd></div><div><dt>Publiée le</dt><dd>{when.format(new Date(current.publishedAt))} par {current.author ?? 'compte indisponible'}</dd></div><div><dt>Motif</dt><dd>{current.reason}</dd></div></dl>
                    : <StateMessage kind="empty" title="Aucune version publiée">Les apprenants et enseignants ne voient encore aucun cours institutionnel.</StateMessage>}
                <div className="inline-links"><Link className="text-link" href="/planning">Retour au brouillon</Link><Link className="text-link" href="/planning?source=published">Voir le planning publié</Link></div>
            </section>

            <section className="surface" aria-labelledby="preview-title">
                <h2 id="preview-title">Différences entre le brouillon et la version publiée</h2>
                {preview.blocked
                    ? <StateMessage kind="error" title="Prévisualisation indisponible">{preview.blocked}</StateMessage>
                    : preview.total === 0
                        ? <StateMessage kind="empty" title="Aucune différence à publier">Le planning publié est identique au brouillon.</StateMessage>
                        : <>
                            <p className="section-description">{preview.total} cours concernés : {summary(preview.counts)}. Vérifiez la liste avant de publier.</p>
                            {list(preview.changes)}
                            {pager(preview.pagination)}
                            <div className="form-narrow" style={{ marginTop: '1rem' }}>
                                <TextAreaField label="Motif de la publication" hint="Conservé avec la version. Trois caractères minimum." required rows={2} value={reason} onChange={(event) => setReason(event.target.value)} error={errors.reason} />
                                <div className="form-actions"><Button disabled={reason.trim().length < 3} onClick={() => setConfirming(true)}>Publier ces {preview.total} différences</Button></div>
                            </div>
                        </>}
            </section>

            <section className="surface" aria-labelledby="reports-title">
                <h2 id="reports-title">Indisponibilités signalées par les enseignants</h2>
                {reports.length === 0
                    ? <StateMessage kind="empty" title="Aucun signalement en attente" />
                    : <ul className="plain-list">{reports.map((report) => {
                        const value = decisions[report.id] ?? { decision: 'accept_cancel', note: '' };
                        return <li key={report.id}>
                            <strong>{report.title} — {when.format(new Date(report.startsAt))}</strong>
                            <p className="item-meta">{report.teacher} : {report.reason}<br />Brouillon actuel : {statusLabels[report.draftStatus] ?? report.draftStatus}. Le planning publié ne change qu’à la prochaine publication.</p>
                            <div className="form-narrow">
                                <SelectField label="Décision" value={value.decision} onChange={(event) => setDecisions({ ...decisions, [report.id]: { ...value, decision: event.target.value } })}><option value="accept_cancel">Accepter et annuler le cours dans le brouillon</option><option value="accept_other">Accepter : je prépare un report ou un remplacement</option><option value="decline">Refuser</option></SelectField>
                                <TextField label="Note pour l’enseignant (facultative)" value={value.note} onChange={(event) => setDecisions({ ...decisions, [report.id]: { ...value, note: event.target.value } })} />
                                <div className="form-actions"><Button variant="secondary" onClick={() => router.post(`/planning/indisponibilites/${report.id}/decision`, value, { preserveScroll: true, onError: (received) => setErrors(received), onSuccess: () => setErrors({}) })}>Enregistrer la décision pour {report.teacher}</Button></div>
                            </div>
                        </li>;
                    })}</ul>}
            </section>

            <section className="surface" aria-labelledby="versions-title">
                <h2 id="versions-title">Versions publiées</h2>
                {versions.length === 0
                    ? <StateMessage kind="empty" title="Aucune version" />
                    : <ul className="plain-list">{versions.map((version) => <li key={version.version}>
                        <strong>Version {version.version}{version.kind === 'rollback' ? ` — retour à la version ${version.sourceVersion}` : ''}{current?.version === version.version ? ' · en vigueur' : ''}</strong>
                        <p className="item-meta">{when.format(new Date(version.publishedAt))} par {version.author ?? 'compte indisponible'} · {summary(version.summary)}<br />Motif : {version.reason}</p>
                        <div className="form-actions">
                            <Link className="text-link" href={`/planning/publication?version=${version.version}#detail`}>Voir les différences</Link>
                            {current && version.version < current.version && <Button variant="secondary" onClick={() => { setTarget(version); setErrors({}); }}>Revenir à la version {version.version}</Button>}
                        </div>
                    </li>)}</ul>}
                {pager(versionsPagination)}
            </section>

            {detail && <section className="surface" id="detail" aria-labelledby="detail-title">
                <h2 id="detail-title">Différences de la version {detail.version}</h2>
                <p className="section-description">{summary(detail.summary)}. Motif : {detail.reason}</p>
                {list(detail.changes)}
                {pager(detail.pagination)}
            </section>}

            <ConfirmDialog open={confirming} busy={busy} title={`Publier la version ${(current?.version ?? 0) + 1} du planning de ${institution} ?`} confirmLabel="Publier" onCancel={() => setConfirming(false)} onConfirm={publish}>{preview.total} cours concernés : {summary(preview.counts)}. Les apprenants et enseignants concernés verront ces changements dès la publication. Une version publiée ne se modifie pas ; un retour arrière crée une nouvelle version.</ConfirmDialog>
            <ConfirmDialog open={target !== null} busy={busy} title={`Revenir à la version ${target?.version ?? ''} du planning ?`} confirmLabel="Publier le retour arrière" onCancel={() => setTarget(null)} onConfirm={rollback}>
                <TextAreaField label="Motif du retour arrière" hint="Trois caractères minimum." rows={2} value={rollbackReason} onChange={(event) => setRollbackReason(event.target.value)} error={errors.reason} />
                Seuls les cours à venir reprennent leur état de la version {target?.version}. Les cours ajoutés depuis sont retirés du planning publié et du brouillon, sans suppression. Les cours passés, l’historique et les identifiants des cours sont conservés.
            </ConfirmDialog>
        </CampusLayout>
    );
}
