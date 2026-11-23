// Contrôle réel de la PWA dans un navigateur Chromium (Edge ou Chrome) piloté sans dépendance, par le protocole de débogage.
//
// Usage : node tests/browser/pwa-edge.mjs
// Variables : CAMPUS_BROWSER (chemin du navigateur), CAMPUS_PHP (chemin de php), CAMPUS_PORT (port du serveur local, 8123 par défaut).
// Le script démarre lui-même `php artisan serve`, puis l'arrête pour simuler une coupure de réseau. Il n'utilise que des données fictives,
// écrites dans un profil de navigateur temporaire supprimé à la fin. Il ne se connecte à aucun compte.
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PORT = Number(process.env.CAMPUS_PORT ?? 8123);
const DEBUG_PORT = PORT + 1000;
const BASE = `http://127.0.0.1:${PORT}`;
const BROWSER = process.env.CAMPUS_BROWSER ?? ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'].find(existsSync);
const PHP = process.env.CAMPUS_PHP ?? 'php';
const results = [];
const check = (label, ok, detail = '') => { results.push({ label, ok, detail }); console.log(`${ok ? 'OK   ' : 'ÉCHEC'} ${label}${detail ? ' — ' + detail : ''}`); };
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitFor(url, wanted, tries = 60) {
    for (let i = 0; i < tries; i++) {
        try { await fetch(url); if (wanted) return true; } catch { if (!wanted) return true; }
        await sleep(250);
    }

    return false;
}

function serve() {
    return spawn(PHP, ['artisan', 'serve', '--host=127.0.0.1', `--port=${PORT}`, '--no-interaction'], { stdio: 'ignore' });
}

// `artisan serve` lance un processus enfant : il faut arrêter tout l'arbre, sinon le serveur continue de répondre.
function stop(child) {
    if (!child || child.exitCode !== null) return;
    if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' }); else child.kill('SIGKILL');
}

