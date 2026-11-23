import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CampusLayout from '../layouts/CampusLayout';
import { Button } from '../components/ui/Button';
import { StateMessage } from '../components/ui/Feedback';
import { ErrorSummary, SelectField, TextField } from '../components/ui/Fields';
import { useUnsavedWarning } from '../components/ui/useUnsavedWarning';

type Props = {
    profile: { version: number; level: string; fields: string[]; languages: string[]; residenceCountry: string; nationality: string };
    levels: Record<string, string>; labels: { residenceCountry: string | null; nationality: string | null }; disclaimer: string;
};

const split = (value: string) => value.split(',').map((item) => item.trim()).filter(Boolean);

export default function OpportunityProfile({ profile, levels, labels, disclaimer }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const form = useForm({ expected_version: profile.version, level: profile.level, fields: profile.fields.join(', '), languages: profile.languages.join(', '), residence_country: profile.residenceCountry, nationality: profile.nationality });
    const send = (data: typeof form.data) => ({ expected_version: data.expected_version, level: data.level || null, fields: split(data.fields), languages: split(data.languages), residence_country: data.residence_country.trim() || null, nationality: data.nationality.trim() || null });
    useUnsavedWarning(form.isDirty && !form.processing && !form.wasSuccessful, 'Vos critères');
    const submit = (event: FormEvent) => { event.preventDefault(); form.transform(send); form.put('/opportunites/profil', { preserveScroll: true }); };
    const erase = () => { form.transform((data) => ({ expected_version: data.expected_version, level: null, fields: [], languages: [], residence_country: null, nationality: null })); form.put('/opportunites/profil', { preserveScroll: true }); };
    return (
        <CampusLayout active="opportunities" title="Mes critères">
            <Head title="Mes critères" />
            <div className="page-heading"><p className="eyebrow">OPPORTUNITÉS</p><h1>Mes critères</h1><p className="page-intro">Déclarez seulement ce que vous voulez. Ces critères servent uniquement à comparer les fiches à votre situation ; ils ne sont visibles que par vous et rien n’est déduit du reste de votre compte.</p></div>
            {errors.reference && <StateMessage kind="error" title="Critères non enregistrés">{errors.reference}</StateMessage>}
            <section className="surface" aria-labelledby="criteria-title">
                <h2 id="criteria-title">Critères déclarés</h2>
                <p className="section-description">{disclaimer}</p>
                <form onSubmit={submit} className="form-narrow">
                    <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
                    <SelectField label="Niveau d’études (facultatif)" value={form.data.level} error={form.errors.level} onChange={(event) => form.setData('level', event.target.value)}>
                        <option value="">Non déclaré</option>{Object.entries(levels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </SelectField>
                    <TextField label="Domaines (facultatif, séparés par des virgules)" hint="Dix au plus. Reprenez de préférence les intitulés affichés dans le filtre « Domaine »." value={form.data.fields} error={form.errors.fields} onChange={(event) => form.setData('fields', event.target.value)} />
                    <TextField label="Langues (facultatif, codes à deux lettres)" hint="Par exemple fr, en." value={form.data.languages} error={form.errors.languages} onChange={(event) => form.setData('languages', event.target.value)} />
                    <TextField label="Pays de résidence (facultatif, code à deux lettres)" hint={`Par exemple CI.${labels.residenceCountry ? ` Enregistré : ${labels.residenceCountry}.` : ''} Utile seulement pour les fiches qui posent une condition de résidence.`} maxLength={2} value={form.data.residence_country} error={form.errors.residence_country} onChange={(event) => form.setData('residence_country', event.target.value)} />
                    <TextField label="Nationalité (facultatif, code à deux lettres)" hint={`Distincte du pays de résidence.${labels.nationality ? ` Enregistrée : ${labels.nationality}.` : ''} Utile seulement pour les fiches qui posent une condition de nationalité. Vous pouvez ne pas la déclarer : ces fiches indiqueront alors « information à vérifier ».`} maxLength={2} value={form.data.nationality} error={form.errors.nationality} onChange={(event) => form.setData('nationality', event.target.value)} />
                    <div className="form-actions"><Button type="submit" busy={form.processing}>Enregistrer mes critères</Button>{profile.version > 0 && <Button variant="danger" busy={form.processing} onClick={erase}>Tout effacer</Button>}</div>
                </form>
            </section>
            <div className="inline-links"><Link className="text-link" href="/opportunites">Retour aux opportunités</Link></div>
        </CampusLayout>
    );
}
