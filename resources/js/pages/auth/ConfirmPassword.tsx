import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { TextField } from '../../components/ui/Fields';

export default function ConfirmPassword({ googleAvailable }: { googleAvailable: boolean }) {
    const form = useForm({ password: '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/confirmation-mot-de-passe', { onFinish: () => form.reset('password') });
    }
    return (
        <CampusLayout active="profile" title="Confirmation">
            <Head title="Confirmation du mot de passe" />
            <div className="page-heading"><p className="eyebrow">ACTION SENSIBLE</p><h1>Confirmez votre mot de passe</h1><p className="page-intro">Cette vérification protège les actions sensibles. Après confirmation, vous revenez à l’écran précédent pour relancer l’action.</p></div>
            <section className="surface form-narrow" aria-labelledby="confirm-title">
                <h2 id="confirm-title">Votre mot de passe</h2>
                <form onSubmit={submit} noValidate>
                    <TextField label="Mot de passe" type="password" name="password" autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Confirmer</Button></div>
                </form>
                {googleAvailable && <div className="form-actions" style={{ marginTop: '1rem' }}>
                    <a className="button button-secondary" href="/confirmation-google">Confirmer avec Google</a>
                    <p className="field-hint">Pour un compte créé avec Google, sans mot de passe : Google vous redemande de vous identifier, puis vous revenez à l’écran précédent.</p>
                </div>}
            </section>
        </CampusLayout>
    );
}
