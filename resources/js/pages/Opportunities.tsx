import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { Pagination } from '../components/ui/Collection';
import { StateMessage } from '../components/ui/Feedback';
import { CheckboxField, SelectField, TextField } from '../components/ui/Fields';
import { DeadlineState, type Deadline } from '../components/OpportunityDeadline';

type Item = {
    id: string; categoryLabel: string; title: string; organizer: string; campaign: string; countryLabels: string[]; modality: string; funding: string; fees: string; fields: string[];
    deadline: Deadline; verifiedAt: string | null; availability: string; match: { overall: 'match' | 'check' | 'mismatch'; overallLabel: string } | null;
};
type Filters = { q: string; categorie: string; niveau: string; pays: string; domaine: string; modalite: string; financement: string; langue: string; cloture: string; tri: string };
type Option = { value: string; label: string };
type Props = {
    opportunities: Item[]; pagination: { page: number; total: number; previous: string | null; next: string | null; count: number }; filters: Filters;
    options: { categories: Record<string, string>; levels: Record<string, string>; modalities: Record<string, string>; funding: Record<string, string>; closings: Record<string, string>; sorts: Record<string, string>; countries: Option[]; languages: Option[]; fields: string[] };
    searchedTerms: string[]; relevanceRule: string; signedIn: boolean; hasProfile: boolean; matchDisclaimer: string;
};

const DEFAULTS: Filters = { q: '', categorie: '', niveau: '', pays: '', domaine: '', modalite: '', financement: '', langue: '', cloture: 'ouvertes', tri: 'cloture' };

