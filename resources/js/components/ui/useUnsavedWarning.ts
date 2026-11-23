import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Avertit avant d'abandonner une saisie non enregistrée : à la fermeture ou au rechargement de l'onglet, et avant de quitter l'écran
 * par un lien de Campus. L'envoi du formulaire lui-même et les rafraîchissements de la même page ne déclenchent rien.
 */
export function useUnsavedWarning(dirty: boolean, what = 'Votre saisie'): void {
    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); };
        window.addEventListener('beforeunload', warn);
        const stop = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get' || visit.url.pathname === window.location.pathname) return;
            if (!window.confirm(`${what} n’est pas enregistrée. Quitter cette page et la perdre ?`)) event.preventDefault();
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            stop();
        };
    }, [dirty, what]);
}
