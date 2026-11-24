import { Head, Link, usePage } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { Icon } from '../components/Icon';
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
    const firstName = (auth.user?.name ?? '').trim().split(/\s+/)[0] ?? '';
    const shortDate = new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', month: 'short', timeZone: timezone });
    // Prochain cours : le premier cours du jour, non annulé, qui n'est pas terminé.
    const nextCourse = today.find((course) => !course.cancelled && new Date(course.endsAt).getTime() > now) ?? null;
    const minutesToNext = nextCourse ? Math.round((new Date(nextCourse.startsAt).getTime() - now) / 60000) : 0;
    const nextLabel = minutesToNext <= 0 ? 'En cours' : minutesToNext < 60 ? `Dans ${minutesToNext} min` : `Dans ${Math.floor(minutesToNext / 60)} h ${String(minutesToNext % 60).padStart(2, '0')}`;
    // Prochaine action proposée : uniquement tirée de ce qui existe (tâche en retard, puis retour en attente). Aucune recommandation calculée.
    const lateTask = upcomingTasks.find((task) => task.late) ?? null;
    const nextAction = lateTask
        ? { text: `Une tâche est en retard : ${lateTask.title}.`, href: '/taches', action: 'Ouvrir mes tâches' }
        : pendingCheckIns[0]
            ? { text: `Notez en une minute votre retour sur ${pendingCheckIns[0].title}.`, href: `/planning/occurrences/${pendingCheckIns[0].occurrenceId}/retour`, action: 'Renseigner mon retour' }
            : null;
    const soon = now + 48 * 3600 * 1000;

    return (
        <CampusLayout active="home" title="Accueil">
            <Head title="Accueil" />
            <div className="dashboard-header">
                <div>
                    <time dateTime={currentTime} className="page-date">{formatted} · fuseau {timezone}</time>
                    <h1>{firstName ? `Bonjour ${firstName}` : 'Bonjour'}</h1>
                    <p className="eyebrow">{campus.context.kind === 'individual' ? 'ESPACE INDIVIDUEL' : campus.context.label.toUpperCase()}</p>
                </div>
                <Link className="button button-primary" href="/taches"><Icon name="plus" size={18} />Ajouter une tâche</Link>
            </div>

            {institutionSteps && <Checklist id="institution-steps-title" title={`Mise en route de ${campus.context.label}`} steps={institutionSteps}
                intro="Parcours de l’administration, calculé sur les données réelles de votre établissement pour l’année en cours." />}
            {!individualDone && <Checklist id="individual-steps-title" title="Bien démarrer dans votre espace individuel" steps={individualSteps}
                intro="Sept étapes courtes, dans l’ordre que vous voulez. Aucune n’est obligatoire." />}
            {nothingYet && individualDone && <StateMessage title="Rien d’urgent aujourd’hui">Aucun cours, aucun retour en attente, aucune tâche ouverte.</StateMessage>}

            <div className="dashboard-grid">
                <div className="dashboard-column">
                    {nextCourse && <div className="next-course">
                        <div className="next-course-top"><span className="section-kicker">Prochain cours</span><span className="pill pill-accent">{nextLabel}</span></div>
                        <span className="next-course-title">{nextCourse.title}</span>
                        <span className="next-course-meta">{time.format(new Date(nextCourse.startsAt))} – {time.format(new Date(nextCourse.endsAt))} · {nextCourse.provenance === 'personal' ? 'Planning personnel' : 'Planning de l’établissement'}</span>
                        <Link className="button" href="/planning">Voir le planning</Link>
                    </div>}
                    <section className="surface" aria-labelledby="today-title">
                        <div className="card-heading"><h2 id="today-title">Cours du jour</h2>{today.length > 0 && <Link className="text-link" href="/planning">Tout le planning</Link>}</div>
                        {today.length === 0 ? <StateMessage kind="empty" title="Aucun cours aujourd’hui" action={<Link className="text-link" href="/planning">Ouvrir le planning</Link>} />
                            : <ul className="row-list">{today.map((course) => {
                                const started = new Date(course.startsAt).getTime() <= now;
                                const ended = new Date(course.endsAt).getTime() <= now;
                                const canCheckIn = course.checkIn !== null && !course.cancelled && started && (course.checkIn.status === null || course.checkIn.status === 'deferred');

                                return <li key={course.id} className={course.cancelled ? 'is-cancelled' : ended ? 'is-past' : course === nextCourse ? 'is-current' : ''}>
                                    <span className="row-time">{time.format(new Date(course.startsAt))}</span>
                                    <span className="row-main"><span className="row-title">{course.title}</span><span className="item-meta">jusqu’à {time.format(new Date(course.endsAt))}{course.checkIn?.status === 'completed' && ' · retour renseigné'}</span></span>
                                    <ProvenanceBadge source={course.provenance} />
                                    {course.cancelled ? <span className="pill pill-danger">Annulé</span> : ended && !canCheckIn && <span className="row-side">Terminé</span>}
                                    {canCheckIn && <Link className="text-link" href={`/planning/occurrences/${course.id}/retour`}>Renseigner mon retour</Link>}
                                </li>;
                            })}</ul>}
                    </section>
                </div>

                <div className="dashboard-column">
                    {nextAction && <div className="highlight-card">
                        <span className="section-kicker">Votre prochaine action</span>
                        <p>{nextAction.text}</p>
                        <div className="form-actions" style={{ marginTop: 0 }}><Link className="button button-primary" href={nextAction.href}>{nextAction.action}</Link></div>
                    </div>}
                    <section className="surface" aria-labelledby="tasks-title">
                        <div className="card-heading"><h2 id="tasks-title">Tâches proches</h2>{upcomingTasks.length > 0 && <Link className="text-link" href="/taches">Toutes mes tâches</Link>}</div>
                        {upcomingTasks.length === 0 ? <StateMessage kind="empty" title="Aucune tâche ouverte" action={<Link className="text-link" href="/taches">Ajouter une tâche</Link>} />
                            : <ul className="tile-list">{upcomingTasks.map((task) => {
                                const due = task.dueAt ? new Date(task.dueAt) : null;
                                const near = due !== null && due.getTime() <= soon;

                                return <li key={task.id}>
                                    <span className={`dot ${task.late ? 'dot-danger' : near ? 'dot-warning' : ''}`} aria-hidden="true" />
                                    <span className="row-main"><span className="row-title">{task.title}</span><span className="item-meta">{due ? `Échéance ${dateTime.format(due)}` : 'Sans échéance'}</span></span>
                                    <ProvenanceBadge source={task.collective ? 'institutional' : 'personal'} />
                                    {task.late ? <span className="pill pill-danger">En retard</span> : due && <span className={near ? 'pill pill-warning' : 'row-side'}>{shortDate.format(due)}</span>}
                                </li>;
                            })}</ul>}
                    </section>
                    {pendingCheckIns.length > 0 && <section className="surface" aria-labelledby="checkins-title">
                        <div className="card-heading"><h2 id="checkins-title">Retours à renseigner</h2><Link className="text-link" href="/retours">Tous mes retours</Link></div>
                        <p className="section-description">Ces cours sont terminés : notez en une minute ce qu’il vous reste à faire. C’est privé et facultatif.</p>
                        <ul className="tile-list">{pendingCheckIns.map((course) => <li key={course.occurrenceId}>
                            <span className="row-main"><span className="row-title">{course.title}</span><span className="item-meta">Terminé le {dateTime.format(new Date(course.endsAt))}{course.deferred && ' · reporté à plus tard'}</span></span>
                            <ProvenanceBadge source={course.provenance} />
                            <Link className="button button-secondary" href={`/planning/occurrences/${course.occurrenceId}/retour`}>Renseigner mon retour</Link>
                        </li>)}</ul>
                    </section>}
                </div>

                <div className="dashboard-column">
                    <section className="surface" aria-labelledby="grades-title">
                        <div className="card-heading"><h2 id="grades-title">Notes et moyennes</h2>{grades.length > 0 && <Link className="text-link" href="/notes">Détail et provenance des notes</Link>}</div>
                        {grades.length === 0 ? <StateMessage kind="empty" title="Aucune note pour la période en cours" action={<Link className="text-link" href="/notes">Ouvrir mes notes</Link>}>Aucune moyenne n’est affichée tant qu’aucune note n’existe.</StateMessage>
                            : <ul className="tile-list">{grades.map((view) => <li key={view.label}>
                                <span className="row-main">
                                    <span className="row-title">{view.label} <span className="item-meta">· {view.period}</span></span>
                                    {view.average !== null
                                        ? <span className="stat-line"><span className="item-meta">Moyenne indicative :</span><strong className="stat-value">{view.average}</strong><span className="item-meta">/ 20</span></span>
                                        : <span className="item-meta">Pas encore de moyenne calculable</span>}
                                    <span className="item-meta">Couverture : {view.counted} matière(s) comptée(s) sur {view.total}.{view.missingCoefficients > 0 && ` ${view.missingCoefficients} matière(s) sans coefficient : la moyenne générale ne les compte pas.`} Une note absente ne vaut jamais zéro.</span>
                                </span>
                                <ProvenanceBadge source={view.provenance} />
                            </li>)}</ul>}
                    </section>
                    <section className="surface" aria-labelledby="opportunities-title">
                        <div className="card-heading"><h2 id="opportunities-title">Opportunités</h2>{opportunities.items.length > 0 && <Link className="text-link" href="/opportunites">Tout le catalogue</Link>}</div>
                        {!opportunities.hasProfile && <StateMessage title="Aucun critère déclaré" action={<span className="form-actions"><Link className="button button-secondary" href="/opportunites/profil">Déclarer mes critères</Link><Link className="text-link" href="/opportunites">Parcourir le catalogue</Link></span>}>Campus ne propose ici que des fiches compatibles avec ce que vous avez déclaré (niveau, domaines, langues, pays). Sans critères, rien n’est suggéré à votre place.</StateMessage>}
                        {opportunities.hasProfile && opportunities.items.length === 0 && <StateMessage kind="empty" title="Aucune fiche ouverte n’est compatible avec vos critères pour l’instant" action={<Link className="text-link" href="/opportunites">Parcourir le catalogue</Link>} />}
                        {opportunities.items.length > 0 && <>
                            <ul className="tile-list">{opportunities.items.map((item) => <li key={item.id}>
                                <span className="row-main"><span className="row-title">{item.title}</span><span className="item-meta">{item.categoryLabel} · {item.organizer} · date limite : {item.deadline.text}</span><span className="item-meta">{item.matchLabel}</span></span>
                                <Link className="text-link" href={`/opportunites/${item.id}`}>Ouvrir la fiche</Link>
                            </li>)}</ul>
                            <p className="item-meta" style={{ marginTop: '.75rem' }}>{opportunities.disclaimer}</p>
                            <div className="inline-links"><Link className="text-link" href="/opportunites/suivi">Mes favoris et démarches</Link></div>
                        </>}
                    </section>
                </div>
            </div>

            <div className="inline-links"><Link className="text-link" href="/profil">Profil et fuseau</Link><Link className="text-link" href="/notifications/preferences">Préférences de notification</Link><Link className="text-link" href="/hors-ligne">Hors connexion</Link><Link className="text-link" href="/confidentialite/parametres">Confidentialité et données</Link><Link className="text-link" href="/aide">Aide et support</Link></div>
        </CampusLayout>
    );
}
