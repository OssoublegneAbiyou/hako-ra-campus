import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { StateMessage } from '../components/ui/Feedback';
import { CheckboxField, SelectField, TextField } from '../components/ui/Fields';

export type SavedSearch = {
    id: number; version: number; name: string; criteria: string[]; query: string; frequency: string; frequencyLabel: string; email: boolean; push: boolean; active: boolean;
    since: string; lastRunAt: string | null; lastDigestAt: string | null; announced?: number;
};
type Props = {
    searches: SavedSearch[]; frequencies: Record<string, string>; channels: { email: boolean; push: boolean; category: { enabled: boolean; email: boolean; push: boolean } };
    limits: { searches: number; items: number }; reminders: { first: number; second: number };
};

function Row({ search, frequencies, channels }: { search: SavedSearch; frequencies: Record<string, string>; channels: Props['channels'] }) {
    const [form, setForm] = useState({ name: search.name, frequency: search.frequency, email: search.email, push: search.push });
    const [deleting, setDeleting] = useState(false);
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    const save = (change: Partial<typeof form & { active: boolean }>) => router.patch(`/opportunites/alertes/${search.id}`, { expected_version: search.version, ...form, active: search.active, ...change }, { preserveScroll: true });
    return <li>
        <strong>{search.name}</strong> <span className="subtle-badge">{search.active ? 'Alertes actives' : 'Alertes arrêtées'}</span>
        <p className="item-meta">{search.criteria.join(' · ')}</p>
        <p className="item-meta" role="status">
            {search.active ? `${search.frequencyLabel}. Fiches publiées ou mises à jour depuis le ${when.format(new Date(search.since))}.` : 'Aucune synthèse n’est produite tant que les alertes sont arrêtées.'}
            {search.lastRunAt ? ` Dernière vérification : ${when.format(new Date(search.lastRunAt))}.` : ' Pas encore vérifiée.'}
            {search.lastDigestAt ? ` Dernière synthèse : ${when.format(new Date(search.lastDigestAt))}.` : ' Aucune synthèse envoyée.'}
            {` ${search.announced ?? 0} fiche(s) annoncée(s) en tout.`}
        </p>
        <div className="form-narrow">
            <TextField label={`Nom — ${search.name}`} maxLength={100} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
            <SelectField label={`Fréquence — ${search.name}`} value={form.frequency} onChange={(event) => setForm({ ...form, frequency: event.target.value })}>
                {Object.entries(frequencies).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </SelectField>
            <CheckboxField label={`Aussi par courriel — ${search.name}`} hint={!channels.email ? 'Courriel non configuré sur ce serveur.' : !channels.category.email ? 'Activez d’abord le courriel pour « Opportunités et favoris » dans vos préférences de notification.' : 'Le courriel ne contient qu’un titre neutre et un lien.'} checked={form.email} onChange={(event) => setForm({ ...form, email: event.target.checked })} />
            <CheckboxField label={`Aussi sur mes appareils — ${search.name}`} hint={!channels.push ? 'Notifications du navigateur non configurées sur ce serveur.' : !channels.category.push ? 'Activez d’abord vos appareils pour « Opportunités et favoris » dans vos préférences de notification.' : undefined} checked={form.push} onChange={(event) => setForm({ ...form, push: event.target.checked })} />
            <div className="form-actions">
                <Button variant="secondary" onClick={() => save({})}>Enregistrer</Button>
                {search.active
                    ? <Button variant="danger" onClick={() => save({ active: false })}>Arrêter les alertes</Button>
                    : <Button onClick={() => save({ active: true })}>Reprendre les alertes</Button>}
                <Button variant="danger" onClick={() => setDeleting(true)}>Supprimer la recherche</Button>
            </div>
        </div>
        <div className="inline-links"><Link className="text-link" href={`/opportunites/alertes/${search.id}`}>Fiches annoncées</Link><Link className="text-link" href={`/opportunites?${search.query}`}>Lancer cette recherche</Link></div>
        <ConfirmDialog open={deleting} title="Supprimer cette recherche ?" confirmLabel="Supprimer la recherche" onCancel={() => setDeleting(false)} onConfirm={() => router.delete(`/opportunites/alertes/${search.id}`, { onFinish: () => setDeleting(false) })}>
            La recherche « {search.name} » et l’historique de ses annonces seront supprimés. Vos favoris et vos démarches sont conservés.
        </ConfirmDialog>
    </li>;
}

export default function OpportunityAlerts({ searches, frequencies, channels, limits, reminders }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    return (
        <CampusLayout active="opportunities" title="Mes alertes">
            <Head title="Mes alertes" />
            <div className="page-heading"><p className="eyebrow">OPPORTUNITÉS</p><h1>Mes alertes</h1><p className="page-intro">Vos recherches enregistrées vous préviennent des nouvelles fiches et des changements de date. Tout est privé, et vous pouvez arrêter chaque alerte à tout moment sans perdre vos favoris.</p></div>
            {errors.reference && <StateMessage kind="error" title="Action non enregistrée">{errors.reference}</StateMessage>}
            {!channels.category.enabled && <StateMessage title="Catégorie désactivée">Les notifications « Opportunités et favoris » sont désactivées dans vos préférences : aucune synthèse ni rappel ne vous sera remis, même pour une alerte active. <Link className="text-link" href="/notifications/preferences">Ouvrir mes préférences</Link></StateMessage>}

            <section className="surface" aria-labelledby="searches-title">
                <h2 id="searches-title">Recherches enregistrées ({searches.length} sur {limits.searches})</h2>
                <p className="section-description">Une synthèse regroupe au plus {limits.items} fiches et n’annonce jamais deux fois la même. Les fiches qui existaient avant l’enregistrement ne sont pas annoncées.</p>
                {searches.length === 0
                    ? <StateMessage kind="empty" title="Aucune recherche enregistrée" action={<Link className="button button-secondary" href="/opportunites">Ouvrir le catalogue</Link>}>Appliquez des filtres dans le catalogue, puis « Enregistrer cette recherche ».</StateMessage>
                    : <ul className="plain-list">{searches.map((search) => <Row key={`${search.id}-${search.version}`} search={search} frequencies={frequencies} channels={channels} />)}</ul>}
            </section>

            <section className="surface" aria-labelledby="reminders-title">
                <h2 id="reminders-title">Rappels des fiches que je suis</h2>
                <p>{reminders.first === 0 && reminders.second === 0 ? 'Rappels d’échéance désactivés.' : `Rappel ${[reminders.first, reminders.second].filter((days) => days > 0).map((days) => `${days} jour${days > 1 ? 's' : ''}`).join(' puis ')} avant la date limite de vos favoris et de vos démarches en cours.`}</p>
                <p className="item-meta">Seulement quand la fiche publie une date. Pour une date sans heure, le rappel part à votre heure de rappel habituelle : aucune heure de clôture n’est supposée. Vous êtes aussi prévenu si une fiche suivie est suspendue ou si sa date change.</p>
                <div className="inline-links"><Link className="text-link" href="/notifications/preferences">Régler ces rappels et les canaux</Link><Link className="text-link" href="/opportunites/suivi">Mes favoris et mes démarches</Link></div>
            </section>
        </CampusLayout>
    );
}
