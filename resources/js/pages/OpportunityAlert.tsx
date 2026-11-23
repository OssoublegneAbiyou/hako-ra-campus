import { Head, Link, router } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { StateMessage } from '../components/ui/Feedback';
import type { SavedSearch } from './OpportunityAlerts';

type Announced = {
    id: number; kind: 'new' | 'updated'; kindLabel: string; detail: string | null; announcedAt: string; opportunityId: string; title: string; organizer: string;
    availability: string; sourceUrl: string | null; sourceHost: string | null; deadline: string | null;
};
type Props = { search: SavedSearch; announced: Announced[] };

export default function OpportunityAlert({ search, announced }: Props) {
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    return (
        <CampusLayout active="opportunities" title={search.name}>
            <Head title={search.name} />
            <div className="page-heading"><p className="eyebrow">ALERTE · {search.active ? 'ACTIVE' : 'ARRÊTÉE'}</p><h1>{search.name}</h1><p className="page-intro">Fiches annoncées parce qu’elles répondent à vos critères : {search.criteria.join(' · ')}.</p></div>

            <section className="surface" aria-labelledby="state-title">
                <h2 id="state-title">État de l’alerte</h2>
                <p role="status">{search.active ? `${search.frequencyLabel}.` : 'Alertes arrêtées : aucune synthèse n’est produite.'} {search.lastRunAt ? `Dernière vérification le ${when.format(new Date(search.lastRunAt))}.` : 'Pas encore vérifiée.'}</p>
                <div className="form-actions">
                    {search.active
                        ? <Button variant="danger" onClick={() => router.patch(`/opportunites/alertes/${search.id}`, { expected_version: search.version, name: search.name, frequency: search.frequency, email: search.email, push: search.push, active: false }, { preserveScroll: true })}>Arrêter les alertes de cette recherche</Button>
                        : <Button onClick={() => router.patch(`/opportunites/alertes/${search.id}`, { expected_version: search.version, name: search.name, frequency: search.frequency, email: search.email, push: search.push, active: true }, { preserveScroll: true })}>Reprendre les alertes</Button>}
                    <Link className="button button-secondary" href={`/opportunites?${search.query}`}>Lancer cette recherche</Link>
                </div>
                <p className="item-meta">Arrêter une alerte ne supprime ni vos favoris ni vos démarches.</p>
            </section>

            <section className="surface" aria-labelledby="announced-title">
                <h2 id="announced-title">Fiches annoncées</h2>
                {announced.length === 0 ? <StateMessage kind="empty" title="Aucune fiche annoncée pour l’instant">Seules les fiches publiées ou mises à jour après l’enregistrement de la recherche sont annoncées.</StateMessage>
                    : <ul className="plain-list">{announced.map((item) => <li key={item.id}>
                        <strong>{item.title}</strong> <span className="subtle-badge">{item.kindLabel}</span>
                        <p className="item-meta">{item.organizer} · annoncée le {when.format(new Date(item.announcedAt))}{item.deadline && ` · date limite : ${item.deadline}`}</p>
                        {item.detail && <p className="item-meta">{item.detail}</p>}
                        {item.availability === 'published'
                            ? <div className="form-actions">
                                <Link className="button button-secondary" href={`/opportunites/${item.opportunityId}`}>Ouvrir la fiche</Link>
                                {item.sourceUrl && <a className="text-link" href={item.sourceUrl} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">Source officielle ({item.sourceHost}) <span aria-hidden="true">↗</span></a>}
                            </div>
                            : <p className="item-meta">{item.availability === 'suspended' ? 'Fiche suspendue depuis par l’équipe éditoriale : elle n’est plus proposée. Ne candidatez pas à partir d’un ancien lien.' : 'Fiche clôturée depuis.'}</p>}
                    </li>)}</ul>}
            </section>
            <div className="inline-links"><Link className="text-link" href="/opportunites/alertes">Toutes mes alertes</Link></div>
        </CampusLayout>
    );
}
