import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { OneTimeLinkPanel } from '../../components/OneTimeLinkPanel';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { Pagination } from '../../components/ui/Collection';
import { SelectField, TextField } from '../../components/ui/Fields';
import { StateMessage } from '../../components/ui/Feedback';

type Person = { id: number; name: string };
type InstitutionRow = { id: number; code: string; name: string; years: { id: number; name: string }[]; admins: Person[] };
type PendingInvitation = { id: number; email: string | null; status: 'pending' | 'pending_review' | 'expired'; institution: string | null; candidate: string | null; expiresAt: string };
type Props = {
    institutions: InstitutionRow[]; previousHref: string | null; nextHref: string | null; currentPage: number;
    editors: Person[]; adminInvitations: PendingInvitation[]; editorInvitations: PendingInvitation[];
};
type Grant = { role: 'institution_admin' | 'catalogue_editor'; label: string; institution?: InstitutionRow };
const statusLabels = { pending: 'en attente d’acceptation', pending_review: 'à contrôler', expired: 'expirée' };

function InvitationList({ invitations }: { invitations: PendingInvitation[] }) {
    if (invitations.length === 0) return null;
    return <ul className="plain-list" style={{ marginTop: '1rem' }}>{invitations.map((invitation) => <li key={invitation.id}>
        <strong>Invitation {statusLabels[invitation.status]}</strong>
        <p className="item-meta">{invitation.institution ?? 'Plateforme'} · {invitation.email ?? 'sans adresse électronique'}{invitation.candidate && ` · compte demandeur : ${invitation.candidate}`}</p>
        <div className="form-actions">
            {invitation.status === 'pending_review'
                ? <><Button onClick={() => router.post(`/administration/invitations/${invitation.id}/decision`, { decision: 'approve' }, { preserveScroll: true })}>Approuver {invitation.candidate}</Button><Button variant="secondary" onClick={() => router.post(`/administration/invitations/${invitation.id}/decision`, { decision: 'reject' }, { preserveScroll: true })}>Refuser</Button></>
                : <Button variant="secondary" onClick={() => router.post(`/administration/invitations/${invitation.id}/revocation`, {}, { preserveScroll: true })}>Révoquer l’invitation</Button>}
        </div>
    </li>)}</ul>;
}

