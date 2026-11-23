import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { CheckboxField, TextField } from '../../components/ui/Fields';
import { StateMessage } from '../../components/ui/Feedback';

export default function GoogleRegistration({ email, name, minorsPathwayOpen }: { email: string; name: string; minorsPathwayOpen: boolean }) {
    const form = useForm({ name, adult_declaration: false });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/connexion/google/creation');
    }
    return (
        <CampusLayout active="profile" title="Créer un compte">
            <Head title="Créer un compte avec Google" />
            <div className="page-heading"><p className="eyebrow">VOTRE COMPTE</p><h1>Créer un compte avec Google</h1><p className="page-intro">Aucun compte Campus n’existe encore pour {email}. Un compte individuel va être créé, utilisable sans établissement.</p></div>
            <section className="surface form-narrow" aria-labelledby="google-register-title">
                <h2 id="google-register-title">Vos informations</h2>
                <p className="section-description">Google a transmis à Campus votre nom, votre adresse et l’identifiant de votre compte Google. Campus ne reçoit rien d’autre, ne lit ni vos messages ni vos fichiers, et ne garde aucun accès à votre compte Google. Aucun rôle d’enseignant ou d’administration ne se choisit ici.</p>
                <form onSubmit={submit} noValidate>
                    <TextField label="Nom" name="name" autoComplete="name" required maxLength={120} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                    <p className="item-meta">Adresse : <strong>{email}</strong>, déjà vérifiée par Google.</p>
                    {minorsPathwayOpen
                        ? <StateMessage title="Inscription des mineurs">Le parcours dédié est ouvert selon le cadre validé par HAKO-RA.</StateMessage>
                        : <CheckboxField label="Je déclare être majeur selon la loi qui m’est applicable." hint="L’inscription des mineurs n’est pas encore ouverte : son cadre d’information et d’autorisation doit d’abord être validé." checked={form.data.adult_declaration} onChange={(event) => form.setData('adult_declaration', event.target.checked)} error={form.errors.adult_declaration} />}
                    <p className="item-meta">Ce que Campus fait de vos données : <Link className="text-link" href="/confidentialite">notice de confidentialité</Link>.</p>
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Créer mon compte</Button></div>
                </form>
                <div className="inline-links"><Link className="text-link" href="/connexion">Annuler et revenir à la connexion</Link></div>
            </section>
        </CampusLayout>
    );
}
