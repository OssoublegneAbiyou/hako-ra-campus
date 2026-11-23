import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { TextField } from '../../components/ui/Fields';
import { StateMessage } from '../../components/ui/Feedback';

export default function ForgotPassword() {
    const form = useForm({ email: '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/mot-de-passe-oublie');
    }
    return (
        <CampusLayout active="profile" title="Mot de passe oublié">
            <Head title="Mot de passe oublié" />
            <div className="page-heading"><p className="eyebrow">VOTRE COMPTE</p><h1>Mot de passe oublié</h1><p className="page-intro">Indiquez l’adresse électronique de votre compte pour recevoir un lien de réinitialisation.</p></div>
            <section className="surface form-narrow" aria-labelledby="forgot-title">
                <h2 id="forgot-title">Demander un lien</h2>
                <form onSubmit={submit} noValidate>
                    <TextField label="Adresse électronique" type="email" name="email" autoComplete="email" required value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Envoyer le lien</Button></div>
                </form>
                <StateMessage title="Compte remis par un établissement, sans adresse électronique ?">La récupération se fait auprès de l’administration de votre établissement, qui contrôle votre identité et vous remet un nouveau secret personnel.</StateMessage>
                <div className="inline-links"><Link className="text-link" href="/connexion">Retour à la connexion</Link></div>
            </section>
        </CampusLayout>
    );
}
