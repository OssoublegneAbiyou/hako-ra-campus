import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout from '../../layouts/AuthLayout';
import { Button } from '../../components/ui/Button';
import { CheckboxField, TextField } from '../../components/ui/Fields';

export default function Login({ registrationEnabled, googleEnabled }: { registrationEnabled: boolean; googleEnabled: boolean }) {
    const form = useForm({ login: '', password: '', remember: false });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/connexion', { onFinish: () => form.reset('password') });
    }
    return (
        <AuthLayout>
            <Head title="Connexion" />
            <div className="page-heading"><h1>Se connecter</h1><p className="page-intro">Un seul compte pour votre espace individuel et vos établissements.{registrationEnabled && <> Pas encore de compte ? <Link className="text-link" href="/inscription">Créer un compte</Link></>}</p></div>
            <section className="surface" aria-labelledby="login-title">
                <h2 id="login-title" className="sr-only">Vos identifiants</h2>
                {googleEnabled && <>
                    <a className="button button-provider" href="/connexion/google">Continuer avec Google</a>
                    <p className="field-hint" style={{ marginTop: '.5rem' }}>Google transmet à Campus votre nom, votre adresse et l’identifiant de votre compte Google, rien d’autre. Si votre compte Campus a un second facteur, son code reste demandé.</p>
                    <p className="auth-divider">ou</p>
                </>}
                <form onSubmit={submit} noValidate>
                    <TextField label="Adresse électronique ou identifiant" hint="L’identifiant est celui remis par votre établissement, par exemple HK-AB12CD34. Le matricule ne sert pas à se connecter." name="login" autoComplete="username" required value={form.data.login} onChange={(event) => form.setData('login', event.target.value)} error={form.errors.login} />
                    <TextField label="Mot de passe" type="password" name="password" autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} />
                    <CheckboxField label="Rester connecté sur cet appareil" hint="À éviter sur un appareil partagé." checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Se connecter</Button></div>
                </form>
                <div className="inline-links">
                    <Link className="text-link" href="/mot-de-passe-oublie">Mot de passe oublié</Link>
                    <Link className="text-link" href="/activation">Activer un compte remis par un établissement</Link>
                </div>
            </section>
        </AuthLayout>
    );
}
