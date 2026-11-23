import { Head, Link, usePage } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { StateMessage } from '../components/ui/Feedback';
import type { CampusProps } from '../types';

type Notice = { version: string; publishedOn: string; changes: string; hash: string; toComplete: string[]; sections: { title: string; paragraphs: string[] }[] };
type Props = { notice: Notice; current: string; history: { version: string; publishedOn: string; changes: string; hash: string }[]; durations: Record<string, number>; minorsOpen: boolean };

export default function Privacy({ notice, current, history, minorsOpen }: Props) {
    const signedIn = usePage<CampusProps>().props.auth.user !== null;
    const day = new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeZone: 'UTC' });

    return (
        <CampusLayout active="privacy" title="Confidentialité">
            <Head title="Notice de confidentialité" />
            <div className="page-heading"><p className="eyebrow">CONFIDENTIALITÉ</p><h1>Notice de confidentialité</h1>
                <p className="page-intro">Version du {day.format(new Date(notice.publishedOn))}{notice.version !== current && ' — ancienne version, conservée pour mémoire'}. Elle décrit ce que Campus fait réellement de vos données.</p></div>

            <StateMessage kind="error" title="Projet de notice : des informations restent à compléter avant toute ouverture">
                Ce texte n’a pas été validé juridiquement et ne déclare pas le service conforme à une réglementation. Restent à fournir ou à valider :
                <ul className="plain-list">{notice.toComplete.map((item) => <li key={item}>{item}</li>)}</ul>
            </StateMessage>
            {notice.version !== current && <StateMessage title="Une version plus récente existe" action={<Link className="text-link" href="/confidentialite">Lire la version en vigueur</Link>} />}

            {notice.sections.map((section) => <section className="surface" key={section.title}>
                <h2>{section.title}</h2>
                {section.paragraphs.map((paragraph) => <p key={paragraph} style={{ marginBottom: '.6rem' }}>{paragraph}</p>)}
            </section>)}

            {!minorsOpen && <StateMessage title="Inscription des personnes mineures fermée">Elle ne s’ouvre pas par un simple réglage : il faut un cadre validé et sa référence. Aucun âge légal n’est supposé par l’application.</StateMessage>}

            <section className="surface" aria-labelledby="rights-title">
                <h2 id="rights-title">Exercer vos droits</h2>
                {signedIn
                    ? <p>Export, rectification, opposition et suppression du compte se font depuis <Link className="text-link" href="/confidentialite/parametres">Confidentialité et données</Link>.</p>
                    : <p>Connectez-vous pour exporter vos données, demander une rectification, vous opposer à un traitement ou supprimer votre compte. <Link className="text-link" href="/connexion">Se connecter</Link></p>}
            </section>

            <section className="surface" aria-labelledby="history-title">
                <h2 id="history-title">Versions de la notice</h2>
                <ul className="plain-list">{history.map((item) => <li key={item.version}>
                    <strong>{day.format(new Date(item.publishedOn))}</strong>{item.version === current && ' — en vigueur'}
                    <p className="item-meta">{item.changes}</p>
                    <p className="item-meta">Empreinte du texte : {item.hash.slice(0, 16)}…</p>
                    {item.version !== notice.version && <Link className="text-link" href={`/confidentialite?version=${item.version}`}>Lire cette version</Link>}
                </li>)}</ul>
            </section>
        </CampusLayout>
    );
}
