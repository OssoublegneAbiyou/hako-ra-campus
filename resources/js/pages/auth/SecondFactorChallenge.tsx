import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { TextField } from '../../components/ui/Fields';

export default function SecondFactorChallenge() {
    const form = useForm({ code: '' });
    const logout = useForm({});
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/second-facteur', { onFinish: () => form.reset('code') });
    }
    return (
        <CampusLayout active="profile" title="Second facteur">
            <Head title="Second facteur" />
            <div className="page-heading"><p className="eyebrow">CONNEXION · ÉTAPE 2 SUR 2</p><h1>Saisissez votre code</h1><p className="page-intro">Votre mot de passe est accepté. Ce compte est protégé par un second facteur : ouvrez votre application d’authentification et recopiez le code à six chiffres affiché pour Campus.</p></div>
            <section className="surface form-narrow" aria-labelledby="code-title">
                <h2 id="code-title">Code de vérification</h2>
                <form onSubmit={submit} noValidate>
                    <TextField label="Code à six chiffres, ou code de secours" name="code" inputMode="text" autoComplete="one-time-code" autoFocus required maxLength={20}
                        hint="Le code change toutes les 30 secondes. Sans votre téléphone, saisissez l’un de vos codes de secours : il ne servira qu’une fois."
                        value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} error={form.errors.code} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Vérifier</Button><Button variant="secondary" disabled={logout.processing} onClick={() => logout.post('/deconnexion')}>Se déconnecter</Button></div>
                </form>
                <p className="field-hint">Téléphone et codes de secours perdus ? Le support peut retirer le second facteur après avoir vérifié votre identité. Il ne vous demandera jamais votre mot de passe.</p>
            </section>
        </CampusLayout>
    );
}
