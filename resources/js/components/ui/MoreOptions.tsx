import { useEffect, useRef, type ReactNode } from 'react';

/**
 * Champs facultatifs d'un formulaire, repliés par défaut pour ne montrer que l'essentiel.
 * Le bloc s'ouvre de lui-même quand un de ses champs porte une erreur ou une valeur déjà saisie : rien n'est caché à la personne.
 */
export function MoreOptions({ label = 'Plus d’options', reveal = false, children }: { label?: string; reveal?: boolean; children: ReactNode }) {
    const details = useRef<HTMLDetailsElement>(null);
    useEffect(() => {
        if (reveal && details.current) details.current.open = true;
    }, [reveal]);

    return <details ref={details} className="more-options"><summary>{label}</summary><div>{children}</div></details>;
}
