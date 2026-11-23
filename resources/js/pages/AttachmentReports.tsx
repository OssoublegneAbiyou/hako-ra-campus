import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { Pagination } from '../components/ui/Collection';
import { StateMessage } from '../components/ui/Feedback';
import { TextField } from '../components/ui/Fields';

type Report = { id: number; reason: string; status: string; createdAt: string; decisionNote: string | null; attachmentId: string; fileName: string; downloadable: boolean; withdrawn: boolean; deleted: boolean };
type Props = { reports: Report[]; pagination: { page: number; total: number; previous: string | null; next: string | null } };

function Row({ report }: { report: Report }) {
    const [note, setNote] = useState('');
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    const decide = (decision: 'upheld' | 'dismissed') => router.post(`/pieces-jointes/signalements/${report.id}/decision`, { decision, note }, { preserveScroll: true });
    return <li>
        <strong>{report.fileName}</strong>
        <p className="item-meta">Signalé le {when.format(new Date(report.createdAt))} · {report.status === 'open' ? 'à traiter' : report.status === 'upheld' ? 'fichier retiré' : 'classé sans suite'}{report.deleted && ' · fichier supprimé par son auteur'}</p>
        <p>{report.reason}</p>
        {report.decisionNote && <p className="item-meta">Note de décision : {report.decisionNote}</p>}
        {report.downloadable && <a className="button button-secondary" href={`/pieces-jointes/${report.attachmentId}`} download>Télécharger pour examen</a>}
        {report.status === 'open' && <div className="form-narrow">
            <TextField label="Note de décision (facultative)" maxLength={500} value={note} onChange={(event) => setNote(event.target.value)} />
            <div className="form-actions"><Button variant="danger" onClick={() => decide('upheld')}>Retirer le fichier de la diffusion</Button><Button variant="secondary" onClick={() => decide('dismissed')}>Classer sans suite</Button></div>
        </div>}
    </li>;
}

export default function AttachmentReports({ reports, pagination }: Props) {
    return (
        <CampusLayout active="home" title="Fichiers signalés">
            <Head title="Fichiers signalés" />
            <div className="page-heading"><p className="eyebrow">ADMINISTRATION</p><h1>Fichiers signalés</h1><p className="page-intro">Fichiers partagés avec un groupe et signalés par un membre. Vous ne voyez ici que les fichiers signalés, jamais les fichiers personnels.</p></div>
            <section className="surface" aria-labelledby="reports-title">
                <h2 id="reports-title">Signalements</h2>
                {reports.length === 0 ? <StateMessage kind="empty" title="Aucun signalement" /> : <ul className="plain-list">{reports.map((report) => <Row key={`${report.id}-${report.status}`} report={report} />)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>
            <div className="inline-links"><Link className="text-link" href="/administration/etablissement">Établissement</Link></div>
        </CampusLayout>
    );
}
