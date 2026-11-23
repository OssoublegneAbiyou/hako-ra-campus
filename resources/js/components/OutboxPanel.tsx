import { useState } from 'react';
import { Button } from './ui/Button';
import { ConfirmDialog } from './ui/ConfirmDialog';
import { StateMessage } from './ui/Feedback';
import { STATE_LABELS, differences, discardDraft, isSupported, messageFor, resolveConflict, retryDraft, synchronizeDrafts, useOutbox, type Operation } from '../offline/outbox';

const KINDS: Record<Operation['type'], string> = { 'task.create': 'Nouvelle tâche personnelle', 'task.update': 'Modification d’une tâche personnelle', 'check_in.save': 'Retour privé après cours' };
const FIELD_LABELS: Record<string, string> = {
    title: 'Titre', description: 'Description', due_at: 'Échéance', priority: 'Priorité', status: 'État', personal_subject_id: 'Matière',
    summary: 'Résumé personnel', work_to_do: 'Travail à effectuer', points_to_review: 'Points à revoir', understanding: 'Niveau de compréhension',
};
const VALUES: Record<string, Record<string, string>> = {
    status: { todo: 'À faire', in_progress: 'En cours', done: 'Terminé', abandoned: 'Abandonné' },
    priority: { low: 'Basse', normal: 'Normale', high: 'Haute' },
    understanding: { 1: 'Pas compris', 2: 'À revoir', 3: 'Plutôt compris', 4: 'Bien compris' },
};
const EXPLANATIONS: Record<Operation['state'], string> = {
    local: 'Écrit sur cet appareil, pas encore envoyé. Il partira au retour du réseau ou quand vous demanderez la synchronisation.',
    pending: 'Envoi tenté sans réponse. Il sera renvoyé à l’identique : il ne peut pas créer de doublon.',
    synced: 'Enregistré sur le serveur.',
    refused: 'Le serveur a refusé cet envoi. Votre brouillon reste ici tant que vous ne l’abandonnez pas.',
    conflict: 'Cet élément a été modifié ailleurs depuis votre brouillon. Rien n’a été écrasé : choisissez la version à garder.',
};

function show(field: string, value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '(vide)';
    if (field === 'personal_subject_id') return 'matière personnelle';
    if (field === 'due_at') return String(value).replace('T', ' à ');

    return VALUES[field]?.[String(value)] ?? String(value);
}

function Draft({ operation }: { operation: Operation }) {
    const fields = Object.entries(operation.payload).filter(([field, value]) => value !== null && field in FIELD_LABELS && field !== 'personal_subject_id');

    return <details><summary>Voir mon brouillon</summary><dl className="detail-list">{fields.map(([field, value]) => <div key={field}><dt>{FIELD_LABELS[field]}</dt><dd style={{ whiteSpace: 'pre-wrap' }}>{show(field, value)}</dd></div>)}</dl></details>;
}

/**
 * Brouillons et envois de ce compte sur cet appareil, avec leurs cinq états : enregistré sur cet appareil, en attente, synchronisé, refusé, conflit.
 * Aucun choix n'est fait à la place de la personne : un conflit attend sa décision, un refus garde son brouillon.
 */
