import { router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from './ui/Button';
import { ConfirmDialog } from './ui/ConfirmDialog';
import { TextField } from './ui/Fields';

export type AttachmentItem = { id: string; name: string; sizeBytes: number; shared: boolean; mine: boolean; status: string; statusLabel: string; withdrawn: boolean; downloadable: boolean; canDelete: boolean; canReport: boolean };
export type AttachmentLimits = { usedBytes: number; maxBytes: number; maxFileBytes: number; maxPerObject: number; scannerConfigured: boolean };

const megabytes = (bytes: number) => `${(bytes / 1048576).toLocaleString('fr', { maximumFractionDigits: 1 })} Mo`;

export function AttachmentList({ items, uploadUrl, limits, canUpload, shared = false, label }: { items: AttachmentItem[]; uploadUrl: string; limits: AttachmentLimits; canUpload: boolean; shared?: boolean; label: string }) {
    const input = useRef<HTMLInputElement>(null);
    const [key, setKey] = useState(() => crypto.randomUUID());
    const [busy, setBusy] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const [deleting, setDeleting] = useState<AttachmentItem | null>(null);
    const [reporting, setReporting] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const errors = usePage().props.errors as Record<string, string>;
    const upload = () => {
        const file = input.current?.files?.[0];
        if (!file) return;
        // Contrôle de confort seulement : le serveur revérifie la taille, le type réel et le contenu.
        if (file.size > limits.maxFileBytes) { setLocalError(`Ce fichier dépasse ${megabytes(limits.maxFileBytes)}.`); return; }
        setLocalError(null);
        router.post(uploadUrl, { client_key: key, visibility: shared ? 'group' : 'personal', file }, {
            preserveScroll: true, forceFormData: true, onStart: () => setBusy(true), onFinish: () => setBusy(false),
            onSuccess: () => { setKey(crypto.randomUUID()); if (input.current) input.current.value = ''; },
        });
    };
    return <div className="field">
        <p className="collection-label">{label}</p>
        {items.length > 0 && <ul className="plain-list">{items.map((item) => <li key={item.id}>
            <strong>{item.name}</strong> <span className="item-meta">· {megabytes(item.sizeBytes)} · {item.shared ? 'partagé avec le groupe' : 'visible par vous seul'}</span>
            <p className="item-meta" role="status">{item.withdrawn ? 'Retiré de la diffusion par l’administration' : item.statusLabel}</p>
            <div className="form-actions">
                {item.downloadable && <a className="button button-secondary" href={`/pieces-jointes/${item.id}`} download>Télécharger</a>}
                {item.canReport && <Button variant="secondary" onClick={() => setReporting(reporting === item.id ? null : item.id)}>Signaler</Button>}
                {item.canDelete && <Button variant="danger" onClick={() => setDeleting(item)}>Supprimer</Button>}
            </div>
            {reporting === item.id && <form className="form-narrow" onSubmit={(event) => { event.preventDefault(); router.post(`/pieces-jointes/${item.id}/signalement`, { reason }, { preserveScroll: true, onSuccess: () => { setReporting(null); setReason(''); } }); }}>
                <TextField label="Pourquoi signalez-vous ce fichier ?" required minLength={5} maxLength={500} value={reason} error={errors.reason} onChange={(event) => setReason(event.target.value)} />
                <div className="form-actions"><Button type="submit">Envoyer le signalement</Button></div>
            </form>}
        </li>)}</ul>}
        {canUpload && <>
            <label className="sr-only" htmlFor={`file-${key}`}>Choisir un fichier PDF, JPEG ou PNG</label>
            <input ref={input} id={`file-${key}`} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" disabled={busy} />
            <p className="field-hint">PDF, JPEG ou PNG, {megabytes(limits.maxFileBytes)} au plus. Chaque fichier est vérifié par un antivirus avant d’être téléchargeable.</p>
            {!limits.scannerConfigured && <p className="field-hint">L’antivirus n’est pas configuré sur ce serveur : les fichiers déposés restent bloqués et ne peuvent pas être téléchargés.</p>}
            {(localError ?? errors.file) && <p className="field-error" role="alert">{localError ?? errors.file}</p>}
            <div className="form-actions"><Button variant="secondary" busy={busy} onClick={upload}>Déposer le fichier</Button></div>
        </>}
        <ConfirmDialog open={deleting !== null} title="Supprimer ce fichier ?" confirmLabel="Supprimer le fichier" onCancel={() => setDeleting(null)}
            onConfirm={() => deleting && router.delete(`/pieces-jointes/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) })}>
            Le fichier « {deleting?.name} » sera supprimé définitivement.
        </ConfirmDialog>
    </div>;
}
