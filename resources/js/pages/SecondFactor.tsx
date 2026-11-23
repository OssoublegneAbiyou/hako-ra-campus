import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Fields';
import { StateMessage } from '../components/ui/Feedback';

type Props = {
    enabled: boolean; enabledAt: string | null; required: boolean; remainingRecoveryCodes: number;
    setup: { secret: string; uri: string } | null; recoveryCodes: string[] | null;
};

export default function SecondFactor({ enabled, enabledAt, required, remainingRecoveryCodes, setup, recoveryCodes }: Props) {
    const confirm = useForm({ code: '' });
    const remove = useForm({ code: '' });
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeStyle: 'short' });
    const submitConfirm = (event: FormEvent) => { event.preventDefault(); confirm.post('/profil/second-facteur/confirmation', { onFinish: () => confirm.reset('code') }); };
    const submitRemove = (event: FormEvent) => { event.preventDefault(); remove.delete('/profil/second-facteur', { onFinish: () => remove.reset('code') }); };
    return (
        <CampusLayout active="profile" title="Second facteur">
            <Head title="Second facteur" />
            <div className="page-heading"><p className="eyebrow">SÉCURITÉ DU COMPTE</p><h1>Second facteur</h1><p className="page-intro">En plus du mot de passe, un code à six chiffres généré par une application sur votre téléphone. {required ? 'Il est obligatoire pour vos rôles d’enseignement, d’administration ou d’édition.' : 'Il est facultatif pour votre compte.'}</p></div>
            <div className="inline-links"><Link className="text-link" href="/profil">Retour au profil</Link></div>

            {recoveryCodes && <section className="surface" aria-labelledby="recovery-title">
                <h2 id="recovery-title">Vos codes de secours</h2>
                <StateMessage kind="info" title="Notez-les maintenant : ils ne seront plus affichés">Chaque code remplace une fois le code de votre téléphone. Gardez-les hors de cet appareil, par exemple sur papier.</StateMessage>
                <ul className="plain-list" aria-label="Codes de secours">{recoveryCodes.map((code) => <li key={code}><code>{code}</code></li>)}</ul>
            </section>}

            {!enabled && !setup && <section className="surface" aria-labelledby="start-title">
                <h2 id="start-title">Activer</h2>
                <p>Il vous faut une application d’authentification sur votre téléphone : Google Authenticator, ou toute application compatible avec les codes temporaires. Aucun compte Google n’est nécessaire, et rien n’est envoyé à Google.</p>
                <p className="section-description">Votre mot de passe vous sera redemandé avant de commencer.</p>
                <div className="form-actions"><Button onClick={() => router.post('/profil/second-facteur/activation')}>Activer le second facteur</Button></div>
            </section>}

            {!enabled && setup && <section className="surface" aria-labelledby="setup-title">
                <h2 id="setup-title">Relier votre application</h2>
                <ol>
                    <li>Ouvrez l’application d’authentification, puis « Ajouter un compte » et « Saisir une clé de configuration ».</li>
                    <li>Nom du compte : Campus. Clé : <code>{setup.secret}</code>. Type : basé sur l’heure.</li>
                    <li>Saisissez ci-dessous le code à six chiffres que l’application affiche.</li>
                </ol>
                <p className="section-description">Sur le téléphone où l’application est installée, vous pouvez aussi <a className="text-link" href={setup.uri}>ouvrir directement l’application</a>. Cette clé est secrète : ne la partagez pas et ne la photographiez pas.</p>
                <form onSubmit={submitConfirm} className="form-narrow" noValidate>
                    <TextField label="Code à six chiffres" name="code" inputMode="numeric" autoComplete="one-time-code" required maxLength={10} value={confirm.data.code} onChange={(event) => confirm.setData('code', event.target.value)} error={confirm.errors.code} />
                    <div className="form-actions"><Button type="submit" busy={confirm.processing}>Confirmer et activer</Button><Button variant="secondary" onClick={() => router.delete('/profil/second-facteur')}>Abandonner</Button></div>
                </form>
            </section>}

            {enabled && <section className="surface" aria-labelledby="state-title">
                <h2 id="state-title">Activé</h2>
                <p>Second facteur actif{enabledAt ? ` depuis le ${when.format(new Date(enabledAt))}` : ''}. Un code vous est demandé à chaque connexion.</p>
                <p>Codes de secours restants : <strong>{remainingRecoveryCodes}</strong>.</p>
                <div className="form-actions"><Button variant="secondary" onClick={() => router.post('/profil/second-facteur/codes-de-secours')}>Créer de nouveaux codes de secours</Button></div>
                <h3 style={{ marginTop: '1.5rem' }}>Retirer le second facteur</h3>
                {required && <StateMessage kind="error" title="Vos rôles privilégiés seront fermés">Sans second facteur, vos actions d’enseignement, d’administration ou d’édition restent indisponibles jusqu’à une nouvelle activation.</StateMessage>}
                <form onSubmit={submitRemove} className="form-narrow" noValidate>
                    <TextField label="Code actuel de l’application, ou code de secours" name="code" autoComplete="one-time-code" required maxLength={20} value={remove.data.code} onChange={(event) => remove.setData('code', event.target.value)} error={remove.errors.code} />
                    <div className="form-actions"><Button type="submit" variant="danger" busy={remove.processing}>Retirer le second facteur</Button></div>
                </form>
            </section>}
        </CampusLayout>
    );
}
