import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type KeyboardEvent } from 'react';
import { start } from '../offline/runtime';
import { reconcile } from '../offline/store';
import { Icon } from './Icon';
import { WeekRelief } from './WeekRelief';
import type { CampusProps } from '../types';

const CHECKOUT = ['Il me reste un devoir', 'Je note ce qu’il faut revoir', 'J’ai une note à saisir', 'Rien à signaler'];

const ROLES: { key: string; label: string; items: [string, string, string][] }[] = [
    { key: 'eleve', label: 'Élève ou étudiant', items: [
        ['home', 'Ta journée d’un coup d’œil', 'Prochain cours, tâches du jour, moyenne et opportunités, sur l’accueil.'],
        ['grades', 'Notes sur 20', 'Tes notes personnelles et celles publiées par ton établissement, jamais mélangées. Moyennes avec coefficients, expliquées.'],
        ['tasks', 'Tâches et étapes', 'Une case à cocher, un jour, une heure. Les gros devoirs se découpent en petites étapes.'],
        ['planning', 'Ton planning', 'Calendrier de la semaine, tes cours perso à côté de ceux de ton établissement. Une case vide : une tâche.'],
        ['opportunities', 'Bourses, concours, stages', 'Un catalogue vérifié, filtré selon ton niveau et tes domaines. Les dates limites te sont rappelées.'],
        ['profile', 'Un seul compte', 'Ton espace perso reste à toi, même si tu changes d’établissement ou si tu en as plusieurs.'],
    ] },
    { key: 'enseignant', label: 'Enseignant', items: [
        ['tasks', 'Publier un devoir', 'Il apparaît chez les élèves du groupe, avec sa date limite et ses fichiers.'],
        ['grades', 'Évaluations et notes', 'Saisie en brouillon, puis publication. Chaque élève ne voit que ses propres résultats.'],
        ['planning', 'Signaler une indisponibilité', 'L’administration décide ; la classe est prévenue dès que le planning publié change.'],
    ] },
    { key: 'etablissement', label: 'Établissement', items: [
        ['planning', 'Import Excel, avec simulation', 'L’emploi du temps est vérifié ligne par ligne avant toute application : rien n’est publié par erreur.'],
        ['bell', 'Publication versionnée', 'Chaque publication est datée et réversible. Les élèves et enseignants concernés sont prévenus.'],
        ['profile', 'Invitations et rôles', 'Chaque rôle vaut pour un établissement et une année. Les données d’un établissement restent chez lui.'],
    ] },
];

const REMINDERS: [string, string, string, string][] = [
    ['Fin de cours', 'Un cours vient de se terminer', '5 minutes après la fin : une minute pour noter ce qu’il reste à faire.', 'orange'],
    ['Tâche', 'Une tâche est à faire dans 1 h', 'La veille, le jour même et juste avant l’heure, comme tu le choisis.', 'teal'],
    ['Planning', 'Votre planning a changé', 'Cours ajouté, déplacé ou annulé : tu le sais avant d’arriver en salle.', 'violet'],
    ['Opportunité', 'Une date limite approche', 'Pour les bourses et concours que tu suis, une semaine et un jour avant.', 'yellow'],
];

/**
 * Page d'accueil des visiteurs : présentation de Campus dans le style « nuit », sans aucune donnée réelle.
 * Comme les autres mises en page, l'ouverture efface la copie de lecture laissée par un compte sur un appareil partagé.
 */