export default function Platform({ institutions, previousHref, nextHref, currentPage, editors, adminInvitations, editorInvitations }: Props) {
    const form = useForm({ code: '', name: '', timezone: 'Africa/Abidjan', year_name: '', year_code: '', starts_on: '', ends_on: '' });
    const errors = form.errors as Record<string, string>;
    const [grant, setGrant] = useState<Grant | null>(null);
    const [yearId, setYearId] = useState('');
    const [email, setEmail] = useState('');
    const [revoked, setRevoked] = useState<Person | null>(null);
    const [busy, setBusy] = useState(false);
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/administration/plateforme/etablissements', { preserveScroll: true, onSuccess: () => form.reset() });
    }
    function confirmGrant() {
        if (!grant) return;
        setBusy(true);
        router.post('/administration/invitations', {
            role: grant.role, email: email || null, institution_id: grant.institution?.id ?? null, academic_year_id: yearId ? Number(yearId) : null,
        }, { preserveScroll: true, onSuccess: () => setEmail(''), onFinish: () => { setBusy(false); setGrant(null); } });
    }
    function confirmRevoke() {
        if (!revoked) return;
        setBusy(true);
        router.post(`/administration/appartenances/${revoked.id}/retrait`, {}, { preserveScroll: true, onFinish: () => { setBusy(false); setRevoked(null); } });
    }
    return (
        <CampusLayout active="" title="Administration de la plateforme">
            <Head title="Administration de la plateforme" />
            <div className="page-heading"><p className="eyebrow">PLATEFORME</p><h1>Établissements et habilitations</h1><p className="page-intro">Création des établissements et habilitation de leur administration. Ce rôle ne donne accès ni aux notes privées, ni aux retours de cours, ni aux démarches des utilisateurs.</p></div>
            <OneTimeLinkPanel />
            <div className="inline-links"><Link className="text-link" href="/administration/plateforme/demandes">Demandes de confidentialité et conservation</Link></div>
            {(errors.invitation || errors.academic_year_id) && <StateMessage kind="error" title="Action non effectuée">{errors.invitation ?? errors.academic_year_id}</StateMessage>}

            <section className="surface" aria-labelledby="grant-title">
                <h2 id="grant-title">Adresse de la personne à habiliter</h2>
                <div className="form-narrow"><TextField label="Adresse électronique (facultative)" hint="Utilisée pour la prochaine habilitation lancée ci-dessous. Sans adresse, remettez le lien en personne." type="email" value={email} onChange={(event) => setEmail(event.target.value)} error={errors.email} /></div>
            </section>

            <section className="surface" aria-labelledby="institutions-title">
                <h2 id="institutions-title">Établissements</h2>
                {institutions.length === 0
                    ? <StateMessage kind="empty" title="Aucun établissement">Créez le premier établissement ci-dessous.</StateMessage>
                    : <ul className="plain-list">{institutions.map((institution) => <li key={institution.id}>
                        <strong>{institution.name}</strong>
                        <p className="item-meta">Code {institution.code} · {institution.years.map((year) => year.name).join(', ') || 'aucune année ouverte'}</p>
                        {institution.admins.map((admin) => <div className="form-actions" key={admin.id}><span className="item-meta">Administration : {admin.name}</span><Button variant="secondary" onClick={() => setRevoked(admin)}>Retirer</Button></div>)}
                        {institution.years.length > 0 && <div className="form-actions"><Button onClick={() => { setYearId(String(institution.years[institution.years.length - 1].id)); setGrant({ role: 'institution_admin', label: `l’administration de ${institution.name}`, institution }); }}>Habiliter une administration</Button></div>}
                    </li>)}</ul>}
                <Pagination currentPage={currentPage} totalPages={nextHref ? currentPage + 1 : currentPage} previousHref={previousHref ?? undefined} nextHref={nextHref ?? undefined} />
                <InvitationList invitations={adminInvitations} />
            </section>

            <section className="surface" aria-labelledby="editors-title">
                <h2 id="editors-title">Édition du catalogue</h2>
                {editors.length === 0 ? <StateMessage kind="empty" title="Aucun éditeur habilité" /> : <ul className="plain-list">{editors.map((editor) => <li key={editor.id}><strong>{editor.name}</strong><div className="form-actions"><Button variant="secondary" onClick={() => setRevoked(editor)}>Retirer</Button></div></li>)}</ul>}
                <div className="form-actions"><Button onClick={() => setGrant({ role: 'catalogue_editor', label: 'l’édition du catalogue' })}>Habiliter un éditeur</Button></div>
                <InvitationList invitations={editorInvitations} />
            </section>

            <section className="surface" aria-labelledby="create-title">
                <h2 id="create-title">Créer un établissement</h2>
                <form onSubmit={submit} noValidate className="form-narrow">
                    <TextField label="Nom" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={errors.name} />
                    <TextField label="Code interne" hint="Lettres, chiffres et tirets." required value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} error={errors.code} />
                    <TextField label="Fuseau horaire IANA" required value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)} error={errors.timezone} />
                    <TextField label="Nom de la première année" hint="Par exemple : Année 2026–2027." required value={form.data.year_name} onChange={(event) => form.setData('year_name', event.target.value)} error={errors.year_name} />
                    <TextField label="Code de l’année" required value={form.data.year_code} onChange={(event) => form.setData('year_code', event.target.value)} error={errors.year_code} />
                    <TextField label="Début de l’année" type="date" required value={form.data.starts_on} onChange={(event) => form.setData('starts_on', event.target.value)} error={errors.starts_on} />
                    <TextField label="Fin de l’année" type="date" required value={form.data.ends_on} onChange={(event) => form.setData('ends_on', event.target.value)} error={errors.ends_on} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Créer l’établissement</Button></div>
                </form>
            </section>

            <ConfirmDialog open={grant !== null} busy={busy} title={`Habiliter une personne pour ${grant?.label ?? ''} ?`} confirmLabel="Créer l’invitation" onCancel={() => setGrant(null)} onConfirm={confirmGrant}>
                {grant?.institution && <SelectField label="Année concernée" value={yearId} onChange={(event) => setYearId(event.target.value)}>{grant.institution.years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}</SelectField>}
                Ce rôle est privilégié. L’invitation est tracée à votre nom{email ? ` et adressée à ${email}` : ', sans adresse électronique'}.
            </ConfirmDialog>
            <ConfirmDialog open={revoked !== null} busy={busy} title={`Retirer l’habilitation de ${revoked?.name ?? ''} ?`} confirmLabel="Retirer l’habilitation" onCancel={() => setRevoked(null)} onConfirm={confirmRevoke}>Les accès privilégiés de cette personne sont fermés immédiatement. Son compte et son espace individuel sont conservés. L’opération est tracée.</ConfirmDialog>
        </CampusLayout>
    );
}
