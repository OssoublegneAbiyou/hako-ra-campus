import { Head, Link, usePage } from '@inertiajs/react';
import { useState, useSyncExternalStore } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { OutboxPanel } from '../components/OutboxPanel';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { StateMessage } from '../components/ui/Feedback';
import { expired, initialState, type DeviceState } from '../offline/policy';
import { unsyncedOperations } from '../offline/outbox';
import { canPromptInstall, promptInstall, subscribe } from '../offline/runtime';
import { choosePersonal, purgeAll, readState, synchronize } from '../offline/store';
import type { CampusProps } from '../types';

type Props = { ttlDays: number; windowDays: number };

// État de l'appareil lu hors du rendu (stockage local, horloge, capacités du navigateur), exposé à React comme une source externe.
type DeviceView = { state: DeviceState; stale: boolean; standalone: boolean; apple: boolean; supported: boolean };
const SERVER_VIEW: DeviceView = { state: initialState(), stale: false, standalone: false, apple: false, supported: true };
let deviceView = SERVER_VIEW;
let deviceLoaded = false;
const watchers = new Set<() => void>();

function computeView(): DeviceView {
    const state = readState();

    return { state, stale: state.lastSyncAt !== null && expired(state.lastSyncAt, Date.now(), state.ttlDays), standalone: window.matchMedia('(display-mode: standalone)').matches,
        apple: /iPhone|iPad|iPod/.test(navigator.userAgent), supported: 'indexedDB' in window && 'serviceWorker' in navigator };
}

function refreshView(): void {
    deviceView = computeView();
    watchers.forEach((watcher) => watcher());
}

function watchDevice(watcher: () => void): () => void {
    watchers.add(watcher);
    if (!deviceLoaded) {
        deviceLoaded = true;
        deviceView = computeView();
    }

    return () => watchers.delete(watcher);
}

