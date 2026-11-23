import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from './ui/Button';
import { CheckboxField, ErrorSummary, SelectField, TextAreaField, TextField } from './ui/Fields';
import type { Deadline } from './OpportunityDeadline';

export type CatalogueOptions = {
    categories: Record<string, string>; modalities: Record<string, string>; levels: Record<string, string>; funding: Record<string, string>; fees: Record<string, string>;
    deadlines: Record<string, string>; timezones: string[]; dateRule: string; minConditions: number;
};
export type Editable = {
    id: string; version: number; status: string; category: string; title: string; organizer: string; campaign: string; sourceUrl: string; applicationUrl: string; summary: string; conditions: string;
    countries: string[]; modality: string; levels: string[]; fields: string[]; languages: string[]; eligibleNationalities: string[]; eligibleResidences: string[]; funding: string; fees: string; feesDetail: string; deadline: Deadline;
};

const split = (value: string) => value.split(',').map((item) => item.trim()).filter(Boolean);

export function OpportunityForm({ options, opportunity }: { options: CatalogueOptions; opportunity?: Editable }) {
    const live = opportunity?.status === 'published' || opportunity?.status === 'suspended';
    const form = useForm({
        expected_version: opportunity?.version ?? 0, category: opportunity?.category ?? 'scholarship', title: opportunity?.title ?? '', organizer: opportunity?.organizer ?? '', campaign: opportunity?.campaign ?? '',
        source_url: opportunity?.sourceUrl ?? '', application_url: opportunity?.applicationUrl ?? '', summary: opportunity?.summary ?? '', conditions: opportunity?.conditions ?? '',
        countries: (opportunity?.countries ?? []).join(', '), modality: opportunity?.modality ?? 'unspecified', levels: opportunity?.levels ?? ([] as string[]), fields: (opportunity?.fields ?? []).join(', '),
        languages: (opportunity?.languages ?? []).join(', '), eligible_nationalities: (opportunity?.eligibleNationalities ?? []).join(', '), eligible_residences: (opportunity?.eligibleResidences ?? []).join(', '), funding: opportunity?.funding ?? 'unspecified', fees: opportunity?.fees ?? 'unspecified', fees_detail: opportunity?.feesDetail ?? '',
        deadline_kind: (opportunity?.deadline.kind ?? 'unknown') as string, deadline_local: opportunity?.deadline.localInput ?? '', deadline_timezone: opportunity?.deadline.timezone ?? '', deadline_date: opportunity?.deadline.date ?? '',
        confirm_same_source: false, attest: false,
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, countries: split(data.countries), fields: split(data.fields), languages: split(data.languages), eligible_nationalities: split(data.eligible_nationalities), eligible_residences: split(data.eligible_residences), application_url: data.application_url || null,
            deadline_local: data.deadline_kind === 'instant' ? data.deadline_local : null, deadline_timezone: data.deadline_kind === 'instant' ? data.deadline_timezone : null,
            deadline_date: data.deadline_kind === 'date' ? data.deadline_date : null }));
        if (opportunity) form.patch(`/administration/catalogue/${opportunity.id}`, { preserveScroll: true }); else form.post('/administration/catalogue');
    };
    const toggleLevel = (level: string, checked: boolean) => form.setData('levels', checked ? [...form.data.levels, level] : form.data.levels.filter((item) => item !== level));
    return <form onSubmit={submit} className="form-narrow">
        <ErrorSummary errors={Object.entries(form.errors).map(([id, message]) => ({ id, message: String(message) }))} />
        <SelectField label="Catégorie" value={form.data.category} error={form.errors.category} onChange={(event) => form.setData('category', event.target.value)}>
            {Object.entries(options.categories).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </SelectField>
        <TextField label="Titre factuel" required maxLength={200} value={form.data.title} error={form.errors.title} onChange={(event) => form.setData('title', event.target.value)} />
        <TextField label="Organisateur" required maxLength={200} value={form.data.organizer} error={form.errors.organizer} onChange={(event) => form.setData('organizer', event.target.value)} />
        <TextField label="Campagne ou édition" required maxLength={60} hint="Par exemple 2026-2027. Une autre année est une nouvelle édition, pas une modification." value={form.data.campaign} error={form.errors.campaign} onChange={(event) => form.setData('campaign', event.target.value)} />
        <TextField label="Adresse de la source officielle" required type="url" hint="http:// ou https://. Campus n’appelle jamais cette adresse : c’est vous qui la vérifiez." value={form.data.source_url} error={form.errors.source_url} onChange={(event) => form.setData('source_url', event.target.value)} />
        {form.errors.source_url && <CheckboxField label="Ce n’est pas un doublon : une autre fiche de la même campagne peut utiliser cette source" checked={form.data.confirm_same_source} onChange={(event) => form.setData('confirm_same_source', event.target.checked)} />}
        <TextField label="Lien de candidature (facultatif)" type="url" value={form.data.application_url} error={form.errors.application_url} onChange={(event) => form.setData('application_url', event.target.value)} />
        <TextAreaField label="Résumé (facultatif)" rows={3} maxLength={2000} value={form.data.summary} error={form.errors.summary} onChange={(event) => form.setData('summary', event.target.value)} />
        <TextAreaField label="Conditions publiées par l’organisateur" hint={`Obligatoires pour publier (${options.minConditions} caractères au moins). Reprenez ce que publie la source ; n’ajoutez aucune promesse.`} rows={6} maxLength={5000} value={form.data.conditions} error={form.errors.conditions} onChange={(event) => form.setData('conditions', event.target.value)} />
        <TextField label="Pays où se déroule l’opportunité (codes à deux lettres, séparés par des virgules)" hint="Par exemple CI, FR. Laisser vide si non précisé." value={form.data.countries} error={form.errors.countries} onChange={(event) => form.setData('countries', event.target.value)} />
        <SelectField label="Modalité" value={form.data.modality} error={form.errors.modality} onChange={(event) => form.setData('modality', event.target.value)}>
            {Object.entries(options.modalities).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </SelectField>
        <fieldset className="field"><legend>Niveaux</legend>{Object.entries(options.levels).map(([value, label]) => <CheckboxField key={value} label={label} checked={form.data.levels.includes(value)} onChange={(event) => toggleLevel(value, event.target.checked)} />)}</fieldset>
        <TextField label="Domaines (séparés par des virgules)" value={form.data.fields} error={form.errors.fields} onChange={(event) => form.setData('fields', event.target.value)} />
        <TextField label="Langues (codes à deux lettres, séparés par des virgules)" hint="Par exemple fr, en." value={form.data.languages} error={form.errors.languages} onChange={(event) => form.setData('languages', event.target.value)} />
        <TextField label="Nationalités admises selon la source (codes à deux lettres)" hint="Laisser vide si la source ne pose aucune condition de nationalité ou ne le précise pas. Ne déduisez rien." value={form.data.eligible_nationalities} error={form.errors.eligible_nationalities} onChange={(event) => form.setData('eligible_nationalities', event.target.value)} />
        <TextField label="Pays de résidence admis selon la source (codes à deux lettres)" hint="Laisser vide si la source ne pose aucune condition de résidence ou ne le précise pas." value={form.data.eligible_residences} error={form.errors.eligible_residences} onChange={(event) => form.setData('eligible_residences', event.target.value)} />
        <SelectField label="Financement" value={form.data.funding} error={form.errors.funding} onChange={(event) => form.setData('funding', event.target.value)}>
            {Object.entries(options.funding).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </SelectField>
        <SelectField label="Frais de candidature" value={form.data.fees} error={form.errors.fees} onChange={(event) => form.setData('fees', event.target.value)}>
            {Object.entries(options.fees).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </SelectField>
        <TextField label="Précision sur les frais (facultatif)" maxLength={300} value={form.data.fees_detail} error={form.errors.fees_detail} onChange={(event) => form.setData('fees_detail', event.target.value)} />

        <SelectField label="Date limite : précision publiée par l’organisateur" hint="Ne choisissez « date et heure » que si la source indique une heure et un fuseau. N’inventez jamais une heure." value={form.data.deadline_kind} error={form.errors.deadline_kind} onChange={(event) => form.setData('deadline_kind', event.target.value)}>
            {Object.entries(options.deadlines).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </SelectField>
        {form.data.deadline_kind === 'instant' && <>
            <TextField label="Date et heure, telles que publiées" type="datetime-local" required value={form.data.deadline_local} error={form.errors.deadline_local} onChange={(event) => form.setData('deadline_local', event.target.value)} />
            <SelectField label="Fuseau horaire indiqué par l’organisateur" required hint="Aucun fuseau n’est supposé : une échéance internationale n’est pas en heure d’Abidjan par défaut." value={form.data.deadline_timezone} error={form.errors.deadline_timezone} onChange={(event) => form.setData('deadline_timezone', event.target.value)}>
                <option value="">Choisir le fuseau</option>{options.timezones.map((zone) => <option key={zone} value={zone}>{zone}</option>)}
            </SelectField>
        </>}
        {form.data.deadline_kind === 'date' && <TextField label="Date, sans heure" type="date" required hint={options.dateRule} value={form.data.deadline_date} error={form.errors.deadline_date} onChange={(event) => form.setData('deadline_date', event.target.value)} />}

        {live && <CheckboxField label="J’ai vérifié moi-même la source officielle aujourd’hui" hint="Obligatoire pour modifier une fiche publiée. Cet acte est daté et enregistré à votre nom." error={form.errors.attest} checked={form.data.attest} onChange={(event) => form.setData('attest', event.target.checked)} />}
        <div className="form-actions"><Button type="submit" busy={form.processing}>{opportunity ? 'Enregistrer' : 'Créer le brouillon'}</Button></div>
    </form>;
}
