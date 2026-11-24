// Contrôles d'accessibilité, de temps de chargement et d'injection dans un navigateur Chromium réel (Edge ou Chrome), sans dépendance.
//
// Usage : node tests/browser/audit-edge.mjs
// Variables : CAMPUS_BROWSER, CAMPUS_PHP, CAMPUS_PORT (8125 par défaut).
//
// Le script travaille sur la base locale de développement et ses comptes fictifs, ouverts le temps du passage avec un mot de passe
// aléatoire jamais affiché, puis refermés. Ce sont des contrôles automatiques : ils ne valent pas un audit d'accessibilité ni une
// conformité WCAG, et le temps de chargement est mesuré sur ce poste, serveur local, réseau bridé par le navigateur.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createServer, request as httpRequest } from 'node:http';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const PORT = Number(process.env.CAMPUS_PORT ?? 8125);
const DEBUG_PORT = PORT + 1000;
const BASE = `http://127.0.0.1:${PORT}`;
// Second accès au même serveur, à travers un relais local qui compresse les réponses (gzip), comme le fera un serveur web de production.
// Le serveur de développement de PHP ne compresse rien : sans ce relais, la mesure ne dirait rien du cas réel.
const COMPRESSED_PORT = PORT + 2;
const COMPRESSED = `http://127.0.0.1:${COMPRESSED_PORT}`;
const BROWSER = process.env.CAMPUS_BROWSER ?? ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'].find(existsSync);
const PHP = process.env.CAMPUS_PHP ?? 'php';
const results = [];
const check = (label, ok, detail = '') => { results.push({ label, ok, detail }); console.log(`${ok ? 'OK   ' : 'ÉCHEC'} ${label}${detail ? ' — ' + detail : ''}`); };
const note = (text) => console.log(`NOTE  ${text}`);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const artisan = (...args) => spawnSync(PHP, ['artisan', ...args, '--no-interaction'], { encoding: 'utf8' });

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

// Le serveur temporaire est lancé sans le journal de navigateur de Laravel Boost : cet outil de développement injecte un script en ligne,
// que la politique de contenu refuse à juste titre et qui n'existe pas hors du poste de développement.
// Fonctions exécutées dans la page.
const PRELUDE = `
// Les champs facultatifs sont repliés à l'écran : le script les déplie avant chaque geste, comme une personne qui ouvre « Plus d'options ».
if (document.querySelector('.task-toggle[aria-expanded="false"]')) { document.querySelectorAll('.task-toggle[aria-expanded="false"]').forEach((row) => row.click()); await new Promise((resolve) => setTimeout(resolve, 200)); }
document.querySelectorAll('details.more-options:not([open])').forEach((block) => { block.open = true; });
const norm = (s) => (s || '').replace(/\\s+/g, ' ').trim();
const visible = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
const setValue = (el, value) => { const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : el instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, value); el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
const byLabel = (text) => { const label = [...document.querySelectorAll('label')].filter(visible).find((l) => norm(l.textContent).startsWith(text)); if (!label) throw new Error('Champ introuvable : ' + text); return label.control || document.getElementById(label.htmlFor); };
const settle = (ms = 1500) => new Promise((resolve) => setTimeout(resolve, ms));
const text = () => norm(document.body.innerText);
const parse = (color) => { const m = color.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const p = m[1].split(/[,\\/ ]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
const luminance = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
const background = (el) => { let color = { r: 255, g: 255, b: 255, a: 1 }; const chain = []; for (let node = el; node && node.nodeType === 1; node = node.parentElement) chain.unshift(node); for (const node of chain) { const s = getComputedStyle(node); if (s.backgroundImage !== 'none') return null; const c = parse(s.backgroundColor); if (c && c.a > 0) color = over(c, color); } return color; };
const accessibleName = (el) => norm(el.getAttribute('aria-label') || (el.getAttribute('aria-labelledby') ? document.getElementById(el.getAttribute('aria-labelledby'))?.textContent : '') || el.textContent || el.getAttribute('title') || el.querySelector('img')?.alt || '');
// Relevé d'accessibilité de la page courante. Limites : les fonds en image ou en dégradé ne sont pas mesurés, l'opacité héritée non plus.
const audit = () => {
    const controls = [...document.querySelectorAll('input:not([type=hidden]), select, textarea')].filter(visible);
    const unlabelled = controls.filter((c) => !(c.labels && c.labels.length) && !c.getAttribute('aria-label') && !c.getAttribute('aria-labelledby')).map((c) => c.name || c.id || c.type);
    const actions = [...document.querySelectorAll('button, a[href]')].filter(visible);
    const unnamed = actions.filter((a) => accessibleName(a) === '').length;
    const small = [...document.querySelectorAll('button, a.button, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea')].filter(visible).filter((b) => b.getBoundingClientRect().height < 43.5).map((b) => norm(b.textContent || b.name || b.type).slice(0, 24) + ':' + Math.round(b.getBoundingClientRect().height));
    const low = [];
    let measured = 0;
    for (const el of document.querySelectorAll('h1, h2, h3, p, li, a, button, label, span, dt, dd, th, td, summary, legend, time, strong, option')) {
        if (!visible(el) || ![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== '')) continue;
        const style = getComputedStyle(el);
        const fg = parse(style.color); const bg = background(el);
        if (!fg || !bg) continue;
        measured++;
        const color = over(fg, bg);
        const [l1, l2] = [luminance(color), luminance(bg)].sort((a, b) => b - a);
        const ratio = (l1 + 0.05) / (l2 + 0.05);
        const size = parseFloat(style.fontSize); const bold = Number(style.fontWeight) >= 700;
        const needed = size >= 24 || (bold && size >= 18.66) ? 3 : 4.5;
        if (ratio + 0.005 < needed) low.push((el.className ? '.' + String(el.className).split(' ')[0] : el.tagName.toLowerCase()) + ' ' + ratio.toFixed(2) + ' « ' + norm(el.textContent).slice(0, 20) + ' »');
    }
    const headings = [...document.querySelectorAll('h1, h2, h3, h4')].filter(visible).map((h) => Number(h.tagName[1]));
    const jumps = headings.filter((level, index) => index > 0 && level > headings[index - 1] + 1).length;
    return {
        scroll: document.documentElement.scrollWidth - window.innerWidth, unlabelled, unnamed, small, low: [...new Set(low)], measured,
        h1: document.querySelectorAll('h1').length, jumps, lang: document.documentElement.lang, main: document.querySelectorAll('main').length, title: document.title !== '',
        images: [...document.querySelectorAll('img')].filter((i) => !i.hasAttribute('alt')).length,
    };
};
`;

