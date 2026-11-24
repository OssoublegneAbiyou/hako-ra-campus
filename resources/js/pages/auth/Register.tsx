import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout from '../../layouts/AuthLayout';
import { Button } from '../../components/ui/Button';
import { CheckboxField, TextField } from '../../components/ui/Fields';
import { StateMessage } from '../../components/ui/Feedback';

export default function Register({ minorsPathwayOpen, googleEnabled }: { minorsPathwayOpen: boolean; googleEnabled: boolean }) {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '', adult_declaration: false });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/inscription', { onFinish: () => form.reset('password', 'password_confirmation') });
    }
    return (
        <AuthLayout>
            <Head title="Créer un compte" />
            <div className="page-heading"><h1>Créer un compte</h1><p className="page-intro">Un compte individuel, utilisable sans établissement. Un rattachement à un établissement se fait uniquement sur invitation.</p></div>
            <section className="surface form-narrow" aria-labelledby="register-title">
                <h2 id="register-title">Vos informations</h2>
                <p className="section-description">Votre nom sert à vous identifier dans votre espace. Votre adresse sert à vérifier le compte, à vous connecter et à récupérer l’accès. Aucun rôle d’enseignant ou d’administration ne se choisit ici.</p>
                <form onSubmit={submit} noValidate>
                    <TextField label="Nom" name="name" autoComplete="name" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                    <TextField label="Adresse électronique" type="email" name="email" autoComplete="email" required value={form.data.email} onChange={(event) => form.setData('email', event.target.value.toLowerCase())} error={form.errors.email} />
                    <TextField label="Mot de passe" hint="Au moins 10 caractères, avec une lettre et un chiffre." type="password" name="password" autoComplete="new-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} />
                    <TextField label="Confirmation du mot de passe" type="password" name="password_confirmation" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                    {minorsPathwayOpen
                        ? <StateMessage title="Inscription des mineurs">Le parcours dédié est ouvert selon le cadre validé par HAKO-RA.</StateMessage>
                        : <CheckboxField label="Je déclare être majeur selon la loi qui m’est applicable." hint="L’inscription des mineurs n’est pas encore ouverte : son cadre d’information et d’autorisation doit d’abord être validé." checked={form.data.adult_declaration} onChange={(event) => form.setData('adult_declaration', event.target.checked)} error={form.errors.adult_declaration} />}
                    <p className="item-meta">Ce que Campus fait de vos données : <Link className="text-link" href="/confidentialite">notice de confidentialité</Link>. Vous pourrez confirmer l’avoir lue depuis votre compte.</p>
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Créer mon compte</Button></div>
                </form>
                <div className="inline-links"><Link className="text-link" href="/connexion">J’ai déjà un compte</Link>{googleEnabled && <a className="text-link" href="/connexion/google">Créer mon compte avec Google</a>}</div>
            </section>
        </AuthLayout>
    );
}
