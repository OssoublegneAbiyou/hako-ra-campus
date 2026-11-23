import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, useSyncExternalStore, type ReactNode } from 'react';
import { applyUpdate, start, subscribe, updateWaiting } from '../offline/runtime';
import { synchronizeDrafts, useOutbox } from '../offline/outbox';
import { reconcile, synchronize } from '../offline/store';
import { Icon } from '../components/Icon';
import { StateMessage } from '../components/ui/Feedback';
import type { CampusProps, NavigationItem } from '../types';

function NavigationEntry({ item, active }: { item: NavigationItem; active: string }) {
    const content = <><Icon name={item.key} /><span>{item.label}</span></>;
    return item.href
        ? <Link href={item.href} className={`nav-item ${active === item.key ? 'is-active' : ''}`} aria-current={active === item.key ? 'page' : undefined}>{content}</Link>
        : <span className="nav-item is-unavailable" aria-disabled="true" title="Fonctionnalité en préparation">{content}<span className="sr-only"> — en préparation</span></span>;
}

export default function CampusLayout({ children, active, title }: { children: ReactNode; active: string; title: string }) {
    const { campus, flash, auth } = usePage<CampusProps>().props;
    const updateReady = useSyncExternalStore(subscribe, updateWaiting, () => false);
    const accountKey = auth.accountKey ?? null;
    const unsent = useOutbox(accountKey).filter((operation) => operation.state !== 'synced').length;
    useEffect(() => {
        start();
        let alive = true;
        // Envoi des brouillons écrits hors connexion : à l'ouverture, au retour du réseau et au retour sur l'onglet.
        // Aucune synchronisation en arrière-plan du navigateur n'est nécessaire.
        const send = () => {
            void synchronizeDrafts(accountKey).then(async (summary) => {
                if (!summary || summary.synced === 0) return;
                try { await synchronize(); } catch { /* la copie de lecture sera rafraîchie à la prochaine ouverture */ }
                if (alive) router.reload();
            });
        };
        const visible = () => { if (document.visibilityState === 'visible') send(); };
        // À chaque ouverture : effacement si l'appareil est partagé, si le compte a changé ou si la copie est trop ancienne ; sinon resynchronisation.
        void reconcile(accountKey).then(send);
        window.addEventListener('online', send);
        document.addEventListener('visibilitychange', visible);

        return () => {
            alive = false;
            window.removeEventListener('online', send);
            document.removeEventListener('visibilitychange', visible);
        };
    }, [accountKey]);
    const [online, setOnline] = useState(typeof navigator === 'undefined' || navigator.onLine);
    // Envoi interrompu par le réseau : la page reste telle quelle, la saisie n'est pas perdue, et la personne peut renvoyer.
    const [networkFailure, setNetworkFailure] = useState(false);
    useEffect(() => {
        const failed = router.on('networkError', () => setNetworkFailure(true));
        const started = router.on('start', () => setNetworkFailure(false));

        return () => {
            failed();
            started();
        };
    }, []);
    useEffect(() => {
        const update = () => setOnline(navigator.onLine);
        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        return () => {
            window.removeEventListener('online', update);
            window.removeEventListener('offline', update);
        };
    }, []);
    const primary = campus.navigation.filter((item) => item.key !== 'grades');
    return (
        <div className="campus-shell">
            <a className="skip-link" href="#contenu">Aller au contenu</a>
            <aside className="desktop-sidebar">
                <Link href="/" className="brand"><span className="brand-mark" aria-hidden="true">H</span><span>HAKO-RA<span className="brand-subtitle">Campus</span></span></Link>
                <p className="sidebar-caption">VOTRE QUOTIDIEN</p>
                <nav aria-label="Navigation principale ordinateur" className="desktop-navigation">{primary.map((item) => <NavigationEntry key={item.key} item={item} active={active} />)}</nav>
                <p className="sidebar-caption">ESPACE D’ÉTUDES</p><Link className="nav-item" href="/etudes/referentiels">Matières et référentiels</Link><Link className={`nav-item ${active === 'help' ? 'is-active' : ''}`} href="/aide">Aide et support</Link>
                <nav aria-label="Espace d’études">{campus.navigation.filter((item) => item.key === 'grades').map((item) => <NavigationEntry key={item.key} item={item} active={active} />)}</nav>
                {campus.administration.length > 0 && <nav aria-label="Administration"><p className="sidebar-caption">ADMINISTRATION</p>{campus.administration.map((item) => <Link className="nav-item" key={item.href} href={item.href}>{item.label}</Link>)}</nav>}
                <div className="sidebar-footer"><span aria-hidden="true">✦</span><p>Un pas après l’autre.<br /><span>Votre parcours vous appartient.</span></p></div>
            </aside>
            <div className="workspace">
                <header className="workspace-header">
                    <Link href="/" className="mobile-brand" aria-label="HAKO-RA Campus, accueil">H<span>Campus</span></Link>
                    <p className="header-title">{title}</p>
                    {campus.unreadNotifications !== null && <Link href="/notifications" className="text-link" aria-label={`Notifications, ${campus.unreadNotifications} non lue(s)`}>Notifications{campus.unreadNotifications > 0 && ` (${campus.unreadNotifications})`}</Link>}
                    <div className="context-badge" aria-label="Contexte actif"><span className="context-symbol" aria-hidden="true">◉</span><div>{campus.contexts.length > 0
                        ? <><label className="sr-only" htmlFor="context-select">Changer de contexte</label><select id="context-select" className="context-select" value={campus.context.membershipId ?? ''} onChange={(event) => router.post('/contexte', { membership_id: event.target.value === '' ? null : Number(event.target.value) })}><option value="">Espace individuel</option>{campus.contexts.map((context) => <option key={context.membershipId} value={context.membershipId}>{context.label}</option>)}</select></>
                        : <strong>{campus.context.label}</strong>}<span>{campus.context.description}</span></div></div>
                </header>
                {campus.administration.length > 0 && <nav className="mobile-administration" aria-label="Administration mobile">{campus.administration.map((item) => <Link className="text-link" key={item.href} href={item.href}>{item.label}</Link>)}</nav>}
                <main id="contenu" tabIndex={-1} className="main-content">
                    {updateReady && <StateMessage title="Une nouvelle version de Campus est prête" action={<button type="button" className="button button-secondary" onClick={applyUpdate}>Mettre à jour maintenant</button>}>La page sera rechargée. Enregistrez d’abord une saisie en cours ; sinon, la mise à jour se fera à la prochaine ouverture.</StateMessage>}
                    {!online && <StateMessage kind="offline" title="Vous êtes hors connexion">Les pages déjà ouvertes restent affichées. Sur un appareil personnel, une tâche personnelle ou un retour de cours s’enregistre comme brouillon et part au retour du réseau ; tout le reste exige la connexion.</StateMessage>}
                    {unsent > 0 && <StateMessage title={`${unsent} brouillon(s) de cet appareil ne sont pas encore synchronisés`} action={<Link className="text-link" href="/hors-ligne">Voir leur état</Link>}>Ils restent sur cet appareil tant qu’ils ne sont pas envoyés ou abandonnés.</StateMessage>}
                    {networkFailure && online && <StateMessage kind="error" title="Le réseau n’a pas répondu">Votre demande n’est pas partie, ou sa réponse s’est perdue. Votre saisie est toujours à l’écran : renvoyez-la. Un envoi répété ne crée pas de doublon.</StateMessage>}
                    {flash?.problem && <StateMessage kind="error" title="Action non enregistrée" action={<button type="button" className="button button-secondary" onClick={() => window.location.reload()}>Recharger la page</button>}>{flash.problem}</StateMessage>}
                    {campus.privacyNoticePending && active !== 'privacy' && <StateMessage title="La notice de confidentialité est à lire" action={<Link className="text-link" href="/confidentialite/parametres">Lire et confirmer</Link>}>Elle décrit ce que Campus fait de vos données. Rien n’est bloqué en attendant.</StateMessage>}
                    {flash?.status && <StateMessage kind="success" title={flash.status} />}
                    {children}
                </main>
                <footer className="workspace-footer"><Link href="/etudes/referentiels">Matières et référentiels</Link><Link href="/aide">Aide et support</Link><Link href="/confidentialite">Confidentialité</Link>{auth.user && <Link href="/notifications/preferences">Préférences de notification</Link>}<Link href="/hors-ligne">Hors connexion</Link><span>HAKO-RA Campus · version {campus.version}</span>{campus.development && <Link href="/developpement/composants">Atelier des composants</Link>}</footer>
            </div>
            <nav aria-label="Navigation principale mobile" className="mobile-navigation">{primary.map((item) => <NavigationEntry key={item.key} item={item} active={active} />)}</nav>
        </div>
    );
}
