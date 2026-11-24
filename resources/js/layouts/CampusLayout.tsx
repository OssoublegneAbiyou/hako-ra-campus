import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, useSyncExternalStore, type ReactNode } from 'react';
import { applyUpdate, start, subscribe, updateWaiting } from '../offline/runtime';
import { synchronizeDrafts, useOutbox } from '../offline/outbox';
import { reconcile, synchronize } from '../offline/store';
import { Icon } from '../components/Icon';
import { QuickAdd } from '../components/QuickAdd';
import { NotificationPopups } from '../components/NotificationPopups';
import { onQuickAdd, type QuickAddKind } from '../components/quickAddEvents';
import { StateMessage } from '../components/ui/Feedback';
import type { CampusProps, NavigationItem } from '../types';

function NavigationEntry({ item, active }: { item: NavigationItem; active: string }) {
    // Libellé court de la navigation ; l'écran garde son nom complet.
    const label = item.key === 'opportunities' ? 'Bourses' : item.label;
    const content = <><span className="nav-icon"><Icon name={item.key} size={24} /></span><span>{label}</span></>;
    return item.href
        ? <Link href={item.href} className={`nav-item ${active === item.key ? 'is-active' : ''}`} aria-current={active === item.key ? 'page' : undefined}>{content}</Link>
        : <span className="nav-item is-unavailable" aria-disabled="true" title="Fonctionnalité en préparation">{content}<span className="sr-only"> — en préparation</span></span>;
}

/** Pastilles de contexte : l'espace personnel et chaque établissement du compte. Le serveur vérifie le choix ; la personne reste sur l'écran. */
export function ContextChips() {
    const { campus } = usePage<CampusProps>().props;
    if (campus.contexts.length === 0) return null;
    const choose = (membershipId: number | null) => router.post('/contexte', { membership_id: membershipId }, { preserveScroll: true });

    return <div className="context-chips" role="group" aria-label="Changer de contexte">
        <button type="button" className="chip" aria-pressed={campus.context.membershipId === null} title="Espace individuel" onClick={() => choose(null)}>Perso</button>
        {campus.contexts.map((context) => <button type="button" className="chip" key={context.membershipId} aria-pressed={campus.context.membershipId === context.membershipId} title={context.label} onClick={() => choose(context.membershipId)}>{context.short}</button>)}
    </div>;
}

