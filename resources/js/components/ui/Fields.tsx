import { useId, type ComponentProps, type ReactNode } from 'react';

type FieldProps = { label: string; hint?: string; error?: string; id?: string };
function FieldFrame({ id, label, hint, error, children }: FieldProps & { id: string; children: ReactNode }) {
    return <div className="field"><label htmlFor={id}>{label}</label>{hint && <p id={`${id}-hint`} className="field-hint">{hint}</p>}{children}{error && <p id={`${id}-error`} className="field-error">{error}</p>}</div>;
}
function description(id: string, hint?: string, error?: string) {
    return [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ') || undefined;
}
export function TextField({ label, hint, error, id, ...props }: FieldProps & Omit<ComponentProps<'input'>, 'id'>) {
    const generated = useId();
    const fieldId = id ?? generated;
    return <FieldFrame id={fieldId} label={label} hint={hint} error={error}><input {...props} id={fieldId} className="input" aria-invalid={!!error} aria-describedby={description(fieldId, hint, error)} /></FieldFrame>;
}
export function SelectField({ label, hint, error, id, children, ...props }: FieldProps & Omit<ComponentProps<'select'>, 'id'>) {
    const generated = useId();
    const fieldId = id ?? generated;
    return <FieldFrame id={fieldId} label={label} hint={hint} error={error}><select {...props} id={fieldId} className="input" aria-invalid={!!error} aria-describedby={description(fieldId, hint, error)}>{children}</select></FieldFrame>;
}
export function TextAreaField({ label, hint, error, id, ...props }: FieldProps & Omit<ComponentProps<'textarea'>, 'id'>) {
    const generated = useId();
    const fieldId = id ?? generated;
    return <FieldFrame id={fieldId} label={label} hint={hint} error={error}><textarea {...props} id={fieldId} className="input" aria-invalid={!!error} aria-describedby={description(fieldId, hint, error)} /></FieldFrame>;
}
export function CheckboxField({ label, hint, error, id, ...props }: FieldProps & Omit<ComponentProps<'input'>, 'id' | 'type'>) {
    const generated = useId();
    const fieldId = id ?? generated;
    return <div className="field checkbox-field"><input {...props} type="checkbox" id={fieldId} aria-invalid={!!error} aria-describedby={description(fieldId, hint, error)} /><div><label htmlFor={fieldId}>{label}</label>{hint && <p id={`${fieldId}-hint`} className="field-hint">{hint}</p>}{error && <p id={`${fieldId}-error`} className="field-error">{error}</p>}</div></div>;
}
export function ErrorSummary({ errors }: { errors: { id: string; message: string }[] }) {
    if (!errors.length) return null;
    return <div role="alert" className="error-summary"><p className="font-semibold">La saisie doit être corrigée.</p><ul>{errors.map((error) => <li key={error.id}><a href={`#${error.id}`}>{error.message}</a></li>)}</ul></div>;
}
