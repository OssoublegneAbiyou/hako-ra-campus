import { Head, useForm } from '@inertiajs/react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { StateMessage } from '../../components/ui/Feedback';
import { purgeAll } from '../../offline/store';

export default function VerifyEmail({ email, mailConfigured, expiresInMinutes }: { email: string; mailConfigured: boolean; expiresInMinutes: number }) {
    const resend = useForm({});
    const logout = useForm({});
    return (
        <CampusLayout active="profile" title="Vérification de l’adresse">
            <Head title="Vérification de l’adresse" />
            <div className="page-heading"><p className="eyebrow">VOTRE COMPTE</p><h1>Vérifiez votre adresse</h1><p className="page-intro">Un lien de vérification est adressé à {email}. Il expire après {expiresInMinutes} minutes. Votre espace s’ouvre une fois l’adresse vérifiée.</p></div>
            {!mailConfigured && <StateMessage kind="error" title="Envoi de courriels non configuré sur cet environnement">Aucun message ne quitte ce serveur : le lien ne peut pas être reçu tant qu’un service d’envoi n’est pas configuré.</StateMessage>}
            <section className="surface form-narrow" aria-labelledby="verify-title">
                <h2 id="verify-title">Lien non reçu ?</h2>
                <p className="section-description">Vérifiez les courriers indésirables, puis demandez un nouveau lien.</p>
                <div className="form-actions">
                    <Button busy={resend.processing} onClick={() => resend.post('/verification-email/renvoi')}>Demander un nouveau lien</Button>
                    <Button variant="secondary" busy={logout.processing} onClick={() => { void purgeAll({ unsubscribePush: true }).finally(() => logout.post('/deconnexion')); }}>Se déconnecter</Button>
                </div>
            </section>
        </CampusLayout>
    );
}
