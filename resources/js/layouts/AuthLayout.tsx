import { Link, usePage } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import { start } from '../offline/runtime';
import { reconcile } from '../offline/store';
import { Icon } from '../components/Icon';
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
        <div className="auth-shell">
            <a className="skip-link" href="#contenu">Aller au contenu</a>
            <aside className="auth-aside">
                <Link href="/" className="auth-brand" aria-label="HAKO-RA Campus, accueil"><span className="brand-mark" aria-hidden="true"><Icon name="star" size={24} /></span><span>HAKO-RA Campus</span></Link>
                <div className="auth-pitch">
                    <p className="auth-tagline">Organisez vos études, un pas après l’autre.</p>
                    <p>Planning, tâches, notes et opportunités vérifiées au même endroit, sur votre ordinateur comme sur votre téléphone.</p>
                </div>
                <ul className="auth-tiles">
                    <li><strong>Planning</strong><span>Personnel et d’établissement</span></li>
                    <li><strong>Retour après cours</strong><span>Privé, en une minute</span></li>
                    <li><strong>Tâches</strong><span>Devoirs découpés en étapes</span></li>
                </ul>
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
