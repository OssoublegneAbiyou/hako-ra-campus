import { Head, Link, usePage } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { ProvenanceBadge, StateMessage } from '../components/ui/Feedback';
import type { Deadline } from '../components/OpportunityDeadline';
import type { CampusProps } from '../types';

type Course = { id: string; title: string; startsAt: string; endsAt: string; cancelled: boolean; provenance: 'personal' | 'institutional'; checkIn: { version: number; status: string | null } | null };
type PendingCheckIn = { occurrenceId: string; title: string; endsAt: string; provenance: 'personal' | 'institutional'; deferred: boolean };
type UpcomingTask = { id: string; title: string; dueAt: string | null; late: boolean; collective: boolean };
type GradeView = { label: string; provenance: 'personal' | 'institutional'; period: string; average: string | null; counted: number; total: number; missingCoefficients: number };
type OpportunityCard = { id: string; title: string; organizer: string; categoryLabel: string; deadline: Deadline; matchLabel: string };
type Step = { key: string; label: string; done: boolean; href: string; action: string };
type Dashboard = {
    timezone: string; today: Course[]; pendingCheckIns: PendingCheckIn[]; upcomingTasks: UpcomingTask[]; grades: GradeView[];
    opportunities: { hasProfile: boolean; items: OpportunityCard[]; disclaimer: string }; individualSteps: Step[]; institutionSteps: Step[] | null;
};
type Props = { locale: string; currentTime: string; registrationEnabled: boolean; dashboard: Dashboard | null };

/** Parcours de démarrage : chaque étape est cochée quand l'objet réel existe ; la première étape restante porte l'action à faire. */
function Checklist({ id, title, intro, steps }: { id: string; title: string; intro: string; steps: Step[] }) {
    const next = steps.find((step) => !step.done);
    const done = steps.filter((step) => step.done).length;

    return <section className="surface" aria-labelledby={id}>
        <h2 id={id}>{title}</h2>
        <p className="section-description">{intro} {done} étape(s) sur {steps.length} faite(s).</p>
        <ol className="checklist">{steps.map((step) => <li key={step.key} className={step.done ? 'is-done' : step === next ? 'is-next' : ''} aria-current={step === next ? 'step' : undefined}>
            <span className="checklist-mark" aria-hidden="true">{step.done ? '✓' : step === next ? '→' : '○'}</span>
            <span>{step.label}<span className="sr-only">{step.done ? ' — fait' : step === next ? ' — prochaine étape' : ' — à faire'}</span></span>
            {step === next && <Link className="button button-primary" href={step.href}>{step.action}</Link>}
            {step.done && step.key !== 'account' && <Link className="text-link" href={step.href}>Ouvrir</Link>}
        </li>)}</ol>
    </section>;
}

