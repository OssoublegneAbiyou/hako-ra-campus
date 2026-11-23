import type { ComponentProps } from 'react';

type Props = ComponentProps<'button'> & { variant?: 'primary' | 'secondary' | 'danger'; busy?: boolean };
export function Button({ children, variant = 'primary', busy = false, disabled, type = 'button', className = '', ...props }: Props) {
    return <button {...props} type={type} className={`button button-${variant} ${className}`} disabled={disabled || busy} aria-busy={busy || undefined}>{busy && <span className="spinner" aria-hidden="true" />}{children}</button>;
}