export default function Opportunities({ opportunities, pagination, filters, options, searchedTerms, relevanceRule, signedIn, hasProfile, matchDisclaimer }: Props) {
    const [form, setForm] = useState<Filters>(filters);
    const [saving, setSaving] = useState(false);
    const [alert, setAlert] = useState({ name: '', frequency: 'daily', email: false, push: false });
    const savable = (['q', 'categorie', 'niveau', 'pays', 'domaine', 'modalite', 'financement', 'langue'] as (keyof Filters)[]).filter((key) => filters[key] !== '');
    const saveSearch = (event: FormEvent) => {
        event.preventDefault();
        router.post('/opportunites/alertes', { ...alert, filters: Object.fromEntries(savable.map((key) => [key, filters[key]])) });
    };
    const errors = usePage().props.errors as Record<string, string>;
    const day = new Intl.DateTimeFormat('fr', { dateStyle: 'medium' });
    const set = (key: keyof Filters, value: string) => setForm({ ...form, [key]: value });
    // Seuls les filtres différents de leur valeur par défaut partent dans l'adresse : elle reste lisible et partageable.
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/opportunites', Object.fromEntries(Object.entries(form).filter(([key, value]) => value !== '' && value !== DEFAULTS[key as keyof Filters])), { preserveScroll: true });
    };
    const active = (Object.keys(DEFAULTS) as (keyof Filters)[]).filter((key) => filters[key] !== DEFAULTS[key]);
    const select = (label: string, key: keyof Filters, entries: [string, string][], empty: string) => <SelectField label={label} value={form[key]} error={errors[key]} onChange={(event) => set(key, event.target.value)}>
        <option value="">{empty}</option>{entries.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
    </SelectField>;
    return (
        <CampusLayout active="opportunities" title="Opportunités">
            <Head title="Opportunités" />
            <div className="page-heading"><p className="eyebrow">CATALOGUE VÉRIFIÉ</p><h1>Opportunités</h1><p className="page-intro">Bourses, concours, stages et formations. Chaque fiche renvoie à la source officielle, vérifiée par l’équipe éditoriale à la date indiquée. Cette vérification ne garantit ni votre éligibilité ni l’obtention.</p></div>

            <section className="surface" aria-labelledby="filters-title">
                <h2 id="filters-title">Rechercher et filtrer</h2>
                <form onSubmit={submit} role="search">
                    <div className="filter-bar">
                        <TextField label="Titre, organisateur ou domaine" type="search" maxLength={100} value={form.q} error={errors.q} onChange={(event) => set('q', event.target.value)} />
                        {select('Catégorie', 'categorie', Object.entries(options.categories), 'Toutes')}
                        {select('Niveau', 'niveau', Object.entries(options.levels).filter(([value]) => value !== 'any'), 'Tous')}
                        {select('Pays où se déroule l’opportunité', 'pays', options.countries.map((item) => [item.value, item.label]), 'Tous')}
                        {select('Domaine', 'domaine', options.fields.map((field) => [field, field]), 'Tous')}
                        {select('Modalité', 'modalite', Object.entries(options.modalities), 'Toutes')}
                        {select('Financement', 'financement', Object.entries(options.funding), 'Tous')}
                        {select('Langue', 'langue', options.languages.map((item) => [item.value, item.label]), 'Toutes')}
                        <SelectField label="Clôture" value={form.cloture} error={errors.cloture} onChange={(event) => set('cloture', event.target.value)}>
                            {Object.entries(options.closings).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
                        </SelectField>
                        <SelectField label="Tri" value={form.tri} error={errors.tri} onChange={(event) => set('tri', event.target.value)}>
                            {Object.entries(options.sorts).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
                        </SelectField>
                    </div>
                    <div className="form-actions"><Button type="submit">Appliquer</Button>{active.length > 0 && <Link className="button button-secondary" href="/opportunites">Réinitialiser les filtres ({active.length})</Link>}</div>
                </form>
                <p className="item-meta">{form.tri === 'pertinence' ? relevanceRule : 'Tri par défaut : clôture connue la plus proche d’abord, puis les fiches sans date limite connue, par titre.'}</p>
                {signedIn && savable.length > 0 && !saving && <div className="form-actions"><Button variant="secondary" onClick={() => setSaving(true)}>Enregistrer cette recherche et être prévenu</Button></div>}
                {signedIn && saving && <form onSubmit={saveSearch} className="form-narrow">
                    <p className="item-meta">Vous serez prévenu des fiches publiées ou mises à jour à partir de maintenant qui répondent à ces filtres, toujours parmi les fiches ouvertes. Les fiches déjà au catalogue ne sont pas annoncées.</p>
                    {errors.filters && <p className="field-error" role="alert">{errors.filters}</p>}
                    <TextField label="Nom de la recherche (facultatif)" maxLength={100} value={alert.name} error={errors.name} onChange={(event) => setAlert({ ...alert, name: event.target.value })} />
                    <SelectField label="Fréquence des synthèses" value={alert.frequency} error={errors.frequency} onChange={(event) => setAlert({ ...alert, frequency: event.target.value })}>
                        <option value="daily">Une synthèse par jour au plus</option><option value="weekly">Une synthèse par semaine au plus</option>
                    </SelectField>
                    <CheckboxField label="Aussi par courriel" hint="Selon vos préférences de notification. Le centre de notifications reçoit toujours la synthèse." checked={alert.email} onChange={(event) => setAlert({ ...alert, email: event.target.checked })} />
                    <CheckboxField label="Aussi sur mes appareils" checked={alert.push} onChange={(event) => setAlert({ ...alert, push: event.target.checked })} />
                    <div className="form-actions"><Button type="submit">Enregistrer la recherche</Button><Button variant="secondary" onClick={() => setSaving(false)}>Annuler</Button></div>
                </form>}
                {filters.cloture === '30j' && <p className="item-meta">Les fiches sans date limite connue (clôture continue ou date non communiquée) ne peuvent pas figurer dans ce filtre : choisissez « Sans date limite connue » pour les voir.</p>}
            </section>

            <section className="surface" aria-labelledby="list-title">
                <h2 id="list-title">{pagination.count} fiche{pagination.count > 1 ? 's' : ''}{searchedTerms.length > 0 && ` pour « ${searchedTerms.join(' ')} »`}</h2>
                {signedIn && !hasProfile && <StateMessage title="Comparer avec votre situation" action={<Link className="button button-secondary" href="/opportunites/profil">Déclarer mes critères</Link>}>Déclarez, si vous le souhaitez, votre niveau, vos domaines et vos langues : chaque fiche indiquera ce qui correspond et ce qui reste à vérifier.</StateMessage>}
                {hasProfile && <p className="item-meta">{matchDisclaimer} <Link className="text-link" href="/opportunites/profil">Modifier mes critères</Link></p>}
                {signedIn && <div className="inline-links"><Link className="text-link" href="/opportunites/suivi">Mes favoris et mes démarches</Link><Link className="text-link" href="/opportunites/alertes">Mes alertes</Link></div>}
                {opportunities.length === 0
                    ? <StateMessage kind="empty" title="Aucune fiche ne correspond à ces filtres" action={active.length > 0 ? <Link className="button button-secondary" href="/opportunites">Réinitialiser les filtres</Link> : undefined}>Le catalogue ne contient que des fiches vérifiées par l’équipe éditoriale.</StateMessage>
                    : <ul className="plain-list">{opportunities.map((item) => <li key={item.id}>
                        <Link className="text-link" href={`/opportunites/${item.id}`}><strong>{item.title}</strong></Link> {item.availability === 'archived' ? <span className="subtle-badge">Clôturée et archivée</span> : <DeadlineState deadline={item.deadline} />}
                        {item.match && <span className="subtle-badge"> {item.match.overallLabel}</span>}
                        <p className="item-meta">{item.categoryLabel} · {item.organizer} · campagne {item.campaign} · {item.funding} · {item.fees} · {item.modality}{item.countryLabels.length > 0 && ` · ${item.countryLabels.join(', ')}`}{item.fields.length > 0 && ` · ${item.fields.join(', ')}`}</p>
                        <p className="item-meta">Date limite : {item.deadline.text}{item.verifiedAt && ` · source vérifiée le ${day.format(new Date(item.verifiedAt))}`}</p>
                    </li>)}</ul>}
                <Pagination currentPage={pagination.page} totalPages={pagination.total} previousHref={pagination.previous ?? undefined} nextHref={pagination.next ?? undefined} />
            </section>
        </CampusLayout>
    );
}
