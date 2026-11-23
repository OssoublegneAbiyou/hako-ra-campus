import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { StateMessage } from '../components/ui/Feedback';
import { CheckboxField, ErrorSummary, SelectField, TextField } from '../components/ui/Fields';

type Category = { key: string; label: string; mandatory: boolean; hint: string; enabled: boolean; email: boolean; push: boolean };
type Props = {
    preference: { version: number; timezone: string; quietStart: string; quietEnd: string; courseEndDelay: number; taskDayBefore: boolean; taskSameDay: boolean; taskReminderTime: string; digestEnabled: boolean; digestTime: string; favoriteFirstDays: number; favoriteSecondDays: number };
    categories: Category[]; channels: { email: boolean; push: boolean; pushPublicKey: string | null; hasVerifiedEmail: boolean };
    devices: { id: number; label: string; createdAt: string; lastAcceptedAt: string | null }[]; recentDeliveries: { id: number; channel: string; status: string; at: string }[]; timezones: string[];
};

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64Url.length / 4) * 4, '=');
    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
}

function Devices({ channels, devices }: Pick<Props, 'channels' | 'devices'>) {
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    const supported = typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const enable = async () => {
        if (!channels.pushPublicKey) return;
        setBusy(true);
        setMessage(null);
        try {
            // La demande d'autorisation n'est faite qu'ici, après ce clic et son explication.
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                setMessage('Vous avez refusé les notifications du navigateur. Rien n’est bloqué : vos rappels restent dans le centre de notifications.');
                return;
            }
            const registration = await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(channels.pushPublicKey) });
            router.post('/notifications/appareils', { endpoint: subscription.endpoint }, { preserveScroll: true });
        } catch {
            setMessage('L’activation a échoué sur cet appareil. Vos rappels restent disponibles dans le centre de notifications.');
        } finally {
            setBusy(false);
        }
    };
    return <section className="surface" aria-labelledby="devices-title">
        <h2 id="devices-title">Notifications sur cet appareil</h2>
        {!channels.push
            ? <StateMessage title="Non configuré sur ce serveur">Les notifications du navigateur ne sont pas disponibles pour l’instant. Le centre de notifications fonctionne normalement.</StateMessage>
            : !supported
                ? <StateMessage title="Non pris en charge par ce navigateur">Sur iPhone et iPad, il faut d’abord ajouter Campus à l’écran d’accueil. Le centre de notifications fonctionne dans tous les cas.</StateMessage>
                : <>
                    <p className="section-description">En activant, ce navigateur affichera un message générique (« Vous avez une nouvelle notification ») sans aucun détail : ni note, ni cours, ni tâche. Vous lisez le détail dans Campus, après connexion. Vous pouvez refuser ou revenir en arrière à tout moment.</p>
                    <div className="form-actions"><Button busy={busy} onClick={() => void enable()}>Activer sur cet appareil</Button></div>
                </>}
        {message && <StateMessage title="Notifications du navigateur">{message}</StateMessage>}
        {errors.endpoint && <StateMessage kind="error" title="Activation impossible">{errors.endpoint}</StateMessage>}
        {devices.length > 0 && <ul className="plain-list">{devices.map((device) => <li key={device.id}>
            <strong>{device.label}</strong>
            <p className="item-meta">Activé le {when.format(new Date(device.createdAt))}{device.lastAcceptedAt ? ` · dernier envoi accepté le ${when.format(new Date(device.lastAcceptedAt))}` : ' · aucun envoi accepté pour l’instant'}</p>
            <Button variant="secondary" onClick={() => router.delete(`/notifications/appareils/${device.id}`, { preserveScroll: true })}>Retirer cet appareil</Button>
        </li>)}</ul>}
    </section>;
}

