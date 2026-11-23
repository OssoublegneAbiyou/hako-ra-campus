import { Head } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { TextField, TextAreaField, SelectField, ErrorSummary } from '../components/ui/Fields';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { FilterBar, Pagination, ResponsiveCollection } from '../components/ui/Collection';
import { LoadingState, ProvenanceBadge, StateMessage, SuccessNotice, type StateKind } from '../components/ui/Feedback';

type Example = { id: string; title: string; subject: string; source: 'personal' | 'institutional' };
export default function ComponentLab({ page, examples }: { page: number; examples: Example[] }) {
    const [title, setTitle] = useState('');
    const [detail, setDetail] = useState('');
    const [error, setError] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [success, setSuccess] = useState('');
    const [search, setSearch] = useState('');
    const [source, setSource] = useState('');
    const [state, setState] = useState<StateKind>('info');
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!title.trim()) {
            setError('Renseignez le titre de cet exemple.');
            document.getElementById('example-title')?.focus();
            return;
        }
        setError('');
        setConfirm(true);
    }
    const rows = examples.filter((row) => row.title.toLocaleLowerCase('fr').includes(search.toLocaleLowerCase('fr')) && (!source || source === row.source));
    return <CampusLayout active="" title="Atelier des composants"><Head title="Atelier des composants" /><div className="page-heading"><p className="eyebrow">DÉVELOPPEMENT UNIQUEMENT</p><h1>Des repères communs.</h1><p className="page-intro">Atelier local avec exemples fictifs. Aucune saisie n’est enregistrée ou envoyée.</p></div>
        {success && <SuccessNotice message={success} onDismiss={() => setSuccess('')} />}
        <section className="surface" aria-labelledby="form-title"><h2 id="form-title">Champs et confirmation</h2><form onSubmit={submit} noValidate>
            <ErrorSummary errors={error ? [{ id: 'example-title', message: error }] : []} />
            <TextField id="example-title" label="Titre de l’exemple" hint="Champ obligatoire, utilisé uniquement dans cet atelier." required value={title} onChange={(event) => setTitle(event.target.value)} error={error} />
            <TextAreaField label="Précision facultative" value={detail} onChange={(event) => setDetail(event.target.value)} rows={3} />
            <div className="form-actions"><Button type="submit">Vérifier l’exemple</Button><Button busy>Enregistrement en cours…</Button></div>
        </form></section>
        <section className="surface" aria-labelledby="collection-title"><h2 id="collection-title">Listes et filtres</h2><p className="section-description">Exemples fictifs de présentation. Les filtres s’appliquent à la page affichée.</p>
            <FilterBar search={search} onSearch={setSearch} source={source} onSource={setSource} onReset={() => { setSearch(''); setSource(''); }} />
            {(search || source) && <p className="filter-summary" role="status">Filtres actifs : {search && `recherche « ${search} »`}{search && source && ' ; '}{source && (source === 'personal' ? 'personnel' : 'établissement')}. {rows.length} exemple(s) sur cette page.</p>}
            <ResponsiveCollection rows={rows} rowKey={(row) => row.id} caption="Exemples fictifs" columns={[{ key: 'title', label: 'Intitulé', render: (row) => row.title }, { key: 'subject', label: 'Matière', render: (row) => row.subject }, { key: 'source', label: 'Provenance', render: (row) => <ProvenanceBadge source={row.source} /> }]} />
            <Pagination currentPage={page} totalPages={2} previousHref={page > 1 ? '/developpement/composants?page=1' : undefined} nextHref={page < 2 ? '/developpement/composants?page=2' : undefined} />
        </section>
        <section className="surface" aria-labelledby="states-title"><h2 id="states-title">États et retours</h2><SelectField label="État à prévisualiser" value={state} onChange={(event) => setState(event.target.value as StateKind)}><option value="info">Information</option><option value="empty">Liste vide</option><option value="error">Erreur récupérable</option><option value="offline">Hors connexion</option><option value="session-expired">Session expirée</option><option value="success">Succès</option><option value="loading">Chargement</option></SelectField>
            {state === 'loading' ? <LoadingState /> : <StateMessage kind={state} title={state === 'error' ? 'L’enregistrement a échoué — exemple' : state === 'session-expired' ? 'Votre session a expiré — exemple' : state === 'offline' ? 'Connexion indisponible — exemple' : state === 'empty' ? 'Aucun élément — exemple' : state === 'success' ? 'Action confirmée — exemple' : 'Information — exemple'}>Cet état est illustratif. Votre saisie au-dessus reste conservée sur cet écran.</StateMessage>}
        </section>
        <ConfirmDialog open={confirm} title={`Confirmer « ${title} » ?`} confirmLabel="Confirmer l’exemple" onCancel={() => setConfirm(false)} onConfirm={() => { setConfirm(false); setSuccess('Exemple local confirmé ; aucune donnée enregistrée.'); }}>Cette action vérifie le dialogue uniquement. Elle ne supprime ni n’enregistre aucune donnée.</ConfirmDialog>
    </CampusLayout>;
}
