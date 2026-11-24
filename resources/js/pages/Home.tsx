import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import CampusLayout, { ContextChips } from '../layouts/CampusLayout';
import { Icon } from '../components/Icon';
import { openQuickAdd } from '../components/quickAddEvents';
import { Landing } from '../components/Landing';
import { ProvenanceBadge, StateMessage } from '../components/ui/Feedback';
import type { Deadline } from '../components/OpportunityDeadline';
import type { CampusProps } from '../types';

type Course = { id: string; title: string; room: string | null; startsAt: string; endsAt: string; cancelled: boolean; provenance: 'personal' | 'institutional'; checkIn: { version: number; status: string | null } | null };
type PendingCheckIn = { occurrenceId: string; title: string; endsAt: string; provenance: 'personal' | 'institutional'; deferred: boolean };
type UpcomingTask = {
    id: string; title: string; dueAt: string | null; late: boolean; collective: boolean; subject: string | null; priority: string; version: number;
    description: string | null; dueLocal: string | null; personalSubjectId: number | null; personalTargetLocal: string | null;
};
type GradeView = { label: string; provenance: 'personal' | 'institutional'; period: string; average: string | null; counted: number; total: number; missingCoefficients: number; subjects: { name: string; average: string }[] };
type OpportunityCard = { id: string; title: string; organizer: string; categoryLabel: string; deadline: Deadline; matchLabel: string };
type Step = { key: string; label: string; done: boolean; href: string; action: string };
type Dashboard = {
    timezone: string; today: Course[]; pendingCheckIns: PendingCheckIn[]; upcomingTasks: UpcomingTask[]; grades: GradeView[];
    opportunities: { hasProfile: boolean; items: OpportunityCard[]; disclaimer: string }; individualSteps: Step[]; institutionSteps: Step[] | null;
};
type Props = { locale: string; currentTime: string; registrationEnabled: boolean; dashboard: Dashboard | null };

/** Parcours de démarrage : la prochaine étape d'abord, la liste complète repliée. Une étape est cochée quand l'objet réel existe. */
function Checklist({ id, title, steps }: { id: string; title: string; steps: Step[] }) {
    const next = steps.find((step) => !step.done);
    const done = steps.filter((step) => step.done).length;

    return <section className="surface start-card" aria-labelledby={id}>
        <div className="card-heading"><h2 id={id}>{title}</h2><span className="item-meta">{done} étape(s) sur {steps.length} faite(s)</span></div>
        {next && <div className="start-next"><span>{next.label}</span><Link className="button button-primary" href={next.href}>{next.action}</Link></div>}
        <details><summary>Voir toutes les étapes</summary>
            <ol className="checklist">{steps.map((step) => <li key={step.key} className={step.done ? 'is-done' : step === next ? 'is-next' : ''} aria-current={step === next ? 'step' : undefined}>
                <span className="checklist-mark" aria-hidden="true">{step.done ? '✓' : step === next ? '→' : '○'}</span>
                <span>{step.label}<span className="sr-only">{step.done ? ' — fait' : step === next ? ' — prochaine étape' : ' — à faire'}</span></span>
                {step.done && step.key !== 'account' && <Link className="text-link" href={step.href}>Ouvrir</Link>}
            </li>)}</ol>
        </details>
    </section>;
}

/** Pastille ronde de « Ta journée » : une heure, une moyenne ou une icône, qui mène à l'écran correspondant. */
function DayRing({ href, tone, label, hint, children }: { href: string; tone: 'next' | 'soon' | 'accent' | 'quiet'; label: string; hint: string; children: ReactNode }) {
    return <li><Link className="day-ring" href={href}>
        <span className={`ring ${tone === 'next' ? 'ring-next' : tone === 'accent' ? 'ring-accent' : tone === 'quiet' ? 'ring-quiet' : ''}`}><span>{children}</span></span>
        <span className="day-ring-text"><strong>{label}</strong><small>{hint}</small></span>
    </Link></li>;
}

export default function Home({ locale, currentTime, registrationEnabled, dashboard }: Props) {
    const { auth } = usePage<CampusProps>().props;

    // Visiteur : la page de présentation, dans le style « nuit ».
    if (!dashboard && !auth.user) {
        return <Landing registrationEnabled={registrationEnabled} />;
    }
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

    return <Dashboard locale={locale} currentTime={currentTime} dashboard={dashboard} />;
}

