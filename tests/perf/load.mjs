// Charge mixte sur HAKO-RA Campus, sans dépendance : des utilisateurs virtuels se connectent avec les comptes fictifs, lisent leurs écrans
// courants et créent une tâche de temps en temps. Le script mesure le temps de réponse du serveur vu du client et le taux d'erreurs techniques.
//
// Deux usages, à ne pas confondre :
//   node tests/perf/load.mjs --smoke          essai court local (5 utilisateurs, 60 s) : vérifie que l'outil et l'application tiennent debout.
//   node tests/perf/load.mjs --full           mesure complète (100 utilisateurs, 30 minutes) : à lancer seulement sur un environnement décrit,
//                                             proche de la production, avec ses 5 000 comptes fictifs. Jamais sur un service tiers ni en production.
// Options : --base=http://hote:port (défaut : serveur local temporaire lancé par le script), --users=N, --seconds=N,
//           --allow-remote (obligatoire pour une cible qui n'est pas ce poste : confirme que la charge y est autorisée).
// Variables : CAMPUS_PHP (chemin de php), CAMPUS_PORT (8126 par défaut).
//
// Cibles du cahier des charges, à vérifier, jamais garanties : 95 % des réponses courantes sous 800 ms, moins de 1 % d'erreurs techniques.
// Le serveur de développement de PHP traite une requête à la fois : un essai local ne dit rien de la capacité d'un vrai déploiement.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes, randomUUID } from 'node:crypto';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).map((arg) => { const [key, value] = arg.replace(/^--/, '').split('='); return [key, value ?? true]; }));
const full = args.full === true;
if (!full && args.smoke !== true) { console.log('Précisez --smoke (essai court local) ou --full (mesure complète sur un environnement décrit).'); process.exit(2); }
const PORT = Number(process.env.CAMPUS_PORT ?? 8126);
const PHP = process.env.CAMPUS_PHP ?? 'php';
const local = args.base === undefined;
const BASE = local ? `http://127.0.0.1:${PORT}` : String(args.base).replace(/\/$/, '');
const USERS = Number(args.users ?? (full ? 100 : 5));
const SECONDS = Number(args.seconds ?? (full ? 1800 : 60));
const host = new URL(BASE).hostname;
if (!['127.0.0.1', 'localhost'].includes(host) && args['allow-remote'] !== true) {
    console.log(`Cible distante (${host}) : ajoutez --allow-remote seulement si vous êtes autorisé à y lancer de la charge. Jamais sur un service tiers ni en production.`);
    process.exit(2);
}
const ACCOUNTS = ['independant', 'apprenant-double', 'polyvalent', 'enseignant-a', 'admin-a'];
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const artisan = (...parameters) => spawnSync(PHP, ['artisan', ...parameters, '--no-interaction'], { encoding: 'utf8' });

// Accès temporaire aux comptes fictifs (poste de développement seulement) : mot de passe aléatoire, jamais affiché.
let password = process.env.CAMPUS_LOAD_PASSWORD ?? null;
let server = null;
if (local) {
    try { await fetch(BASE + '/up'); console.log(`Un serveur occupe déjà le port ${PORT} : arrêtez-le avant de relancer.`); process.exit(2); } catch { /* port libre */ }
    const directory = mkdtempSync(join(tmpdir(), 'campus-acces-'));
    password = randomBytes(24).toString('base64url');
    writeFileSync(join(directory, 'mot-de-passe'), password, { mode: 0o600 });
    const opened = artisan('campus:fixtures:access', `--password-file=${join(directory, 'mot-de-passe')}`);
    rmSync(directory, { recursive: true, force: true });
    if (opened.status !== 0) { console.log('Accès aux comptes fictifs impossible : ' + (opened.stdout + opened.stderr).trim()); process.exit(2); }
    server = spawn(PHP, ['artisan', 'serve', '--host=127.0.0.1', `--port=${PORT}`, '--no-interaction'], { stdio: 'ignore', env: { ...process.env, BOOST_BROWSER_LOGS_WATCHER: 'false' } });
    for (let i = 0; i < 80; i++) { try { await fetch(BASE + '/up'); break; } catch { await sleep(250); } }
} else if (!password) {
    console.log('Cible fournie : donnez le mot de passe des comptes de charge dans la variable CAMPUS_LOAD_PASSWORD.');
    process.exit(2);
}
const cleanup = () => {
    if (server && server.exitCode === null) { if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(server.pid), '/T', '/F'], { stdio: 'ignore' }); else server.kill('SIGKILL'); }
    if (local) artisan('campus:fixtures:access', '--lock');
};
process.on('SIGINT', () => { cleanup(); process.exit(3); });
process.on('SIGTERM', () => { cleanup(); process.exit(3); });

const samples = [];
const statuses = new Map();
let technicalErrors = 0;
let rateLimited = 0;