export function Landing({ registrationEnabled }: { registrationEnabled: boolean }) {
    const { campus } = usePage<CampusProps>().props;
    const [picked, setPicked] = useState<boolean[]>(CHECKOUT.map(() => false));
    const [saved, setSaved] = useState<string | null>(null);
    const [role, setRole] = useState(0);
    useEffect(() => {
        start();
        void reconcile(null);
    }, []);
    const toggle = (index: number) => {
        setSaved(null);
        setPicked((current) => current.map((value, position) => index === CHECKOUT.length - 1 ? position === index && !value : position === CHECKOUT.length - 1 ? false : position === index ? !value : value));
    };
    const save = () => {
        const chosen = CHECKOUT.filter((_, index) => picked[index]);
        setSaved(chosen.length === 0 ? 'Choisis au moins une réponse, ou « Rien à signaler ».' : picked[CHECKOUT.length - 1] ? 'C’est noté : pas de rappel de plus pour ce cours.' : `Noté : ${chosen.join(', ').toLowerCase()}. Rangé avec le cours de mathématiques.`);
    };
    const keyTabs = (event: KeyboardEvent) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        const next = (role + (event.key === 'ArrowRight' ? 1 : ROLES.length - 1)) % ROLES.length;
        setRole(next);
        document.getElementById(`tab-${ROLES[next].key}`)?.focus();
    };

    return <div className="campus-shell theme-night landing">
        <Head title="HAKO-RA Campus" />
        <a className="skip-link" href="#contenu">Aller au contenu</a>
        <div className="night-aurora" aria-hidden="true"><i /><i /></div>
        <header className="night-header"><div className="night-wrap">
            <Link href="/" className="night-logo" aria-label="HAKO-RA Campus, accueil"><span className="night-logo-mark" aria-hidden="true"><Icon name="planning" size={18} strokeWidth={2.4} /></span>HAKO-RA Campus</Link>
            <nav className="night-links" aria-label="Sections"><a href="#apres-le-cours">Après le cours</a><a href="#rappels">Rappels</a><a href="#pour-qui">Pour qui</a><Link href="/opportunites">Opportunités</Link></nav>
            <Link className="night-btn night-btn-primary night-btn-sm" href="/connexion">Se connecter</Link>
        </div></header>

        <main id="contenu" tabIndex={-1}>
            <section className="night-hero"><div className="night-wrap night-hero-grid">
                <div className="night-copy">
                    <span className="night-eyebrow">Planning, tâches et notes · Côte d’Ivoire</span>
                    <h1>Ta semaine prend <em>du relief.</em></h1>
                    <p className="night-lead">HAKO-RA Campus réunit tes cours, tes tâches et tes notes dans une seule semaine. Les changements de salle arrivent avant toi, et chaque fin de cours te laisse une minute pour ne rien oublier.</p>
                    <div className="night-cta">
                        {registrationEnabled ? <Link className="night-btn night-btn-primary" href="/inscription">Créer un compte</Link> : <Link className="night-btn night-btn-primary" href="/connexion">Se connecter</Link>}
                        <a className="night-btn night-btn-ghost" href="#apres-le-cours">Voir comment ça marche</a>
                    </div>
                    <ul className="night-facts"><li><b>5 min</b>après le cours, ton retour</li><li><b>/20</b>notes et coefficients</li><li><b>.xlsx</b>emploi du temps importé</li></ul>
                </div>
                <div className="night-stage"><WeekRelief /><p className="night-hint">Semaine d’exemple · touche un cours</p></div>
            </div></section>

            <section className="night-block" id="apres-le-cours" aria-labelledby="checkout-title"><div className="night-wrap night-split">
                <div className="night-copy">
                    <span className="night-eyebrow">Après le cours</span>
                    <h2 id="checkout-title">Le cours est fini. <em>Une minute</em> pour ne rien oublier.</h2>
                    <p className="night-lead">Cinq minutes après chaque cours, Campus te demande ce qu’il reste à faire. Un devoir, une notion à revoir, une note : tu choisis, ou « rien à signaler ». C’est privé : ni tes enseignants ni ton établissement ne le lisent.</p>
                </div>
                <div className="night-checkout" role="group" aria-labelledby="checkout-demo-title">
                    <div className="night-checkout-head">
                        <span className="night-bell" aria-hidden="true"><Icon name="bell" size={20} /></span>
                        <p><strong id="checkout-demo-title">Mathématiques vient de se terminer</strong>Salle 12 · il y a 5 min · démonstration</p>
                    </div>
                    <div className="night-chips">{CHECKOUT.map((label, index) => <button type="button" key={label} className="night-chip" aria-pressed={picked[index]} onClick={() => toggle(index)}>{label}</button>)}</div>
                    <div className="night-cta"><button type="button" className="night-btn night-btn-primary night-btn-sm" onClick={save}>Enregistrer</button></div>
                    <p className={`night-result ${saved && !saved.startsWith('Choisis') ? 'is-ok' : ''}`} aria-live="polite">{saved ?? 'Sur cette page, rien n’est enregistré : c’est un exemple.'}</p>
                </div>
            </div></section>

            <section className="night-block" id="rappels" aria-labelledby="reminders-title"><div className="night-wrap">
                <div className="night-copy">
                    <span className="night-eyebrow">Toujours à jour</span>
                    <h2 id="reminders-title">Les rappels arrivent <em>au bon moment.</em></h2>
                    <p className="night-lead">Dans l’application, sur ton téléphone ou par courriel, selon ce que tu choisis. Tu règles les heures et le silence de la nuit : Campus reste une aide, pas une source de stress.</p>
                </div>
                <ul className="night-reminders">{REMINDERS.map(([kind, title, text, tone]) => <li key={kind} className={`night-reminder tone-${tone}`}><span className="night-kind">{kind}</span><strong>{title}</strong><p>{text}</p></li>)}</ul>
            </div></section>

            <section className="night-block" id="pour-qui" aria-labelledby="roles-title"><div className="night-wrap">
                <div className="night-copy">
                    <span className="night-eyebrow">Pour toute l’école</span>
                    <h2 id="roles-title">Une même semaine, <em>trois points de vue.</em></h2>
                    <p className="night-lead">Les élèves s’organisent, les enseignants publient, l’établissement tient le planning. En indépendant, tu crées ton propre emploi du temps.</p>
                </div>
                <div className="night-tabs" role="tablist" aria-label="Pour qui">{ROLES.map((item, index) => <button type="button" key={item.key} role="tab" id={`tab-${item.key}`} aria-selected={role === index} aria-controls={`panel-${item.key}`} tabIndex={role === index ? 0 : -1} className="night-tab" onClick={() => setRole(index)} onKeyDown={keyTabs}>{item.label}</button>)}</div>
                {ROLES.map((item, index) => <div key={item.key} id={`panel-${item.key}`} role="tabpanel" aria-labelledby={`tab-${item.key}`} hidden={role !== index} className="night-features">
                    {item.items.map(([icon, title, text]) => <div key={title} className="night-feature"><span className="night-feature-icon" aria-hidden="true"><Icon name={icon} size={19} /></span><h3>{title}</h3><p>{text}</p></div>)}
                </div>)}
            </div></section>

            <section className="night-block" aria-labelledby="opportunities-title"><div className="night-wrap night-split">
                <div className="night-copy">
                    <span className="night-eyebrow">Ouvert à tous</span>
                    <h2 id="opportunities-title">Bourses, concours, stages : <em>des fiches vérifiées.</em></h2>
                    <p className="night-lead">Chaque fiche renvoie à sa source officielle et indique sa date limite telle que l’organisateur la donne. Le catalogue se consulte sans compte.</p>
                    <div className="night-cta"><Link className="night-btn night-btn-ghost" href="/opportunites">Parcourir le catalogue</Link></div>
                </div>
                <ul className="night-categories" aria-label="Catégories du catalogue">{[['scholarship', 'Bourses'], ['competition', 'Concours'], ['internship', 'Stages'], ['training', 'Formations']].map(([icon, label]) => <li key={icon} className={`night-category visual-${icon}`}><Icon name={icon} size={34} strokeWidth={1.75} /><span>{label}</span></li>)}</ul>
            </div></section>

            <section className="night-block" aria-labelledby="final-title"><div className="night-wrap"><div className="night-final">
                <div className="night-copy">
                    <span className="night-eyebrow">Gratuit pour s’organiser</span>
                    <h2 id="final-title">Commence la prochaine semaine <em>en avance.</em></h2>
                    <p className="night-lead">Un nom, une adresse électronique, un mot de passe : ton espace est prêt. Si ton établissement utilise Campus, il t’enverra une invitation pour te rattacher à ta classe.</p>
                </div>
                <div className="night-cta night-cta-stack">
                    {registrationEnabled && <Link className="night-btn night-btn-primary" href="/inscription">Créer un compte</Link>}
                    <Link className="night-btn night-btn-ghost" href="/connexion">J’ai déjà un compte</Link>
                </div>
            </div></div></section>
        </main>

        <footer className="night-footer"><div className="night-wrap">
            <span>HAKO-RA Campus · version {campus.version}</span>
            <nav aria-label="Informations"><Link href="/aide">Aide et support</Link><Link href="/confidentialite">Confidentialité</Link><Link href="/opportunites">Opportunités</Link></nav>
            <span>Les cours montrés sur cette page sont des exemples.</span>
        </div></footer>
    </div>;
}
