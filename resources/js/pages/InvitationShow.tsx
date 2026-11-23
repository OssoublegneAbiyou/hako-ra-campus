import { Head, Link, useForm } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { StateMessage } from '../components/ui/Feedback';

type Props = {
    token: string;
    invalidMessage: string | null;
    invitation: { perimeter: string; role: string; expiresAt: string } | null;
    account: { name: string; verified: boolean } | null;
    registrationEnabled: boolean;
};

export default function InvitationShow({ token, invalidMessage, invitation, account, registrationEnabled }: Props) {
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    return (
        <CampusLayout active="profile" title="Invitation">
            <Head title="Invitation" />
            <div className="page-heading"><p className="eyebrow">RATTACHEMENT</p><h1>Invitation</h1></div>
            {!invitation
                ? <StateMessage kind="error" title="Invitation indisponible" action={<Link className="text-link" href="/">Retour à l’accueil</Link>}>{invalidMessage}</StateMessage>
                : <section className="surface form-narrow" aria-labelledby="invitation-title">
                    <h2 id="invitation-title">{invitation.perimeter}</h2>
                    <dl className="detail-list">
                        <div><dt>Rôle proposé</dt><dd>{invitation.role}</dd></div>
                        <div><dt>Lien valable jusqu’au</dt><dd>{new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(invitation.expiresAt))}</dd></div>
                    </dl>
                    <p className="section-description">Votre compte reste unique : ce rattachement s’ajoute à votre espace individuel et à vos autres appartenances. Il n’ouvre que le rôle indiqué ci-dessus.</p>
                    {errors.invitation && <StateMessage kind="error" title="Rattachement non effectué">{errors.invitation}</StateMessage>}
                    {account === null && <>
                        <StateMessage title="Vous avez déjà un compte Campus ?">Connectez-vous d’abord : l’invitation sera rattachée à ce compte, sans en créer un second.</StateMessage>
                        <div className="form-actions">
                            <Link className="button button-primary" href="/connexion">Me connecter avec mon compte</Link>
                            {registrationEnabled && <Link className="button button-secondary" href="/inscription">Je n’ai pas encore de compte</Link>}
                        </div>
                    </>}
                    {account !== null && !account.verified && <StateMessage kind="error" title="Compte à vérifier" action={<Link className="text-link" href="/verification-email">Vérifier mon adresse</Link>}>Vérifiez votre adresse électronique avant d’accepter cette invitation.</StateMessage>}
                    {account !== null && account.verified && <div className="form-actions">
                        <Button busy={form.processing} onClick={() => form.post(`/invitation/${token}/acceptation`)}>Rattacher à mon compte ({account.name})</Button>
                        <Link className="button button-secondary" href="/profil">Plus tard</Link>
                    </div>}
                </section>}
        </CampusLayout>
    );
}
