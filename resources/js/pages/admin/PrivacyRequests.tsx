import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { Pagination } from '../../components/ui/Collection';
import { StateMessage } from '../../components/ui/Feedback';
import { SelectField, TextAreaField } from '../../components/ui/Fields';

type Item = { id: string; type: string; typeLabel: string; status: string; statusLabel: string; requester: string; contact: string | null; message: string | null; response: string | null; createdAt: string; closedAt: string | null; open: boolean };
type Props = { requests: Item[]; pagination: { page: number; total: number; previous: string | null; next: string | null }; durations: Record<string, number>; inactiveAccounts: number };

function Handle({ item }: { item: Item }) {
    const [status, setStatus] = useState('completed');
    const [response, setResponse] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    return <div className="form-narrow">
        <SelectField label="Décision" value={status} onChange={(event) => setStatus(event.target.value)} error={errors.status}>
            <option value="in_progress">En cours de traitement</option><option value="completed">Traitée</option><option value="refused">Refusée</option>
        </SelectField>
        <TextAreaField label="Réponse à la personne" hint="Obligatoire pour clôturer. Elle la lira dans son suivi." rows={3} maxLength={2000} value={response} onChange={(event) => setResponse(event.target.value)} error={errors.response} />
        <div className="form-actions"><Button variant="secondary" busy={busy} onClick={() => router.post(`/administration/plateforme/demandes/${item.id}/traitement`, { status, response }, {
            preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: (received) => setErrors(received), onSuccess: () => setErrors({}) })}>Enregistrer pour la demande de {item.requester}</Button></div>
    </div>;
}

export default function PrivacyRequests({ requests, pagination, durations, inactiveAccounts }: Props) {
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeStyle: 'short' });

    return (
        <CampusLayout active="" title="Demandes de confidentialité">
            <Head title="Demandes de confidentialité" />
            <div className="page-heading"><p className="eyebrow">PLATEFORME</p><h1>Demandes de confidentialité</h1><p className="page-intro">Rectifications et oppositions à traiter ; exports et suppressions, traités automatiquement, y figurent pour mémoire. Vous lisez ce que la personne vous a écrit, jamais son espace individuel.</p></div>

            <section className="surface" aria-labelledby="list-title">
                <h2 id="list-title">Demandes</h2>
                {requests.length === 0 ? <StateMessage kind="empty" title="Aucune demande" />
                    : <ul className="plain-list">{requests.map((item) => <li key={item.id}>
                        <strong>{item.typeLabel} — {item.requester}</strong> <span className="subtle-badge">{item.statusLabel}</span>
                        <p className="item-meta">Reçue le {when.format(new Date(item.createdAt))}{item.closedAt && ` · close le ${when.format(new Date(item.closedAt))}`}{item.contact && ` · ${item.contact}`}</p>
                        {item.message && <p style={{ whiteSpace: 'pre-wrap' }}>{item.message}</p>}
                        {item.response && <p style={{ whiteSpace: 'pre-wrap' }}><strong>Réponse :</strong> {item.response}</p>}
                        {item.open && (item.type === 'rectification' || item.type === 'objection') && <Handle item={item} />}
                    </li>)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>

            <section className="surface" aria-labelledby="retention-title">
                <h2 id="retention-title">Conservation en vigueur</h2>
                <dl className="detail-list">
                    <div><dt>Invitations expirées ou traitées</dt><dd>{durations.invitation_days} jours</dd></div>
                    <div><dt>Fichiers bruts d’import</dt><dd>{durations.import_raw_days} jours</dd></div>
                    <div><dt>Notifications et traces d’envoi</dt><dd>{durations.notification_days} jours</dd></div>
                    <div><dt>Journal d’audit</dt><dd>{durations.audit_months} mois</dd></div>
                    <div><dt>Corbeille des tâches</dt><dd>{durations.task_trash_days} jours</dd></div>
                    <div><dt>Exports de données</dt><dd>{durations.export_days} jours</dd></div>
                    <div><dt>Demandes closes</dt><dd>{durations.request_months} mois</dd></div>
                    <div><dt>Sauvegardes glissantes (lot 24)</dt><dd>{durations.backup_days} jours</dd></div>
                    <div><dt>Journal des effacements</dt><dd>{durations.journal_days} jours</dd></div>
                </dl>
                <StateMessage title={`Revue d’inactivité : ${inactiveAccounts} compte(s) sans activité depuis ${durations.inactivity_months} mois`}>Aucun compte n’est supprimé automatiquement pour inactivité. Ce nombre appelle un examen ; la suite à donner reste à définir.</StateMessage>
            </section>
            <div className="inline-links"><Link className="text-link" href="/administration/plateforme">Administration de la plateforme</Link><Link className="text-link" href="/confidentialite">Notice de confidentialité</Link></div>
        </CampusLayout>
    );
}
