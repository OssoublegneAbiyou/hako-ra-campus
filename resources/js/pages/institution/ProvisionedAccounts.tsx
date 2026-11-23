import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { Pagination } from '../../components/ui/Collection';
import { CheckboxField, SelectField, TextField } from '../../components/ui/Fields';
import { StateMessage } from '../../components/ui/Feedback';
import type { CampusProps } from '../../types';

type AccountRow = { id: number; name: string; loginIdentifier: string; localIdentifier: string | null; state: 'active' | 'pending' | 'expired' };
type Props = {
    membershipId: number; institution: string; minorsPathwayOpen: boolean; identityChecks: Record<string, string>; secretTtlHours: number;
    accounts: AccountRow[]; previousHref: string | null; nextHref: string | null; currentPage: number;
};
const stateLabels = { active: 'Activé par son titulaire', pending: 'En attente d’activation', expired: 'Secret expiré : récupération assistée nécessaire' };

export default function ProvisionedAccounts({ membershipId, institution, minorsPathwayOpen, identityChecks, secretTtlHours, accounts, previousHref, nextHref, currentPage }: Props) {
    const { flash } = usePage<CampusProps>().props;
    const base = `/etablissement/${membershipId}/comptes`;
    const form = useForm({ name: '', local_identifier: '', adult_attested: false });
    const [target, setTarget] = useState<AccountRow | null>(null);
    const [check, setCheck] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [recoveryErrors, setRecoveryErrors] = useState<Record<string, string>>({});
    const [recovering, setRecovering] = useState(false);
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(base, { preserveScroll: true, onSuccess: () => form.reset() });
    }
    function recover() {
        if (!target) return;
        setRecovering(true);
        router.post(`${base}/${target.id}/recuperation`, { identity_check: check, identity_confirmed: confirmed }, {
            preserveScroll: true,
            onError: (errors) => setRecoveryErrors(errors),
            onSuccess: () => { setCheck(''); setConfirmed(false); setRecoveryErrors({}); },
            onFinish: () => { setRecovering(false); setTarget(null); },
        });
    }
    return (
        <CampusLayout active="profile" title="Comptes sans adresse électronique">
            <Head title="Comptes sans adresse électronique" />
            <div className="page-heading"><p className="eyebrow">ADMINISTRATION · {institution.toUpperCase()}</p><h1>Comptes sans adresse électronique</h1><p className="page-intro">Créez un compte pour un apprenant sans adresse individuelle et remettez-lui en personne son identifiant et son secret temporaire.</p></div>

            {flash.oneTimeSecret && <section className="secret-panel" role="status" aria-labelledby="secret-title">
                <h2 id="secret-title">À remettre en personne à {flash.oneTimeSecret.name}</h2>
                <dl className="detail-list"><div><dt>Identifiant</dt><dd className="secret-value">{flash.oneTimeSecret.identifier}</dd></div><div><dt>Secret temporaire</dt><dd className="secret-value">{flash.oneTimeSecret.secret}</dd></div></dl>
                <p className="item-meta">Ce secret s’affiche une seule fois et expire après {secretTtlHours} heures. Ne l’envoyez pas par un canal collectif, ne le notez pas sur une liste de classe. La personne choisit son mot de passe sur la page « Activer un compte ».</p>
            </section>}

            <section className="surface" aria-labelledby="create-title">
                <h2 id="create-title">Créer un compte</h2>
                <form onSubmit={submit} noValidate className="form-narrow">
                    <TextField label="Nom de l’apprenant" name="name" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                    <TextField label="Matricule dans l’établissement" hint="Sert au rattachement vérifié. Il ne sert ni d’identifiant de connexion ni de mot de passe." name="local_identifier" required value={form.data.local_identifier} onChange={(event) => form.setData('local_identifier', event.target.value)} error={form.errors.local_identifier} />
                    {!minorsPathwayOpen && <CheckboxField label="J’atteste que cette personne ne relève pas du parcours des mineurs." hint="Ce parcours n’est pas ouvert : aucun compte ne doit être créé pour une personne qui en relève." checked={form.data.adult_attested} onChange={(event) => form.setData('adult_attested', event.target.checked)} error={form.errors.adult_attested} />}
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Créer le compte</Button></div>
                </form>
            </section>

            <section className="surface" aria-labelledby="list-title">
                <h2 id="list-title">Comptes de votre périmètre</h2>
                {(recoveryErrors.identity_check || recoveryErrors.identity_confirmed) && <StateMessage kind="error" title="Récupération non effectuée">{recoveryErrors.identity_check ?? recoveryErrors.identity_confirmed}</StateMessage>}
                {accounts.length === 0
                    ? <StateMessage kind="empty" title="Aucun compte sans adresse électronique">Les comptes créés ici apparaîtront dans cette liste.</StateMessage>
                    : <>
                        <div className="form-narrow">
                            <SelectField label="Contrôle d’identité réalisé" hint="Obligatoire avant toute récupération assistée." value={check} onChange={(event) => setCheck(event.target.value)}><option value="">Choisir…</option>{Object.entries(identityChecks).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</SelectField>
                            <CheckboxField label="J’ai contrôlé moi-même l’identité de la personne concernée." checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                        </div>
                        <ul className="plain-list">{accounts.map((account) => <li key={account.id}>
                            <strong>{account.name}</strong>
                            <p className="item-meta">Identifiant {account.loginIdentifier} · matricule {account.localIdentifier ?? 'non renseigné'}<br />{stateLabels[account.state]}</p>
                            <div className="form-actions"><Button variant="secondary" disabled={!check || !confirmed} onClick={() => setTarget(account)}>Récupération assistée</Button></div>
                        </li>)}</ul>
                        <Pagination currentPage={currentPage} totalPages={nextHref ? currentPage + 1 : currentPage} previousHref={previousHref ?? undefined} nextHref={nextHref ?? undefined} />
                    </>}
            </section>
            <div className="inline-links"><Link className="text-link" href="/profil">Retour au profil</Link></div>
            <ConfirmDialog open={target !== null} title={`Émettre un nouveau secret pour ${target?.name ?? ''} ?`} confirmLabel={recovering ? 'Émission en cours…' : 'Émettre le secret'} onCancel={() => setTarget(null)} onConfirm={recover}>L’ancien mot de passe et toutes les sessions de ce compte seront invalidés. L’opération est tracée avec votre nom et le contrôle d’identité déclaré, sans le secret.</ConfirmDialog>
        </CampusLayout>
    );
}