/** Un utilisateur virtuel : ses cookies, son jeton de formulaire, la version des écrans. */
function virtualUser(account) {
    const cookies = new Map();
    let version = '';
    const call = async (label, method, path, body) => {
        const headers = { Accept: 'text/html, application/xhtml+xml', Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; '), 'X-Requested-With': 'XMLHttpRequest' };
        if (version) { headers['X-Inertia'] = 'true'; headers['X-Inertia-Version'] = version; }
        if (method !== 'GET') { headers['Content-Type'] = 'application/json'; headers['X-XSRF-TOKEN'] = decodeURIComponent(cookies.get('XSRF-TOKEN') ?? ''); }
        const started = performance.now();
        let status = 0;
        let text = '';
        try {
            const response = await fetch(BASE + path, { method, headers, body: body ? JSON.stringify(body) : undefined, redirect: 'manual' });
            status = response.status;
            text = await response.text();
            for (const cookie of response.headers.getSetCookie()) { const [pair] = cookie.split(';'); const index = pair.indexOf('='); cookies.set(pair.slice(0, index), pair.slice(index + 1)); }
        } catch { status = 0; }
        const elapsed = performance.now() - started;
        samples.push({ label, elapsed });
        statuses.set(status, (statuses.get(status) ?? 0) + 1);
        // Erreur technique : pas de réponse, ou erreur du serveur. Un refus de débit (429) est compté à part : c'est une protection, pas une panne.
        if (status === 0 || status >= 500) technicalErrors++;
        if (status === 429) rateLimited++;

        return { status, text };
    };

    return {
        async signIn() {
            const page = await call('connexion (écran)', 'GET', '/connexion');
            version = (page.text.match(/"version":"([^"]*)"/) ?? [])[1] ?? '';
            const result = await call('connexion (envoi)', 'POST', '/connexion', { login: `${account}@example.test`, password, remember: false });

            return result.status === 302 || result.status === 303 || result.status === 409;
        },
        async round() {
            // Parcours courant : accueil, tâches, planning, notes, notifications ; une création de tâche toutes les quelques boucles.
            for (const [label, path] of [['accueil', '/'], ['tâches', '/taches'], ['planning', '/planning'], ['notes', '/notes'], ['notifications', '/notifications'], ['copie hors connexion', '/hors-ligne/instantane']]) {
                await call(label, 'GET', path);
                await sleep(500 + Math.random() * 1500);
            }
            if (Math.random() < 0.3) await call('création de tâche', 'POST', '/taches', { client_key: randomUUID(), title: '[CHARGE] Tâche fictive ' + randomUUID().slice(0, 8) });
        },
    };
}

console.log(`Cible : ${BASE} — ${USERS} utilisateur(s) virtuel(s), ${SECONDS} s, mode ${full ? 'mesure complète' : 'essai court'}.`);
const until = Date.now() + SECONDS * 1000;
const signedIn = await Promise.all(Array.from({ length: USERS }, async (_, index) => {
    const user = virtualUser(ACCOUNTS[index % ACCOUNTS.length]);
    await sleep(index * 150);
    if (!await user.signIn()) return false;
    while (Date.now() < until) await user.round();

    return true;
}));
cleanup();

const percentile = (values, p) => { if (values.length === 0) return 0; const sorted = [...values].sort((a, b) => a - b); return sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * p) - 1)]; };
const all = samples.map((sample) => sample.elapsed);
console.log(`Connexions réussies : ${signedIn.filter(Boolean).length} sur ${USERS}.`);
console.log(`Requêtes : ${samples.length} en ${SECONDS} s, soit ${(samples.length / SECONDS).toFixed(1)} par seconde.`);
console.log(`Temps de réponse — médiane : ${Math.round(percentile(all, 0.5))} ms ; 95e centile : ${Math.round(percentile(all, 0.95))} ms ; 99e centile : ${Math.round(percentile(all, 0.99))} ms ; maximum : ${Math.round(Math.max(0, ...all))} ms.`);
for (const label of [...new Set(samples.map((sample) => sample.label))]) {
    const values = samples.filter((sample) => sample.label === label).map((sample) => sample.elapsed);
    console.log(`  ${label.padEnd(24)} ${String(values.length).padStart(5)} requêtes ; médiane ${String(Math.round(percentile(values, 0.5))).padStart(5)} ms ; 95e centile ${String(Math.round(percentile(values, 0.95))).padStart(5)} ms`);
}
console.log(`Codes de réponse : ${[...statuses].sort((a, b) => a[0] - b[0]).map(([status, count]) => `${status === 0 ? 'sans réponse' : status} × ${count}`).join(' ; ')}.`);
const errorRate = samples.length ? (technicalErrors / samples.length) * 100 : 100;
console.log(`Erreurs techniques (sans réponse ou erreur du serveur) : ${technicalErrors}, soit ${errorRate.toFixed(2)} %. Refus de débit (429) : ${rateLimited}.`);
const p95 = percentile(all, 0.95);
console.log(`Cibles : 95e centile sous 800 ms — ${p95 < 800 ? 'atteinte' : 'non atteinte'} dans cet essai ; erreurs techniques sous 1 % — ${errorRate < 1 ? 'atteinte' : 'non atteinte'} dans cet essai.`);
console.log(full ? 'Mesure complète : décrivez l’environnement (machines, base, nombre de comptes) avec ces résultats.' : 'Essai court sur le serveur de développement, qui traite une requête à la fois : ces chiffres ne valent pas mesure de capacité.');
process.exit(signedIn.every(Boolean) && errorRate < 1 ? 0 : 1);