export default function CampusLayout({ children, active, title }: { children: ReactNode; active: string; title: string }) {
    const { campus, flash, auth } = usePage<CampusProps>().props;
    const [adding, setAdding] = useState<QuickAddKind | null>(null);
    const [addKind, setAddKind] = useState<QuickAddKind>('task');
    const [addDue, setAddDue] = useState<string | undefined>(undefined);
    useEffect(() => onQuickAdd(({ kind, due }) => { setAddKind(kind); setAddDue(due); setAdding(kind); }), []);
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
    const entry = (key: string) => campus.navigation.filter((item) => item.key === key);
    const desktop = ['home', 'planning', 'tasks', 'grades', 'opportunities'].flatMap(entry);
    const initial = (auth.user?.name ?? '').trim()[0]?.toUpperCase() ?? '';
    const open = (kind: QuickAddKind) => { setAddKind(kind); setAddDue(undefined); setAdding(kind); };
    return (
        <div className={`campus-shell ${active === 'home' ? 'is-home' : ''}`} data-title={title}>
            <a className="skip-link" href="#contenu">Aller au contenu</a>
            <div className="workspace">
                <header className="app-header"><div className="app-header-inner">
                    <Link href="/" className="brand" aria-label="HAKO-RA Campus, accueil"><span className="brand-mark" aria-hidden="true"><Icon name="star" strokeWidth={2.5} /></span><span className="brand-long">HAKO-RA Campus</span><span className="brand-short">Campus</span></Link>
                    <nav aria-label="Navigation principale ordinateur" className="top-navigation">{desktop.map((item) => <NavigationEntry key={item.key} item={item} active={active} />)}</nav>
                    <div className="header-actions">
                        {campus.quickAddAvailable && <button type="button" className="button button-primary button-add" onClick={() => open('task')}><Icon name="plus" size={18} strokeWidth={2.5} />Ajouter</button>}
                        {campus.unreadNotifications !== null && <Link href="/notifications" className="icon-button" aria-label={`Notifications, ${campus.unreadNotifications} non lue(s)`} title="Notifications"><Icon name="bell" />{campus.unreadNotifications > 0 && <span className="icon-dot" aria-hidden="true" />}</Link>}
                        {auth.user
                            ? <Link href="/profil" className="avatar-link" aria-label={`Profil de ${auth.user.name}`} aria-current={active === 'profile' ? 'page' : undefined} title="Profil">{initial}</Link>
                            : <Link href="/connexion" className="button button-primary">Se connecter</Link>}
                    </div>
                </div></header>
                {campus.administration.length > 0 && <nav className="sub-navigation" aria-label="Administration"><div><span>Administration</span>{campus.administration.map((item) => <Link className="text-link" key={item.href} href={item.href}>{item.label}</Link>)}</div></nav>}
                <main id="contenu" tabIndex={-1} className="main-content">
                    {active !== 'home' && campus.contexts.length > 0 && <div className="context-chips context-bar"><ContextChips /><span className="context-role">{campus.context.description}</span></div>}
                    {updateReady && <StateMessage title="Une nouvelle version de Campus est prête" action={<button type="button" className="button button-secondary" onClick={applyUpdate}>Mettre à jour maintenant</button>}>La page sera rechargée. Enregistrez d’abord une saisie en cours ; sinon, la mise à jour se fera à la prochaine ouverture.</StateMessage>}
                    {!online && <StateMessage kind="offline" title="Vous êtes hors connexion">Les pages déjà ouvertes restent affichées. Sur un appareil personnel, une tâche personnelle ou un retour de cours s’enregistre comme brouillon et part au retour du réseau ; tout le reste exige la connexion.</StateMessage>}
                    {unsent > 0 && <StateMessage title={`${unsent} brouillon(s) de cet appareil ne sont pas encore synchronisés`} action={<Link className="text-link" href="/hors-ligne">Voir leur état</Link>}>Ils restent sur cet appareil tant qu’ils ne sont pas envoyés ou abandonnés.</StateMessage>}
                    {networkFailure && online && <StateMessage kind="error" title="Le réseau n’a pas répondu">Votre demande n’est pas partie, ou sa réponse s’est perdue. Votre saisie est toujours à l’écran : renvoyez-la. Un envoi répété ne crée pas de doublon.</StateMessage>}
                    {flash?.problem && <StateMessage kind="error" title="Action non enregistrée" action={<button type="button" className="button button-secondary" onClick={() => window.location.reload()}>Recharger la page</button>}>{flash.problem}</StateMessage>}
                    {campus.privacyNoticePending && (active === 'home' || active === 'profile') && <StateMessage title="La notice de confidentialité est à lire" action={<Link className="text-link" href="/confidentialite/parametres">Lire et confirmer</Link>}>Elle décrit ce que Campus fait de vos données. Rien n’est bloqué en attendant.</StateMessage>}
                    {flash?.status && <StateMessage kind="success" title={flash.status} />}
                    {children}
                </main>
                <footer className="workspace-footer"><div><Link href="/taches">Tâches</Link><Link href="/etudes/referentiels">Matières et périodes</Link><Link href="/aide">Aide et support</Link><Link href="/confidentialite">Confidentialité</Link>{auth.user && <Link href="/notifications/preferences">Préférences de notification</Link>}<Link href="/hors-ligne">Hors connexion</Link><span>HAKO-RA Campus · version {campus.version}</span>{campus.development && <Link href="/developpement/composants">Atelier des composants</Link>}</div></footer>
            </div>
            <nav aria-label="Navigation principale mobile" className="mobile-navigation">
                {['home', 'planning'].flatMap(entry).map((item) => <NavigationEntry key={item.key} item={item} active={active} />)}
                {campus.quickAddAvailable
                    ? <button type="button" className="nav-add" aria-label="Ajouter" onClick={() => open('task')}><Icon name="plus" size={26} strokeWidth={2.5} /></button>
                    : <Link href={auth.user ? '/profil' : '/connexion'} className="nav-add" aria-label={auth.user ? 'Profil' : 'Se connecter'}><Icon name="profile" size={24} /></Link>}
                {['grades', 'opportunities'].flatMap(entry).map((item) => <NavigationEntry key={item.key} item={item} active={active} />)}
            </nav>
            {campus.unreadNotifications !== null && <NotificationPopups accountKey={accountKey} />}
            {campus.quickAddAvailable && <QuickAdd open={adding !== null} kind={addKind} due={addDue} onKind={setAddKind} onClose={() => setAdding(null)} />}
        </div>
    );
}
