import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { STATE_LABELS, messageFor, synchronizeDrafts, unsyncedOperations, type Operation } from '../offline/outbox';
import { purgeAll } from '../offline/store';
import type { CampusProps } from '../types';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { SelectField, TextField } from '../components/ui/Fields';
import { StateMessage } from '../components/ui/Feedback';

type Account = { name: string; email: string | null; loginIdentifier: string | null; provisioned: boolean };
type MembershipSummary = { id: number; role: string; institution: string | null; accountsHref: string | null };
type SessionSummary = { handle: string; ipAddress: string | null; userAgent: string; lastActiveAt: string; current: boolean };
type Props = { account: Account; timezone: { current: string; confirmed: boolean; options: string[] }; memberships: MembershipSummary[]; privilegedBlocked: boolean; secondFactorEnabled: boolean; google: { enabled: boolean; linked: boolean; linkedEmail: string | null }; sessionsSupported: boolean; sessions: SessionSummary[] };

export default function Profile({ account, timezone, memberships, privilegedBlocked, secondFactorEnabled, google, sessionsSupported, sessions }: Props) {
    const form = useForm({ name: account.name });
    const logout = useForm({});
    const zone = useForm({ timezone: timezone.current });
    const accountKey = usePage<CampusProps>().props.auth.accountKey ?? null;
    const [unsent, setUnsent] = useState<Operation[] | null>(null);
    const [leaveMessage, setLeaveMessage] = useState<string | null>(null);
    const [leaveBusy, setLeaveBusy] = useState(false);
    // La déconnexion efface réellement tout ce que Campus conserve sur l'appareil, brouillons compris.
    const leave = () => { void purgeAll({ unsubscribePush: true }).finally(() => logout.post('/deconnexion')); };
    // Avant cela, s'il reste des brouillons qui ne sont pas sur le serveur, la personne est prévenue et décide.
    const signOut = async () => {
        const drafts = await unsyncedOperations(accountKey);
        if (drafts.length === 0) { leave(); return; }
        setLeaveMessage(null);
        setUnsent(drafts);
    };
    const synchronizeThenLeave = async () => {
        setLeaveBusy(true);
        const summary = await synchronizeDrafts(accountKey);
        const rest = await unsyncedOperations(accountKey);
        setLeaveBusy(false);
        if (rest.length === 0) { leave(); return; }
        setUnsent(rest);
        setLeaveMessage(`${messageFor(summary)} Vous êtes toujours connecté : ces brouillons ne sont pas sur le serveur.`);
    };
    const [confirmOthers, setConfirmOthers] = useState(false);
    const [leaving, setLeaving] = useState<MembershipSummary | null>(null);
    const [busyHandle, setBusyHandle] = useState('');
    const dateFormat = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch('/profil', { preserveScroll: true });
    }
    function revoke(handle: string) {
        setBusyHandle(handle);
        router.delete(`/profil/sessions/${handle}`, { preserveScroll: true, onFinish: () => setBusyHandle('') });
    }
    const others = sessions.filter((session) => !session.current).length;
    return (
        <CampusLayout active="profile" title="Profil">
            <Head title="Profil" />
            <div className="page-heading"><p className="eyebrow">VOTRE COMPTE</p><h1>Profil</h1><p className="page-intro">Votre identité, vos appartenances et les appareils connectés à votre compte.</p></div>

            <section className="surface" aria-labelledby="account-title">
                <h2 id="account-title">Compte</h2>
                <dl className="detail-list">
                    {account.email && <div><dt>Adresse électronique</dt><dd>{account.email} — vérifiée</dd></div>}
                    {account.loginIdentifier && <div><dt>Identifiant de connexion</dt><dd>{account.loginIdentifier}</dd></div>}
                    {account.provisioned && <div><dt>Récupération de l’accès</dt><dd>Auprès de l’administration de votre établissement, après contrôle de votre identité</dd></div>}
                </dl>
                <form onSubmit={submit} noValidate className="form-narrow">
                    <TextField label="Nom" name="name" autoComplete="name" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                    <div className="form-actions">
                        <Button type="submit" busy={form.processing} disabled={!form.isDirty}>Enregistrer le nom</Button>
                        <Button variant="secondary" busy={logout.processing} onClick={() => void signOut()}>Se déconnecter</Button>
                    </div>
                </form>
            </section>

            <section className="surface" aria-labelledby="timezone-title" id="fuseau">
                <h2 id="timezone-title">Fuseau horaire</h2>
                <p className="section-description">Il fixe l’heure des échéances que vous saisissez et l’affichage de votre planning personnel. Les dates déjà enregistrées gardent leur instant : seul leur affichage suit le fuseau choisi. Le planning d’un établissement garde le fuseau de l’établissement.</p>
                {!timezone.confirmed && <StateMessage title="Fuseau à confirmer">Par défaut : {timezone.current}. Confirmez-le ou choisissez le vôtre.</StateMessage>}
                <form onSubmit={(event) => { event.preventDefault(); zone.patch('/profil/fuseau', { preserveScroll: true }); }} noValidate className="form-narrow">
                    <SelectField label="Mon fuseau horaire" value={zone.data.timezone} error={zone.errors.timezone} onChange={(event) => zone.setData('timezone', event.target.value)}>
                        {timezone.options.map((name) => <option key={name} value={name}>{name}</option>)}
                    </SelectField>
                    <div className="form-actions"><Button type="submit" busy={zone.processing}>{timezone.confirmed ? 'Enregistrer ce fuseau' : 'Confirmer ce fuseau'}</Button></div>
                </form>
                <div className="inline-links"><Link className="text-link" href="/notifications/preferences">Préférences de notification</Link><Link className="text-link" href="/hors-ligne">Hors connexion et installation</Link><Link className="text-link" href="/confidentialite/parametres">Confidentialité et données</Link><Link className="text-link" href="/aide">Aide et support</Link></div>
            </section>

            {unsent !== null && <section className="surface" aria-labelledby="leave-title" data-leave-warning>
                <h2 id="leave-title">Des brouillons ne sont pas synchronisés</h2>
                <StateMessage kind="error" title={`${unsent.length} brouillon(s) écrit(s) sur cet appareil ne sont pas enregistrés sur le serveur`}>Se déconnecter efface tout ce que Campus conserve sur cet appareil. Sans synchronisation, ces brouillons seront perdus définitivement.</StateMessage>
                <ul className="plain-list">{unsent.map((operation) => <li key={operation.id}><strong>{operation.label}</strong><p className="item-meta">{STATE_LABELS[operation.state]}</p></li>)}</ul>
                {leaveMessage && <StateMessage title="Synchronisation">{leaveMessage} <Link className="text-link" href="/hors-ligne">Examiner ces brouillons</Link></StateMessage>}
                <div className="form-actions">
                    <Button busy={leaveBusy} onClick={() => void synchronizeThenLeave()}>Synchroniser, puis me déconnecter</Button>
                    <Button variant="danger" disabled={leaveBusy} onClick={leave}>Abandonner ces brouillons et me déconnecter</Button>
                    <Button variant="secondary" disabled={leaveBusy} onClick={() => setUnsent(null)}>Rester connecté</Button>
                </div>
            </section>}

            <section className="surface" aria-labelledby="memberships-title">
                <h2 id="memberships-title">Appartenances</h2>
                {memberships.length === 0
                    ? <StateMessage kind="empty" title="Aucun établissement">Vous utilisez Campus en espace individuel. Un rattachement se fait sur invitation d’un établissement.</StateMessage>
                    : <ul className="plain-list">{memberships.map((membership) => <li key={membership.id}><strong>{membership.role}</strong><p className="item-meta">{membership.institution ?? 'Plateforme HAKO-RA Campus'}</p>{membership.accountsHref && <Link className="text-link" href={membership.accountsHref}>Comptes sans adresse électronique</Link>}<div className="form-actions"><Button variant="secondary" onClick={() => router.post('/contexte', { membership_id: membership.id })}>Utiliser ce contexte</Button><Button variant="secondary" onClick={() => setLeaving(membership)}>Quitter</Button></div></li>)}</ul>}
                <p className="section-description" style={{ marginTop: '1rem' }}>Le contexte actif se change aussi depuis l’en-tête. Quitter une appartenance ferme ses accès ; votre compte et votre espace individuel restent.</p>
                {privilegedBlocked && <StateMessage kind="error" title="Actions privilégiées indisponibles">Vos rôles d’enseignement, d’administration ou d’édition exigent un second facteur. Tant qu’il n’est pas activé, ces actions restent fermées. <Link className="text-link" href="/profil/second-facteur">Activer le second facteur</Link></StateMessage>}
                <p className="section-description">Second facteur : {secondFactorEnabled ? 'activé' : 'non activé'}. <Link className="text-link" href="/profil/second-facteur">{secondFactorEnabled ? 'Gérer le second facteur' : 'Protéger mon compte par un code'}</Link></p>
                {(google.enabled || google.linked) && <p className="section-description">Connexion par Google : {google.linked ? `reliée${google.linkedEmail ? ` (${google.linkedEmail})` : ''}` : 'non reliée'}. {google.enabled && !google.linked && <a className="text-link" href="/profil/google/rattachement">Relier mon compte Google</a>}</p>}
            </section>

            <section className="surface" aria-labelledby="sessions-title">
                <h2 id="sessions-title">Sessions actives</h2>
                {!sessionsSupported
                    ? <StateMessage title="Liste indisponible sur cet environnement">La consultation des sessions exige le stockage des sessions en base. Un changement de mot de passe ferme toujours les autres sessions.</StateMessage>
                    : <>
                        <p className="section-description">Fermez une session que vous ne reconnaissez pas. Votre mot de passe vous sera demandé.</p>
                        <ul className="plain-list">{sessions.map((session) => <li key={session.handle}>
                            <strong>{session.current ? 'Cet appareil — session en cours' : 'Autre appareil'}</strong>
                            <p className="item-meta">{session.userAgent || 'Navigateur non précisé'}<br />Adresse {session.ipAddress ?? 'inconnue'} · dernière activité {dateFormat.format(new Date(session.lastActiveAt))}</p>
                            {!session.current && <div className="form-actions"><Button variant="secondary" busy={busyHandle === session.handle} onClick={() => revoke(session.handle)}>Fermer cette session</Button></div>}
                        </li>)}</ul>
                        {others > 0 && <div className="form-actions"><Button variant="danger" onClick={() => setConfirmOthers(true)}>Fermer les {others} autre(s) session(s)</Button></div>}
                    </>}
            </section>
            <ConfirmDialog open={leaving !== null} title={`Quitter « ${leaving?.role ?? ''} — ${leaving?.institution ?? 'Plateforme HAKO-RA Campus'} » ?`} confirmLabel="Quitter cette appartenance" onCancel={() => setLeaving(null)} onConfirm={() => { const id = leaving?.id; setLeaving(null); if (id) router.post(`/profil/appartenances/${id}/depart`, {}, { preserveScroll: true }); }}>Vos accès à ce périmètre seront fermés immédiatement. Y revenir demandera une nouvelle invitation. Votre mot de passe vous sera demandé.</ConfirmDialog>
            <ConfirmDialog open={confirmOthers} title={`Fermer les ${others} autre(s) session(s) ?`} confirmLabel="Fermer les autres sessions" onCancel={() => setConfirmOthers(false)} onConfirm={() => { setConfirmOthers(false); router.delete('/profil/sessions', { preserveScroll: true }); }}>Les autres appareils devront se reconnecter. La session de cet appareil reste ouverte.</ConfirmDialog>
        </CampusLayout>
    );
}
