import { Head, Link, router } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { Pagination } from '../components/ui/Collection';
import { StateMessage } from '../components/ui/Feedback';

type Item = { id: number; category: string; categoryLabel: string; title: string; body: string | null; url: string; deliveredAt: string; read: boolean };
type Props = { notifications: Item[]; pagination: { page: number; total: number; previous: string | null; next: string | null }; unread: number };

export default function Notifications({ notifications, pagination, unread }: Props) {
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    return (
        <CampusLayout active="home" title="Notifications">
            <Head title="Notifications" />
            <div className="page-heading"><p className="eyebrow">CENTRE DE NOTIFICATIONS</p><h1>Notifications</h1><p className="page-intro">Tous vos rappels sont ici, même si vous refusez les notifications du navigateur ou les courriels.</p></div>
            <section className="surface" aria-labelledby="list-title">
                <h2 id="list-title">{unread > 0 ? `${unread} non lue(s)` : 'Tout est lu'}</h2>
                {notifications.length === 0
                    ? <StateMessage kind="empty" title="Aucune notification">Vos rappels de cours, de tâches et de planning apparaîtront ici.</StateMessage>
                    : <ul className="plain-list">{notifications.map((item) => <li key={item.id}>
                        <strong>{item.title}</strong>{!item.read && <span className="subtle-badge"> Non lue</span>}
                        <p className="item-meta">{item.categoryLabel} · {when.format(new Date(item.deliveredAt))}</p>
                        {item.body && <p>{item.body}</p>}
                        <Button variant="secondary" onClick={() => router.post(`/notifications/${item.id}/lecture`)}>Ouvrir</Button>
                    </li>)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
                {unread > 0 && <div className="form-actions"><Button variant="secondary" onClick={() => router.post('/notifications/lecture', {}, { preserveScroll: true })}>Tout marquer comme lu</Button></div>}
            </section>
            <div className="inline-links"><Link className="text-link" href="/notifications/preferences">Préférences de notification</Link></div>
        </CampusLayout>
    );
}
