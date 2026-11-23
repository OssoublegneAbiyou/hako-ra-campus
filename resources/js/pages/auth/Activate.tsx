import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { TextField } from '../../components/ui/Fields';

export default function Activate() {
    const form = useForm({ identifier: '', secret: '', password: '', password_confirmation: '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/activation', { onFinish: () => form.reset('password', 'password_confirmation') });
    }
    return (
        <CampusLayout active="profile" title="Activation du compte">
            <Head title="Activation du compte" />
            <div className="page-heading"><p className="eyebrow">COMPTE REMIS PAR UN ÉTABLISSEMENT</p><h1>Activer mon compte</h1><p className="page-intro">Saisissez l’identifiant et le secret temporaire qui vous ont été remis en personne, puis choisissez votre mot de passe. Le secret temporaire ne sert qu’une fois.</p></div>
            <section className="surface form-narrow" aria-labelledby="activate-title">
                <h2 id="activate-title">Activation</h2>
                <form onSubmit={submit} noValidate>
                    <TextField label="Identifiant" hint="Par exemple HK-AB12CD34. Ce n’est pas votre matricule." name="identifier" autoComplete="username" autoCapitalize="characters" required value={form.data.identifier} onChange={(event) => form.setData('identifier', event.target.value)} error={form.errors.identifier} />
                    <TextField label="Secret temporaire" hint="Douze caractères, par exemple ABCD-EFGH-JKMN." name="secret" autoComplete="off" autoCapitalize="characters" required value={form.data.secret} onChange={(event) => form.setData('secret', event.target.value)} error={form.errors.secret} />
                    <TextField label="Votre mot de passe" hint="Au moins 10 caractères, avec une lettre et un chiffre. Ne le partagez avec personne." type="password" name="password" autoComplete="new-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} />
                    <TextField label="Confirmation du mot de passe" type="password" name="password_confirmation" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Activer mon compte</Button></div>
                </form>
                <div className="inline-links"><Link className="text-link" href="/connexion">Retour à la connexion</Link></div>
            </section>
        </CampusLayout>
    );
}