function Dashboard({ locale, currentTime, dashboard }: { locale: string; currentTime: string; dashboard: Dashboard }) {
    const { campus, auth } = usePage<CampusProps>().props;
    const { timezone, today, pendingCheckIns, upcomingTasks, grades, opportunities, individualSteps, institutionSteps } = dashboard;
    const [closing, setClosing] = useState<string[]>([]);
    const now = new Date(currentTime).getTime();
    const time = new Intl.DateTimeFormat(locale, { timeStyle: 'short', timeZone: timezone });
    const day = new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: timezone });
    const longDate = new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: timezone }).format(new Date(currentTime));
    const shortDate = new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: timezone });
    const dateTime = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone });
    const individualDone = individualSteps.every((step) => step.done);
    const name = auth.user?.name ?? '';
    const firstName = name.trim().split(/\s+/)[0] ?? '';
    // Cours à venir aujourd'hui : non annulés et pas encore terminés.
    const coming = today.filter((course) => !course.cancelled && new Date(course.endsAt).getTime() > now);
    const nextCourse = coming[0] ?? null;
    const minutesToNext = nextCourse ? Math.round((new Date(nextCourse.startsAt).getTime() - now) / 60000) : 0;
    const nextLabel = minutesToNext <= 0 ? 'En cours' : minutesToNext < 60 ? `Dans ${minutesToNext} min` : `Dans ${Math.floor(minutesToNext / 60)} h ${String(minutesToNext % 60).padStart(2, '0')}`;
    const dueToday = (task: UpcomingTask) => task.dueAt !== null && day.format(new Date(task.dueAt)) === day.format(new Date(currentTime));
    const taskToday = upcomingTasks.find((task) => dueToday(task) && !task.late) ?? null;
    const average = grades.find((view) => view.average !== null) ?? null;
    // Marquer une tâche comme faite : par la route de la tâche, avec sa version et son contenu inchangé. Rien d'autre n'est modifié.
    const finish = (task: UpcomingTask) => {
        setClosing((ids) => [...ids, task.id]);
        const options = { preserveScroll: true, onError: () => setClosing((ids) => ids.filter((id) => id !== task.id)) };
        if (task.collective) router.put(`/taches/${task.id}/progression`, { expected_version: task.version, status: 'done', personal_target_at: task.personalTargetLocal }, options);
        else router.patch(`/taches/${task.id}`, { expected_version: task.version, title: task.title, description: task.description, due_at: task.dueLocal, priority: task.priority, personal_subject_id: task.personalSubjectId, status: 'done' }, options);
    };

    return (
        <CampusLayout active="home" title="Accueil">
            <Head title="Accueil" />
            <div className="home-greeting">
                <div className="home-hello">
                    <Link href="/profil" className="avatar-link avatar-accent" aria-label={`Profil de ${name}`}>{firstName[0]?.toUpperCase() ?? ''}</Link>
                    <div><h1>{firstName ? `Salut ${firstName}` : 'Salut'}</h1><time dateTime={currentTime} className="page-date">{longDate}</time></div>
                    {campus.unreadNotifications !== null && <Link href="/notifications" className="icon-button" aria-label={`Notifications, ${campus.unreadNotifications} non lue(s)`}><Icon name="bell" size={22} />{campus.unreadNotifications > 0 && <span className="icon-dot" aria-hidden="true" />}</Link>}
                </div>
                <ContextChips />
            </div>

            {institutionSteps && <Checklist id="institution-steps-title" title={`Mise en route de ${campus.context.label}`} steps={institutionSteps} />}
            {!individualDone && <Checklist id="individual-steps-title" title="Pour bien démarrer" steps={individualSteps} />}

            <section className="home-section" aria-labelledby="day-title">
                <h2 id="day-title" className="section-kicker">Ta journée</h2>
                <ul className="day-rings">
                    {coming.slice(0, 2).map((course, index) => <DayRing key={course.id} href="/planning" tone={index === 0 ? 'next' : 'soon'} label={course.title} hint={index === 0 ? nextLabel.toLowerCase() : (course.room ?? 'cours suivant')}>{time.format(new Date(course.startsAt))}</DayRing>)}
                    {taskToday && <DayRing href="/taches" tone="soon" label={taskToday.subject ?? 'À rendre'} hint={taskToday.title}>{time.format(new Date(taskToday.dueAt as string))}</DayRing>}
                    {average && <DayRing href="/notes" tone="accent" label="Moyenne" hint={average.period}>{average.average}</DayRing>}
                    <DayRing href="/opportunites" tone="quiet" label="Bourses" hint={opportunities.hasProfile ? `${opportunities.items.length} pour toi` : 'critères à choisir'}><Icon name="opportunities" size={26} /></DayRing>
                </ul>
            </section>

            <div className="home-grid">
                <div className="home-main">
                    <section className="next-course" aria-labelledby="next-course-title">
                        <div>
                            <div className="next-course-top"><span className="section-kicker">{nextCourse ? 'Prochain cours' : 'Aujourd’hui'}</span>{nextCourse && <span className="pill pill-accent">{nextLabel}</span>}</div>
                            <h2 id="next-course-title">{nextCourse ? nextCourse.title : today.length > 0 ? 'Plus de cours aujourd’hui' : 'Pas de cours aujourd’hui'}</h2>
                            {nextCourse && <p className="next-course-meta">{time.format(new Date(nextCourse.startsAt))} – {time.format(new Date(nextCourse.endsAt))}{nextCourse.room && ` · ${nextCourse.room}`}{nextCourse.provenance === 'institutional' && ' · Établissement'}</p>}
                        </div>
                        <div className="next-course-actions"><Link className="button" href="/planning">Voir le planning</Link><button type="button" className="button button-outline" onClick={() => openQuickAdd('task')}>+ Tâche</button></div>
                    </section>

                    <section className="home-section" aria-labelledby="tasks-title">
                        <div className="card-heading"><h2 id="tasks-title">À faire</h2>{upcomingTasks.length > 0 && <Link className="text-link" href="/taches">Tout voir</Link>}</div>
                        {upcomingTasks.length > 0 && <ul className="todo-list">{upcomingTasks.map((task) => {
                            const due = task.dueAt ? new Date(task.dueAt) : null;
                            const done = closing.includes(task.id);

                            return <li key={task.id} className={done ? 'is-done' : ''}>
                                <button type="button" className="todo-check" aria-pressed={done} disabled={done} aria-label={`Marquer « ${task.title} » comme fait`} onClick={() => finish(task)}><Icon name="check" strokeWidth={3} /></button>
                                <div className="todo-main">
                                    <span className="todo-title">{task.title}</span>
                                    <div className="todo-meta">
                                        {task.late ? <span className="pill pill-danger">En retard</span> : due === null ? <span className="pill pill-quiet">Sans date</span>
                                            : dueToday(task) ? <span className="pill pill-warm">Aujourd’hui {time.format(due)}</span> : <span className="pill pill-info">{shortDate.format(due)}</span>}
                                        {task.collective && <ProvenanceBadge source="institutional" />}
                                        <span>{[task.subject, task.priority === 'high' ? 'priorité haute' : null].filter(Boolean).join(' · ')}</span>
                                    </div>
                                </div>
                            </li>;
                        })}</ul>}
                        <button type="button" className="add-row" onClick={() => openQuickAdd('task')}>+ Ajouter une tâche</button>
                    </section>

                    {pendingCheckIns.length > 0 && <section className="home-section" aria-labelledby="checkins-title">
                        <div className="card-heading"><h2 id="checkins-title">Après tes cours</h2><Link className="text-link" href="/retours">Tout voir</Link></div>
                        <ul className="todo-list">{pendingCheckIns.map((course) => <li key={course.occurrenceId}>
                            <div className="todo-main"><span className="todo-title">{course.title}</span><div className="todo-meta"><span>Terminé le {dateTime.format(new Date(course.endsAt))}{course.deferred && ' · reporté'}</span></div></div>
                            <Link className="text-link" href={`/planning/occurrences/${course.occurrenceId}/retour`}>Noter ce qu’il reste à faire</Link>
                        </li>)}</ul>
                    </section>}

                    {today.length > 1 && <section className="surface" aria-labelledby="today-title">
                        <div className="card-heading"><h2 id="today-title">Tes cours aujourd’hui</h2></div>
                        <ul className="row-list">{today.map((course) => {
                            const ended = new Date(course.endsAt).getTime() <= now;

                            return <li key={course.id} className={course.cancelled ? 'is-cancelled' : ended ? 'is-past' : ''}>
                                <span className="row-time">{time.format(new Date(course.startsAt))}</span>
                                <span className="row-main"><span className="row-title">{course.title}</span><span className="item-meta">jusqu’à {time.format(new Date(course.endsAt))}{course.room && ` · ${course.room}`}</span></span>
                                {course.provenance === 'institutional' && <ProvenanceBadge source="institutional" />}
                                {course.cancelled ? <span className="pill pill-danger">Annulé</span> : ended && <span className="row-side">Terminé</span>}
                            </li>;
                        })}</ul>
                    </section>}
                </div>

                <aside className="home-aside" aria-label="Notes et opportunités">
                    {grades.length === 0 && <section className="average-card" aria-labelledby="grades-title">
                        <div className="average-top"><div><h2 id="grades-title" className="section-kicker">Ta moyenne</h2><p className="item-meta">Ajoute ta première note : ta moyenne se calcule toute seule.</p></div></div>
                        <button type="button" className="add-row" onClick={() => openQuickAdd('grade')}>+ Ajouter une note</button>
                    </section>}
                    {grades.map((view, index) => <section className="average-card" key={view.label} aria-labelledby={`grades-title-${index}`}>
                        <div className="average-top">
                            <div><h2 id={`grades-title-${index}`} className="section-kicker">{view.provenance === 'personal' ? 'Ta moyenne' : view.label} · {view.period}</h2>
                                {view.average !== null ? <span className="average-value">{view.average}<small> / 20</small></span> : <span className="item-meta">Pas encore de moyenne calculable</span>}</div>
                            <Link className="text-link" href="/notes">Détail</Link>
                        </div>
                        {view.subjects.length > 0 && <ul className="average-bars">{view.subjects.slice(0, 4).map((subject) => <li key={subject.name}>
                            <div><span>{subject.name}</span><strong>{subject.average}</strong></div>
                            <div className="progress-track" aria-hidden="true"><div className="progress-bar" style={{ width: `${Math.max(0, Math.min(100, Number(subject.average.replace(',', '.')) * 5))}%` }} /></div>
                        </li>)}</ul>}
                        <p className="item-meta">Moyenne indicative : {view.counted} matière(s) comptée(s) sur {view.total}.{view.missingCoefficients > 0 && ` ${view.missingCoefficients} sans coefficient.`}</p>
                    </section>)}

                    <section className="tinted-card" aria-labelledby="opportunities-title">
                        <h2 id="opportunities-title">Bourses, concours, stages</h2>
                        {!opportunities.hasProfile && <><p>Dis-nous ton niveau et tes domaines : on te montre seulement ce qui te correspond.</p>
                            <Link className="button button-primary" href="/opportunites/profil">Choisir mes critères</Link><Link className="text-link" href="/opportunites">Voir tout le catalogue</Link></>}
                        {opportunities.hasProfile && opportunities.items.length === 0 && <><p>Rien ne correspond à tes critères pour l’instant.</p><Link className="text-link" href="/opportunites">Voir tout le catalogue</Link></>}
                        {opportunities.items.length > 0 && <>
                            <ul>{opportunities.items.map((item) => <li key={item.id}>
                                <Link className="text-link" href={`/opportunites/${item.id}`}>{item.title}</Link>
                                <span className="item-meta">{item.categoryLabel} · {item.organizer} · date limite : {item.deadline.text}</span><span className="item-meta">{item.matchLabel}</span>
                            </li>)}</ul>
                            <p className="item-meta">{opportunities.disclaimer}</p>
                            <Link className="text-link" href="/opportunites">Voir tout le catalogue</Link>
                        </>}
                    </section>
                </aside>
            </div>
        </CampusLayout>
    );
}
