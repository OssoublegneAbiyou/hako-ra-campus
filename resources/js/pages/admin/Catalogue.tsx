import { Head, Link, router, usePage } from '@inertiajs/react';
import CampusLayout from '../../layouts/CampusLayout';
import { Pagination } from '../../components/ui/Collection';
import { StateMessage } from '../../components/ui/Feedback';
import { SelectField } from '../../components/ui/Fields';
import { OpportunityForm, type CatalogueOptions } from '../../components/OpportunityForm';

type Item = { id: string; title: string; organizer: string; campaign: string; categoryLabel: string; status: string; statusLabel: string; deadline: string; updatedAt: string };
type Props = { opportunities: Item[]; pagination: { page: number; total: number; previous: string | null; next: string | null }; filter: string; options: CatalogueOptions; openReports: number };

const STATUSES: Record<string, string> = { draft: 'Brouillon', review: 'À vérifier', published: 'Publiée', suspended: 'Suspendue', archived: 'Archivée' };

export default function Catalogue({ opportunities, pagination, filter, options, openReports }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    return (
        <CampusLayout active="opportunities" title="Catalogue éditorial">
            <Head title="Catalogue éditorial" />
            <div className="page-heading"><p className="eyebrow">ADMINISTRATION ÉDITORIALE</p><h1>Catalogue des opportunités</h1><p className="page-intro">Brouillon, vérification, publication, suspension, archivage. Seules les fiches publiées sont visibles du public.</p></div>
            {errors.reference && <StateMessage kind="error" title="Action non enregistrée">{errors.reference}</StateMessage>}
            <div className="inline-links"><Link className="text-link" href="/administration/catalogue/signalements">Signalements à traiter ({openReports})</Link><Link className="text-link" href="/opportunites">Voir le catalogue public</Link></div>

            <section className="surface" aria-labelledby="list-title">
                <h2 id="list-title">Fiches</h2>
                <div className="filter-bar">
                    <SelectField label="État" value={filter} onChange={(event) => router.get('/administration/catalogue', event.target.value ? { statut: event.target.value } : {}, { preserveScroll: true })}>
                        <option value="">Tous les états</option>{Object.entries(STATUSES).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </SelectField>
                </div>
                {opportunities.length === 0 ? <StateMessage kind="empty" title="Aucune fiche" />
                    : <ul className="plain-list">{opportunities.map((item) => <li key={item.id}>
                        <Link className="text-link" href={`/administration/catalogue/${item.id}`}><strong>{item.title}</strong></Link> <span className="subtle-badge">{item.statusLabel}</span>
                        <p className="item-meta">{item.categoryLabel} · {item.organizer} · campagne {item.campaign} · {item.deadline} · modifiée le {when.format(new Date(item.updatedAt))}</p>
                    </li>)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>

            <section className="surface" aria-labelledby="create-title">
                <h2 id="create-title">Nouvelle fiche</h2>
                <p className="section-description">La fiche est créée en brouillon. Elle ne sera publiée qu’après votre vérification explicite de la source.</p>
                <OpportunityForm options={options} />
            </section>
        </CampusLayout>
    );
}
