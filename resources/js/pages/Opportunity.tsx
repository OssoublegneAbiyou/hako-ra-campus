import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { rememberConsulted } from '../offline/store';
import type { CampusProps } from '../types';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, SelectField, TextAreaField } from '../components/ui/Fields';
import { DeadlineDetail, type Deadline } from '../components/OpportunityDeadline';
import { ApplicationPanel, type Application } from '../components/ApplicationPanel';

type Detail = {
    id: string; category: string; categoryLabel: string; title: string; organizer: string; campaign: string; sourceUrl: string | null; sourceHost: string | null; applicationUrl: string | null; applicationHost: string | null;
    summary: string | null; conditions: string | null; countryLabels: string[]; modality: string | null; levels: string[]; fields: string[]; languageLabels: string[]; eligibleNationalities: string[]; eligibleResidences: string[];
    funding: string | null; fees: string | null; feesDetail: string | null; deadline: Deadline | null; verifiedAt: string | null; availability: 'published' | 'suspended' | 'archived';
};
type Criterion = { key: string; label: string; result: 'match' | 'check' | 'mismatch'; resultLabel: string; explanation: string };
type Props = {
    opportunity: Detail; reportReasons: Record<string, string>; canReport: boolean; myReport: { status: string; statusLabel: string; decisionLabel: string | null } | null;
    signedIn: boolean; hasProfile: boolean; match: { overall: string; overallLabel: string; criteria: Criterion[] } | null; matchDisclaimer: string;
    favorite: boolean; canFavorite: boolean; application: Application | null; applicationStatuses: Record<string, string>;
};

