import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { CheckboxField, TextField } from '../../components/ui/Fields';

export default function Login({ registrationEnabled, googleEnabled }: { registrationEnabled: boolean; googleEnabled: boolean }) {
    const form = useForm({ login: '', password: '', remember: false });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/connexion', { onFinish: () => form.reset('password') });
    }
    return (
        <CampusLayout active="profile" title="Connexion">
            <Head title="Connexion" />
            <div className="page-heading"><p className="eyebrow">VOTRE COMPTE</p><h1>Connexion</h1><p className="page-intro">Un seul compte pour votre espace individuel et vos établissements.</p></div>
            <section className="surface form-narrow" aria-labelledby="login-title">
                <h2 id="login-title">Se connecter</h2>
                <form onSubmit={submit} noValidate>
                    <TextField label="Adresse électronique ou identifiant" hint="L’identifiant est celui remis par votre établissement, par exemple HK-AB12CD34. Le matricule ne sert pas à se connecter." name="login" autoComplete="username" required value={form.data.login} onChange={(event) => form.setData('login', event.target.value)} error={form.errors.login} />
                    <TextField label="Mot de passe" type="password" name="password" autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} />
                    <CheckboxField label="Rester connecté sur cet appareil" hint="À éviter sur un appareil partagé." checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Se connecter</Button></div>
                </form>
                {googleEnabled && <div className="form-actions" style={{ marginTop: '1rem' }}>
                    <a className="button button-secondary" href="/connexion/google">Continuer avec Google</a>
                    <p className="field-hint">Google transmet à Campus votre nom, votre adresse et l’identifiant de votre compte Google, rien d’autre. Si votre compte Campus a un second facteur, son code reste demandé.</p>
                </div>}
                <div className="inline-links">
                    <Link className="text-link" href="/mot-de-passe-oublie">Mot de passe oublié</Link>
                    <Link className="text-link" href="/activation">Activer un compte remis par un établissement</Link>
                    {registrationEnabled && <Link className="text-link" href="/inscription">Créer un compte</Link>}
                </div>
            </section>
        </CampusLayout>
    );
}
