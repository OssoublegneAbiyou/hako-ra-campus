// Second facteur des comptes privilégiés de HAKO-RA Campus, sans dérogation, dans un navigateur Chromium réel (Edge ou Chrome), piloté sans dépendance par le protocole de débogage.
//
// Usage : node tests/browser/second-factor-edge.mjs
// Variables : CAMPUS_BROWSER (chemin du navigateur), CAMPUS_PHP (chemin de php), CAMPUS_PORT (port du serveur local, 8130 par défaut).
//
// Le script travaille sur la base locale de développement et ses comptes fictifs (@example.test) uniquement. Il leur donne, le temps du
// passage, un mot de passe aléatoire jamais affiché (php artisan campus:fixtures:access), puis les referme. Les données qu'il crée sont
// fictives. Le serveur temporaire lancé ici tourne SANS la dérogation CAMPUS_PRIVILEGED_WITHOUT_MFA : le rôle d'administration ne
// s'ouvre que par le second facteur. Le compte fictif est remis sans second facteur à la fin (campus:second-factor:reset).
import { spawn, spawnSync } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PORT = Number(process.env.CAMPUS_PORT ?? 8130);
const DEBUG_PORT = PORT + 1000;
const BASE = `http://127.0.0.1:${PORT}`;
const BROWSER = process.env.CAMPUS_BROWSER ?? ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'].find(existsSync);
const PHP = process.env.CAMPUS_PHP ?? 'php';
const RUN = new Date().toISOString().slice(5, 16).replace(/[-T:]/g, '');
const TAG = `[SECOND FACTEUR ${RUN}]`;
const results = [];
const check = (label, ok, detail = '') => { results.push({ label, ok, detail }); console.log(`${ok ? 'OK   ' : 'ÉCHEC'} ${label}${detail ? ' — ' + detail : ''}`); };
const note = (text) => console.log(`NOTE  ${text}`);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const artisan = (...args) => spawnSync(PHP, ['artisan', ...args, '--no-interaction'], { encoding: 'utf8' });
// Traitement en file lancé avec la même dérogation locale que le serveur temporaire : sans elle, l'analyse d'un import s'arrête, les droits d'administration n'étant pas ouverts.
const worker = () => spawnSync(PHP, ['artisan', 'queue:work', '--stop-when-empty', '--max-time=90', '--no-interaction'], { encoding: 'utf8', env: { ...process.env, CAMPUS_PRIVILEGED_WITHOUT_MFA: 'false' } });

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
// Les champs facultatifs sont repliés à l'écran : le script les déplie avant chaque geste, comme une personne qui ouvre « Plus d'options ».
if (document.querySelector('.task-toggle[aria-expanded="false"]')) { document.querySelectorAll('.task-toggle[aria-expanded="false"]').forEach((row) => row.click()); await new Promise((resolve) => setTimeout(resolve, 200)); }
document.querySelectorAll('details.more-options:not([open])').forEach((block) => { block.open = true; });
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
const server = spawn(PHP, ['artisan', 'serve', '--host=127.0.0.1', `--port=${PORT}`, '--no-interaction'], { stdio: 'ignore', env: { ...process.env, CAMPUS_PRIVILEGED_WITHOUT_MFA: 'false' } });
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
const emergency = async () => { await closeBrowser(); stop(server); artisan('campus:fixtures:access', '--lock'); console.log('Contrôles interrompus : serveur et navigateur arrêtés, comptes fictifs refermés.'); process.exit(3); };
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
        await evaluate(`const chips = [...document.querySelectorAll('.context-chips .chip')]; const chip = chips.find((c) => norm(c.title + ' ' + c.textContent).includes(${JSON.stringify(label)})); if (!chip) throw new Error('Contexte introuvable : ' + chips.map((c) => norm(c.title)).join(' | ')); chip.click(); window.__actions = (window.__actions || 0) + 1; await settle(2500); return true;`);
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

    // Code temporaire calculé par le script comme le ferait une application d'authentification (RFC 6238, SHA-1, 30 s, 6 chiffres).
    const totp = (secret, at = Date.now()) => {
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        const bits = [...secret.replace(/\s/g, '')].map((c) => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
        const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
        const counter = Buffer.alloc(8);
        counter.writeBigUInt64BE(BigInt(Math.floor(at / 30000)));
        const hash = createHmac('sha1', key).update(counter).digest();
        const offset = hash[19] & 0x0f;

        return String((hash.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
    };
    // Un code ne sert qu'une fois : avant chaque nouvelle saisie, le script attend le pas de 30 secondes suivant.
    const nextStep = async () => { await sleep(30000 - (Date.now() % 30000) + 1500); };
    const reset = () => artisan('campus:second-factor:reset', 'admin-a@example.test', '--reason=Remise à zéro du compte fictif par le script de recette');

    console.log('\n— Second facteur (admin-a, sans aucune dérogation) —');
    // État de départ propre : aucun second facteur sur le compte fictif.
    reset();
    let secret = '';
    await attempt('Sans second facteur : connexion possible, écrans d’administration fermés, activation proposée', async () => {
        const where = await signIn('admin-a');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go('/administration/etablissement');
        const closed = await evaluate(`return text().includes('Accès interdit');`);
        await go('/profil');
        const offered = await evaluate(`return [text().includes('Actions privilégiées indisponibles'), [...document.querySelectorAll('a')].some((a) => a.getAttribute('href') === '/profil/second-facteur')].join('|');`);

        return closed && offered === 'true|true' ? 'administration fermée ; lien d’activation présent sur le profil' : false;
    });
    await attempt('Activation : mot de passe redemandé, clé affichée, code exact exigé', async () => {
        await go('/profil/second-facteur');
        const start = `press('Activer le second facteur'); await settle(3000); return location.pathname;`;
        if (await evaluate(start) === '/confirmation-mot-de-passe') {
            await evaluate(`fill('Mot de passe', ${JSON.stringify(password)}); press('Confirmer'); await settle(3000); return true;`);
            await go('/profil/second-facteur');
            if (await evaluate(`return [...document.querySelectorAll('button')].some((b) => norm(b.textContent) === 'Activer le second facteur');`)) await evaluate(start);
        }
        secret = await evaluate(`return norm(document.querySelector('section[aria-labelledby="setup-title"] code')?.textContent).replace(/ /g, '');`);
        if (!/^[A-Z2-7]{32}$/.test(secret)) { secret = ''; return false; }
        await evaluate(`fill('Code à six chiffres', '000000'); press('Confirmer et activer'); await settle(3000); return true;`);
        const refused = await evaluate(`return document.querySelectorAll('.field-error, [aria-invalid=true]').length > 0 && document.querySelector('section[aria-labelledby="setup-title"]') !== null;`);
        await evaluate(`fill('Code à six chiffres', ${JSON.stringify(totp(secret))}); press('Confirmer et activer'); await settle(3500); return true;`);
        const done = await evaluate(`return [document.querySelector('section[aria-labelledby="state-title"]') !== null, document.querySelectorAll('section[aria-labelledby="recovery-title"] code').length, document.querySelector('section[aria-labelledby="setup-title"]') === null].join('|');`);

        return refused && done === 'true|8|true' ? 'code faux refusé ; code exact accepté ; 8 codes de secours affichés une fois (non reproduits ici)' : false;
    });
    await attempt('Session vérifiée : l’écran d’administration de l’établissement s’ouvre', async () => {
        if (!secret) return false;
        await go('/administration/etablissement');

        return await evaluate(`return !text().includes('Accès interdit') && document.querySelector('section[aria-labelledby="invite-title"]') !== null;`);
    });
    await attempt('Nouvelle connexion : après le mot de passe, tout renvoie à la saisie du code', async () => {
        if (!secret) return false;
        await signIn('admin-a');
        const first = await evaluate(`return location.pathname;`);
        await go('/administration/etablissement');
        const second = await evaluate(`return [location.pathname, document.querySelector('.context-chips') === null, text().includes('Établissement fictif A')].join('|');`);

        return first === '/second-facteur' && second === '/second-facteur|true|false' ? 'renvoi vers /second-facteur ; aucun contexte ni établissement affiché' : false;
    });
    await attempt('Code faux refusé, code déjà utilisé refusé, code suivant accepté', async () => {
        if (!secret) return false;
        await go('/second-facteur');
        await evaluate(`fill('Code à six chiffres, ou code de secours', '123456'); press('Vérifier'); await settle(2500); return true;`);
        const wrong = await evaluate(`return location.pathname === '/second-facteur' && document.querySelectorAll('.field-error, [aria-invalid=true]').length > 0;`);
        await nextStep();
        const code = totp(secret);
        await evaluate(`fill('Code à six chiffres, ou code de secours', ${JSON.stringify(code)}); press('Vérifier'); await settle(3500); return true;`);
        const accepted = await evaluate(`return location.pathname !== '/second-facteur';`);
        await useContext('Établissement fictif A');
        await go('/administration/etablissement');
        const open = await evaluate(`return document.querySelector('section[aria-labelledby="invite-title"]') !== null;`);
        // Le même code, rejoué depuis une autre connexion dans le même pas de temps, ne passe pas.
        await signIn('admin-a');
        await go('/second-facteur');
        await evaluate(`fill('Code à six chiffres, ou code de secours', ${JSON.stringify(code)}); press('Vérifier'); await settle(2500); return true;`);
        const replay = await evaluate(`return location.pathname === '/second-facteur';`);

        return wrong && accepted && open && replay ? 'faux refusé ; exact accepté et administration ouverte ; rejeu refusé' : [wrong, accepted, open, replay].join('|') === 'true|true|true|true';
    });
    await attempt('Récupération contrôlée : retrait par l’exploitation, tracé ; le rôle reste fermé sans nouveau facteur', async () => {
        if (!secret) return false;
        const done = reset();
        await signIn('admin-a');
        await useContext('Établissement fictif A');
        await go('/administration/etablissement');
        const closed = await evaluate(`return text().includes('Accès interdit');`);

        return done.status === 0 && closed ? 'second facteur retiré par la commande ; administration de nouveau fermée' : false;
    });
    check('Aucune erreur JavaScript pendant les contrôles', pageErrors.length === 0, pageErrors.slice(0, 3).join(' ; '));
} catch (error) {
    check('Exécution des contrôles', false, String(error?.message ?? error).slice(0, 400));
} finally {
    clearTimeout(watchdog);
    try { socket?.close(); } catch { /* déjà fermé */ }
    await closeBrowser();
    stop(server);
    // Le compte fictif repart sans second facteur, et les comptes fictifs sont refermés quoi qu'il arrive.
    artisan('campus:second-factor:reset', 'admin-a@example.test', '--reason=Remise à zéro du compte fictif par le script de recette');
    const locked = artisan('campus:fixtures:access', '--lock');
    console.log(locked.status === 0 ? 'Comptes fictifs refermés.' : 'ATTENTION : les comptes fictifs n’ont pas pu être refermés. Lancez : php artisan campus:fixtures:access --lock');
    await sleep(1500);
    try { rmSync(profile, { recursive: true, force: true }); } catch { /* profil encore verrouillé : dossier temporaire */ }
}
const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed} contrôle(s) réussi(s), ${failed} en échec.`);
console.log('Le serveur de ce passage tournait sans dérogation : le rôle d’administration n’a été ouvert que par le second facteur.');
process.exit(failed === 0 ? 0 : 1);
