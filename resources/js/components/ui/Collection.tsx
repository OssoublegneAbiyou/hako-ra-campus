import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from './Button';
import { SelectField, TextField } from './Fields';
import { StateMessage } from './Feedback';

type Column<T> = { key: string; label: string; render: (row: T) => ReactNode };
export function ResponsiveCollection<T>({ rows, columns, rowKey, caption }: { rows: T[]; columns: Column<T>[]; rowKey: (row: T) => string; caption: string }) {
    if (!rows.length) return <StateMessage kind="empty" title="Aucun élément à afficher">Ajustez les filtres ou revenez plus tard.</StateMessage>;
    return <><ul className="collection-cards" aria-label={caption}>{rows.map((row) => <li key={rowKey(row)}>{columns.map((column) => <div key={column.key}><span className="collection-label">{column.label}</span><div>{column.render(row)}</div></div>)}</li>)}</ul><div className="collection-table"><table><caption className="sr-only">{caption}</caption><thead><tr>{columns.map((column) => <th key={column.key} scope="col">{column.label}</th>)}</tr></thead><tbody>{rows.map((row) => <tr key={rowKey(row)}>{columns.map((column) => <td key={column.key}>{column.render(row)}</td>)}</tr>)}</tbody></table></div></>;
}
export function Pagination({ currentPage, totalPages, previousHref, nextHref }: { currentPage: number; totalPages: number; previousHref?: string; nextHref?: string }) {
    if (totalPages <= 1) return null;
    return <nav className="pagination" aria-label="Pagination">{previousHref ? <Link className="button button-secondary" href={previousHref}>Précédente</Link> : <span className="pagination-disabled">Précédente</span>}<span aria-current="page">Page {currentPage} sur {totalPages}</span>{nextHref ? <Link className="button button-secondary" href={nextHref}>Suivante</Link> : <span className="pagination-disabled">Suivante</span>}</nav>;
}
export function FilterBar({ search, onSearch, source, onSource, onReset }: { search: string; onSearch: (value: string) => void; source: string; onSource: (value: string) => void; onReset: () => void }) {
    return <section aria-label="Filtres" className="filter-bar"><TextField label="Rechercher" type="search" value={search} onChange={(event) => onSearch(event.target.value)} /><SelectField label="Provenance" value={source} onChange={(event) => onSource(event.target.value)}><option value="">Toutes les provenances</option><option value="personal">Personnel</option><option value="institutional">Établissement</option></SelectField><Button variant="secondary" onClick={onReset}>Réinitialiser</Button></section>;
}
