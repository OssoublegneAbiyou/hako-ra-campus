import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { TextField } from '../../components/ui/Fields';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/reinitialisation', { onFinish: () => form.reset('password', 'password_confirmation') });
    }
    return (
        <CampusLayout active="profile" title="Nouveau mot de passe">
            <Head title="Nouveau mot de passe" />
            <div className="page-heading"><p className="eyebrow">VOTRE COMPTE</p><h1>Nouveau mot de passe</h1><p className="page-intro">Vos autres sessions seront fermées après ce changement.</p></div>
            <section className="surface form-narrow" aria-labelledby="reset-title">
                <h2 id="reset-title">Choisir un mot de passe</h2>
                <form onSubmit={submit} noValidate>
                    <TextField label="Adresse électronique" type="email" name="email" autoComplete="email" required value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} />
                    <TextField label="Nouveau mot de passe" hint="Au moins 10 caractères, avec une lettre et un chiffre." type="password" name="password" autoComplete="new-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} />
                    <TextField label="Confirmation du mot de passe" type="password" name="password_confirmation" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Enregistrer le mot de passe</Button></div>
                </form>
                <div className="inline-links"><Link className="text-link" href="/mot-de-passe-oublie">Demander un nouveau lien</Link></div>
            </section>
        </CampusLayout>
    );
}