if (!BROWSER) { console.log('Aucun navigateur Chromium trouvé : contrôle non exécuté.'); process.exit(2); }
try { await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`); console.log(`Un navigateur occupe déjà le port ${DEBUG_PORT} : arrêtez-le avant de relancer.`); process.exit(2); } catch { /* port libre */ }
try { await fetch(BASE + '/up'); console.log(`Un serveur occupe déjà le port ${PORT} : arrêtez-le avant de relancer.`); process.exit(2); } catch { /* port libre */ }
const profile = mkdtempSync(join(tmpdir(), 'campus-pwa-'));
let server = serve();
const browser = spawn(BROWSER, ['--headless=new', `--remote-debugging-port=${DEBUG_PORT}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });
let socket;
try {
    if (!await waitFor(BASE + '/up', true)) throw new Error('Le serveur local ne répond pas.');
    if (!await waitFor(`http://127.0.0.1:${DEBUG_PORT}/json/version`, true)) throw new Error('Le navigateur ne répond pas.');
    const target = await (await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/new?about:blank`, { method: 'PUT' })).json();
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let next = 0;
    const pending = new Map();
    const waiters = new Map();
    socket.onmessage = (message) => {
        const data = JSON.parse(message.data);
        if (data.id && pending.has(data.id)) { pending.get(data.id)(data); pending.delete(data.id); }
        if (data.method && waiters.has(data.method)) { waiters.get(data.method)(); waiters.delete(data.method); }
    };
    const send = (method, params = {}) => new Promise((resolve) => { const id = ++next; pending.set(id, resolve); socket.send(JSON.stringify({ id, method, params })); });
    const evaluate = async (expression) => {
        const reply = await send('Runtime.evaluate', { expression: `(async () => { ${expression} })()`, awaitPromise: true, returnByValue: true });
        if (reply.result?.exceptionDetails) throw new Error(reply.result.exceptionDetails.exception?.description ?? 'Erreur dans la page');

        return reply.result?.result?.value;
    };
    const go = async (path) => {
        const loaded = new Promise((resolve) => waiters.set('Page.loadEventFired', resolve));
        await send('Page.navigate', { url: BASE + path });
        await Promise.race([loaded, sleep(15000)]);
        for (let i = 0; i < 40; i++) {
            const ready = await evaluate(`return document.querySelector('.campus-shell, #retry') !== null;`).catch(() => false);
            if (ready) break;
            await sleep(250);
        }
        await sleep(300);
    };
    await send('Page.enable');
    await send('Runtime.enable');
    const version = await (await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`)).json();
    console.log('Navigateur : ' + version.Browser);

    // 1. En ligne, sans compte : service worker, manifest, caches.
    await go('/');
    const worker = await evaluate(`const r = await Promise.race([navigator.serviceWorker.ready, new Promise((_, no) => setTimeout(() => no(new Error('service worker non prêt')), 15000))]); return r.active.scriptURL;`);
    check('Service worker enregistré et actif', worker === BASE + '/sw.js', worker);
    const manifest = await evaluate(`const link = document.querySelector('link[rel="manifest"]'); const m = await (await fetch(link.href)).json(); return [m.name, m.display, m.icons.length].join('|');`);
    check('Manifest lié à la page et lisible', manifest === 'HAKO-RA Campus|standalone|3', manifest);
    const installability = await send('Page.getInstallabilityErrors');
    const errors = (installability.result?.installabilityErrors ?? []).map((error) => error.errorId);
    check('Critères d’installation du navigateur', errors.length === 0, errors.length ? errors.join(', ') : 'aucune erreur signalée');
    await go('/opportunites');
    await go('/hors-ligne');
    const cached = await evaluate(`const out = []; for (const name of await caches.keys()) { for (const request of await (await caches.open(name)).keys()) out.push(name + ' ' + new URL(request.url).pathname); } return out;`);
    const allowed = cached.every((entry) => /^campus-shell-v\d+ \/(offline\.html|offline-core\.js|offline\.js|manifest\.webmanifest|icons\/icon-(192|512)\.png)$/.test(entry) || /^campus-static-v\d+ \/build\/assets\//.test(entry));
    check('Caches : uniquement la coque publique et les fichiers de build', allowed && cached.length >= 6, `${cached.length} entrées, aucune page`);
    check('Aucune page HTML ni réponse de données en cache', !cached.some((entry) => / \/(|opportunites|hors-ligne|planning|taches|notes)$/.test(entry)));
    const fresh = await evaluate(`return [String(localStorage.getItem('campus.offline.state')), (await indexedDB.databases()).map((d) => d.name).join(',')].join('|');`);
    check('Appareil partagé par défaut : aucun stockage privé créé', fresh === 'null|', fresh);

    // 2. Affichage mobile et zoom (R55) sur les écrans de ce lot.
    for (const [label, width, path] of [['mobile 360 px', 360, '/hors-ligne'], ['zoom 200 % (équivalent 320 px)', 320, '/hors-ligne'], ['mobile 360 px', 360, '/offline.html']]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 640, deviceScaleFactor: 2, mobile: true });
        await go(path);
        const layout = await evaluate(`const buttons = [...document.querySelectorAll('button, a.button')].filter((b) => b.getClientRects().length); return [document.documentElement.scrollWidth, window.innerWidth, buttons.length, Math.min(...buttons.map((b) => Math.round(b.getBoundingClientRect().height)))].join('|');`);
        const [scroll, inner, count, minHeight] = layout.split('|').map(Number);
        check(`${path} en ${label} : pas de défilement horizontal`, scroll <= inner, `largeur du contenu ${scroll} px pour ${inner} px`);
        // Sans compte, la page d'installation peut ne présenter aucun bouton (le navigateur ne propose l'installation qu'une fois) : seuls les boutons présents sont mesurés.
        check(`${path} en ${label} : boutons présents d’au moins 44 px de haut`, count === 0 || minHeight >= 44, count === 0 ? 'aucun bouton affiché sur cette page à ce moment' : `${count} bouton(s), plus petit : ${minHeight} px`);
    }
    await go('/offline.html');
    const focus = await evaluate(`document.getElementById('retry').focus(); return document.activeElement.id + '|' + getComputedStyle(document.activeElement).outlineStyle;`);
    check('Page hors ligne : bouton atteignable au clavier', focus.startsWith('retry|'), focus);
    await send('Emulation.clearDeviceMetricsOverride');

    // 3. Réseau coupé (serveur arrêté) : page neutre à la place de n'importe quelle page.
    stop(server);
    if (!await waitFor(BASE + '/up', false)) throw new Error('Le serveur local répond encore : coupure de réseau non simulée.');
    for (const path of ['/planning', '/notes', '/administration/etablissement']) {
        await go(path);
        const neutral = await evaluate(`return [document.title, document.getElementById('private')?.hidden, document.body.innerText.includes('Aucune donnée n’est enregistrée sur cet appareil')].join('|');`);
        check(`Réseau coupé, ${path} : page neutre sans donnée`, neutral === 'Hors connexion · HAKO-RA Campus|true|true', neutral);
    }

    // 4. Appareil personnel : une copie fictive est lue hors connexion, avec sa date et l'avertissement.
    // La copie est écrite au format du lot 19 (base en version 1, sans file de brouillons) : la page doit la faire évoluer sans rien perdre.
    const seed = (mode, daysAgo) => evaluate(`
        const at = new Date(Date.now() - ${daysAgo} * 86400000).toISOString();
        const past = new Date(Date.now() - 3 * 3600000).toISOString();
        localStorage.setItem('campus.offline.state', JSON.stringify({ mode: '${mode}', accountKey: 'compte-fictif-a', lastSyncAt: at, ttlDays: 7 }));
        await new Promise((resolve) => { const gone = indexedDB.deleteDatabase('campus-private'); gone.onsuccess = gone.onerror = gone.onblocked = resolve; });
        await new Promise((resolve, reject) => {
            const open = indexedDB.open('campus-private', 1);
            open.onupgradeneeded = () => { open.result.createObjectStore('snapshot'); open.result.createObjectStore('consulted', { keyPath: 'id' }); };
            open.onerror = () => reject(open.error);
            open.onsuccess = () => {
                const tx = open.result.transaction(['snapshot', 'consulted'], 'readwrite');
                tx.objectStore('snapshot').put({ accountKey: 'compte-fictif-a', displayName: 'Personne fictive', generatedAt: at, timezone: 'Africa/Abidjan', windowDays: 14, ttlDays: 7,
                    planning: [{ id: '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0002', title: 'Cours fictif hors ligne', startsAt: past, endsAt: past, cancelled: false, provenance: 'personal', checkIn: { version: 0, status: null } }],
                    checkIns: [],
                    tasks: [{ id: '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0001', title: 'Tâche fictive hors ligne', dueAt: null, late: false, collective: false, description: 'Description fictive', dueLocal: null, priority: 'normal', status: 'todo', version: 3, personalSubjectId: null },
                        { id: '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0003', title: 'Devoir fictif collectif', dueAt: null, late: false, collective: true, description: null, dueLocal: null, priority: 'normal', status: 'todo', version: null, personalSubjectId: null }],
                    grades: [{ label: 'Mes notes personnelles', period: 'Semestre fictif', general: '12,67', subjects: [{ name: 'Matière fictive', average: '12,67', grades: [{ title: 'Évaluation fictive', value: '14', scale: '20', status: 'graded' }] }] }],
                    opportunities: [{ id: 'x', title: 'Bourse fictive hors ligne', organizer: 'Organisme fictif', state: 'open', deadline: null }] }, 'current');
                tx.objectStore('consulted').put({ id: 'y', title: 'Fiche fictive consultée', organizer: 'Organisme fictif', deadline: null, at });
                tx.oncomplete = () => { open.result.close(); resolve(); };
            };
        });
        return true;`);
    await seed('personal', 1);
    await go('/planning');
    const offline = await evaluate(`const text = document.body.innerText; return [document.getElementById('private').hidden, text.includes('Cours fictif hors ligne'), text.includes('Tâche fictive hors ligne'), text.includes('12,67'), text.includes('Bourse fictive hors ligne'), text.includes('Fiche fictive consultée'), text.includes('Dernière synchronisation'), text.includes('potentiellement périmées'), text.includes('Notes (lecture seule)')].join('|');`);
    check('Appareil personnel hors connexion : planning, tâches, notes et opportunités lus, avec date et avertissement', offline === 'false|true|true|true|true|true|true|true|true', offline);
    const limited = await evaluate(`return [document.querySelectorAll('a, input[type=file], input[type=password], input[type=email]').length, document.querySelectorAll('#grades form, #grades input, #grades button, #planning button, #opportunities button').length, document.body.innerText.includes('Devoir fictif collectif — sans échéance — devoir de l’établissement, à modifier en ligne')].join('|');`);
    check('Hors connexion : aucun lien, aucun envoi de fichier, aucune connexion ; notes en lecture seule ; devoir d’établissement non modifiable', limited === '0|0|true', limited);

    // 4 bis. R56 — le stockage local du lot 19 (version 1) évolue vers la version 2 sans perdre la copie ; la file de brouillons est créée.
    const stores = () => evaluate(`const info = (await indexedDB.databases()).find((d) => d.name === 'campus-private'); const db = await CampusOffline.openDatabase(indexedDB); const names = [...db.objectStoreNames].join(','); db.close(); return [info?.version, names].join('|');`);
    check('R56 — base locale passée de la version 1 à la version 2, copie conservée, file créée', await stores() === '2|consulted,outbox,snapshot', await stores());
    const queue = () => evaluate(`const db = await CampusOffline.openDatabase(indexedDB); const rows = await new Promise((resolve) => { db.transaction('outbox').objectStore('outbox').getAll().onsuccess = (event) => resolve(event.target.result); }); db.close(); return rows.sort((a, b) => a.sequence - b.sequence);`);
    const drafts = () => evaluate(`return [...document.querySelectorAll('#drafts li[data-outbox-state]')].map((li) => li.getAttribute('data-outbox-state') + ':' + li.querySelector('strong').textContent + ':' + li.querySelector('.state').textContent).join(' | ');`);

    // 4 ter. Brouillons écrits sans réseau : nouvelle tâche personnelle, modification d'une tâche, retour privé sur un cours.
    await evaluate(`const form = document.querySelector('#new-task form'); const [title, description, due, priority] = form.querySelectorAll('input, textarea, select'); title.value = '  Tâche fictive écrite hors connexion  '; description.value = 'Note fictive'; due.value = '2026-11-20T18:00'; priority.value = 'high'; form.requestSubmit(); await new Promise((resolve) => setTimeout(resolve, 900)); return true;`);
    await evaluate(`[...document.querySelectorAll('#tasks button')].find((b) => b.textContent === 'Modifier').click(); await new Promise((resolve) => setTimeout(resolve, 200)); const form = document.querySelector('#tasks form'); const controls = form.querySelectorAll('input, textarea, select'); controls[0].value = 'Tâche fictive modifiée hors connexion'; controls[4].value = 'in_progress'; form.requestSubmit(); await new Promise((resolve) => setTimeout(resolve, 900)); return true;`);
    await evaluate(`[...document.querySelectorAll('#checkins button')].find((b) => b.textContent === 'Écrire mon retour').click(); await new Promise((resolve) => setTimeout(resolve, 200)); const form = document.querySelector('#checkins form'); const controls = form.querySelectorAll('textarea, select'); controls[0].value = 'Retour fictif écrit hors connexion'; controls[3].value = '3'; form.requestSubmit(); await new Promise((resolve) => setTimeout(resolve, 900)); return true;`);
    const listed = await drafts();
    check('Trois brouillons écrits hors connexion, affichés « Enregistré sur cet appareil »', listed === 'local:Nouvelle tâche personnelle — Tâche fictive écrite hors connexion:Enregistré sur cet appareil | local:Modification d’une tâche personnelle — Tâche fictive modifiée hors connexion:Enregistré sur cet appareil | local:Retour privé après cours — Cours fictif hors ligne:Enregistré sur cet appareil', listed);
    const written = await queue();
    const shape = written.map((operation) => [operation.type, operation.context, operation.accountKey, operation.state, operation.attempts, operation.fingerprint, operation.targetId, operation.baseVersion, /^[0-9a-f-]{36}$/.test(operation.id)].join(','));
    check('Chaque opération porte identifiant, compte, contexte, type et version connue ; rien n’est encore parti', JSON.stringify(shape) === JSON.stringify(['task.create,personal,compte-fictif-a,local,0,,,0,true', 'task.update,personal,compte-fictif-a,local,0,,0199aaaa-bbbb-7ccc-8ddd-eeeeffff0001,3,true', 'check_in.save,personal,compte-fictif-a,local,0,,0199aaaa-bbbb-7ccc-8ddd-eeeeffff0002,0,true']), shape.join(' ; '));
    check('Contenu nettoyé et limité aux champs attendus', JSON.stringify(written[0].payload) === JSON.stringify({ title: 'Tâche fictive écrite hors connexion', description: 'Note fictive', due_at: '2026-11-20T18:00', priority: 'high', personal_subject_id: null, source_occurrence_id: null }) && written[1].payload.status === 'in_progress' && written[2].payload.understanding === 3, JSON.stringify(written[0].payload));
    const guarded = await evaluate(`return [document.querySelector('#tasks').innerText.includes('Un brouillon de modification est enregistré'), document.querySelector('#checkins').innerText.includes('Un brouillon de retour est enregistré')].join('|');`);
    check('Un élément qui a déjà un brouillon ne propose pas un second formulaire', guarded === 'true|true', guarded);
    // Formulaire incomplet : refusé à l'écran, rien n'entre dans la file.
    const invalid = await evaluate(`const form = document.querySelector('#new-task form'); form.querySelector('input').value = '   '; form.requestSubmit(); await new Promise((resolve) => setTimeout(resolve, 600)); return document.querySelector('#new-task [role=alert]').textContent;`);
    check('Titre vide : erreur affichée, aucun brouillon ajouté', invalid === 'Le titre est obligatoire.' && (await queue()).length === 3, invalid);
    // Le brouillon survit à la fermeture et à la réouverture de la page, toujours sans réseau.
    await go('/taches');
    check('Brouillons toujours présents après rechargement hors connexion', (await drafts()) === listed, await drafts());
    // Modifier un brouillon pas encore parti le remplace, sans en créer un second.
    await evaluate(`[...document.querySelectorAll('#drafts button')].find((b) => b.textContent === 'Modifier ce brouillon').click(); await new Promise((resolve) => setTimeout(resolve, 200)); const form = document.querySelector('#drafts form'); form.querySelector('input').value = 'Tâche fictive corrigée hors connexion'; form.requestSubmit(); await new Promise((resolve) => setTimeout(resolve, 900)); return true;`);
    const replaced = await queue();
    check('Brouillon jamais envoyé : remplacé sur place', replaced.length === 3 && replaced[0].id === written[0].id && replaced[0].type === 'task.create' && replaced[0].payload.title === 'Tâche fictive corrigée hors connexion', replaced.map((operation) => operation.payload.title ?? operation.label).join(' ; '));

    // 4 quater. R55 — la page hors ligne avec ses formulaires, en mobile.
    await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 640, deviceScaleFactor: 2, mobile: true });
    await go('/taches');
    const mobile = await evaluate(`const controls = [...document.querySelectorAll('button, input, select, textarea')].filter((c) => c.getClientRects().length); const unlabelled = [...document.querySelectorAll('input, select, textarea')].filter((c) => !c.labels || c.labels.length === 0).length; return [document.documentElement.scrollWidth, window.innerWidth, Math.min(...controls.map((c) => Math.round(c.getBoundingClientRect().height))), unlabelled].join('|');`);
    const [mobileScroll, mobileInner, mobileHeight, unlabelled] = mobile.split('|').map(Number);
    check('Page hors ligne avec brouillons en 360 px : pas de défilement horizontal, contrôles d’au moins 44 px, chaque champ a son libellé', mobileScroll <= mobileInner && mobileHeight >= 44 && unlabelled === 0, mobile);
    await send('Emulation.clearDeviceMetricsOverride');

    // 4 quinquies. Copie de lecture périmée alors que des brouillons attendent : la copie est effacée, les brouillons restent.
    await evaluate(`const state = JSON.parse(localStorage.getItem('campus.offline.state')); state.lastSyncAt = new Date(Date.now() - 8 * 86400000).toISOString(); localStorage.setItem('campus.offline.state', JSON.stringify(state)); return true;`);
    await go('/notes');
    await sleep(500);
    const kept = await evaluate(`const db = await CampusOffline.openDatabase(indexedDB); const count = (name) => new Promise((resolve) => { db.transaction(name).objectStore(name).count().onsuccess = (event) => resolve(event.target.result); }); const counts = [await count('snapshot'), await count('consulted'), await count('outbox')]; db.close(); const state = JSON.parse(localStorage.getItem('campus.offline.state')); return [counts.join(','), state.mode, state.lastSyncAt, document.getElementById('copies').hidden, document.body.innerText.includes('Vos brouillons sont conservés'), document.body.innerText.includes('12,67')].join('|');`);
    check('Copie périmée avec brouillons : copie effacée, brouillons conservés et affichés', kept === '0,0,3|personal||true|true|false', kept);

    // 4 sexies. R46 — effacer l'appareil avec des brouillons : confirmation demandée ; un refus ne supprime rien.
    await evaluate(`window.confirm = (text) => { window.__asked = text; return false; }; document.getElementById('erase').click(); await new Promise((resolve) => setTimeout(resolve, 800)); return true;`);
    const refusedErase = await evaluate(`return [window.__asked, (await indexedDB.databases()).some((d) => d.name === 'campus-private'), document.querySelectorAll('#drafts li[data-outbox-state]').length].join('|');`);
    check('Effacement avec brouillons : avertissement, et refus sans aucune perte', refusedErase === '3 brouillon(s) ne sont pas enregistrés sur le serveur. Tout effacer, brouillons compris ?|true|3', refusedErase);
    await evaluate(`window.confirm = () => true; [...document.querySelectorAll('#drafts button')].find((b) => b.textContent === 'Abandonner ce brouillon').click(); await new Promise((resolve) => setTimeout(resolve, 800)); return true;`);
    check('Abandon explicite d’un brouillon : lui seul disparaît', (await queue()).length === 2, String((await queue()).length));
    await evaluate(`window.confirm = () => true; document.getElementById('erase').click(); await new Promise((resolve) => setTimeout(resolve, 1500)); return true;`).catch(() => true);
    await sleep(800);
    const wiped = await evaluate(`return [String(localStorage.getItem('campus.offline.state')), (await indexedDB.databases()).some((d) => d.name === 'campus-private'), document.getElementById('private').hidden].join('|');`);
    check('Effacement confirmé : brouillons, copie et réglages réellement supprimés', wiped === 'null|false|true', wiped);

    // 5. Copie de plus de 7 jours : effacée à l'ouverture, pas affichée.
    await seed('personal', 8);
    await go('/planning');
    await sleep(500);
    const expired = await evaluate(`return [document.getElementById('private').hidden, document.body.innerText.includes('ont été effacées'), String(localStorage.getItem('campus.offline.state')), (await indexedDB.databases()).some((d) => d.name === 'campus-private')].join('|');`);
    check('Copie non vérifiée depuis plus de 7 jours : effacée à l’ouverture', expired === 'true|true|null|false', expired);

    // 6. Appareil partagé : une copie qui traînerait est effacée et jamais affichée.
    await seed('shared', 1);
    await go('/notes');
    await sleep(500);
    const shared = await evaluate(`return [document.getElementById('private').hidden, document.body.innerText.includes('Cours fictif hors ligne'), String(localStorage.getItem('campus.offline.state')), (await indexedDB.databases()).some((d) => d.name === 'campus-private')].join('|');`);
    check('Appareil partagé : rien n’est affiché et tout reste est effacé', shared === 'true|false|null|false', shared);

    // 7. Effacement manuel depuis la page hors ligne.
    await seed('personal', 1);
    await go('/taches');
    await evaluate(`document.getElementById('erase').click(); await new Promise((resolve) => setTimeout(resolve, 1500)); return true;`).catch(() => true);
    await sleep(800);
    const erased = await evaluate(`return [document.getElementById('private').hidden, String(localStorage.getItem('campus.offline.state')), (await indexedDB.databases()).some((d) => d.name === 'campus-private')].join('|');`);
    check('Bouton « Effacer ces données de cet appareil »', erased === 'true|null|false', erased);

    // 8. Retour du réseau : l'application se recharge normalement. Un brouillon écrit hors connexion attend son propriétaire :
    //    sans compte connecté, il n'est ni envoyé ni effacé.
    await seed('personal', 1);
    await go('/taches');
    await evaluate(`const form = document.querySelector('#new-task form'); form.querySelector('input').value = 'Tâche fictive en attente du réseau'; form.requestSubmit(); await new Promise((resolve) => setTimeout(resolve, 900)); return true;`);
    server = serve();
    await waitFor(BASE + '/up', true);
    await go('/');
    const back = await evaluate(`return document.title + '|' + (document.querySelector('.campus-shell') !== null);`);
    check('Retour du réseau : page d’accueil servie par le serveur', back.endsWith('HAKO-RA Campus|true') && !back.startsWith('Hors connexion'), back);
    await go('/hors-ligne');
    await sleep(1500);
    const waiting = await evaluate(`const db = await CampusOffline.openDatabase(indexedDB); const rows = await new Promise((resolve) => { db.transaction('outbox').objectStore('outbox').getAll().onsuccess = (event) => resolve(event.target.result); }); db.close(); return [typeof CampusOffline.createOutbox, CampusOffline.DB_VERSION, rows.length, rows[0]?.state, rows[0]?.attempts, rows[0]?.payload.title].join('|');`);
    check('Application en ligne sans compte : le cœur commun est chargé, le brouillon n’est ni envoyé ni effacé', waiting === 'function|2|1|local|0|Tâche fictive en attente du réseau', waiting);
} catch (error) {
    check('Exécution du contrôle', false, String(error?.message ?? error));
} finally {
    try {
        const info = await (await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`)).json();
        const control = new WebSocket(info.webSocketDebuggerUrl);
        await new Promise((resolve) => { control.onopen = () => { control.send(JSON.stringify({ id: 1, method: 'Browser.close' })); setTimeout(resolve, 1500); }; control.onerror = resolve; });
    } catch { /* navigateur déjà fermé */ }
    try { socket?.close(); } catch { /* déjà fermé */ }
    stop(browser);
    stop(server);
    await sleep(1500);
    try { rmSync(profile, { recursive: true, force: true }); } catch { /* profil encore verrouillé : dossier temporaire */ }
}
const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed} contrôle(s) réussi(s), ${failed} en échec.`);
process.exit(failed === 0 ? 0 : 1);
