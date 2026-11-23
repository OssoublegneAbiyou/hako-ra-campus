import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { Pagination } from '../../components/ui/Collection';
import { StateMessage } from '../../components/ui/Feedback';
import { SelectField, TextAreaField } from '../../components/ui/Fields';

type Step = { action: string; actor: string; comment: string | null; to: string | null; at: string };
type Report = {
    id: number; version: number; reason: string; message: string | null; status: string; statusLabel: string; createdAt: string; assignee: string | null; decision: string | null; mine: boolean;
    opportunity: { id: string; title: string; campaign: string; statusLabel: string }; history: Step[];
};
type Props = { reports: Report[]; pagination: { page: number; total: number; previous: string | null; next: string | null }; filter: string; statuses: Record<string, string>; decisions: Record<string, string> };

const ACTIONS: Record<string, string> = { created: 'Signalement reçu', assigned: 'Pris en charge', commented: 'Commentaire interne', decided: 'Décision' };

function Row({ report, decisions }: { report: Report; decisions: Record<string, string> }) {
    const [comment, setComment] = useState('');
    const [decision, setDecision] = useState('no_action');
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    const errors = usePage().props.errors as Record<string, string>;
    const send = (data: Record<string, unknown>) => router.post(`/administration/catalogue/signalements/${report.id}`, { expected_version: report.version, ...data }, { preserveScroll: true, onSuccess: () => setComment('') });
    const open = report.status === 'new' || report.status === 'in_progress';
    return <li>
        <strong>{report.reason}</strong> <span className="subtle-badge">{report.statusLabel}</span>
        <p className="item-meta">Fiche : <Link className="text-link" href={`/administration/catalogue/${report.opportunity.id}`}>{report.opportunity.title}</Link> (campagne {report.opportunity.campaign}, {report.opportunity.statusLabel}) · reçu le {when.format(new Date(report.createdAt))} · responsable : {report.assignee ?? 'personne'}{report.decision && ` · décision : ${report.decision}`}</p>
        {report.message && <p>{report.message}</p>}
        <ul className="plain-list">{report.history.map((step, index) => <li key={index}><span className="item-meta">{when.format(new Date(step.at))} · {ACTIONS[step.action] ?? step.action} · {step.actor}{step.to && ` · ${step.to}`}</span>{step.comment && <p>{step.comment}</p>}</li>)}</ul>
        {report.mine && <StateMessage title="Vous avez émis ce signalement">Un autre éditeur doit le traiter.</StateMessage>}
        {!report.mine && <div className="form-narrow">
            <TextAreaField label={`Commentaire interne — signalement n° ${report.id}`} hint="Visible de l’équipe éditoriale seulement, jamais du signalant ni du public." rows={2} maxLength={1000} value={comment} error={errors.comment} onChange={(event) => setComment(event.target.value)} />
            <div className="form-actions">
                {report.status === 'new' && <Button variant="secondary" onClick={() => send({ action: 'assign' })}>Prendre en charge</Button>}
                <Button variant="secondary" onClick={() => send({ action: 'comment', comment })}>Ajouter le commentaire</Button>
            </div>
            {open && <>
                <SelectField label={`Décision — signalement n° ${report.id}`} hint="« Fiche suspendue » retire immédiatement la fiche du catalogue public." value={decision} error={errors.decision} onChange={(event) => setDecision(event.target.value)}>
                    {Object.entries(decisions).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </SelectField>
                <div className="form-actions"><Button variant={decision === 'suspended' || decision === 'archived' ? 'danger' : 'primary'} onClick={() => send({ action: 'decide', decision, comment })}>Enregistrer la décision</Button></div>
            </>}
        </div>}
    </li>;
}

export default function CatalogueReports({ reports, pagination, filter, statuses, decisions }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    return (
        <CampusLayout active="opportunities" title="Signalements du catalogue">
            <Head title="Signalements du catalogue" />
            <div className="page-heading"><p className="eyebrow">ADMINISTRATION ÉDITORIALE</p><h1>Signalements</h1><p className="page-intro">Un signalement ne retire jamais une fiche à lui seul : c’est votre décision, tracée, qui le fait. L’identité des signalants n’est pas affichée.</p></div>
            {errors.reference && <StateMessage kind="error" title="Action non enregistrée">{errors.reference}</StateMessage>}
            <section className="surface" aria-labelledby="queue-title">
                <h2 id="queue-title">File éditoriale</h2>
                <div className="filter-bar">
                    <SelectField label="État" value={filter} onChange={(event) => router.get('/administration/catalogue/signalements', event.target.value ? { statut: event.target.value } : {}, { preserveScroll: true })}>
                        <option value="">À traiter</option>{Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </SelectField>
                </div>
                {reports.length === 0 ? <StateMessage kind="empty" title="Aucun signalement dans cette file" /> : <ul className="plain-list">{reports.map((report) => <Row key={`${report.id}-${report.version}`} report={report} decisions={decisions} />)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>
            <div className="inline-links"><Link className="text-link" href="/administration/catalogue">Catalogue éditorial</Link></div>
        </CampusLayout>
    );
}
