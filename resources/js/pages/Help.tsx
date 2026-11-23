import { Head, Link, usePage } from '@inertiajs/react';
import CampusLayout from '../layouts/CampusLayout';
import { StateMessage } from '../components/ui/Feedback';
import type { CampusProps } from '../types';

type Topic = { title: string; text: string; href?: string; link?: string; member?: boolean };

export default function Help({ supportEmail, offlineTtlDays }: { supportEmail: string | null; offlineTtlDays: number }) {
    const signedIn = usePage<CampusProps>().props.auth.user !== null;
    const topics: Topic[] = [
        { title: 'Créer mon premier cours', text: 'Dans Planning, « Ajouter un cours ou événement » demande un titre, une date et des horaires. Un cours répété chaque semaine se crée de la même façon, avec une date de fin.', href: '/planning', link: 'Ouvrir le planning', member: true },
        { title: 'Ajouter une tâche', text: 'Dans Tâches, seul le titre est obligatoire. L’échéance, la matière et la priorité sont facultatives ; vous pouvez découper une tâche en étapes.', href: '/taches', link: 'Ouvrir mes tâches', member: true },
        { title: 'Noter mes résultats', text: 'Les moyennes sont indicatives. Une note absente ne vaut jamais zéro, et une matière sans coefficient n’entre pas dans la moyenne générale.', href: '/notes', link: 'Ouvrir mes notes', member: true },
        { title: 'Retour après un cours', text: 'Après chaque cours, vous pouvez noter ce que vous retenez et ce qu’il reste à faire. Ce retour est privé : ni vos enseignants, ni votre établissement, ni la plateforme ne peuvent le lire.', href: '/retours', link: 'Mes retours', member: true },
        { title: 'Rejoindre un établissement', text: 'Le rattachement se fait uniquement par une invitation de l’établissement. Vous gardez un seul compte : votre espace individuel reste séparé, et vous changez de contexte depuis l’en-tête.', href: '/profil', link: 'Mes appartenances', member: true },
        { title: 'Opportunités', text: 'Les fiches sont vérifiées par une équipe éditoriale et renvoient à la source officielle. La comparaison avec vos critères est indicative : seul l’organisateur décide de l’éligibilité.', href: '/opportunites', link: 'Parcourir le catalogue' },
        { title: 'Notifications', text: 'Le centre de notifications fonctionne sans courriel ni notification du navigateur. Vous choisissez les catégories, les canaux et vos heures calmes.', href: '/notifications/preferences', link: 'Mes préférences', member: true },
        { title: 'Sans réseau', text: `Sur un appareil personnel, vous pouvez garder une copie de lecture et écrire des brouillons qui partent au retour du réseau. La copie est effacée après ${offlineTtlDays} jours sans vérification.`, href: '/hors-ligne', link: 'Hors connexion et installation' },
        { title: 'Mot de passe oublié', text: 'Avec une adresse électronique, utilisez « Mot de passe oublié » sur l’écran de connexion. Avec un identifiant fourni par votre établissement, adressez-vous à son administration.', href: signedIn ? undefined : '/mot-de-passe-oublie', link: 'Réinitialiser mon mot de passe' },
        { title: 'Appareil perdu ou partagé', text: 'Depuis le profil, fermez les sessions ouvertes sur vos autres appareils, puis réinitialisez votre mot de passe par « Mot de passe oublié ». Sur un appareil partagé, déconnectez-vous toujours.', href: '/profil', link: 'Mes appareils connectés', member: true },
    ];

    return (
        <CampusLayout active="help" title="Aide et support">
            <Head title="Aide et support" />
            <div className="page-heading"><p className="eyebrow">AIDE</p><h1>Aide et support</h1><p className="page-intro">Les réponses courtes aux questions les plus fréquentes, et la façon de joindre le support.</p></div>

            <section className="surface" aria-labelledby="topics-title">
                <h2 id="topics-title">Questions fréquentes</h2>
                <ul className="plain-list">{topics.map((topic) => <li key={topic.title}>
                    <strong>{topic.title}</strong>
                    <p>{topic.text}</p>
                    {topic.href && (!topic.member || signedIn) && <Link className="text-link" href={topic.href}>{topic.link}</Link>}
                </li>)}</ul>
            </section>

            <section className="surface" aria-labelledby="support-title">
                <h2 id="support-title">Joindre le support</h2>
                {supportEmail
                    ? <><p>Écrivez à <a className="text-link" href={`mailto:${supportEmail}`}>{supportEmail}</a>. Décrivez ce que vous faisiez et le message affiché.</p>
                        <p className="item-meta">N’envoyez jamais votre mot de passe. Le support ne peut pas lire vos notes privées, vos retours de cours ni vos démarches, et ne se connecte pas à votre place.</p></>
                    : <StateMessage title="Aucun contact de support n’est encore configuré">L’adresse du support n’a pas été renseignée sur cette installation. Si vous dépendez d’un établissement, adressez-vous à son administration.</StateMessage>}
            </section>

            <section className="surface" aria-labelledby="privacy-title">
                <h2 id="privacy-title">Ce qui reste privé</h2>
                <ul className="plain-list">
                    <li>Vos tâches personnelles, vos notes personnelles, vos retours après cours et vos démarches ne sont lisibles que par vous.</li>
                    <li>Votre établissement voit ce qu’il publie (planning, devoirs, résultats) et la liste de ses membres ; il ne voit pas votre espace individuel.</li>
                    <li>Personne, y compris l’administration de la plateforme, ne peut se connecter à votre place.</li>
                </ul>
            </section>
        </CampusLayout>
    );
}
