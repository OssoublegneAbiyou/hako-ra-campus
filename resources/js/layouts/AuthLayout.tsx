import { Link, usePage } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import { start } from '../offline/runtime';
import { reconcile } from '../offline/store';
import { Icon } from '../components/Icon';
import { WeekRelief } from '../components/WeekRelief';
import { StateMessage } from '../components/ui/Feedback';
import type { CampusProps } from '../types';

/**
 * Écrans d'accès (connexion, création de compte) : panneau de présentation et formulaire, sans la navigation de l'application.
 * Comme la mise en page principale, l'ouverture efface la copie de lecture d'un appareil partagé ou d'un autre compte.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
    const { flash, auth, campus } = usePage<CampusProps>().props;
    const accountKey = auth.accountKey ?? null;
    useEffect(() => {
        start();
        void reconcile(accountKey);
    }, [accountKey]);
    return (
        <div className="auth-shell theme-night">
            <a className="skip-link" href="#contenu">Aller au contenu</a>
            <div className="night-aurora" aria-hidden="true"><i /><i /></div>
            <aside className="auth-aside">
                <Link href="/" className="auth-brand" aria-label="HAKO-RA Campus, accueil"><span className="brand-mark" aria-hidden="true"><Icon name="planning" size={22} strokeWidth={2.4} /></span><span>HAKO-RA Campus</span></Link>
                <div className="auth-pitch">
                    <span className="night-eyebrow">Planning, tâches et notes</span>
                    <p className="auth-tagline">Ta semaine prend <em>du relief.</em></p>
                    <p className="night-lead">Tes cours, tes tâches et tes notes au même endroit, sur ordinateur comme sur téléphone.</p>
                </div>
                <div className="auth-relief"><WeekRelief compact /></div>
            </aside>
            <main id="contenu" tabIndex={-1} className="auth-main">
                <div className="auth-panel">
                    {flash?.problem && <StateMessage kind="error" title="Action non enregistrée">{flash.problem}</StateMessage>}
                    {flash?.status && <StateMessage kind="success" title={flash.status} />}
                    {children}
                    <nav className="auth-footer" aria-label="Informations"><Link className="text-link" href="/opportunites">Opportunités</Link><Link className="text-link" href="/aide">Aide et support</Link><Link className="text-link" href="/confidentialite">Confidentialité</Link></nav>
                    <p className="item-meta" style={{ textAlign: 'center' }}>HAKO-RA Campus · version {campus.version}</p>
                </div>
            </main>
        </div>
    );
}