export default function Offline({ ttlDays, windowDays }: Props) {
    const { auth } = usePage<CampusProps>().props;
    const accountKey = auth.accountKey ?? null;
    const { state, stale, standalone, apple, supported } = useSyncExternalStore(watchDevice, () => deviceView, () => SERVER_VIEW);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [draftsAtRisk, setDraftsAtRisk] = useState(0);
    const installable = useSyncExternalStore(subscribe, canPromptInstall, () => false);
    const when = new Intl.DateTimeFormat('fr', { dateStyle: 'full', timeStyle: 'short' });
    const personal = state.mode === 'personal' && state.accountKey === accountKey && accountKey !== null;

    const enable = async () => {
        if (!accountKey) return;
        setBusy(true);
        setMessage(null);
        try {
            await purgeAll();
            choosePersonal(accountKey, ttlDays);
            const at = await synchronize();
            setMessage(at ? 'Vos données de lecture sont copiées sur cet appareil.' : 'La copie n’a pas pu être faite maintenant. Elle sera retentée à la prochaine ouverture en ligne.');
        } catch {
            setMessage('Ce navigateur a refusé l’enregistrement. Rien n’est conservé sur cet appareil.');
            await purgeAll();
        } finally {
            refreshView();
            setBusy(false);
        }
    };
    // Repasser en « appareil partagé » efface tout, brouillons compris : s'il en reste, la personne est prévenue et confirme.
    const askDisable = async () => {
        const drafts = await unsyncedOperations(accountKey);
        if (drafts.length > 0) { setDraftsAtRisk(drafts.length); return; }
        await disable();
    };
    const disable = async () => {
        setDraftsAtRisk(0);
        setBusy(true);
        await purgeAll();
        refreshView();
        setMessage('Les données conservées sur cet appareil ont été effacées. Cet appareil est de nouveau traité comme partagé.');
        setBusy(false);
    };
    const refresh = async () => {
        setBusy(true);
        try { setMessage(await synchronize() ? 'Synchronisation faite.' : 'Synchronisation impossible pour l’instant.'); } catch { setMessage('Synchronisation impossible pour l’instant.'); }
        refreshView();
        setBusy(false);
    };
    const install = async () => {
        const outcome = await promptInstall();
        setMessage(outcome === 'accepted' ? 'Campus est ajouté à cet appareil.' : 'Installation non faite. Campus reste utilisable dans le navigateur, sans aucune différence de fonctions.');
    };

    return (
        <CampusLayout active="profile" title="Hors connexion et installation">
            <Head title="Hors connexion et installation" />
            <div className="page-heading"><p className="eyebrow">CET APPAREIL</p><h1>Hors connexion et installation</h1><p className="page-intro">Campus est un site web que vous pouvez ajouter à votre écran d’accueil. Ce n’est pas une application native : il fonctionne dans votre navigateur, et l’essentiel exige une connexion.</p></div>
            {message && <StateMessage title="Cet appareil">{message}</StateMessage>}

            <section className="surface" aria-labelledby="install-title">
                <h2 id="install-title">Ajouter Campus à cet appareil</h2>
                {standalone ? <p>Campus est déjà ouvert comme raccourci installé sur cet appareil.</p>
                    : installable ? <><p>Votre navigateur permet d’ajouter Campus comme raccourci, avec son icône.</p><div className="form-actions"><Button onClick={() => void install()}>Installer le raccourci Campus</Button></div></>
                        : apple ? <p>Sur iPhone et iPad : ouvrez le menu « Partager » de Safari, puis choisissez « Sur l’écran d’accueil ».</p>
                            : <p>Ce navigateur ne propose pas d’installation pour l’instant. Vous pouvez ajouter cette page à vos favoris ; Campus fonctionne de la même façon.</p>}
                <p className="item-meta">Installer ou non ne change aucune fonction. Vous pouvez retirer le raccourci à tout moment, comme n’importe quelle icône.</p>
            </section>

            <section className="surface" aria-labelledby="device-title">
                <h2 id="device-title">Lecture hors connexion</h2>
                <p>Par défaut, cet appareil est traité comme <strong>partagé</strong> : Campus n’y conserve aucune de vos données. Sans réseau, une page neutre s’affiche.</p>
                {!supported && <StateMessage title="Non disponible sur ce navigateur">Ce navigateur ne permet pas la lecture hors connexion. Campus fonctionne normalement en ligne.</StateMessage>}
                {!accountKey && <p className="item-meta">Connectez-vous pour choisir de conserver vos données de lecture sur un appareil personnel. <Link className="text-link" href="/connexion">Se connecter</Link></p>}
                {accountKey && supported && !personal && <>
                    <p>Si cet appareil est <strong>à vous seul</strong>, vous pouvez y conserver une copie en lecture seule de votre planning des {windowDays} prochains jours, de vos tâches ouvertes, des notes que vous avez le droit de lire, et de vos opportunités favorites ou consultées.</p>
                    <ul className="plain-list">
                        <li>N’activez pas ce choix sur un ordinateur d’établissement, de cybercafé ou prêté : toute personne utilisant ensuite ce navigateur pourrait lire ces données sans connexion.</li>
                        <li>Hors connexion, vous pourrez aussi écrire des brouillons : créer ou modifier une tâche personnelle, préparer votre retour privé sur un cours. Ils partent au retour du réseau.</li>
                        <li>Tout le reste exige la connexion : notes (lecture seule hors connexion), devoirs et planning d’établissement, administration, publication, fichiers, et la connexion à Campus elle-même.</li>
                    </ul>
                    <div className="form-actions"><Button busy={busy} onClick={() => void enable()}>Cet appareil est personnel : conserver mes données de lecture</Button></div>
                </>}
                {personal && <>
                    <StateMessage title="Appareil personnel">{state.lastSyncAt ? `Dernière synchronisation : ${when.format(new Date(state.lastSyncAt))}.` : 'Aucune synchronisation réussie pour l’instant.'} {stale ? 'Ces données sont trop anciennes et seront effacées.' : 'Hors connexion, ces données peuvent être périmées.'}</StateMessage>
                    <div className="form-actions"><Button variant="secondary" busy={busy} onClick={() => void refresh()}>Synchroniser maintenant</Button><Button variant="danger" busy={busy} onClick={() => void askDisable()}>Effacer et traiter cet appareil comme partagé</Button></div>
                    <ConfirmDialog open={draftsAtRisk > 0} title="Effacer aussi vos brouillons ?" confirmLabel="Tout effacer, brouillons compris" busy={busy} onCancel={() => setDraftsAtRisk(0)} onConfirm={() => void disable()}>{draftsAtRisk} brouillon(s) écrit(s) sur cet appareil ne sont pas enregistrés sur le serveur. Ils seront perdus définitivement. Synchronisez-les d’abord si vous voulez les garder.</ConfirmDialog>
                </>}
            </section>

            {personal && <OutboxPanel accountKey={accountKey} onSynced={() => void refresh()} />}

            <section className="surface" aria-labelledby="rules-title">
                <h2 id="rules-title">Ce qu’il faut savoir</h2>
                <ul className="plain-list">
                    <li>À chaque ouverture en ligne, Campus revérifie vos droits et remplace la copie : ce à quoi vous n’avez plus accès disparaît de l’appareil.</li>
                    <li>Sans vérification pendant {ttlDays} jours, la copie est effacée à l’ouverture suivante.</li>
                    <li>Un brouillon écrit hors connexion porte la version que vous aviez sous les yeux. Si l’élément a changé ailleurs entre-temps, rien n’est écrasé : Campus vous montre les deux versions et vous choisissez.</li>
                    <li>La synchronisation se fait à l’ouverture de Campus, au retour du réseau, ou avec le bouton « Synchroniser maintenant ». Elle ne se fait pas quand Campus est fermé.</li>
                    <li>Se déconnecter efface la copie, les brouillons, les réglages locaux et l’abonnement aux notifications de cet appareil ; s’il reste des brouillons non synchronisés, Campus vous prévient d’abord. Si un autre compte se connecte sur cet appareil, les brouillons du compte précédent sont effacés sans pouvoir être récupérés.</li>
                    <li><strong>Limite à connaître :</strong> si un accès vous est retiré pendant que l’appareil est hors connexion, Campus ne peut pas effacer l’appareil à distance. La copie reste lisible jusqu’au retour du réseau, ou au plus {ttlDays} jours. En cas de perte ou de vol, changez votre mot de passe et fermez vos sessions depuis un autre appareil.</li>
                </ul>
            </section>
        </CampusLayout>
    );
}
