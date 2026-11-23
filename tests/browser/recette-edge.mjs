// Recette de bout en bout de HAKO-RA Campus (éditeur, alertes, moyenne, fichier, synchronisation, import, report, devoir) dans un navigateur Chromium réel (Edge ou Chrome), piloté sans dépendance par le protocole de débogage.
//
// Usage : node tests/browser/recette-edge.mjs
// Variables : CAMPUS_BROWSER (chemin du navigateur), CAMPUS_PHP (chemin de php), CAMPUS_PORT (port du serveur local, 8128 par défaut).
//
// Le script travaille sur la base locale de développement et ses comptes fictifs (@example.test) uniquement. Il leur donne, le temps du
// passage, un mot de passe aléatoire jamais affiché (php artisan campus:fixtures:access), puis les referme. Les données qu'il crée sont
// fictives et préfixées « [RECETTE] ». Les rôles d'enseignement et d'administration ne s'ouvrent qu'avec la dérogation locale
// CAMPUS_PRIVILEGED_WITHOUT_MFA, limitée au serveur temporaire lancé ici.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PORT = Number(process.env.CAMPUS_PORT ?? 8128);
const DEBUG_PORT = PORT + 1000;
const BASE = `http://127.0.0.1:${PORT}`;
const BROWSER = process.env.CAMPUS_BROWSER ?? ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'].find(existsSync);
const PHP = process.env.CAMPUS_PHP ?? 'php';
const RUN = new Date().toISOString().slice(5, 16).replace(/[-T:]/g, '');
const TAG = `[RECETTE ${RUN}]`;
const results = [];
const check = (label, ok, detail = '') => { results.push({ label, ok, detail }); console.log(`${ok ? 'OK   ' : 'ÉCHEC'} ${label}${detail ? ' — ' + detail : ''}`); };
const note = (text) => console.log(`NOTE  ${text}`);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const artisan = (...args) => spawnSync(PHP, ['artisan', ...args, '--no-interaction'], { encoding: 'utf8' });
// Traitement en file lancé avec la même dérogation locale que le serveur temporaire : sans elle, l'analyse d'un import s'arrête, les droits d'administration n'étant pas ouverts.
const worker = () => spawnSync(PHP, ['artisan', 'queue:work', '--stop-when-empty', '--max-time=90', '--no-interaction'], { encoding: 'utf8', env: { ...process.env, CAMPUS_PRIVILEGED_WITHOUT_MFA: 'true' } });

async function waitFor(url, wanted, tries = 80) {
    for (let i = 0; i < tries; i++) {
        try { await fetch(url); if (wanted) return true; } catch { if (!wanted) return true; }
        await sleep(250);
    }

    return false;
}

function stop(child) {
    if (!child || child.exitCode !== null) return;
    if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' }); else child.kill('SIGKILL');
}

// Fonctions utilisées dans la page : elles manipulent l'interface comme une personne (champ désigné par son libellé, bouton par son texte).
const PRELUDE = `
const norm = (s) => (s || '').replace(/\\s+/g, ' ').trim();
const visible = (el) => el.getClientRects().length > 0;
const scope = (root) => typeof root === 'string' ? document.querySelector(root) : (root || document);
const byLabel = (text, root) => {
    // Libellé exact d'abord (« Nom » ne doit pas désigner « Nom ou code »), sinon début du libellé.
    const labels = [...scope(root).querySelectorAll('label')].filter(visible);
    const own = (l) => norm([...l.childNodes].filter((n) => n.nodeType === 3 || (n.nodeType === 1 && !n.matches('input, select, textarea, .field-hint, small'))).map((n) => n.textContent).join(' '));
    const label = labels.find((l) => own(l) === text || norm(l.textContent) === text) || labels.find((l) => norm(l.textContent).startsWith(text));
    if (!label) throw new Error('Champ introuvable : ' + text + ' — libellés présents : ' + [...scope(root).querySelectorAll('label')].filter(visible).map((l) => norm(l.textContent).slice(0, 40)).join(' | '));
    return label.control || label.querySelector('input, select, textarea') || document.getElementById(label.htmlFor);
};
const setValue = (el, value) => {
    const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : el instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, value);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    window.__actions = (window.__actions || 0) + 1;
};
const fill = (text, value, root) => setValue(byLabel(text, root), value);
const choose = (text, optionText, root) => {
    const el = byLabel(text, root);
    const option = [...el.options].find((o) => norm(o.textContent).includes(optionText));
    if (!option) throw new Error('Option introuvable : ' + optionText + ' — options : ' + [...el.options].map((o) => norm(o.textContent)).join(' | '));
    setValue(el, option.value);
};
const tick = (text, root) => { const el = byLabel(text, root); if (!el.checked) el.click(); window.__actions = (window.__actions || 0) + 1; };
const press = (text, root) => {
    const button = [...scope(root).querySelectorAll('button, a.button, a.text-link')].filter(visible).find((b) => norm(b.textContent).startsWith(text));
    if (!button) throw new Error('Bouton introuvable : ' + text + ' — boutons présents : ' + [...scope(root).querySelectorAll('button, a.button')].filter(visible).map((b) => norm(b.textContent).slice(0, 30)).join(' | '));
    button.click();
    window.__actions = (window.__actions || 0) + 1;
};
const settle = (ms = 1500) => new Promise((resolve) => setTimeout(resolve, ms));
const text = () => norm(document.body.innerText);
const steps = (id) => [...document.querySelectorAll('section[aria-labelledby="' + id + '"] .checklist li')].map((li) => (li.classList.contains('is-done') ? '✓' : li.classList.contains('is-next') ? '→' : '○'));
`;

