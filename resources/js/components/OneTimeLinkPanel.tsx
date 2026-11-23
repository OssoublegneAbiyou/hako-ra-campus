import { usePage } from '@inertiajs/react';
import type { CampusProps } from '../types';

export function OneTimeLinkPanel() {
    const { flash } = usePage<CampusProps>().props;
    const link = flash.oneTimeLink;
    if (!link) return null;
    return (
        <section className="secret-panel" role="status" aria-labelledby="link-title">
            <h2 id="link-title">Lien d’invitation — {link.role}</h2>
            <span className="link-value">{link.url}</span>
            <p className="item-meta">Ce lien s’affiche une seule fois et expire le {new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(link.expiresAt))}. Remettez-le uniquement à la personne concernée. {link.email ? `Un envoi à ${link.email} a été mis en file ; il ne part que si un service de courriel est configuré.` : 'Aucun courriel n’est envoyé pour cette invitation.'}</p>
        </section>
    );
}