export default function Opportunity({ opportunity, reportReasons, canReport, myReport, signedIn, hasProfile, match, matchDisclaimer, favorite, canFavorite, application, applicationStatuses }: Props) {
    const [reporting, setReporting] = useState(false);
    const form = useForm({ reason: 'broken_link', message: '' });
    const day = new Intl.DateTimeFormat('fr', { dateStyle: 'long' });
    const accountKey = usePage<CampusProps>().props.auth.accountKey ?? null;
    const deadlineText = opportunity.deadline?.text ?? null;
    useEffect(() => {
        // Sur appareil personnel seulement : la fiche publique consultée est retenue pour la relire hors connexion.
        if (opportunity.availability === 'published') void rememberConsulted(accountKey, { id: opportunity.id, title: opportunity.title, organizer: opportunity.organizer, deadline: deadlineText });
    }, [accountKey, opportunity.id, opportunity.title, opportunity.organizer, opportunity.availability, deadlineText]);
    const submit = (event: FormEvent) => { event.preventDefault(); form.post(`/opportunites/${opportunity.id}/signalement`, { preserveScroll: true, onSuccess: () => setReporting(false) }); };
    const open = myReport && (myReport.status === 'new' || myReport.status === 'in_progress');
    const list = (values: string[]) => values.length > 0 ? values.join(', ') : 'Non précisé';
    const suspended = opportunity.availability === 'suspended';
    const archived = opportunity.availability === 'archived';
    return (
        <CampusLayout active="opportunities" title={opportunity.title}>
            <Head title={opportunity.title} />
            <div className="page-heading"><p className="eyebrow">{opportunity.categoryLabel.toUpperCase()} · CAMPAGNE {opportunity.campaign.toUpperCase()}</p><h1>{opportunity.title}</h1><p className="page-intro">{opportunity.organizer}</p></div>

            {suspended && <StateMessage kind="error" title="Fiche suspendue par l’équipe éditoriale">Cette fiche a été retirée du catalogue le temps d’une vérification. Ne candidatez pas à partir d’un ancien lien : attendez qu’elle soit de nouveau publiée, ou consultez directement le site officiel de l’organisateur.</StateMessage>}
            {archived && <StateMessage title="Fiche clôturée et archivée">La date limite est passée. Cette fiche reste lisible pour mémoire ; elle n’apparaît plus dans la liste des fiches ouvertes.</StateMessage>}

            {!suspended && opportunity.deadline && <section className="surface" aria-labelledby="deadline-title">
                <h2 id="deadline-title">Date limite</h2>
                <DeadlineDetail deadline={opportunity.deadline} />
            </section>}

            {!suspended && opportunity.sourceUrl && <section className="surface" aria-labelledby="source-title">
                <h2 id="source-title">Source officielle</h2>
                {opportunity.verifiedAt && <p><strong>Source vérifiée le {day.format(new Date(opportunity.verifiedAt))}</strong> par l’équipe éditoriale.</p>}
                <p className="item-meta">Cette vérification porte sur l’existence et l’authenticité de la source à cette date. Elle ne garantit ni votre éligibilité, ni l’obtention, ni que les informations n’ont pas changé depuis : relisez toujours la source.</p>
                <div className="form-actions">
                    <a className="button button-secondary" href={opportunity.sourceUrl} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">Ouvrir la source officielle ({opportunity.sourceHost}) <span aria-hidden="true">↗</span></a>
                    {opportunity.applicationUrl && <a className="button button-primary" href={opportunity.applicationUrl} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">Candidater sur le site de l’organisateur ({opportunity.applicationHost}) <span aria-hidden="true">↗</span></a>}
                </div>
                <p className="item-meta">Ces liens ouvrent un site extérieur à Campus, dans un nouvel onglet. Aucune information de votre compte n’y est transmise, et Campus n’enregistre pas que vous les avez ouverts : ouvrir un lien ne signifie pas que vous avez candidaté.</p>
            </section>}

            {!suspended && <section className="surface" aria-labelledby="content-title">
                <h2 id="content-title">Conditions publiées par l’organisateur</h2>
                {opportunity.summary && <p style={{ whiteSpace: 'pre-line' }}>{opportunity.summary}</p>}
                {opportunity.conditions && <p style={{ whiteSpace: 'pre-line' }}>{opportunity.conditions}</p>}
                <dl className="detail-list">
                    <dt>Organisateur</dt><dd>{opportunity.organizer}</dd>
                    <dt>Financement</dt><dd>{opportunity.funding}</dd>
                    <dt>Frais</dt><dd>{opportunity.fees}{opportunity.feesDetail && ` — ${opportunity.feesDetail}`}</dd>
                    <dt>Modalité</dt><dd>{opportunity.modality}</dd>
                    <dt>Pays où se déroule l’opportunité</dt><dd>{list(opportunity.countryLabels)}</dd>
                    <dt>Niveaux</dt><dd>{list(opportunity.levels)}</dd>
                    <dt>Domaines</dt><dd>{list(opportunity.fields)}</dd>
                    <dt>Langues</dt><dd>{list(opportunity.languageLabels)}</dd>
                    <dt>Nationalités admises</dt><dd>{list(opportunity.eligibleNationalities)}</dd>
                    <dt>Pays de résidence admis</dt><dd>{list(opportunity.eligibleResidences)}</dd>
                </dl>
            </section>}

            {!suspended && <section className="surface" aria-labelledby="match-title">
                <h2 id="match-title">Comparer avec votre situation</h2>
                {!signedIn && <p className="item-meta">Connectez-vous pour comparer cette fiche aux critères que vous choisissez de déclarer. La consultation reste libre sans compte. <Link className="text-link" href="/connexion">Se connecter</Link></p>}
                {signedIn && !hasProfile && <StateMessage title="Vous n’avez déclaré aucun critère" action={<Link className="button button-secondary" href="/opportunites/profil">Déclarer mes critères</Link>}>Sans critère déclaré, Campus ne compare rien et ne déduit rien de votre compte.</StateMessage>}
                {match && <>
                    <p><strong>{match.overallLabel}</strong></p>
                    <ul className="plain-list">{match.criteria.map((criterion) => <li key={criterion.key}><strong>{criterion.label} : {criterion.resultLabel}</strong><p className="item-meta">{criterion.explanation}</p></li>)}</ul>
                    <p className="item-meta">{matchDisclaimer} Une information manquante ne prouve jamais que vous n’êtes pas éligible. <Link className="text-link" href="/opportunites/profil">Modifier mes critères</Link></p>
                </>}
            </section>}

            <section className="surface" aria-labelledby="follow-title">
                <h2 id="follow-title">Mon suivi</h2>
                {!signedIn && <p className="item-meta">Connectez-vous pour ajouter cette fiche à vos favoris ou suivre votre démarche. <Link className="text-link" href="/connexion">Se connecter</Link></p>}
                {signedIn && <>
                    <div className="form-actions">
                        {favorite
                            ? <Button variant="secondary" onClick={() => router.delete(`/opportunites/${opportunity.id}/favori`, { preserveScroll: true })}>Retirer de mes favoris</Button>
                            : canFavorite && <Button variant="secondary" onClick={() => router.put(`/opportunites/${opportunity.id}/favori`, {}, { preserveScroll: true })}>Ajouter à mes favoris</Button>}
                        <Link className="text-link" href="/opportunites/suivi">Tout mon suivi</Link>
                    </div>
                    <h3>Ma démarche</h3>
                    <ApplicationPanel key={application?.version ?? 0} opportunityId={opportunity.id} application={application} statuses={applicationStatuses} canStart={canFavorite} />
                </>}
            </section>

            {!suspended && !archived && <section className="surface" aria-labelledby="report-title">
                <h2 id="report-title">Signaler un problème</h2>
                {myReport && <StateMessage title={`Votre signalement : ${myReport.statusLabel}`}>{myReport.decisionLabel ?? 'L’équipe éditoriale l’examinera. Votre identité n’est pas publiée.'}</StateMessage>}
                {!signedIn && <p className="item-meta">Connectez-vous pour signaler un lien cassé, une date erronée, une offre close ou un doute sur l’authenticité. <Link className="text-link" href="/connexion">Se connecter</Link></p>}
                {canReport && !open && !reporting && <Button variant="secondary" onClick={() => setReporting(true)}>Signaler cette fiche</Button>}
                {canReport && !open && reporting && <form onSubmit={submit} className="form-narrow">
                    <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
                    <SelectField label="Motif" value={form.data.reason} error={form.errors.reason} onChange={(event) => form.setData('reason', event.target.value)}>
                        {Object.entries(reportReasons).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </SelectField>
                    <TextAreaField label="Précision (500 caractères au plus)" hint="Obligatoire pour le motif « Autre ». Votre identité n’est ni publiée ni affichée à l’équipe éditoriale." rows={3} maxLength={500} value={form.data.message} error={form.errors.message} onChange={(event) => form.setData('message', event.target.value)} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Envoyer le signalement</Button><Button variant="secondary" onClick={() => setReporting(false)}>Annuler</Button></div>
                </form>}
            </section>}
            {(suspended || archived) && myReport && <StateMessage title={`Votre signalement : ${myReport.statusLabel}`}>{myReport.decisionLabel ?? 'L’équipe éditoriale l’examinera.'}</StateMessage>}
            <div className="inline-links"><Link className="text-link" href="/opportunites">Toutes les opportunités</Link></div>
        </CampusLayout>
    );
}