if (!BROWSER) { console.log('Aucun navigateur Chromium trouvé : contrôles non exécutés.'); process.exit(2); }
try { await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`); console.log(`Un navigateur occupe déjà le port ${DEBUG_PORT} : arrêtez-le avant de relancer.`); process.exit(2); } catch { /* port libre */ }
try { await fetch(BASE + '/up'); console.log(`Un serveur occupe déjà le port ${PORT} : arrêtez-le avant de relancer.`); process.exit(2); } catch { /* port libre */ }

const secretDirectory = mkdtempSync(join(tmpdir(), 'campus-acces-'));
const secretFile = join(secretDirectory, 'mot-de-passe');
const password = randomBytes(24).toString('base64url');
writeFileSync(secretFile, password, { mode: 0o600 });
const opened = artisan('campus:fixtures:access', `--password-file=${secretFile}`);
rmSync(secretDirectory, { recursive: true, force: true });
if (opened.status !== 0) { console.log('Accès aux comptes fictifs impossible : ' + (opened.stdout + opened.stderr).trim()); process.exit(2); }

const profile = mkdtempSync(join(tmpdir(), 'campus-audit-'));
const server = spawn(PHP, ['artisan', 'serve', '--host=127.0.0.1', `--port=${PORT}`, '--no-interaction'], { stdio: 'ignore', env: { ...process.env, CAMPUS_PRIVILEGED_WITHOUT_MFA: 'true', BOOST_BROWSER_LOGS_WATCHER: 'false' } });
const browser = spawn(BROWSER, ['--headless=new', `--remote-debugging-port=${DEBUG_PORT}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
// Relais de compression : il transmet la requête telle quelle au serveur local et compresse la réponse si le navigateur l'accepte.
const relay = createServer((incoming, outgoing) => {
    const upstream = httpRequest({ host: '127.0.0.1', port: PORT, path: incoming.url, method: incoming.method, headers: incoming.headers }, (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('end', () => {
            const body = Buffer.concat(chunks);
            const headers = { ...response.headers };
            const text = /text|javascript|json|xml|manifest/.test(String(headers['content-type'] ?? ''));
            if (text && body.length > 512 && /gzip/.test(String(incoming.headers['accept-encoding'] ?? ''))) {
                const packed = gzipSync(body);
                delete headers['content-length'];
                outgoing.writeHead(response.statusCode ?? 200, { ...headers, 'content-encoding': 'gzip', 'content-length': packed.length });
                outgoing.end(packed);
            } else {
                outgoing.writeHead(response.statusCode ?? 200, headers);
                outgoing.end(body);
            }
        });
    });
    upstream.on('error', () => { outgoing.writeHead(502); outgoing.end(); });
    incoming.pipe(upstream);
}).listen(COMPRESSED_PORT, '127.0.0.1');
let socket;
const closeBrowser = async () => {
    try {
        const info = await (await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`)).json();
        const control = new WebSocket(info.webSocketDebuggerUrl);
        await new Promise((resolve) => { control.onopen = () => { control.send(JSON.stringify({ id: 1, method: 'Browser.close' })); setTimeout(resolve, 1500); }; control.onerror = resolve; setTimeout(resolve, 4000); });
    } catch { /* navigateur déjà fermé */ }
    stop(browser);
    if (process.platform === 'win32') spawnSync('powershell', ['-NoProfile', '-Command', `Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'msedge.exe' -and $_.CommandLine -like '*${profile.split(/[\\/]/).pop()}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`], { stdio: 'ignore' });
};
const emergency = async () => { relay.close(); await closeBrowser(); stop(server); artisan('campus:fixtures:access', '--lock'); console.log('Contrôles interrompus : serveur et navigateur arrêtés, comptes fictifs refermés.'); process.exit(3); };
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
    const policyViolations = [];
    let transferred = 0;
    socket.onmessage = (message) => {
        const data = JSON.parse(message.data);
        if (data.id && pending.has(data.id)) { pending.get(data.id)(data); pending.delete(data.id); }
        if (data.method === 'Page.javascriptDialogOpening') void send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
        if (data.method === 'Runtime.exceptionThrown') pageErrors.push(data.params.exceptionDetails.exception?.description?.split('\n')[0] ?? data.params.exceptionDetails.text);
        if (data.method === 'Log.entryAdded' && /Content Security Policy|Refused to/i.test(data.params.entry.text)) policyViolations.push(data.params.entry.text.slice(0, 110) + ' @ ' + String(data.params.entry.url ?? '').replace(BASE, '') + ':' + (data.params.entry.lineNumber ?? '?'));
        if (data.method === 'Network.loadingFinished') transferred += data.params.encodedDataLength;
        if (data.method && waiters.has(data.method)) { waiters.get(data.method)(); waiters.delete(data.method); }
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++next;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error('Le navigateur n’a pas répondu à ' + method)); }, 40000);
        pending.set(id, (data) => { clearTimeout(timer); resolve(data); });
        socket.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async (expression) => {
        const reply = await send('Runtime.evaluate', { expression: `(async () => { ${PRELUDE} ${expression} })()`, awaitPromise: true, returnByValue: true });
        if (reply.result?.exceptionDetails) throw new Error((reply.result.exceptionDetails.exception?.description ?? 'Erreur dans la page').split('\n')[0]);

        return reply.result?.result?.value;
    };
    const go = async (path, settleMs = 500) => {
        const loaded = new Promise((resolve) => waiters.set('Page.loadEventFired', resolve));
        await send('Page.navigate', { url: BASE + path });
        await Promise.race([loaded, sleep(30000)]);
        for (let i = 0; i < 60; i++) {
            if (await evaluate(`return document.querySelector('h1') !== null;`).catch(() => false)) break;
            await sleep(250);
        }
        await sleep(settleMs);
    };
    const attempt = async (label, action) => {
        try { const detail = await action(); check(label, detail !== false, typeof detail === 'string' ? detail : ''); return detail !== false; } catch (error) { check(label, false, String(error?.message ?? error).slice(0, 400)); return false; }
    };
    const signIn = async (name) => {
        await send('Network.clearBrowserCookies');
        await go('/connexion');
        await evaluate(`setValue(byLabel('Adresse électronique ou identifiant'), ${JSON.stringify(name + '@example.test')}); setValue(byLabel('Mot de passe'), ${JSON.stringify(password)}); [...document.querySelectorAll('button')].find((b) => norm(b.textContent).startsWith('Se connecter')).click(); await settle(2500); return true;`);

        return evaluate(`return location.pathname !== '/connexion' && document.querySelector('.campus-shell') !== null;`);
    };
    const viewport = (width) => width === null ? send('Emulation.clearDeviceMetricsOverride') : send('Emulation.setDeviceMetricsOverride', { width, height: 800, deviceScaleFactor: 2, mobile: width < 600 });
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    await send('Log.enable');
    const version = await (await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/version`)).json();
    console.log('Navigateur : ' + version.Browser);

    // ───────────── 1. Accessibilité : relevé automatique par écran, à trois largeurs ─────────────
    const worst = { low: new Map(), small: new Map() };
    const sweep = async (who, pages) => {
        for (const path of pages) {
            for (const [label, width] of [['ordinateur 1280 px', null], ['mobile 360 px', 360], ['zoom 200 % (équivalent 320 px)', 320]]) {
                await viewport(width);
                await go(path, 300);
                const report = await evaluate(`return audit();`);
                const problems = [];
                if (report.scroll > 0) problems.push(`défilement horizontal de ${report.scroll} px`);
                if (report.unlabelled.length) problems.push(`${report.unlabelled.length} champ(s) sans libellé : ${report.unlabelled.slice(0, 3).join(', ')}`);
                if (report.unnamed) problems.push(`${report.unnamed} bouton(s) ou lien(s) sans nom`);
                if (report.images) problems.push(`${report.images} image(s) sans texte de remplacement`);
                if (report.h1 !== 1) problems.push(`${report.h1} titre(s) de niveau 1`);
                if (report.jumps) problems.push(`${report.jumps} saut(s) de niveau de titre`);
                if (report.lang !== 'fr' || !report.title || report.main !== 1) problems.push('langue, titre de page ou zone principale manquant');
                report.low.forEach((item) => worst.low.set(item, `${who} ${path}`));
                if (width === 360) report.small.forEach((item) => worst.small.set(item, `${who} ${path}`));
                check(`${who} ${path} — ${label} : structure, libellés, noms, défilement`, problems.length === 0, problems.length ? problems.join(' ; ') : `${report.measured} textes mesurés`);
            }
        }
        await viewport(null);
    };
    console.log('\n— Accessibilité : visiteur —');
    await sweep('Visiteur', ['/', '/connexion', '/inscription', '/opportunites', '/aide', '/confidentialite', '/hors-ligne']);
    await attempt('Connexion du compte indépendant', async () => await signIn('independant'));
    console.log('\n— Accessibilité : compte indépendant —');
    await sweep('Indépendant', ['/', '/taches', '/planning', '/notes', '/retours', '/profil', '/notifications', '/notifications/preferences', '/opportunites/profil', '/confidentialite/parametres', '/etudes/referentiels']);

    check('Contraste des textes (4,5:1, ou 3:1 pour les grands textes), tous écrans relevés', worst.low.size === 0, worst.low.size ? `${worst.low.size} cas : ` + [...worst.low.entries()].slice(0, 8).map(([item, where]) => `${item} (${where})`).join(' | ') : 'aucun texte sous le seuil parmi ceux mesurés');
    check('Taille des boutons et champs à 360 px (44 px de haut au moins)', worst.small.size === 0, worst.small.size ? `${worst.small.size} cas : ` + [...worst.small.entries()].slice(0, 8).map(([item, where]) => `${item} (${where})`).join(' | ') : 'aucun contrôle sous 44 px');

    // ───────────── 2. Clavier ─────────────
    console.log('\n— Clavier, erreurs, animations —');
    await attempt('Clavier : lien d’évitement en premier, focus visible, parcours sans piège jusqu’au formulaire des tâches', async () => {
        await go('/taches');
        const trail = [];
        for (let i = 0; i < 45; i++) {
            await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
            await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
            trail.push(await evaluate(`const el = document.activeElement; const s = getComputedStyle(el); return [el.tagName.toLowerCase() + (el.type ? ':' + el.type : ''), norm(el.textContent || el.getAttribute('aria-label') || el.labels?.[0]?.textContent || el.name || '').slice(0, 24), (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || s.boxShadow !== 'none', el.closest('main') !== null].join('|');`));
        }
        const first = trail[0].split('|');
        const distinct = new Set(trail).size;
        const invisible = trail.filter((entry) => entry.split('|')[2] !== 'true');
        const reachedForm = trail.some((entry) => entry.startsWith('input:text|') && entry.endsWith('|true'));
        note(`Parcours au clavier : ${distinct} éléments distincts sur 45 tabulations ; premier : « ${first[1]} »`);

        if (first[1].startsWith('Aller au contenu') && distinct >= 20 && invisible.length === 0 && reachedForm) return `${distinct} éléments, focus toujours visible`;
        note(`Clavier : premier « ${first[1]} », ${distinct} éléments, ${invisible.length} sans focus visible (${[...new Set(invisible)].slice(0, 4).join(' ; ')}), formulaire atteint : ${reachedForm}`);

        return false;
    });
    await attempt('Clavier : une boîte de confirmation garde le focus et se ferme avec Échap', async () => {
        await go('/taches');
        const opened = await evaluate(`const button = [...document.querySelectorAll('button')].find((b) => norm(b.textContent) === 'Supprimer'); if (!button) return 'aucune tâche'; button.focus(); button.click(); await settle(500); const dialog = document.querySelector('dialog[open]'); return dialog ? String(dialog.contains(document.activeElement)) : 'pas de dialogue';`);
        if (opened === 'aucune tâche') return 'aucune tâche à supprimer dans le jeu fictif : non exercé';
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await sleep(400);
        const closed = await evaluate(`return document.querySelector('dialog[open]') === null;`);

        if (opened === 'true' && closed) return 'focus dans le dialogue, fermé par Échap';
        note(`Dialogue : focus dedans ${opened} ; fermé par Échap ${closed}`);

        return false;
    });
    await attempt('Erreur de saisie : annoncée, rattachée à son champ, saisie conservée', async () => {
        await go('/profil');
        const state = await evaluate(`const input = byLabel('Nom'); const before = input.value; setValue(input, ''); input.closest('form').requestSubmit(); await settle(2500); const field = byLabel('Nom'); const described = (field.getAttribute('aria-describedby') || '').split(' ').map((id) => document.getElementById(id)).filter(Boolean).map((el) => norm(el.textContent)).join(' '); setValue(field, before); return [field.getAttribute('aria-invalid'), described !== '', field.validity.valid ? 'validation du serveur' : 'validation du navigateur'].join('|');`);

        return /^true\|true\|/.test(state) || state.endsWith('validation du navigateur') ? state : false;
    });
    await attempt('Animations réduites sur demande du système', async () => {
        await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
        await go('/taches');
        const moving = await evaluate(`return [...document.querySelectorAll('*')].filter((el) => { const s = getComputedStyle(el); return (s.animationName !== 'none' && parseFloat(s.animationDuration) > 0) || s.transitionDuration.split(',').some((d) => parseFloat(d) > 0); }).length;`);
        await send('Emulation.setEmulatedMedia', { features: [] });

        if (moving === 0) return 'aucune animation ni transition active';
        note(`${moving} élément(s) encore animés malgré la demande de réduction`);

        return false;
    });

    // ───────────── 3. Contenu saisi : aucune exécution, aucune page cassée ─────────────
    console.log('\n— Contenu saisi et politique de contenu —');
    await attempt('Balises et séquence de rupture saisies dans une tâche : affichées comme du texte, page intacte, rien d’exécuté', async () => {
        const markup = '<img src=x onerror="window.__xss=1"><script>window.__xss=1</script> x<!--<script>y';
        await go('/taches');
        await evaluate(`const form = document.querySelector('section[aria-labelledby="create-title"] form'); setValue(form.querySelector('input'), ${JSON.stringify('[AUDIT] ' + markup)}); form.requestSubmit(); await settle(3000); return true;`);
        const seen = [];
        for (const path of ['/taches', '/']) {
            await go(path);
            // Sur l'accueil la tâche peut ne pas figurer parmi les cinq plus proches : seule la page des tâches doit montrer le texte tel quel.
            seen.push(await evaluate(`return [document.querySelector('.campus-shell') !== null, ${path === '/taches' ? `text().includes(${JSON.stringify('[AUDIT] ' + markup)})` : 'true'}, typeof window.__xss, document.querySelectorAll('img[src="x"]').length].join('|');`));
        }
        // Remise en état : la tâche d'essai part à la corbeille.
        await go('/taches');
        await evaluate(`const item = [...document.querySelectorAll('li')].find((li) => li.querySelector('strong')?.textContent.startsWith('[AUDIT] ')); if (item) { [...item.querySelectorAll('button')].find((b) => norm(b.textContent) === 'Supprimer').click(); await settle(500); [...document.querySelectorAll('dialog[open] button')].at(-1).click(); await settle(2500); } return true;`);

        if (seen.every((entry) => entry === 'true|true|undefined|0')) return 'texte affiché tel quel, aucune image ni script créé';
        note('Contenu saisi : ' + seen.join(' ; '));

        return false;
    });

    // ───────────── 4. Temps de chargement sur réseau bridé ─────────────
    console.log('\n— Temps de chargement : 1,6 Mbit/s descendant, 150 ms de latence —');
    const throttle = (on) => send('Network.emulateNetworkConditions', on ? { offline: false, latency: 150, downloadThroughput: 200000, uploadThroughput: 93750 } : { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    const timed = async (label, path, cold, base = BASE, mode = 'sans compression') => {
        await send('Network.setCacheDisabled', { cacheDisabled: cold });
        await send('Network.setBypassServiceWorker', { bypass: cold });
        transferred = 0;
        const started = Date.now();
        const loaded = new Promise((resolve) => waiters.set('Page.loadEventFired', resolve));
        await send('Page.navigate', { url: base + path });
        let shown = null;
        for (let i = 0; i < 400 && shown === null; i++) {
            // Contenu principal : le titre de l'écran et sa zone principale sont affichés.
            if (await evaluate(`return document.querySelector('main h1, #contenu h1') !== null && document.querySelector('main') !== null;`).catch(() => false)) shown = Date.now() - started;
            else await sleep(50);
        }
        await Promise.race([loaded, sleep(20000)]);
        await sleep(300);
        const ok = shown !== null && shown < 3000;
        // Sans compression, la mesure décrit le serveur de développement, pas un déploiement : elle est relevée, sans valoir réussite ni échec.
        if (cold && mode === 'sans compression') { note(`${label} ${path} — première visite sans cache et sans compression (serveur de développement) : ${shown === null ? 'non affiché' : (shown / 1000).toFixed(2) + ' s'}, ${Math.round(transferred / 1024)} Ko${ok ? '' : ' — au-delà de 3 s : la compression du serveur web est nécessaire'}`); return; }
        check(`${label} ${path} — ${cold ? 'première visite, sans cache' : 'visite suivante, avec cache'}, ${mode} : contenu principal en moins de 3 s`, ok, shown === null ? 'contenu non affiché en 20 s' : `${(shown / 1000).toFixed(2)} s, ${Math.round(transferred / 1024)} Ko transférés`);
    };
    await throttle(true);
    for (const path of ['/', '/taches', '/planning', '/notes']) {
        await timed('Indépendant', path, true, COMPRESSED, 'réponses compressées');
        await timed('Indépendant', path, true);
        await timed('Indépendant', path, false);
    }
    await send('Network.clearBrowserCookies');
    for (const path of ['/', '/connexion', '/opportunites']) {
        await timed('Visiteur', path, true, COMPRESSED, 'réponses compressées');
        await timed('Visiteur', path, true);
        await timed('Visiteur', path, false);
    }
    await throttle(false);
    await send('Network.setCacheDisabled', { cacheDisabled: false });
    await send('Network.setBypassServiceWorker', { bypass: false });

    check('Politique de contenu : aucun script, style ou appel du site refusé par le navigateur', policyViolations.length === 0, policyViolations.slice(0, 3).join(' | '));
    check('Aucune erreur JavaScript pendant les contrôles', pageErrors.length === 0, pageErrors.slice(0, 3).join(' ; '));
} catch (error) {
    check('Exécution des contrôles', false, String(error?.message ?? error).slice(0, 400));
} finally {
    clearTimeout(watchdog);
    relay.close();
    try { socket?.close(); } catch { /* déjà fermé */ }
    await closeBrowser();
    stop(server);
    const locked = artisan('campus:fixtures:access', '--lock');
    console.log(locked.status === 0 ? 'Comptes fictifs refermés.' : 'ATTENTION : les comptes fictifs n’ont pas pu être refermés. Lancez : php artisan campus:fixtures:access --lock');
    await sleep(1500);
    try { rmSync(profile, { recursive: true, force: true }); } catch { /* profil encore verrouillé : dossier temporaire */ }
}
const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed} contrôle(s) réussi(s), ${failed} en échec.`);
console.log('Limites : contrôles automatiques dans un seul navigateur, sur un poste de développement. Ils ne valent ni audit d’accessibilité, ni conformité WCAG, ni mesure de charge.');
process.exit(failed === 0 ? 0 : 1);
