import { useEffect, useRef, useState, type CSSProperties, type PointerEvent } from 'react';

/** Cours d'exemple, inventés : ils ne décrivent aucun établissement réel. */
const COURSES = [
    { day: 0, from: 8, to: 10, title: 'Mathématiques', room: 'Salle 12', color: '#5b4bf5' },
    { day: 0, from: 10.5, to: 12, title: 'Anglais', room: 'Salle 4', color: '#0f7f74', tag: 'Nouvel horaire' },
    { day: 0, from: 14, to: 16, title: 'Physique-chimie', room: 'Labo 1', color: '#c2410c' },
    { day: 1, from: 8, to: 10.5, title: 'Français', room: 'Salle 8', color: '#1d6fd1' },
    { day: 1, from: 13, to: 15, title: 'Histoire-géo', room: 'Salle 5', color: '#be185d', now: true },
    { day: 2, from: 9, to: 12, title: 'Informatique', room: 'Labo 2', color: '#4334d9' },
    { day: 3, from: 8, to: 10, title: 'SVT', room: 'Salle 3', color: '#0f7f74', tag: 'Changement de salle' },
    { day: 3, from: 10.25, to: 12, title: 'Mathématiques', room: 'Salle 12', color: '#5b4bf5' },
    { day: 3, from: 15, to: 17, title: 'Économie', room: 'Salle 1', color: '#8a5a00', cancelled: true },
    { day: 4, from: 8, to: 10, title: 'Philosophie', room: 'Salle 7', color: '#4334d9' },
    { day: 4, from: 14, to: 16, title: 'Anglais', room: 'En ligne', color: '#1d6fd1' },
] as { day: number; from: number; to: number; title: string; room: string; color: string; tag?: string; now?: boolean; cancelled?: boolean }[];
const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
const SHORT = ['LUN', 'MAR', 'MER', 'JEU', 'VEN'];
const FIRST = 8;
const LAST = 18;
const SPAN = LAST - FIRST;
const hhmm = (hour: number) => `${String(Math.floor(hour)).padStart(2, '0')}:${String(Math.round((hour % 1) * 60)).padStart(2, '0')}`;
const percent = (hour: number) => `${(hour - FIRST) / SPAN * 100}%`;

/**
 * Semaine d'exemple « en relief », dessinée en CSS 3D, sans bibliothèque : chaque cours est un bloc posé sur la grille de la semaine.
 * Le plateau suit légèrement le pointeur ; toucher un bloc l'affiche dans la carte. Tout est figé si la personne a réduit les animations.
 */
export function WeekRelief({ compact = false }: { compact?: boolean }) {
    const [picked, setPicked] = useState(4);
    const [tilt, setTilt] = useState({ x: 0, y: 0 });
    const stage = useRef<HTMLDivElement>(null);
    const touched = useRef(false);
    useEffect(() => {
        if (compact || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        // Tant que personne n'a touché la semaine, la carte passe d'un cours à l'autre.
        const timer = window.setInterval(() => { if (!touched.current) setPicked((current) => (current + 1) % COURSES.length); }, 3200);

        return () => window.clearInterval(timer);
    }, [compact]);
    const move = (event: PointerEvent) => {
        const box = stage.current?.getBoundingClientRect();
        if (!box || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        setTilt({ x: ((event.clientX - box.left) / box.width - 0.5) * 2, y: ((event.clientY - box.top) / box.height - 0.5) * 2 });
    };
    const course = COURSES[picked];

    return <div ref={stage} className={`relief ${compact ? 'relief-compact' : ''}`} onPointerMove={move} onPointerLeave={() => setTilt({ x: 0, y: 0 })}>
        <div className="relief-scene" aria-hidden="true">
            <div className="relief-board" style={{ '--tilt-x': tilt.x, '--tilt-y': tilt.y } as CSSProperties}>
                <span />
                {SHORT.map((day, index) => <span key={day} className={`relief-day ${index === 1 ? 'is-today' : ''}`}>{day}</span>)}
                <div className="relief-hours">{[8, 10, 12, 14, 16, 18].map((hour) => <span key={hour} style={{ top: percent(hour) }}>{hhmm(hour)}</span>)}</div>
                {SHORT.map((day, index) => <div key={day} className="relief-column">
                    {index === 1 && <span className="relief-now" style={{ top: percent(14.2) }} />}
                    {COURSES.map((item, position) => item.day !== index ? null : <div key={position} className={`relief-block ${position === picked ? 'is-picked' : ''} ${item.cancelled ? 'is-cancelled' : ''}`}
                        onClick={() => { touched.current = true; setPicked(position); }}
                        style={{ '--c': item.color, '--i': position, top: percent(item.from), height: `calc(${(item.to - item.from) / SPAN * 100}% - 4px)` } as CSSProperties}>
                        <strong>{item.title}</strong>{!compact && item.to - item.from >= 1.5 && <span>{hhmm(item.from)}–{hhmm(item.to)}</span>}
                    </div>)}
                </div>)}
            </div>
        </div>
        {!compact && <div className="relief-card">
            <span className="relief-when">{DAYS[course.day]} · {hhmm(course.from)} – {hhmm(course.to)}</span>
            <span className="relief-name"><span className="relief-swatch" style={{ background: course.color }} aria-hidden="true" />{course.title}</span>
            <span className="relief-meta">{course.room}</span>
            <span className={`relief-pill ${course.now ? 'is-now' : course.cancelled ? 'is-cancelled' : ''}`}>{course.now ? 'En cours' : course.cancelled ? 'Annulé' : course.tag ?? 'Cours'}</span>
        </div>}
    </div>;
}
