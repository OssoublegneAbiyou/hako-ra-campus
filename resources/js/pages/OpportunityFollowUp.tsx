import { Head, Link, router } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { StateMessage } from '../components/ui/Feedback';
import { ApplicationPanel, type Application } from '../components/ApplicationPanel';
import { DeadlineState, type Deadline } from '../components/OpportunityDeadline';

type Favorite = { id: string; title: string; organizer: string; campaign: string; deadline?: Deadline };
type Props = {
    favorites: { open: Favorite[]; soon: Favorite[]; expired: Favorite[]; suspended: Favorite[] }; applications: Application[];
    statuses: Record<string, string>; closingSoonDays: number; maxItems: number;
};

function FavoriteList({ title, hint, items }: { title: string; hint: string; items: Favorite[] }) {
    if (items.length === 0) return null;
    return <>
        <h3>{title} ({items.length})</h3>
        <p className="item-meta">{hint}</p>
        <ul className="plain-list">{items.map((item) => <li key={item.id}>
            <Link className="text-link" href={`/opportunites/${item.id}`}><strong>{item.title}</strong></Link> {item.deadline && <DeadlineState deadline={item.deadline} />}
            <p className="item-meta">{item.organizer} · campagne {item.campaign}{item.deadline && ` · date limite : ${item.deadline.text}`}{item.deadline?.previous && ` · ancienne date : ${item.deadline.previous.text}`}</p>
            <Button variant="secondary" onClick={() => router.delete(`/opportunites/${item.id}/favori`, { preserveScroll: true })}>Retirer de mes favoris</Button>
        </li>)}</ul>
    </>;
}

export default function OpportunityFollowUp({ favorites, applications, statuses, closingSoonDays, maxItems }: Props) {
    const day = new Intl.DateTimeFormat('fr', { dateStyle: 'medium' });
    const total = favorites.open.length + favorites.soon.length + favorites.expired.length + favorites.suspended.length;
    const state: Record<string, string> = { published: 'Fiche publiée', suspended: 'Fiche suspendue par l’équipe éditoriale', archived: 'Fiche clôturée et archivée' };
    return (
        <CampusLayout active="opportunities" title="Mon suivi">
            <Head title="Mon suivi" />
            <div className="page-heading"><p className="eyebrow">OPPORTUNITÉS</p><h1>Mon suivi</h1><p className="page-intro">Vos favoris et vos démarches. Tout ce qui figure ici est privé : ni enseignant, ni établissement, ni équipe de la plateforme n’y a accès.</p></div>

            <section className="surface" aria-labelledby="favorites-title">
                <h2 id="favorites-title">Mes favoris</h2>
                {total === 0 && <StateMessage kind="empty" title="Aucun favori" action={<Link className="button button-secondary" href="/opportunites">Parcourir les opportunités</Link>}>Ajoutez une fiche à vos favoris pour la retrouver ici, sur tous vos appareils.</StateMessage>}
                <FavoriteList title="Bientôt clos" hint={`Clôture connue dans les ${closingSoonDays} prochains jours.`} items={favorites.soon} />
                <FavoriteList title="Ouverts" hint="Y compris les fiches sans date limite connue." items={favorites.open} />
                <FavoriteList title="Suspendus" hint="Retirés du catalogue par l’équipe éditoriale le temps d’une vérification. Ne candidatez pas à partir d’un ancien lien." items={favorites.suspended} />
                <FavoriteList title="Expirés" hint="La date limite est passée. Ces fiches restent lisibles pour mémoire." items={favorites.expired} />
            </section>

            <section className="surface" aria-labelledby="applications-title">
                <h2 id="applications-title">Mes démarches</h2>
                {applications.length === 0 && <StateMessage kind="empty" title="Aucune démarche">Commencez une démarche depuis la page d’une fiche pour suivre votre candidature.</StateMessage>}
                <ul className="plain-list">{applications.map((application) => <li key={`${application.opportunityId}-${application.version}`}>
                    <Link className="text-link" href={`/opportunites/${application.opportunityId}`}><strong>{application.reference.title}</strong></Link> <span className="subtle-badge">{application.statusLabel}</span>
                    <p className="item-meta">{application.reference.category} · {application.reference.organizer} · campagne {application.reference.campaign} · date limite : {application.reference.deadline}{application.reference.sourceHost && ` · source : ${application.reference.sourceHost}`}</p>
                    <p className="item-meta">{state[application.opportunityStatus] ?? 'Fiche indisponible'} · résumé relevé le {day.format(new Date(application.reference.capturedAt))} · état choisi le {day.format(new Date(application.statusChangedAt))}</p>
                    <ApplicationPanel opportunityId={application.opportunityId} application={application} statuses={statuses} canStart={false} maxItems={maxItems} />
                </li>)}</ul>
            </section>
            <div className="inline-links"><Link className="text-link" href="/opportunites">Opportunités</Link><Link className="text-link" href="/opportunites/alertes">Mes alertes</Link><Link className="text-link" href="/opportunites/profil">Mes critères</Link></div>
        </CampusLayout>
    );
}
