import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { OneTimeLinkPanel } from '../../components/OneTimeLinkPanel';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { Pagination } from '../../components/ui/Collection';
import { SelectField, TextField } from '../../components/ui/Fields';
import { StateMessage } from '../../components/ui/Feedback';

type Member = { id: number; name: string; role: string; localIdentifier: string | null; status: string; revocable: boolean };
type PendingInvitation = { id: number; role: string; email: string | null; localIdentifier: string | null; group: string | null; status: 'pending' | 'pending_review' | 'expired'; expiresAt: string; reviewReason: string | null; candidate: string | null };
type Props = {
    institution: string; year: string; members: Member[]; previousHref: string | null; nextHref: string | null; currentPage: number;
    invitations: PendingInvitation[]; groups: { id: number; name: string }[]; accountsHref: string;
};
const reasons: Record<string, string> = {
    identifier_conflict: 'Le matricule de l’invitation est déjà rattaché à un autre compte.',
    email_mismatch: 'Le compte qui a accepté n’a pas l’adresse électronique invitée.',
};
const statusLabels = { pending: 'En attente d’acceptation', pending_review: 'À contrôler', expired: 'Expirée' };

export default function Institution({ institution, year, members, previousHref, nextHref, currentPage, invitations, groups, accountsHref }: Props) {
    const form = useForm({ role: 'learner', email: '', local_identifier: '', group_id: '' });
    const errors = form.errors as Record<string, string>;
    const [target, setTarget] = useState<Member | null>(null);
    const [busy, setBusy] = useState(false);
    const dateFormat = new Intl.DateTimeFormat('fr', { dateStyle: 'medium' });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, group_id: data.role === 'learner' && data.group_id ? Number(data.group_id) : null, local_identifier: data.role === 'learner' ? data.local_identifier : '' }));
        form.post('/administration/invitations', { preserveScroll: true, onSuccess: () => form.reset() });
    }
    function revoke() {
        if (!target) return;
        setBusy(true);
        router.post(`/administration/appartenances/${target.id}/retrait`, {}, { preserveScroll: true, onFinish: () => { setBusy(false); setTarget(null); } });
    }
    return (
        <CampusLayout active="" title="Administration de l’établissement">
            <Head title="Administration de l’établissement" />
            <div className="page-heading"><p className="eyebrow">ADMINISTRATION · {year.toUpperCase()}</p><h1>{institution}</h1><p className="page-intro">Membres et invitations de votre établissement pour cette année. Les données personnelles des membres (notes privées, retours de cours, démarches) ne sont pas accessibles ici.</p></div>
            <OneTimeLinkPanel />
            {errors.invitation && <StateMessage kind="error" title="Action non effectuée">{errors.invitation}</StateMessage>}

            <section className="surface" aria-labelledby="invite-title">
                <h2 id="invite-title">Inviter un membre</h2>
                <p className="section-description">Le rôle d’administration ne s’accorde pas ici : seule la plateforme habilite une administration d’établissement. Inviter un enseignant est tracé.</p>
                <form onSubmit={submit} noValidate className="form-narrow">
                    <SelectField label="Rôle" value={form.data.role} onChange={(event) => form.setData('role', event.target.value)} error={errors.role}><option value="learner">Apprenant</option><option value="teacher">Enseignant</option></SelectField>
                    <TextField label="Adresse électronique (facultative)" hint="Si elle est renseignée, seul un compte portant cette adresse est rattaché sans contrôle. Sans adresse, remettez le lien en personne." type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={errors.email} />
                    {form.data.role === 'learner' && <>
                        <TextField label="Matricule (facultatif)" hint="Sert au rapprochement. S’il est déjà rattaché à un autre compte, la demande restera en attente de votre contrôle." value={form.data.local_identifier} onChange={(event) => form.setData('local_identifier', event.target.value)} error={errors.local_identifier} />
                        <SelectField label="Groupe (facultatif)" value={form.data.group_id} onChange={(event) => form.setData('group_id', event.target.value)} error={errors.group_id}><option value="">Aucun pour l’instant</option>{groups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}</SelectField>
                    </>}
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Créer l’invitation</Button></div>
                </form>
            </section>

            <section className="surface" aria-labelledby="pending-title">
                <h2 id="pending-title">Invitations en cours</h2>
                {invitations.length === 0
                    ? <StateMessage kind="empty" title="Aucune invitation en cours" />
                    : <ul className="plain-list">{invitations.map((invitation) => <li key={invitation.id}>
                        <strong>{invitation.role} — {statusLabels[invitation.status]}</strong>
                        <p className="item-meta">{invitation.email ?? 'Sans adresse électronique'}{invitation.localIdentifier && ` · matricule ${invitation.localIdentifier}`}{invitation.group && ` · ${invitation.group}`} · expire le {dateFormat.format(new Date(invitation.expiresAt))}{invitation.status === 'pending_review' && <><br />Compte demandeur : {invitation.candidate}. {reasons[invitation.reviewReason ?? ''] ?? ''}</>}</p>
                        <div className="form-actions">
                            {invitation.status === 'pending_review' && <>
                                <Button onClick={() => router.post(`/administration/invitations/${invitation.id}/decision`, { decision: 'approve' }, { preserveScroll: true })}>Approuver le rattachement de {invitation.candidate}</Button>
                                <Button variant="secondary" onClick={() => router.post(`/administration/invitations/${invitation.id}/decision`, { decision: 'reject' }, { preserveScroll: true })}>Refuser</Button>
                            </>}
                            {invitation.status !== 'pending_review' && <Button variant="secondary" onClick={() => router.post(`/administration/invitations/${invitation.id}/revocation`, {}, { preserveScroll: true })}>Révoquer l’invitation</Button>}
                        </div>
                    </li>)}</ul>}
            </section>

            <section className="surface" aria-labelledby="members-title">
                <h2 id="members-title">Membres</h2>
                <div className="inline-links"><a className="text-link" href="/administration/etablissement/membres/export">Exporter la liste (CSV)</a><Link className="text-link" href={accountsHref}>Comptes sans adresse électronique</Link></div>
                <ul className="plain-list" style={{ marginTop: '1rem' }}>{members.map((member) => <li key={member.id}>
                    <strong>{member.name}</strong>
                    <p className="item-meta">{member.role}{member.localIdentifier && ` · matricule ${member.localIdentifier}`} · appartenance {member.status.toLowerCase()}</p>
                    {member.revocable && <div className="form-actions"><Button variant="secondary" onClick={() => setTarget(member)}>Retirer l’appartenance</Button></div>}
                </li>)}</ul>
                <Pagination currentPage={currentPage} totalPages={nextHref ? currentPage + 1 : currentPage} previousHref={previousHref ?? undefined} nextHref={nextHref ?? undefined} />
            </section>
            <ConfirmDialog open={target !== null} busy={busy} title={`Retirer l’appartenance de ${target?.name ?? ''} ?`} confirmLabel="Retirer l’appartenance" onCancel={() => setTarget(null)} onConfirm={revoke}>Les accès de cette personne à {institution} sont fermés immédiatement. Son compte et son espace individuel sont conservés. L’opération est tracée.</ConfirmDialog>
        </CampusLayout>
    );
}