export default function Home({ locale, currentTime, registrationEnabled, dashboard }: Props) {
    const { campus, auth } = usePage<CampusProps>().props;
    const timezone = dashboard?.timezone;
    const formatted = new Intl.DateTimeFormat(locale, { dateStyle: 'full', timeZone: timezone }).format(new Date(currentTime));
    const time = new Intl.DateTimeFormat(locale, { timeStyle: 'short', timeZone: timezone });
    const dateTime = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone });
    const now = new Date(currentTime).getTime();

    if (!dashboard) {
        return (
            <CampusLayout active="home" title="Accueil">
                <Head title="Accueil" />
                <div className="page-heading"><p className="eyebrow">HAKO-RA CAMPUS</p><h1>Organisez vos études, un pas après l’autre.</h1><p className="page-intro">Planning, tâches, notes personnelles et opportunités vérifiées, dans un espace qui vous appartient.</p></div>
                {auth.user
                    ? <StateMessage title="Vérifiez votre adresse électronique" action={<Link href="/verification-email" className="button button-primary">Continuer la vérification</Link>}>Votre espace s’ouvre dès que votre adresse est vérifiée.</StateMessage>
                    : <section className="surface" aria-labelledby="start-title">
                        <h2 id="start-title">Commencer</h2>
                        <p className="section-description">La création d’un compte demande un nom, une adresse électronique, un mot de passe et la déclaration que vous êtes majeur. Rien d’autre n’est exigé pour démarrer.</p>
                        <div className="form-actions"><Link href="/connexion" className="button button-primary">Se connecter</Link>{registrationEnabled && <Link href="/inscription" className="button button-secondary">Créer un compte</Link>}</div>
                    </section>}
                <section className="surface" aria-labelledby="public-title">
                    <h2 id="public-title">Sans compte</h2>
                    <p className="section-description">Le catalogue d’opportunités (bourses, stages, concours, formations) est consultable librement. Chaque fiche renvoie à sa source officielle ; Campus ne promet aucune éligibilité.</p>
                    <div className="inline-links"><Link className="text-link" href="/opportunites">Découvrir les opportunités</Link><Link className="text-link" href="/aide">Aide et support</Link></div>
                </section>
            </CampusLayout>
        );
    }

    const { today, pendingCheckIns, upcomingTasks, grades, opportunities, individualSteps, institutionSteps } = dashboard;
    const individualDone = individualSteps.every((step) => step.done);
    const nothingYet = today.length === 0 && pendingCheckIns.length === 0 && upcomingTasks.length === 0;

    return (
        <CampusLayout active="home" title="Accueil">
            <Head title="Accueil" />
            <div className="page-heading"><p className="eyebrow">{campus.context.kind === 'individual' ? 'ESPACE INDIVIDUEL' : campus.context.label.toUpperCase()}</p><h1>Votre prochaine action</h1>
                <p className="page-intro">Ce qui vous attend aujourd’hui, toutes sources confondues. Chaque ligne indique si elle vient de vous ou de votre établissement.</p>
                <time dateTime={currentTime} className="page-date">{formatted} · fuseau {timezone}</time></div>

            {institutionSteps && <Checklist id="institution-steps-title" title={`Mise en route de ${campus.context.label}`} steps={institutionSteps}
                intro="Parcours de l’administration, calculé sur les données réelles de votre établissement pour l’année en cours." />}
            {!individualDone && <Checklist id="individual-steps-title" title="Bien démarrer dans votre espace individuel" steps={individualSteps}
                intro="Sept étapes courtes, dans l’ordre que vous voulez. Aucune n’est obligatoire." />}

            <section className="surface" aria-labelledby="today-title">
                <h2 id="today-title">Cours du jour</h2>
                {today.length === 0 ? <StateMessage kind="empty" title="Aucun cours aujourd’hui" action={<Link className="text-link" href="/planning">Ouvrir le planning</Link>} />
                    : <ul className="plain-list">{today.map((course) => {
                        const started = new Date(course.startsAt).getTime() <= now;
                        const canCheckIn = course.checkIn !== null && !course.cancelled && started && (course.checkIn.status === null || course.checkIn.status === 'deferred');

                        return <li key={course.id}>
                            <strong>{course.title}</strong> <ProvenanceBadge source={course.provenance} />
                            <p className="item-meta">{time.format(new Date(course.startsAt))} – {time.format(new Date(course.endsAt))}{course.cancelled && ' · annulé'}{course.checkIn?.status === 'completed' && ' · retour renseigné'}</p>
                            {canCheckIn && <Link className="text-link" href={`/planning/occurrences/${course.id}/retour`}>Renseigner mon retour</Link>}
                        </li>;
                    })}</ul>}
                {today.length > 0 && <div className="inline-links"><Link className="text-link" href="/planning">Tout le planning</Link></div>}
            </section>

            {pendingCheckIns.length > 0 && <section className="surface" aria-labelledby="checkins-title">
                <h2 id="checkins-title">Retours à renseigner</h2>
                <p className="section-description">Ces cours sont terminés : notez en une minute ce qu’il vous reste à faire. C’est privé et facultatif.</p>
                <ul className="plain-list">{pendingCheckIns.map((course) => <li key={course.occurrenceId}>
                    <strong>{course.title}</strong> <ProvenanceBadge source={course.provenance} />
                    <p className="item-meta">Terminé le {dateTime.format(new Date(course.endsAt))}{course.deferred && ' · reporté à plus tard'}</p>
                    <Link className="button button-secondary" href={`/planning/occurrences/${course.occurrenceId}/retour`}>Renseigner mon retour</Link>
                </li>)}</ul>
                <div className="inline-links"><Link className="text-link" href="/retours">Tous mes retours</Link></div>
            </section>}

            <section className="surface" aria-labelledby="tasks-title">
                <h2 id="tasks-title">Tâches proches</h2>
                {upcomingTasks.length === 0 ? <StateMessage kind="empty" title="Aucune tâche ouverte" action={<Link className="text-link" href="/taches">Ajouter une tâche</Link>} />
                    : <ul className="plain-list">{upcomingTasks.map((task) => <li key={task.id}>
                        <strong>{task.title}</strong> <ProvenanceBadge source={task.collective ? 'institutional' : 'personal'} />
                        <p className="item-meta">{task.dueAt ? `Échéance ${dateTime.format(new Date(task.dueAt))}` : 'Sans échéance'}{task.late && ' · en retard'}</p>
                    </li>)}</ul>}
                {upcomingTasks.length > 0 && <div className="inline-links"><Link className="text-link" href="/taches">Toutes mes tâches</Link></div>}
            </section>

            <section className="surface" aria-labelledby="grades-title">
                <h2 id="grades-title">Notes et moyennes</h2>
                {grades.length === 0 ? <StateMessage kind="empty" title="Aucune note pour la période en cours" action={<Link className="text-link" href="/notes">Ouvrir mes notes</Link>}>Aucune moyenne n’est affichée tant qu’aucune note n’existe.</StateMessage>
                    : <ul className="plain-list">{grades.map((view) => <li key={view.label}>
                        <strong>{view.label}</strong> <ProvenanceBadge source={view.provenance} />
                        <p>{view.average !== null ? <>Moyenne indicative : <strong>{view.average} / 20</strong></> : 'Pas encore de moyenne calculable'} <span className="item-meta">· {view.period}</span></p>
                        <p className="item-meta">Couverture : {view.counted} matière(s) comptée(s) sur {view.total}.{view.missingCoefficients > 0 && ` ${view.missingCoefficients} matière(s) sans coefficient : la moyenne générale ne les compte pas.`} Une note absente ne vaut jamais zéro.</p>
                    </li>)}</ul>}
                {grades.length > 0 && <div className="inline-links"><Link className="text-link" href="/notes">Détail et provenance des notes</Link></div>}
            </section>

            <section className="surface" aria-labelledby="opportunities-title">
                <h2 id="opportunities-title">Opportunités</h2>
                {!opportunities.hasProfile && <StateMessage title="Aucun critère déclaré" action={<span className="form-actions"><Link className="button button-secondary" href="/opportunites/profil">Déclarer mes critères</Link><Link className="text-link" href="/opportunites">Parcourir le catalogue</Link></span>}>Campus ne propose ici que des fiches compatibles avec ce que vous avez déclaré (niveau, domaines, langues, pays). Sans critères, rien n’est suggéré à votre place.</StateMessage>}
                {opportunities.hasProfile && opportunities.items.length === 0 && <StateMessage kind="empty" title="Aucune fiche ouverte n’est compatible avec vos critères pour l’instant" action={<Link className="text-link" href="/opportunites">Parcourir le catalogue</Link>} />}
                {opportunities.items.length > 0 && <>
                    <ul className="plain-list">{opportunities.items.map((item) => <li key={item.id}>
                        <strong>{item.title}</strong>
                        <p className="item-meta">{item.categoryLabel} · {item.organizer} · date limite : {item.deadline.text}</p>
                        <p className="item-meta">{item.matchLabel}</p>
                        <Link className="text-link" href={`/opportunites/${item.id}`}>Ouvrir la fiche</Link>
                    </li>)}</ul>
                    <p className="item-meta">{opportunities.disclaimer}</p>
                    <div className="inline-links"><Link className="text-link" href="/opportunites">Tout le catalogue</Link><Link className="text-link" href="/opportunites/suivi">Mes favoris et démarches</Link></div>
                </>}
            </section>

            {nothingYet && individualDone && <StateMessage title="Rien d’urgent aujourd’hui">Aucun cours, aucun retour en attente, aucune tâche ouverte.</StateMessage>}
            <div className="inline-links"><Link className="text-link" href="/profil">Profil et fuseau</Link><Link className="text-link" href="/notifications/preferences">Préférences de notification</Link><Link className="text-link" href="/hors-ligne">Hors connexion</Link><Link className="text-link" href="/confidentialite/parametres">Confidentialité et données</Link><Link className="text-link" href="/aide">Aide et support</Link></div>
        </CampusLayout>
    );
}
