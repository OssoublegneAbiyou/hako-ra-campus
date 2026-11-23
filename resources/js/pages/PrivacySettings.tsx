import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, SelectField, TextAreaField } from '../components/ui/Fields';
import { useUnsavedWarning } from '../components/ui/useUnsavedWarning';

type PrivacyRequest = { id: string; type: string; typeLabel: string; status: string; statusLabel: string; message: string | null; response: string | null; createdAt: string; closedAt: string | null; cancellable: boolean };
type DataExport = { id: string; createdAt: string; expiresAt: string; sizeBytes: number; available: boolean; downloads: number };
type Props = {
    notice: { version: string; publishedOn: string; toComplete: string[]; acknowledgedAt: string | null }; requests: PrivacyRequest[]; exports: DataExport[];
    exportDays: number; erasureBlocker: string | null; types: Record<string, string>;
};

export default function PrivacySettings({ notice, requests, exports, exportDays, erasureBlocker, types }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const form = useForm({ type: 'rectification', message: '' });
    const [busy, setBusy] = useState(false);
    const [cancelling, setCancelling] = useState<PrivacyRequest | null>(null);
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeStyle: 'short' });
    const day = new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeZone: 'UTC' });
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    useUnsavedWarning(form.data.message.trim() !== '' && !form.processing, 'Votre demande');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/confidentialite/demandes', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <CampusLayout active="profile" title="Confidentialité et données">
            <Head title="Confidentialité et données" />
            <div className="page-heading"><p className="eyebrow">VOS DONNÉES</p><h1>Confidentialité et données</h1><p className="page-intro">Ce que vous avez lu, ce que vous pouvez demander, et l’état de chaque demande.</p></div>

            <section className="surface" aria-labelledby="notice-title">
                <h2 id="notice-title">Notice de confidentialité</h2>
                <p>Version en vigueur : {day.format(new Date(notice.publishedOn))}. <Link className="text-link" href="/confidentialite">Lire la notice</Link></p>
                {notice.acknowledgedAt
                    ? <p className="item-meta" role="status">Vous en avez pris connaissance le {when.format(new Date(notice.acknowledgedAt.replace(' ', 'T') + 'Z'))}. Cette date est conservée avec la version et l’empreinte du texte.</p>
                    : <><StateMessage title="Cette version n’a pas encore été lue">Lisez la notice, puis confirmez. Ce n’est pas un consentement : c’est la trace que l’information vous a été présentée.</StateMessage>
                        {errors.version && <p role="alert">{errors.version}</p>}
                        <div className="form-actions"><Button busy={busy} onClick={() => router.post('/confidentialite/notice', { version: notice.version }, options)}>J’ai pris connaissance de cette notice</Button></div></>}
                <p className="item-meta">{notice.toComplete.length} information(s) de la notice restent à compléter avant l’ouverture du service.</p>
            </section>

            <section className="surface" aria-labelledby="choices-title">
                <h2 id="choices-title">Vos choix facultatifs</h2>
                <p className="section-description">Rien de ce qui suit n’est nécessaire pour utiliser Campus. Chaque choix se fait, et se retire, sur son propre écran.</p>
                <ul className="plain-list">
                    <li><strong>Notifications par courriel et sur le navigateur</strong><p className="item-meta">Vous choisissez les catégories, les canaux et vos heures calmes.</p><Link className="text-link" href="/notifications/preferences">Préférences de notification</Link></li>
                    <li><strong>Critères pour les opportunités</strong><p className="item-meta">Niveau, domaines, langues, pays : déclarés par vous, effaçables à tout moment.</p><Link className="text-link" href="/opportunites/profil">Mes critères</Link></li>
                    <li><strong>Copie de lecture sur cet appareil</strong><p className="item-meta">Aucune donnée n’est conservée sur un appareil tant que vous ne le déclarez pas personnel.</p><Link className="text-link" href="/hors-ligne">Hors connexion</Link></li>
                </ul>
            </section>

            <section className="surface" aria-labelledby="export-title">
                <h2 id="export-title">Obtenir une copie de mes données</h2>
                <p className="section-description">L’export contient vos données personnelles : compte, espace individuel, rattachements, résultats publiés pour vous, préférences, demandes. Il ne contient ni mot de passe, ni donnée d’une autre personne, ni donnée d’un établissement au-delà de ce qui vous concerne. Les fichiers joints se téléchargent depuis leurs écrans.</p>
                {errors.export && <StateMessage kind="error" title="Export non généré">{errors.export}</StateMessage>}
                <div className="form-actions"><Button busy={busy} onClick={() => router.post('/confidentialite/exports', {}, options)}>Générer un export</Button></div>
                <p className="item-meta">Votre mot de passe vous sera redemandé. Le fichier reste disponible {exportDays} jours, pour vous seul, puis il est effacé.</p>
                {exports.length > 0 && <ul className="plain-list">{exports.map((item) => <li key={item.id}>
                    <strong>Export du {when.format(new Date(item.createdAt))}</strong>
                    <p className="item-meta">{Math.max(1, Math.round(item.sizeBytes / 1024))} Ko · {item.available ? `disponible jusqu’au ${when.format(new Date(item.expiresAt))}` : 'expiré : le fichier a été effacé'}{item.downloads > 0 && ` · téléchargé ${item.downloads} fois`}</p>
                    {/* Lien classique : c'est un fichier, pas un écran. */}
                    {item.available && <a className="button button-secondary" href={`/confidentialite/exports/${item.id}`}>Télécharger</a>}
                </li>)}</ul>}
            </section>

            <section className="surface" aria-labelledby="request-title">
                <h2 id="request-title">Demander une rectification ou m’opposer à un traitement</h2>
                <p className="section-description">Votre nom et votre fuseau se corrigent directement dans le profil. Pour le reste, décrivez votre demande : elle est transmise à l’administration de la plateforme, qui ne peut pas lire votre espace individuel. N’y mettez pas de mot de passe.</p>
                <form onSubmit={submit} noValidate className="form-narrow">
                    <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
                    <SelectField label="Type de demande" value={form.data.type} error={form.errors.type} onChange={(event) => form.setData('type', event.target.value)}>
                        {Object.entries(types).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </SelectField>
                    <TextAreaField label="Votre demande" hint="Entre 10 et 2 000 caractères." rows={4} maxLength={2000} value={form.data.message} error={form.errors.message} onChange={(event) => form.setData('message', event.target.value)} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Envoyer ma demande</Button></div>
                </form>
            </section>

            <section className="surface" aria-labelledby="tracking-title">
                <h2 id="tracking-title">Suivi de mes demandes</h2>
                {requests.length === 0 ? <StateMessage kind="empty" title="Aucune demande" />
                    : <ul className="plain-list">{requests.map((request) => <li key={request.id}>
                        <strong>{request.typeLabel}</strong> <span className="subtle-badge">{request.statusLabel}</span>
                        <p className="item-meta">Reçue le {when.format(new Date(request.createdAt))}{request.closedAt && ` · close le ${when.format(new Date(request.closedAt))}`}</p>
                        {request.message && <p style={{ whiteSpace: 'pre-wrap' }}>{request.message}</p>}
                        {request.response && <p style={{ whiteSpace: 'pre-wrap' }}><strong>Réponse :</strong> {request.response}</p>}
                        {request.cancellable && <Button variant="secondary" onClick={() => setCancelling(request)}>Annuler cette demande</Button>}
                    </li>)}</ul>}
            </section>

            <section className="surface" aria-labelledby="erase-title">
                <h2 id="erase-title">Supprimer mon compte</h2>
                <p className="section-description">La suppression efface votre espace individuel et ferme tous vos accès. Elle est définitive. Générez d’abord un export si vous voulez garder une copie.</p>
                {erasureBlocker
                    ? <StateMessage kind="error" title="Suppression impossible pour l’instant">{erasureBlocker}</StateMessage>
                    : <div className="form-actions"><Link className="button button-danger" href="/confidentialite/suppression">Voir les conséquences et supprimer mon compte</Link></div>}
            </section>

            <ConfirmDialog open={cancelling !== null} title={`Annuler la demande « ${cancelling?.typeLabel ?? ''} » ?`} confirmLabel="Annuler la demande" busy={busy} onCancel={() => setCancelling(null)}
                onConfirm={() => { const id = cancelling?.id; setCancelling(null); if (id) router.post(`/confidentialite/demandes/${id}/annulation`, {}, options); }}>
                Elle ne sera pas traitée. Vous pourrez en déposer une nouvelle.
            </ConfirmDialog>
        </CampusLayout>
    );
}
