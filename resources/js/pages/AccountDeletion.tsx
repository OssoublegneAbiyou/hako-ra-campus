import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, TextField } from '../components/ui/Fields';
import { purgeAll } from '../offline/store';

type Props = { blocker: string | null; memberships: string[]; hasRecentExport: boolean; backupDays: number; confirmationWord: string };

export default function AccountDeletion({ blocker, memberships, hasRecentExport, backupDays, confirmationWord }: Props) {
    const form = useForm({ password: '', confirmation: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        // Les données conservées sur cet appareil sont effacées une fois la suppression confirmée par le serveur.
        form.delete('/confidentialite/compte', { onSuccess: () => { void purgeAll({ unsubscribePush: true }); } });
    };

    return (
        <CampusLayout active="profile" title="Supprimer mon compte">
            <Head title="Supprimer mon compte" />
            <div className="page-heading"><p className="eyebrow">ACTION DÉFINITIVE</p><h1>Supprimer mon compte</h1><p className="page-intro">Lisez ce qui sera effacé et ce qui restera avant de confirmer.</p></div>

            <section className="surface" aria-labelledby="erased-title">
                <h2 id="erased-title">Ce qui est effacé</h2>
                <ul className="plain-list">
                    <li>Votre identité de connexion : nom, adresse électronique ou identifiant, mot de passe.</li>
                    <li>Tout votre espace individuel : matières, planning personnel, tâches, notes personnelles, retours après cours, pièces jointes privées.</li>
                    <li>Votre suivi d’opportunités : critères, favoris, démarches, recherches enregistrées.</li>
                    <li>Vos notifications, vos préférences, vos appareils abonnés, vos exports.</li>
                    <li>Toutes vos sessions : vous êtes déconnecté partout, immédiatement.</li>
                </ul>
            </section>

            <section className="surface" aria-labelledby="kept-title">
                <h2 id="kept-title">Ce qui reste, et pourquoi</h2>
                <ul className="plain-list">
                    <li>{memberships.length > 0 ? `Chez ${memberships.join(', ')} : ` : 'Si vous avez été rattaché à un établissement : '}l’historique de votre rattachement et ce que l’établissement a lui-même publié ou décidé (résultats, devoirs, planning). Ces données sont sous sa responsabilité ; elles sont détachées de votre identité de connexion, et c’est à lui de justifier leur conservation.</li>
                    <li>Le journal des actions sensibles, sans leur contenu, pendant sa durée de conservation.</li>
                    <li>La trace de cette demande de suppression : son type, sa date, son état. Aucun texte.</li>
                    <li>Les sauvegardes : vos données en disparaissent à leur renouvellement, sous {backupDays} jours environ, pas instantanément. Si une sauvegarde est restaurée entre-temps, la suppression est appliquée de nouveau.</li>
                </ul>
            </section>

            {!hasRecentExport && <StateMessage title="Vous n’avez pas d’export disponible" action={<Link className="button button-secondary" href="/confidentialite/parametres">Générer un export d’abord</Link>}>Après la suppression, il ne sera plus possible d’obtenir une copie de vos données.</StateMessage>}

            {blocker
                ? <StateMessage kind="error" title="Suppression impossible pour l’instant">{blocker}</StateMessage>
                : <section className="surface" aria-labelledby="confirm-title">
                    <h2 id="confirm-title">Confirmer la suppression</h2>
                    <form onSubmit={submit} noValidate className="form-narrow">
                        <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
                        <TextField label="Votre mot de passe" type="password" autoComplete="current-password" required value={form.data.password} error={form.errors.password} onChange={(event) => form.setData('password', event.target.value)} />
                        <TextField label={`Saisissez le mot ${confirmationWord}`} autoComplete="off" required value={form.data.confirmation} error={form.errors.confirmation} onChange={(event) => form.setData('confirmation', event.target.value)} />
                        <div className="form-actions">
                            <Button type="submit" variant="danger" busy={form.processing}>Supprimer définitivement mon compte</Button>
                            <Link className="button button-secondary" href="/confidentialite/parametres">Ne pas supprimer</Link>
                        </div>
                    </form>
                </section>}
        </CampusLayout>
    );
}