export default function NotificationSettings({ preference, categories, channels, devices, recentDeliveries, timezones }: Props) {
    const optional = categories.filter((category) => !category.mandatory);
    const form = useForm({
        expected_version: preference.version, timezone: preference.timezone, quiet_start: preference.quietStart, quiet_end: preference.quietEnd, course_end_delay: preference.courseEndDelay,
        task_day_before: preference.taskDayBefore, task_same_day: preference.taskSameDay, task_reminder_time: preference.taskReminderTime, digest_enabled: preference.digestEnabled, digest_time: preference.digestTime,
        favorite_first_days: preference.favoriteFirstDays, favorite_second_days: preference.favoriteSecondDays,
        categories: Object.fromEntries(optional.map((category) => [category.key, { enabled: category.enabled, email: category.email, push: category.push }])) as Record<string, { enabled: boolean; email: boolean; push: boolean }>,
    });
    const errors = usePage().props.errors as Record<string, string>;
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
    const setCategory = (key: string, change: Partial<{ enabled: boolean; email: boolean; push: boolean }>) => form.setData('categories', { ...form.data.categories, [key]: { ...form.data.categories[key], ...change } });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, quiet_start: data.quiet_start || null, quiet_end: data.quiet_end || null }));
        form.put('/notifications/preferences', { preserveScroll: true });
    };
    return (
        <CampusLayout active="profile" title="Préférences de notification">
            <Head title="Préférences de notification" />
            <div className="page-heading"><p className="eyebrow">NOTIFICATIONS</p><h1>Préférences de notification</h1><p className="page-intro">Choisissez ce qui vous est rappelé, quand, et par quel canal. Le centre de notifications reste toujours disponible.</p></div>
            {errors.reference && <StateMessage kind="error" title="Préférences non enregistrées">{errors.reference}</StateMessage>}

            <form onSubmit={submit}>
                <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
                <section className="surface" aria-labelledby="when-title">
                    <h2 id="when-title">Quand</h2>
                    <div className="form-narrow">
                        <SelectField label="Fuseau horaire" hint="Les heures ci-dessous sont dans ce fuseau." value={form.data.timezone} error={form.errors.timezone} onChange={(event) => form.setData('timezone', event.target.value)}>
                            {timezones.map((zone) => <option key={zone} value={zone}>{zone}</option>)}
                        </SelectField>
                        <TextField label="Plage silencieuse — début (facultatif)" type="time" hint="Pendant cette plage, aucun courriel ni message du navigateur ne part ; ils sont envoyés à la fin de la plage s’ils sont encore utiles." value={form.data.quiet_start} error={form.errors.quiet_start} onChange={(event) => form.setData('quiet_start', event.target.value)} />
                        <TextField label="Plage silencieuse — fin" type="time" value={form.data.quiet_end} error={form.errors.quiet_end} onChange={(event) => form.setData('quiet_end', event.target.value)} />
                        <TextField label="Rappel après un cours (minutes après la fin)" type="number" min={0} max={120} value={form.data.course_end_delay} error={form.errors.course_end_delay} onChange={(event) => form.setData('course_end_delay', Number(event.target.value))} />
                        <CheckboxField label="Rappel de tâche la veille de l’échéance" checked={form.data.task_day_before} onChange={(event) => form.setData('task_day_before', event.target.checked)} />
                        <CheckboxField label="Rappel de tâche le jour de l’échéance" checked={form.data.task_same_day} onChange={(event) => form.setData('task_same_day', event.target.checked)} />
                        <TextField label="Heure des rappels de tâche" type="time" required value={form.data.task_reminder_time} error={form.errors.task_reminder_time} onChange={(event) => form.setData('task_reminder_time', event.target.value)} />
                        <TextField label="Fiches suivies : premier rappel (jours avant la date limite, 0 pour aucun)" type="number" min={0} max={30} hint="Pour vos favoris et vos démarches en cours, quand la fiche publie une date." value={form.data.favorite_first_days} error={form.errors.favorite_first_days} onChange={(event) => form.setData('favorite_first_days', Number(event.target.value))} />
                        <TextField label="Fiches suivies : second rappel (jours avant la date limite, 0 pour aucun)" type="number" min={0} max={30} value={form.data.favorite_second_days} error={form.errors.favorite_second_days} onChange={(event) => form.setData('favorite_second_days', Number(event.target.value))} />
                        <CheckboxField label="Recevoir une synthèse quotidienne" hint="Facultative. Elle n’est envoyée que s’il y a une tâche à échéance ou un retour de cours à renseigner." checked={form.data.digest_enabled} onChange={(event) => form.setData('digest_enabled', event.target.checked)} />
                        <TextField label="Heure de la synthèse" type="time" required value={form.data.digest_time} error={form.errors.digest_time} onChange={(event) => form.setData('digest_time', event.target.value)} />
                    </div>
                </section>

                <section className="surface" aria-labelledby="what-title">
                    <h2 id="what-title">Quoi et par quel canal</h2>
                    {!channels.email && <p className="item-meta">Courriel : non configuré sur ce serveur, aucun courriel de rappel ne part.</p>}
                    {channels.email && !channels.hasVerifiedEmail && <p className="item-meta">Courriel : votre compte n’a pas d’adresse vérifiée, aucun courriel de rappel ne part.</p>}
                    {!channels.push && <p className="item-meta">Navigateur : non configuré sur ce serveur.</p>}
                    <ul className="plain-list">{categories.map((category) => <li key={category.key}>
                        <strong>{category.label}</strong><p className="item-meta">{category.hint}</p>
                        {category.mandatory ? <p className="item-meta">Toujours actif ; non concerné par la plage silencieuse.</p> : category.key === 'digest'
                            ? <p className="item-meta">{form.data.digest_enabled ? 'Activée ci-dessus.' : 'Désactivée ci-dessus.'}</p>
                            : <CheckboxField label={`Afficher dans le centre de notifications — ${category.label}`} checked={form.data.categories[category.key].enabled} onChange={(event) => setCategory(category.key, { enabled: event.target.checked })} />}
                        {!category.mandatory && <>
                            <CheckboxField label={`Aussi par courriel — ${category.label}`} hint="Le courriel ne contient qu’un titre neutre et un lien." disabled={!channels.email || !form.data.categories[category.key].enabled && category.key !== 'digest'} checked={form.data.categories[category.key].email} onChange={(event) => setCategory(category.key, { email: event.target.checked })} />
                            <CheckboxField label={`Aussi sur mes appareils — ${category.label}`} disabled={!channels.push || !form.data.categories[category.key].enabled && category.key !== 'digest'} checked={form.data.categories[category.key].push} onChange={(event) => setCategory(category.key, { push: event.target.checked })} />
                        </>}
                    </li>)}</ul>
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Enregistrer mes préférences</Button></div>
                </section>
            </form>

            <Devices channels={channels} devices={devices} />

            {recentDeliveries.length > 0 && <section className="surface" aria-labelledby="deliveries-title">
                <h2 id="deliveries-title">Derniers envois externes</h2>
                <p className="section-description">« Accepté par le fournisseur » ne signifie pas « lu ». « Indéterminé » : le fournisseur n’a pas répondu ; le message a pu partir et n’est pas renvoyé.</p>
                <ul className="plain-list">{recentDeliveries.map((delivery) => <li key={delivery.id}>{delivery.channel === 'email' ? 'Courriel' : 'Navigateur'} · {delivery.status} · {when.format(new Date(delivery.at))}</li>)}</ul>
            </section>}
            <div className="inline-links"><Link className="text-link" href="/notifications">Centre de notifications</Link><Link className="text-link" href="/profil">Profil</Link></div>
        </CampusLayout>
    );
}