export function OutboxPanel({ accountKey, compact = false, onSynced }: { accountKey: string | null; compact?: boolean; onSynced?: () => void }) {
    const operations = useOutbox(accountKey);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [abandoning, setAbandoning] = useState<Operation | null>(null);
    const visible = compact ? operations.filter((operation) => operation.state !== 'synced') : operations;
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    const waiting = operations.filter((operation) => operation.state === 'local' || operation.state === 'pending').length;
    const synchronize = async () => {
        setBusy(true);
        const summary = await synchronizeDrafts(accountKey);
        setMessage(messageFor(summary));
        setBusy(false);
        if (summary && summary.synced > 0) onSynced?.();
    };
    if (visible.length === 0) {
        return compact ? null : <section className="surface" aria-labelledby="outbox-title"><h2 id="outbox-title">Brouillons et envois de cet appareil</h2><StateMessage kind="empty" title="Aucun brouillon sur cet appareil">Ce que vous écrivez sans réseau apparaîtra ici, avec son état d’envoi.</StateMessage></section>;
    }

    return <section className="surface" aria-labelledby="outbox-title">
        <h2 id="outbox-title">Brouillons et envois de cet appareil</h2>
        <p className="section-description">Écrits sur cet appareil personnel. Ils ne sont visibles que par vous et ne sont sur le serveur qu’une fois marqués « Synchronisé ».</p>
        {message && <StateMessage title="Synchronisation">{message}</StateMessage>}
        <ul className="plain-list">{visible.map((operation) => {
            const changes = operation.state === 'conflict' ? differences(operation) : [];
            const errors = operation.state === 'refused' ? Object.values(operation.ack?.errors ?? {}).flat() : [];

            return <li key={operation.id} data-outbox-state={operation.state}>
                <strong>{KINDS[operation.type]} — {operation.label}</strong>
                <p className="item-meta"><span className={`outbox-state outbox-${operation.state}`}>{STATE_LABELS[operation.state]}</span> · écrit le {when.format(new Date(operation.createdAt))}{operation.attempts > 0 && ` · ${operation.attempts} envoi(s) tenté(s)`}</p>
                <p className="item-meta">{isSupported(operation) ? EXPLANATIONS[operation.state] : 'Ce brouillon a été écrit par une version plus récente de Campus. Mettez Campus à jour pour l’envoyer ; il est conservé tel quel.'}</p>
                {operation.state === 'refused' && <p role="alert">{operation.ack?.message ?? 'Envoi refusé.'}{errors.length > 0 && ` ${errors.join(' ')}`}</p>}
                {operation.state === 'conflict' && <>
                    <p role="alert">{operation.ack?.message ?? 'Conflit.'}</p>
                    {changes.length > 0
                        ? <ul className="diff-list" aria-label="Différences entre votre brouillon et la version enregistrée">{changes.map((change) => <li key={change.field}>
                            <strong>{change.label}</strong>
                            <dl><div><dt>Votre brouillon</dt><dd>{show(change.field, change.mine)}</dd></div><div><dt>Version enregistrée</dt><dd>{show(change.field, change.theirs)}</dd></div></dl>
                        </li>)}</ul>
                        : <p className="item-meta">{operation.ack?.current ? 'Votre brouillon et la version enregistrée ont le même contenu.' : 'La version enregistrée n’est plus disponible.'}</p>}
                </>}
                {operation.state !== 'synced' && <Draft operation={operation} />}
                {operation.state !== 'synced' && <div className="form-actions">
                    {operation.state === 'conflict' && operation.type !== 'task.create' && (operation.ack?.current || operation.type === 'check_in.save') && <Button variant="secondary" disabled={busy} onClick={() => void resolveConflict(operation.id, 'mine')}>Appliquer mon brouillon à la place</Button>}
                    {operation.state === 'conflict' && <Button variant="secondary" disabled={busy} onClick={() => void resolveConflict(operation.id, 'server')}>Garder la version enregistrée</Button>}
                    {operation.state === 'refused' && <Button variant="secondary" disabled={busy} onClick={() => void retryDraft(operation.id)}>Réessayer cet envoi</Button>}
                    {operation.state !== 'conflict' && <Button variant="danger" disabled={busy} onClick={() => setAbandoning(operation)}>Abandonner ce brouillon</Button>}
                </div>}
            </li>;
        })}</ul>
        <div className="form-actions"><Button busy={busy} disabled={waiting === 0} onClick={() => void synchronize()}>Synchroniser maintenant{waiting > 0 && ` (${waiting})`}</Button></div>
        <ConfirmDialog open={abandoning !== null} title="Abandonner ce brouillon ?" confirmLabel="Abandonner définitivement" onCancel={() => setAbandoning(null)}
            onConfirm={() => { if (abandoning) void discardDraft(abandoning.id); setAbandoning(null); }}>
            « {abandoning?.label} » sera effacé de cet appareil. {abandoning?.state === 'pending' ? 'Un envoi a déjà été tenté : il a peut-être été enregistré sur le serveur.' : 'Il n’a pas été enregistré sur le serveur.'}
        </ConfirmDialog>
    </section>;
}