if (!BROWSER) { console.log('Aucun navigateur Chromium trouvé : parcours non exécutés.'); process.exit(2); }
try { await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`); console.log(`Un navigateur occupe déjà le port ${DEBUG_PORT} : arrêtez-le avant de relancer.`); process.exit(2); } catch { /* port libre */ }
try { await fetch(BASE + '/up'); console.log(`Un serveur occupe déjà le port ${PORT} : arrêtez-le avant de relancer.`); process.exit(2); } catch { /* port libre */ }

// Accès temporaire aux comptes fictifs : mot de passe aléatoire, écrit dans un fichier temporaire hors dépôt, aussitôt supprimé.
const secretDirectory = mkdtempSync(join(tmpdir(), 'campus-acces-'));
const secretFile = join(secretDirectory, 'mot-de-passe');
const password = randomBytes(24).toString('base64url');
writeFileSync(secretFile, password, { mode: 0o600 });
const opened = artisan('campus:fixtures:access', `--password-file=${secretFile}`);
rmSync(secretDirectory, { recursive: true, force: true });
if (opened.status !== 0) { console.log('Accès aux comptes fictifs impossible : ' + (opened.stdout + opened.stderr).trim()); process.exit(2); }

const profile = mkdtempSync(join(tmpdir(), 'campus-parcours-'));
const server = spawn(PHP, ['artisan', 'serve', '--host=127.0.0.1', `--port=${PORT}`, '--no-interaction'], { stdio: 'ignore', env: { ...process.env, CAMPUS_PRIVILEGED_WITHOUT_MFA: 'true' } });
const browser = spawn(BROWSER, ['--headless=new', `--remote-debugging-port=${DEBUG_PORT}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
let socket;
// Garde-fou : au-delà de neuf minutes, ou si le script est interrompu, tout ce qu'il a lancé est arrêté et les comptes fictifs sont refermés.
// Sous Windows, le navigateur sans interface se détache de son lanceur : il est d'abord fermé par son propre protocole, puis par son profil temporaire.
const closeBrowser = async () => {
    try {
        const info = await (await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`)).json();
        const control = new WebSocket(info.webSocketDebuggerUrl);
        await new Promise((resolve) => { control.onopen = () => { control.send(JSON.stringify({ id: 1, method: 'Browser.close' })); setTimeout(resolve, 1500); }; control.onerror = resolve; setTimeout(resolve, 4000); });
    } catch { /* navigateur déjà fermé */ }
    stop(browser);
    if (process.platform === 'win32') spawnSync('powershell', ['-NoProfile', '-Command', `Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'msedge.exe' -and $_.CommandLine -like '*${profile.split(/[\/]/).pop()}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`], { stdio: 'ignore' });
};
const emergency = async () => { await closeBrowser(); stop(server); artisan('campus:fixtures:access', '--lock'); console.log('Recette interrompue : serveur et navigateur arrêtés, comptes fictifs refermés.'); process.exit(3); };
const watchdog = setTimeout(emergency, 9 * 60 * 1000);
process.on('SIGINT', emergency);
process.on('SIGTERM', emergency);
try {
    if (!await waitFor(BASE + '/up', true)) throw new Error('Le serveur local ne répond pas.');
    if (!await waitFor(`http://127.0.0.1:${DEBUG_PORT}/json/version`, true)) throw new Error('Le navigateur ne répond pas.');
    const target = await (await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/new?about:blank`, { method: 'PUT' })).json();
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let next = 0;
    const pending = new Map();
    const waiters = new Map();
    const pageErrors = [];
    let dialogs = 0;
    socket.onmessage = (message) => {
        const data = JSON.parse(message.data);
        if (data.id && pending.has(data.id)) { pending.get(data.id)(data); pending.delete(data.id); }
        // Toute boîte de dialogue du navigateur est acceptée, à chaque fois ; les demandes de confirmation de Campus sont comptées.
        if (data.method === 'Page.javascriptDialogOpening') { if (data.params.type === 'confirm') dialogs++; void send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {}); }
        if (data.method === 'Runtime.exceptionThrown') pageErrors.push(data.params.exceptionDetails.exception?.description?.split('\n')[0] ?? data.params.exceptionDetails.text);
        if (data.method && waiters.has(data.method)) { waiters.get(data.method)(); waiters.delete(data.method); }
    };
    // Chaque commande au navigateur a un délai : un blocage devient une erreur lisible au lieu d'une attente sans fin.
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++next;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error('Le navigateur n’a pas répondu à ' + method)); }, 30000);
        pending.set(id, (data) => { clearTimeout(timer); resolve(data); });
        socket.send(JSON.stringify({ id, method, params }));
    });
    // Le texte évalué n'est jamais affiché : il peut contenir le mot de passe temporaire.
    const evaluate = async (expression) => {
        const reply = await send('Runtime.evaluate', { expression: `(async () => { ${PRELUDE} ${expression} })()`, awaitPromise: true, returnByValue: true });
        if (reply.result?.exceptionDetails) throw new Error((reply.result.exceptionDetails.exception?.description ?? 'Erreur dans la page').split('\n')[0]);

        return reply.result?.result?.value;
    };
    const go = async (path) => {
        const loaded = new Promise((resolve) => waiters.set('Page.loadEventFired', resolve));
        await send('Page.navigate', { url: BASE + path });
        await Promise.race([loaded, sleep(15000)]);
        for (let i = 0; i < 40; i++) {
            if (await evaluate(`return document.querySelector('.campus-shell, main') !== null;`).catch(() => false)) break;
            await sleep(250);
        }
        await sleep(500);
    };
    const attempt = async (label, action) => {
        try { const detail = await action(); check(label, detail !== false, typeof detail === 'string' ? detail : ''); return detail !== false; } catch (error) { check(label, false, String(error?.message ?? error).slice(0, 400)); return false; }
    };
    const signIn = async (name) => {
        await send('Network.clearBrowserCookies');
        await go('/connexion');
        await evaluate(`fill('Adresse électronique ou identifiant', ${JSON.stringify(name + '@example.test')}); fill('Mot de passe', ${JSON.stringify(password)}); press('Se connecter'); await settle(2500); return true;`);

        return evaluate(`return location.pathname + '|' + (document.querySelector('.campus-shell') !== null);`);
    };
    const useContext = async (label) => {
        await go('/');
        await evaluate(`const select = document.getElementById('context-select'); const option = [...select.options].find((o) => norm(o.textContent).includes(${JSON.stringify(label)})); if (!option) throw new Error('Contexte introuvable : ' + [...select.options].map((o) => norm(o.textContent)).join(' | ')); setValue(select, option.value); await settle(2500); return true;`);
    };
    // Le compte connecté quitte l'établissement fictif A depuis son profil : confirmation, puis mot de passe redemandé une fois.
    const leaveA = async () => {
        for (let round = 0; round < 4; round++) {
            await go('/profil');
            const where = await evaluate(`const item = [...document.querySelectorAll('section[aria-labelledby="memberships-title"] li')].find((li) => li.textContent.includes('Établissement fictif A')); if (!item) return 'parti'; press('Quitter', item); await settle(600); press('Quitter cette appartenance', 'dialog[open]'); await settle(3000); return location.pathname;`);
            if (where === 'parti') return true;
            if (where === '/confirmation-mot-de-passe') await evaluate(`fill('Mot de passe', ${JSON.stringify(password)}); press('Confirmer'); await settle(3000); return true;`);
        }

        return false;
    };
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    // Les confirmations du navigateur (abandon d'une saisie) sont acceptées, et comptées.
    const version = await (await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`)).json();
    console.log('Navigateur : ' + version.Browser);
    console.log('Données créées préfixées : ' + TAG);

    const TOKEN = 'Recette' + RUN;
    const day = (offset) => new Date(Date.now() + offset * 86400000).toLocaleDateString('en-CA', { timeZone: 'Africa/Abidjan' });
    const clock = (minutes) => String(Math.floor(minutes / 60)).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0');
    const today = day(0);
    const titleCount = (title) => evaluate(`return [...document.querySelectorAll('li strong')].filter((s) => norm(s.textContent) === ${JSON.stringify(title)}).length;`);
    const publish = async (reason) => {
        await go('/planning/publication');
        await evaluate(`fill('Motif de la publication', ${JSON.stringify(reason)}); await settle(400); press('Publier ces'); await settle(800); return true;`);
        const named = await evaluate(`const dialog = document.querySelector('dialog[open]'); return dialog ? norm(dialog.querySelector('h2').textContent) : '';`);
        await evaluate(`press('Publier', 'dialog[open]'); await settle(4000); return true;`);

        return (await evaluate(`return text().includes('Version en vigueur');`)) ? named : '';
    };

    // ───────────────────────── Opportunités : recherche enregistrée, éditeur, visiteur, favori, alertes ─────────────────────────
    console.log('\n— Opportunités (independant, editeur, visiteur) —');
    const listingTitle = `${TAG} Bourse fictive ${TOKEN}`;
    const searchName = `${TAG} Recherche ${TOKEN}`;
    await attempt('Indépendant : recherche sans résultat enregistrée avec alerte quotidienne', async () => {
        const where = await signIn('independant');
        if (!where.endsWith('|true')) return false;
        await go('/opportunites');
        await evaluate(`fill('Titre, organisateur ou domaine', ${JSON.stringify(TOKEN)}); press('Appliquer'); await settle(3000); return true;`);
        const empty = await evaluate(`return text().includes('Aucune fiche ne correspond à ces filtres');`);
        await evaluate(`press('Enregistrer cette recherche et être prévenu'); await settle(600); fill('Nom de la recherche (facultatif)', ${JSON.stringify(searchName)}); press('Enregistrer la recherche'); await settle(3000); return true;`);
        await go('/opportunites/alertes');
        const saved = await evaluate(`return text().includes(${JSON.stringify(searchName)});`);

        return empty && saved ? 'aucune fiche avant publication ; recherche visible dans « Mes alertes »' : false;
    });
    let editPath = '';
    await attempt('Éditeur : brouillon créé avec source, conditions et date sans heure', async () => {
        const where = await signIn('editeur');
        if (!where.endsWith('|true')) return false;
        await useContext('Édition du catalogue');
        await go('/administration/catalogue');
        if (!await evaluate(`return document.querySelector('section[aria-labelledby="create-title"] form') !== null;`)) {
            note('Écran éditeur : ' + await evaluate(`const select = document.getElementById('context-select'); return location.pathname + ' | contextes : ' + (select ? [...select.options].map((o) => norm(o.textContent)).join(' / ') : 'aucun sélecteur') + ' | ' + text().slice(0, 200);`));
            const picked = await evaluate(`const select = document.getElementById('context-select'); const option = select ? [...select.options].find((o) => /catalogue|éditeur|editeur|plateforme/i.test(o.textContent)) : null; if (!option) return false; setValue(select, option.value); await settle(2500); return true;`);
            if (!picked) return false;
            await go('/administration/catalogue');
        }
        await evaluate(`const form = document.querySelector('section[aria-labelledby="create-title"] form');
            fill('Titre factuel', ${JSON.stringify(listingTitle)}, form); fill('Organisateur', 'Organisme fictif de recette', form); fill('Campagne ou édition', ${JSON.stringify('R-' + RUN)}, form);
            fill('Adresse de la source officielle', ${JSON.stringify('https://example.test/recette/' + RUN)}, form);
            fill('Conditions publiées par l’organisateur', 'Annonce entièrement fictive créée pour la recette du logiciel. Elle ne correspond à aucune offre réelle. Conditions fictives : être inscrit dans une formation fictive et déposer un dossier fictif avant la date indiquée.', form);
            fill('Domaines (séparés par des virgules)', 'recette', form);
            setValue(byLabel('Date limite : précision publiée par l’organisateur', form), 'date'); await settle(500);
            fill('Date, sans heure', '${day(30)}', form); await settle(300); press('Créer le brouillon', form); await settle(4000); return true;`);
        editPath = await evaluate(`return location.pathname;`);
        const state = await evaluate(`return [text().includes(${JSON.stringify(listingTitle)}), [...document.querySelectorAll('button')].some((b) => norm(b.textContent) === 'Envoyer en vérification'), norm(document.querySelector('.error-summary')?.textContent).slice(0, 200)].join('|');`);
        if (!/^\/administration\/catalogue\/[0-9a-f-]{36}$/.test(editPath)) { editPath = ''; return false; }

        return state.startsWith('true|true|') ? 'fiche en brouillon, non publique' : false;
    });
    await attempt('Éditeur : publication refusée sans acte de vérification, puis vérifiée et publiée', async () => {
        if (!editPath) return false;
        await evaluate(`press('Envoyer en vérification'); await settle(3000); return true;`);
        await evaluate(`press('Vérifier et publier'); await settle(3000); return true;`);
        const refused = await evaluate(`return [...document.querySelectorAll('button')].some((b) => norm(b.textContent) === 'Vérifier et publier') && document.querySelectorAll('.error-summary, .field-error').length > 0;`);
        await evaluate(`tick('J’ai consulté moi-même la source officielle'); await settle(300); press('Vérifier et publier'); await settle(3500); return true;`);
        const published = await evaluate(`return [...document.querySelectorAll('button')].some((b) => norm(b.textContent) === 'Suspendre') && text().includes('Source vérifiée le');`);

        return refused && published ? 'refus sans attestation ; publiée après attestation datée' : (published ? 'publiée ; refus préalable non constaté' : false);
    });
    let listingPath = '';
    await attempt('Visiteur sans compte : la fiche publiée se trouve par la recherche du catalogue public', async () => {
        if (!editPath) return false;
        await send('Network.clearBrowserCookies');
        await go('/opportunites');
        await evaluate(`fill('Titre, organisateur ou domaine', ${JSON.stringify(TOKEN)}); press('Appliquer'); await settle(3000); return true;`);
        listingPath = await evaluate(`const link = [...document.querySelectorAll('a')].find((a) => /^\\/opportunites\\/[0-9a-f-]{36}$/.test(a.getAttribute('href') || '') && a.textContent.includes(${JSON.stringify(TOKEN)})); return link ? link.getAttribute('href') : '';`);
        if (!listingPath) return false;
        await go(listingPath);
        const sheet = await evaluate(`return [text().includes('Source officielle'), text().includes('Organisme fictif de recette'), text().includes('Ajouter à mes favoris')].join('|');`);

        return sheet.startsWith('true|true|') ? 'fiche lisible sans compte ; favori réservé au compte (' + sheet.split('|')[2] + ')' : false;
    });
    await attempt('Indépendant : fiche ajoutée aux favoris', async () => {
        if (!listingPath) return false;
        const where = await signIn('independant');
        if (!where.endsWith('|true')) return false;
        await go(listingPath);
        await evaluate(`press('Ajouter à mes favoris'); await settle(3000); return true;`);

        return await evaluate(`return text().includes('Retirer de mes favoris');`);
    });
    await attempt('Éditeur : clôture prolongée sur la fiche publiée, avec nouvelle vérification', async () => {
        if (!editPath) return false;
        const where = await signIn('editeur');
        if (!where.endsWith('|true')) return false;
        await useContext('Édition du catalogue');
        await go(editPath);
        await evaluate(`const section = document.querySelector('section[aria-labelledby="edit-title"]'); fill('Date, sans heure', '${day(40)}', section); tick('J’ai vérifié moi-même la source officielle aujourd’hui', section); await settle(300); press('Enregistrer', section); await settle(4000); return true;`);
        const state = await evaluate(`return [byLabel('Date, sans heure').value, norm(document.querySelector('.error-summary')?.textContent).slice(0, 200)].join('|');`);

        return state === day(40) + '|' ? 'nouvelle date enregistrée : ' + day(40) : false;
    });
    await attempt('Alertes : après un cycle de rappels, le centre de notifications annonce la fiche (synthèse et changement de date)', async () => {
        if (!listingPath) return false;
        const cycle = artisan('campus:notifications:run');
        worker();
        const where = await signIn('independant');
        if (!where.endsWith('|true')) return false;
        await go('/notifications');
        const found = await evaluate(`return [...document.querySelectorAll('section[aria-labelledby="list-title"] li')].map((li) => norm(li.textContent)).filter((t) => t.includes(${JSON.stringify(TOKEN)}) || t.includes(${JSON.stringify(searchName)})).map((t) => t.slice(0, 110)).join(' ## ');`);
        note('Cycle de rappels : code ' + cycle.status + ' ; notifications trouvées : ' + (found || 'aucune'));

        return found ? found.split(' ## ').length + ' notification(s) concernant la fiche' : false;
    });

    // ───────────────────────── Notes : moyenne de référence ─────────────────────────
    console.log('\n— Notes et moyenne (independant) —');
    const subjectName = TAG + ' Matière recette';
    await attempt('Moyenne : 14 sur 20 (coefficient 1) et 36 sur 60 (coefficient 2) affichent 12,67', async () => {
        await go('/etudes/referentiels?kind=subjects');
        await evaluate(`fill('Code', ${JSON.stringify('R' + RUN)}); fill('Nom', ${JSON.stringify(subjectName)}); byLabel('Code').closest('form').requestSubmit(); await settle(2500); return true;`);
        for (const [label, value, scale, weight] of [['Évaluation A', '14', '20', '1'], ['Évaluation B', '36', '60', '2']]) {
            await go('/notes');
            await evaluate(`const form = document.querySelector('section[aria-labelledby="add-title"] form'); fill('Intitulé', ${JSON.stringify(TAG + ' ' + label)}, form); choose('Matière', ${JSON.stringify(subjectName)}, form); fill('Date', '${today}', form); fill('Note', '${value}', form); fill('Barème', '${scale}', form); fill('Coefficient de l’évaluation', '${weight}', form); press('Ajouter la note', form); await settle(3000); return true;`);
        }
        await go('/notes');
        const block = await evaluate(`const heading = [...document.querySelectorAll('h2, h3')].find((h) => h.textContent.includes(${JSON.stringify(subjectName)})); const section = heading ? heading.closest('section') : null; return section ? norm(section.innerText).slice(0, 260) : '';`);
        note('Bloc de la matière : ' + block);

        return block.includes('12,67') ? 'moyenne de la matière affichée : 12,67' : false;
    });

    // ───────────────────────── Fichier : dépôt privé ─────────────────────────
    console.log('\n— Pièce jointe (independant) —');
    const fileTask = TAG + ' Tâche avec fichier';
    await attempt('Fichier : un PDF déposé sur une tâche est reçu, et reste non téléchargeable sans antivirus', async () => {
        await go('/taches');
        await evaluate(`const form = document.querySelector('section[aria-labelledby="create-title"] form'); fill('Titre', ${JSON.stringify(fileTask)}, form); press('Ajouter la tâche', form); await settle(3000); return true;`);
        await go('/taches');
        const pdf = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n164\n%%EOF\n').toString('base64');
        await evaluate(`const item = [...document.querySelectorAll('li strong')].find((s) => norm(s.textContent) === ${JSON.stringify(fileTask)}).closest('li'); const bytes = Uint8Array.from(atob('${pdf}'), (c) => c.charCodeAt(0)); const data = new DataTransfer(); data.items.add(new File([bytes], 'document-fictif.pdf', { type: 'application/pdf' })); const input = item.querySelector('input[type=file]'); input.files = data.files; input.dispatchEvent(new Event('change', { bubbles: true })); await settle(300); press('Déposer le fichier', item); await settle(4000); return true;`);
        await go('/taches');
        const state = await evaluate(`const item = [...document.querySelectorAll('li strong')].find((s) => norm(s.textContent) === ${JSON.stringify(fileTask)}).closest('li'); const file = [...item.querySelectorAll('li')].find((li) => li.textContent.includes('document-fictif.pdf')); return file ? [norm(file.querySelector('[role=status]')?.textContent), [...file.querySelectorAll('a')].some((a) => norm(a.textContent) === 'Télécharger'), text().includes('L’antivirus n’est pas configuré')].join('|') : 'fichier absent|' + norm(item.querySelector('.field-error')?.textContent);`);
        note('État du fichier déposé : ' + state);
        if (state.startsWith('fichier absent')) return false;
        const [status, downloadable] = state.split('|');

        return downloadable === 'false' ? 'reçu, état « ' + status + ' », aucun téléchargement proposé (antivirus non configuré)' : 'reçu, état « ' + status + ' », téléchargement proposé';
    });

    // ───────────────────────── Synchronisation : brouillon hors connexion ─────────────────────────
    console.log('\n— Brouillon hors connexion et synchronisation (independant) —');
    const offlineTask = TAG + ' Tâche hors connexion';
    await attempt('Synchronisation : tâche saisie sans réseau, gardée sur l’appareil, puis envoyée une seule fois', async () => {
        await go('/hors-ligne');
        const enabled = await evaluate(`const button = [...document.querySelectorAll('button')].find((b) => norm(b.textContent).startsWith('Cet appareil est personnel')); if (button) { button.click(); await settle(4000); } return text().includes('Appareil personnel') || [...document.querySelectorAll('button')].some((b) => norm(b.textContent).startsWith('Effacer et traiter'));`);
        note('Appareil : ' + await evaluate(`const state = Object.keys(localStorage).map((k) => { try { const v = JSON.parse(localStorage.getItem(k)); return v && v.mode ? k + ' → mode ' + v.mode + (v.accountKey ? ', compte lié' : ', aucun compte lié') : null; } catch { return null; } }).filter(Boolean).join(' ; '); return (state || 'aucun état local') + ' | cœur hors connexion : ' + (typeof self.CampusOffline) + ' | ' + [...document.querySelectorAll('.state-message, [role=status]')].map((n) => norm(n.textContent).slice(0, 90)).join(' / ');`));
        if (!enabled) return 'stockage privé non disponible dans ce navigateur : étape non exercée';
        await go('/taches');
        await send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
        let kept = false;
        try {
            await evaluate(`const form = document.querySelector('section[aria-labelledby="create-title"] form'); fill('Titre', ${JSON.stringify(offlineTask)}, form); press('Ajouter la tâche', form); await settle(2500); return true;`);
            kept = await evaluate(`return text().includes('Tâche enregistrée sur cet appareil');`);
            note('Hors connexion : navigator.onLine = ' + await evaluate(`return String(navigator.onLine) + ' ; message : ' + [...document.querySelectorAll('.state-message, [role=status], [role=alert]')].map((n) => norm(n.textContent).slice(0, 130)).filter((s) => /tâche|réseau/i.test(s)).join(' / ');`));
            note('Panneau des brouillons, encore hors connexion : ' + (await evaluate(`const panel = document.querySelector('section[aria-labelledby="outbox-title"]'); return panel ? norm(panel.innerText).slice(0, 220) : 'panneau absent';`)));
        } finally {
            await send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
        }
        await sleep(800);
        const waiting = await evaluate(`const panel = document.querySelector('section[aria-labelledby="outbox-title"]'); return panel ? norm(panel.innerText).slice(0, 200) : '';`);
        note('Brouillons de l’appareil avant l’envoi : ' + waiting);
        // Au retour du réseau, Campus envoie de lui-même ; le bouton reste disponible si l'envoi n'est pas encore parti.
        await evaluate(`await settle(4000); const panel = document.querySelector('section[aria-labelledby="outbox-title"]'); const button = panel && [...panel.querySelectorAll('button')].find((b) => norm(b.textContent).startsWith('Synchroniser maintenant') && !b.disabled); if (button) { button.click(); await settle(4000); } return true;`);
        // Second envoi demandé : il ne doit rien créer de plus.
        await evaluate(`const panel = document.querySelector('section[aria-labelledby="outbox-title"]'); const again = panel && [...panel.querySelectorAll('button')].find((b) => norm(b.textContent).startsWith('Synchroniser maintenant') && !b.disabled); if (again) { again.click(); await settle(3000); } return true;`);
        await go('/taches');
        const count = await titleCount(offlineTask);

        return kept && count === 1 ? 'gardée hors connexion, puis une seule tâche sur le serveur' : false;
    });

    // ───────────────────────── Établissement : import valide, publication, report, devoir ─────────────────────────
    console.log('\n— Établissement A (admin-a, apprenant-double, enseignant-a) —');
    const courseDay = day(3);
    const minuteOfDay = Math.floor(Date.now() / 60000) % 200;
    const first = { start: clock(480 + minuteOfDay * 2), end: clock(489 + minuteOfDay * 2) };
    const moved = { start: clock(900 + minuteOfDay * 2), end: clock(909 + minuteOfDay * 2) };
    let imported = false;
    await attempt('Import : un fichier valide est analysé puis appliqué au brouillon, sans effet sur le planning en vigueur', async () => {
        const where = await signIn('admin-a');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        const directory = mkdtempSync(join(tmpdir(), 'campus-import-'));
        const path = join(directory, 'planning-recette.xlsx');
        const rows = [['groupe', 'matiere', 'jour_ou_date', 'debut', 'fin', 'enseignant', 'salle', 'valide_du', 'valide_au', 'recurrence', 'identifiant_externe'], ['A-G1', 'METHODO', courseDay, first.start, first.end, 'enseignant-a@example.test', 'Salle recette', '', '', '', 'RECETTE-' + RUN]];
        const built = spawnSync(PHP, ['-r', 'require "vendor/autoload.php"; file_put_contents($argv[1], App\\Support\\Xlsx\\XlsxWriter::build(["Planning" => json_decode($argv[2], true)]));', path, JSON.stringify(rows)], { encoding: 'utf8' });
        if (built.status !== 0 || !existsSync(path)) { rmSync(directory, { recursive: true, force: true }); return false; }
        const workbook = readFileSync(path).toString('base64');
        rmSync(directory, { recursive: true, force: true });
        await go('/planning/import');
        await evaluate(`const bytes = Uint8Array.from(atob('${workbook}'), (c) => c.charCodeAt(0)); const data = new DataTransfer(); data.items.add(new File([bytes], 'planning-recette.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })); const input = document.getElementById('import-file'); input.files = data.files; input.dispatchEvent(new Event('change', { bubbles: true })); await settle(500); press('Déposer et analyser'); for (let i = 0; i < 40 && !document.querySelector('section[aria-labelledby="batch-title"]'); i++) await settle(250); await settle(800); return true;`);
        worker();
        await go(await evaluate(`return location.pathname + location.search;`));
        const report = await evaluate(`const state = document.querySelector('section[aria-labelledby="batch-title"] dd[role=status]'); return state ? norm(state.textContent) : 'aucun lot affiché';`);
        note('Rapport du lot : ' + report);
        await evaluate(`const lot = document.querySelector('section[aria-labelledby="batch-title"]');
            for (const select of lot.querySelectorAll('select')) { const create = [...select.options].find((o) => o.value === 'create'); if (create) setValue(select, 'create'); }
            await settle(300);
            const confirm = [...lot.querySelectorAll('label')].find((l) => norm(l.textContent).startsWith('Je confirme les'));
            if (confirm) { const box = confirm.control || confirm.querySelector('input'); if (!box.checked) box.click(); fill('Motif de la confirmation', 'Chevauchement fictif accepté pour la recette', lot); }
            await settle(400); press('Appliquer ce lot au brouillon', lot); await settle(800);
            const dialog = document.querySelector('dialog[open]'); const ok = [...dialog.querySelectorAll('button')].find((b) => norm(b.textContent) !== 'Annuler'); ok.click(); await settle(3000); return true;`);
        // L'application au brouillon est, elle aussi, un traitement en file.
        worker();
        await go(await evaluate(`return location.pathname + location.search;`));
        const after = await evaluate(`const state = document.querySelector('section[aria-labelledby="batch-title"] dd[role=status]'); return state ? norm(state.textContent) : norm(document.querySelector('.state-error')?.textContent) || 'aucun lot affiché';`);
        note('Lot après application : ' + after);
        await go(`/planning?view=day&date=${courseDay}`);
        imported = await evaluate(`return [...document.querySelectorAll('.schedule-card')].some((c) => c.textContent.includes('${first.start}'));`);

        return imported ? 'lot « ' + after + ' » ; cours du ' + courseDay + ' à ' + first.start + ' présent au brouillon' : false;
    });
    await attempt('Publication du planning importé, confirmation nommant l’établissement', async () => {
        if (!imported) return false;
        const named = await publish('Publication fictive de la recette');

        return named.includes('Établissement fictif A') ? named : false;
    });
    await attempt('Apprenant des deux établissements : le cours importé apparaît dans le contexte A, pas dans le contexte B', async () => {
        if (!imported) return false;
        const where = await signIn('apprenant-double');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go(`/planning?view=day&date=${courseDay}`);
        const inA = await evaluate(`const card = [...document.querySelectorAll('.schedule-card')].find((c) => c.textContent.includes('${first.start}')); return card ? norm(card.querySelector('.provenance')?.textContent) : '';`);
        await useContext('Établissement fictif B');
        await go(`/planning?view=day&date=${courseDay}`);
        const inB = await evaluate(`return [...document.querySelectorAll('.schedule-card')].some((c) => c.textContent.includes('${first.start}') && /salle recette/i.test(c.textContent));`);

        return inA === 'Établissement' && !inB ? 'visible en A avec la provenance « Établissement » ; absent en B' : false;
    });
    await attempt('Report d’une seule occurrence par l’administration, puis nouvelle publication', async () => {
        if (!imported) return false;
        const where = await signIn('admin-a');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go(`/planning?view=day&date=${courseDay}`);
        await evaluate(`const card = [...document.querySelectorAll('.schedule-card')].find((c) => c.textContent.includes('${first.start}')); press('Modifier / annuler', card); await settle(800); return true;`);
        const submitChange = `press('Confirmer la modification', '#occurrence-form'); for (let i = 0; i < 40 && document.querySelector('#occurrence-form') && !document.querySelector('#occurrence-form .state-error'); i++) await settle(250); await settle(500); return norm(document.querySelector('#occurrence-form .state-error')?.textContent);`;
        let refusal = await evaluate(`const form = document.querySelector('#occurrence-form'); fill('Nouveau début', '${moved.start}', form); fill('Nouvelle fin', '${moved.end}', form); fill('Motif de modification ou d’annulation', 'Report fictif de la recette', form); await settle(300); ${submitChange}`);
        if (refusal) {
            note('Refus affiché : ' + refusal.slice(0, 200));
            refusal = await evaluate(`const form = document.querySelector('#occurrence-form'); tick('Confirmer un chevauchement signalé', form); fill('Justification du chevauchement (10 caractères minimum)', 'Chevauchement fictif accepté pour la recette', form); await settle(300); ${submitChange}`);
        }
        if (refusal) return false;
        const named = await publish('Report fictif publié pour la recette');

        return named ? 'occurrence déplacée de ' + first.start + ' à ' + moved.start + ' ; ' + named : false;
    });
    await attempt('Apprenant : le cours reporté apparaît à son nouvel horaire', async () => {
        if (!imported) return false;
        const where = await signIn('apprenant-double');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go(`/planning?view=day&date=${courseDay}`);
        const seen = await evaluate(`const cards = [...document.querySelectorAll('.schedule-card')].filter((c) => /salle recette/i.test(c.textContent)); return [cards.some((c) => c.textContent.includes('${moved.start}')), cards.some((c) => c.textContent.includes('${first.start}') && !c.textContent.includes('${moved.start}')), norm(cards.find((c) => c.textContent.includes('${moved.start}'))?.innerText).slice(0, 160)].join('|');`);
        note('Carte du cours reporté : ' + seen.split('|')[2]);

        return seen.startsWith('true|false|') ? 'nouvel horaire ' + moved.start + ' ; ancien horaire absent' : false;
    });
    const homework = TAG + ' Devoir fictif';
    await attempt('Enseignant affecté : devoir publié pour son groupe', async () => {
        const where = await signIn('enseignant-a');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go('/taches/devoirs');
        await evaluate(`const section = document.querySelector('section[aria-labelledby="publish-title"]'); const select = byLabel('Groupe et matière', section); setValue(select, [...select.options].find((o) => o.value !== '').value); fill('Titre', ${JSON.stringify(homework)}, section); await settle(300); press('Publier le devoir', section); await settle(3500); return true;`);

        return await evaluate(`return norm(document.querySelector('section[aria-labelledby="list-title"]')?.innerText).includes(${JSON.stringify(homework)});`);
    });
    await attempt('Apprenant : le devoir du groupe arrive dans ses tâches et il le termine pour lui seul', async () => {
        const where = await signIn('apprenant-double');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go('/taches');
        const present = await titleCount(homework);
        if (present !== 1) return false;
        await evaluate(`const item = [...document.querySelectorAll('li strong')].find((s) => norm(s.textContent) === ${JSON.stringify(homework)}).closest('li'); press('Terminer', item); await settle(3500); return true;`);
        await go('/taches?statut=done');
        const state = await evaluate(`const strong = [...document.querySelectorAll('li strong')].find((s) => norm(s.textContent) === ${JSON.stringify(homework)}); const item = strong ? strong.closest('li') : null; return item ? [...item.querySelectorAll('button')].some((b) => norm(b.textContent) === 'Rouvrir') : 'absent';`);
        note('Devoir après « Terminer » : ' + (state === true ? 'bouton « Rouvrir » proposé' : String(state)));
        await useContext('Établissement fictif B');
        await go('/taches');
        const inB = await titleCount(homework);

        return state === true && inB === 0 ? 'terminé pour cet apprenant ; absent de son contexte B' : false;
    });
    await attempt('Administration B : ni le cours importé ni le devoir de l’établissement A ne sont visibles', async () => {
        const where = await signIn('admin-b');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif B');
        await go(`/planning?view=day&date=${courseDay}`);
        const planning = await evaluate(`return /salle recette/i.test(text()) || text().includes(${JSON.stringify(TAG)});`);
        await go('/taches');
        const tasks = await evaluate(`return text().includes(${JSON.stringify(TAG)});`);

        return !planning && !tasks ? 'aucune donnée de A chez B' : false;
    });
    check('Aucune erreur JavaScript pendant la recette', pageErrors.length === 0, pageErrors.slice(0, 3).join(' ; '));
} catch (error) {
    check('Exécution de la recette', false, String(error?.message ?? error).slice(0, 400));
} finally {
    clearTimeout(watchdog);
    try { socket?.close(); } catch { /* déjà fermé */ }
    await closeBrowser();
    stop(server);
    // Les comptes fictifs sont refermés quoi qu'il arrive : le mot de passe temporaire ne sert plus à rien.
    const locked = artisan('campus:fixtures:access', '--lock');
    console.log(locked.status === 0 ? 'Comptes fictifs refermés.' : 'ATTENTION : les comptes fictifs n’ont pas pu être refermés. Lancez : php artisan campus:fixtures:access --lock');
    await sleep(1500);
    try { rmSync(profile, { recursive: true, force: true }); } catch { /* profil encore verrouillé : dossier temporaire */ }
}
const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed} contrôle(s) réussi(s), ${failed} en échec.`);
console.log('Toutes les données créées sont fictives et préfixées. L’annonce créée ici est une fiche d’essai : elle ne fait pas partie d’un catalogue réel.');
process.exit(failed === 0 ? 0 : 1);
