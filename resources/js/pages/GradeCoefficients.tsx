import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { StateMessage } from '../components/ui/Feedback';
import { TextField } from '../components/ui/Fields';

type Subject = { id: number; name: string; coefficient: string; version: number };

function Row({ subject }: { subject: Subject }) {
    const [value, setValue] = useState(subject.coefficient);
    const errors = usePage().props.errors as Record<string, string>;
    return <li>
        <form className="form-actions" onSubmit={(event) => { event.preventDefault(); router.put(`/notes/coefficients/${subject.id}`, { expected_version: subject.version, coefficient: value }, { preserveScroll: true }); }}>
            <TextField label={`Coefficient — ${subject.name}`} inputMode="decimal" hint={subject.version === 0 ? 'Non défini : aucune moyenne générale n’est calculée pour les apprenants ayant une note dans cette matière.' : undefined} value={value} error={errors.coefficient} onChange={(event) => setValue(event.target.value)} />
            <Button type="submit" variant="secondary">Enregistrer</Button>
        </form>
    </li>;
}

export default function GradeCoefficients({ subjects, year }: { subjects: Subject[]; year: string | null }) {
    const errors = usePage().props.errors as Record<string, string>;
    return (
        <CampusLayout active="grades" title="Coefficients des matières">
            <Head title="Coefficients des matières" />
            <div className="page-heading"><p className="eyebrow">ADMINISTRATION</p><h1>Coefficients des matières</h1><p className="page-intro">Coefficients utilisés pour la moyenne générale indicative des apprenants{year ? `, ${year}` : ''}. Strictement positifs. Aucun coefficient n’est supposé par défaut.</p></div>
            {errors.reference && <StateMessage kind="error" title="Action non enregistrée">{errors.reference}</StateMessage>}
            <section className="surface" aria-labelledby="coefficients-title">
                <h2 id="coefficients-title">Matières</h2>
                {subjects.length === 0 ? <StateMessage kind="empty" title="Aucune matière" /> : <ul className="plain-list">{subjects.map((subject) => <Row key={`${subject.id}-${subject.version}`} subject={subject} />)}</ul>}
            </section>
            <div className="inline-links"><Link className="text-link" href="/etudes/referentiels">Référentiels</Link></div>
        </CampusLayout>
    );
}
