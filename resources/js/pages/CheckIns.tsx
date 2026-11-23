import { Head, Link } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { Pagination } from '../components/ui/Collection';
import { ProvenanceBadge, StateMessage } from '../components/ui/Feedback';

type Pending = { occurrenceId: string; title: string; startsAt: string; endsAt: string; provenance: 'personal' | 'institutional'; deferred: boolean };
type Past = { occurrenceId: string; title: string; startsAt: string; status: string; statusLabel: string };
type Props = { pending: Pending[]; pendingDays: number; history: Past[]; pagination: { page: number; total: number; previous: string | null; next: string | null } };

export default function CheckIns({ pending, pendingDays, history, pagination }: Props) {
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    return (
        <CampusLayout active="planning" title="Mes retours de cours">
            <Head title="Mes retours de cours" />
            <div className="page-heading"><p className="eyebrow">RETOURS PRIVÉS</p><h1>Mes retours de cours</h1><p className="page-intro">Après un cours, notez en une minute ce qu’il vous reste à faire et ce qui est à revoir. Ces retours sont privés et sans aucune pénalité.</p></div>

            <section className="surface" aria-labelledby="pending-title">
                <h2 id="pending-title">À renseigner</h2>
                <p className="section-description">Cours terminés depuis moins de {pendingDays} jours. Cette liste ne dépend d’aucune notification.</p>
                {pending.length === 0
                    ? <StateMessage kind="empty" title="Aucun retour en attente">Vos prochains cours terminés apparaîtront ici.</StateMessage>
                    : <ul className="plain-list">{pending.map((course) => <li key={course.occurrenceId}>
                        <strong>{course.title}</strong> <ProvenanceBadge source={course.provenance} />
                        <p className="item-meta">Terminé le {when.format(new Date(course.endsAt))}{course.deferred && ' · reporté à plus tard'}</p>
                        <Link className="button button-primary" href={`/planning/occurrences/${course.occurrenceId}/retour`}>{course.deferred ? 'Reprendre mon retour' : 'Renseigner mon retour'}</Link>
                    </li>)}</ul>}
            </section>

            <section className="surface" aria-labelledby="history-title">
                <h2 id="history-title">Mes retours</h2>
                {history.length === 0
                    ? <StateMessage kind="empty" title="Aucun retour enregistré" />
                    : <ul className="plain-list">{history.map((checkIn) => <li key={checkIn.occurrenceId}>
                        <strong>{checkIn.title}</strong>
                        <p className="item-meta">{when.format(new Date(checkIn.startsAt))} · {checkIn.statusLabel}</p>
                        <Link className="text-link" href={`/planning/occurrences/${checkIn.occurrenceId}/retour`}>Ouvrir</Link>
                    </li>)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>
            <div className="inline-links"><Link className="text-link" href="/planning">Retour au planning</Link></div>
        </CampusLayout>
    );
}
