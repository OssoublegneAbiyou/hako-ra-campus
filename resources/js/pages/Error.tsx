import { Head } from '@inertiajs/react';

// Page d'erreur autonome : elle ne lit aucune donnée partagée (une erreur peut survenir avant qu'elles existent)
// et propose toujours une reprise. Aucun détail technique, aucun identifiant.
const MESSAGES: Record<number, { title: string; text: string; retry: boolean }> = {
    403: { title: 'Accès interdit', text: 'Votre compte, ou le contexte actif, ne donne pas accès à cette page. Si vous avez plusieurs appartenances, changez de contexte depuis l’accueil puis réessayez.', retry: false },
    404: { title: 'Page introuvable', text: 'Cette page n’existe pas, n’existe plus, ou ne vous est pas accessible.', retry: false },
    419: { title: 'Session expirée', text: 'Votre session a expiré pendant que la page était ouverte. Rechargez la page, puis recommencez : rien n’a été enregistré.', retry: true },
    429: { title: 'Trop de demandes', text: 'Trop de demandes ont été envoyées en peu de temps. Patientez une minute, puis réessayez.', retry: true },
    500: { title: 'Une erreur est survenue', text: 'Campus n’a pas pu traiter cette demande. Ce n’est pas de votre fait. Réessayez ; si l’erreur persiste, consultez la page d’aide.', retry: true },
    503: { title: 'Campus est momentanément indisponible', text: 'Une opération de maintenance est en cours. Réessayez dans quelques minutes.', retry: true },
};

export default function ErrorPage({ status }: { status: number }) {
    const message = MESSAGES[status] ?? MESSAGES[500];

    return (
        <main className="main-content" id="contenu" style={{ maxWidth: '40rem', margin: '0 auto', padding: '2rem 1rem' }}>
            <Head title={message.title} />
            <p className="eyebrow">HAKO-RA CAMPUS · ERREUR {status}</p>
            <h1>{message.title}</h1>
            <p className="page-intro" role="alert">{message.text}</p>
            <div className="form-actions" style={{ marginTop: '1.5rem' }}>
                {message.retry && <button type="button" className="button button-primary" onClick={() => window.location.reload()}>Réessayer</button>}
                {/* Liens classiques : ils rechargent entièrement l'application, ce qui rétablit une session ou un jeton périmés. */}
                <a className={`button ${message.retry ? 'button-secondary' : 'button-primary'}`} href="/">Revenir à l’accueil</a>
                <a className="button button-secondary" href="/aide">Aide et support</a>
            </div>
        </main>
    );
}
