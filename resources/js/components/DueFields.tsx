import { shiftDate } from './quickAddEvents';
import { TextField } from './ui/Fields';

const DEFAULT_TIME = '18:00';

/**
 * Échéance d'une tâche en deux gestes : le jour (raccourcis ou calendrier), puis l'heure. La valeur reste au format AAAA-MM-JJTHH:MM attendu par le serveur ;
 * une chaîne vide signifie « sans date ». Choisir une heure sans jour prend le jour même.
 */
export function DueFields({ value, today, error, optional = true, onChange }: { value: string; today: string; error?: string; optional?: boolean; onChange: (value: string) => void }) {
    const [date, time] = value === '' ? ['', ''] : value.split('T');
    const set = (nextDate: string, nextTime: string) => onChange(nextDate === '' ? '' : `${nextDate}T${nextTime || DEFAULT_TIME}`);
    const shortcuts: [string, string][] = [['Aujourd’hui', today], ['Demain', shiftDate(today, 1)], ['Dans une semaine', shiftDate(today, 7)]];

    return <div className="due-fields">
        <div className="due-chips" role="group" aria-label="Jour de l’échéance">{shortcuts.map(([label, day]) => <button type="button" key={label} className="chip" aria-pressed={date === day}
            onClick={() => set(date === day ? '' : day, time)}>{label}</button>)}</div>
        <div className="quick-add-pair">
            <TextField label={optional ? 'Quel jour ? (facultatif)' : 'Quel jour ?'} type="date" value={date} error={error} onChange={(event) => set(event.target.value, time)} />
            <TextField label="À quelle heure ?" type="time" value={time} placeholder={DEFAULT_TIME} onChange={(event) => set(date || today, event.target.value)} />
        </div>
    </div>;
}
