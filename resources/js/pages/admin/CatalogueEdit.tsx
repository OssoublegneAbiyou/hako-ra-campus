import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CampusLayout from '../../layouts/CampusLayout';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { StateMessage } from '../../components/ui/Feedback';
import { CheckboxField, ErrorSummary, TextField } from '../../components/ui/Fields';
import { DeadlineDetail } from '../../components/OpportunityDeadline';
import { OpportunityForm, type CatalogueOptions, type Editable } from '../../components/OpportunityForm';

type Record_ = Editable & { statusLabel: string; verifiedAt: string | null; verifiedBy: string | null; previousEditionId: string | null; nextEditions: { id: string; campaign: string }[] };
type Revision = { version: number; action: string; actor: string; note: string | null; at: string; status: string };
type Props = { opportunity: Record_; revisions: Revision[]; options: CatalogueOptions };

export default function CatalogueEdit({ opportunity, revisions, options }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [attest, setAttest] = useState(false);
    const [note, setNote] = useState('');
    const [campaign, setCampaign] = useState('');
    const [confirming, setConfirming] = useState<'suspend' | 'archive' | null>(null);
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    const act = (action: string) => router.post(`/administration/catalogue/${opportunity.id}/transition`, { action, expected_version: opportunity.version, attest, note }, { preserveScroll: true, onFinish: () => setConfirming(null) });
    const status = opportunity.status;
    return (
        <CampusLayout active="opportunities" title="Fiche du catalogue">
            <Head title={opportunity.title} />
            <div className="page-heading"><p className="eyebrow">ADMINISTRATION ÉDITORIALE · {opportunity.statusLabel.toUpperCase()}</p><h1>{opportunity.title}</h1><p className="page-intro">{opportunity.organizer} · campagne {opportunity.campaign}</p></div>
            {Object.keys(errors).length > 0 && <ErrorSummary errors={Object.entries(errors).map(([id, message]) => ({ id, message: String(message) }))} />}

            <section className="surface" aria-labelledby="state-title">
                <h2 id="state-title">État et vérification</h2>
                <DeadlineDetail deadline={opportunity.deadline} />
                <p>{opportunity.verifiedAt ? `Source vérifiée le ${when.format(new Date(opportunity.verifiedAt))} par ${opportunity.verifiedBy ?? 'un éditeur'}.` : 'Source non encore vérifiée.'}</p>
                {status === 'archived' && <StateMessage title="Fiche archivée">Elle ne se modifie plus. Pour une autre année, créez une nouvelle édition.</StateMessage>}
                {(status === 'review' || status === 'suspended' || status === 'published') && <CheckboxField label="J’ai consulté moi-même la source officielle : elle existe, elle est authentique et la fiche lui correspond" hint="Acte de vérification, daté et enregistré à votre nom. Campus n’interroge jamais la source à votre place." error={errors.attest} checked={attest} onChange={(event) => setAttest(event.target.checked)} />}
                {status === 'published' && <TextField label="Motif de suspension (interne, obligatoire pour suspendre)" maxLength={500} value={note} error={errors.note} onChange={(event) => setNote(event.target.value)} />}
                <div className="form-actions">
                    {status === 'draft' && <Button onClick={() => act('submit')}>Envoyer en vérification</Button>}
                    {status === 'review' && <><Button onClick={() => act('verify')}>Vérifier et publier</Button><Button variant="secondary" onClick={() => act('return')}>Renvoyer en brouillon</Button></>}
                    {status === 'published' && <><Button variant="secondary" onClick={() => act('reverify')}>Confirmer une nouvelle vérification</Button><Button variant="danger" onClick={() => setConfirming('suspend')}>Suspendre</Button></>}
                    {status === 'suspended' && <Button onClick={() => act('resume')}>Reprendre la publication</Button>}
                    {status !== 'archived' && <Button variant="danger" onClick={() => setConfirming('archive')}>Archiver</Button>}
                    {status === 'published' && <Link className="button button-secondary" href={`/opportunites/${opportunity.id}`}>Voir la fiche publique</Link>}
                </div>
            </section>

            {status !== 'archived' && <section className="surface" aria-labelledby="edit-title">
                <h2 id="edit-title">Contenu</h2>
                <OpportunityForm key={opportunity.version} options={options} opportunity={opportunity} />
            </section>}

            <section className="surface" aria-labelledby="edition-title">
                <h2 id="edition-title">Éditions</h2>
                {opportunity.previousEditionId && <p><Link className="text-link" href={`/administration/catalogue/${opportunity.previousEditionId}`}>Édition précédente</Link></p>}
                {opportunity.nextEditions.map((next) => <p key={next.id}><Link className="text-link" href={`/administration/catalogue/${next.id}`}>Édition {next.campaign}</Link></p>)}
                <form className="form-narrow" onSubmit={(event) => { event.preventDefault(); router.post(`/administration/catalogue/${opportunity.id}/edition`, { campaign }); }}>
                    <TextField label="Créer une nouvelle édition pour la campagne" required maxLength={60} hint="Nouvelle fiche en brouillon. Les dates et la vérification ne sont pas recopiées ; cette fiche et son historique restent intacts." value={campaign} error={errors.campaign} onChange={(event) => setCampaign(event.target.value)} />
                    <div className="form-actions"><Button type="submit" variant="secondary">Créer la nouvelle édition</Button></div>
                </form>
            </section>

            <section className="surface" aria-labelledby="history-title">
                <h2 id="history-title">Historique</h2>
                <ul className="plain-list">{revisions.map((revision) => <li key={revision.version}><strong>{revision.action}</strong><p className="item-meta">Version {revision.version} · {revision.actor} · {when.format(new Date(revision.at))} · état : {revision.status}{revision.note && ` · ${revision.note}`}</p></li>)}</ul>
            </section>
            <div className="inline-links"><Link className="text-link" href="/administration/catalogue">Toutes les fiches</Link><Link className="text-link" href="/administration/catalogue/signalements">Signalements</Link></div>

            <ConfirmDialog open={confirming !== null} title={confirming === 'suspend' ? 'Suspendre cette fiche ?' : 'Archiver cette fiche ?'} confirmLabel={confirming === 'suspend' ? 'Suspendre' : 'Archiver'} onCancel={() => setConfirming(null)} onConfirm={() => confirming && act(confirming)}>
                {confirming === 'suspend' ? 'La fiche quittera immédiatement le catalogue public. Les personnes qui la suivent en seront informées. Le motif saisi reste interne.' : 'La fiche quittera le catalogue public et ne pourra plus être modifiée.'}
            </ConfirmDialog>
        </CampusLayout>
    );
}
