// Enregistrement du service worker, détection d'une nouvelle version et aide à l'installation.
// Rien ici ne recharge la page tout seul : une mise à jour n'est appliquée que lorsque la personne le demande.

type InstallPrompt = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

let waiting: ServiceWorker | null = null;
let installPrompt: InstallPrompt | null = null;
const listeners = new Set<() => void>();
let started = false;

function notify(): void {
    listeners.forEach((listener) => listener());
}

export function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function updateWaiting(): boolean {
    return waiting !== null;
}

export function canPromptInstall(): boolean {
    return installPrompt !== null;
}

/** Démarre une seule fois : enregistre le service worker et écoute les événements d'installation. Sans effet si le navigateur ne le permet pas. */
export function start(): void {
    if (started || typeof window === 'undefined') return;
    started = true;
    window.addEventListener('beforeinstallprompt', (event) => {
        // Le navigateur propose l'installation : on garde la proposition pour l'offrir sur la page « Hors connexion », au moment choisi par la personne.
        event.preventDefault();
        installPrompt = event as InstallPrompt;
        notify();
    });
    window.addEventListener('appinstalled', () => { installPrompt = null; notify(); });
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register('/sw.js').then((registration) => {
        const track = (worker: ServiceWorker | null) => {
            if (!worker) return;
            worker.addEventListener('statechange', () => {
                // Une nouvelle version est prête et une ancienne contrôle déjà la page : on prévient, sans rien recharger.
                if (worker.state === 'installed' && navigator.serviceWorker.controller) { waiting = worker; notify(); }
            });
        };
        if (registration.waiting && navigator.serviceWorker.controller) { waiting = registration.waiting; notify(); }
        track(registration.installing);
        registration.addEventListener('updatefound', () => track(registration.installing));
    }).catch(() => { /* enregistrement refusé : Campus fonctionne en ligne comme avant */ });
}

/** La personne accepte la mise à jour : la nouvelle version prend la main, puis la page est rechargée une fois. */
export function applyUpdate(): void {
    if (!waiting) return;
    navigator.serviceWorker.addEventListener('controllerchange', () => window.location.reload(), { once: true });
    waiting.postMessage({ type: 'SKIP_WAITING' });
}

/** Ouvre la proposition d'installation du navigateur. Un refus ne change rien au fonctionnement de Campus. */
export async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
    if (!installPrompt) return 'unavailable';
    const prompt = installPrompt;
    installPrompt = null;
    notify();
    await prompt.prompt();

    return (await prompt.userChoice).outcome;
}
