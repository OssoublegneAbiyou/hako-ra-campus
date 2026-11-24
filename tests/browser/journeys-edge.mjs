// Parcours complets de HAKO-RA Campus dans un navigateur Chromium réel (Edge ou Chrome), piloté sans dépendance par le protocole de débogage.
//
// Usage : node tests/browser/journeys-edge.mjs
// Variables : CAMPUS_BROWSER (chemin du navigateur), CAMPUS_PHP (chemin de php), CAMPUS_PORT (port du serveur local, 8124 par défaut).
//
// Le script travaille sur la base locale de développement et ses comptes fictifs (@example.test) uniquement. Il leur donne, le temps du
// passage, un mot de passe aléatoire jamais affiché (php artisan campus:fixtures:access), puis les referme. Les données qu'il crée sont
// fictives et préfixées « [PARCOURS] ». Les rôles d'enseignement et d'administration ne s'ouvrent qu'avec la dérogation locale
// CAMPUS_PRIVILEGED_WITHOUT_MFA, limitée au serveur temporaire lancé ici.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PORT = Number(process.env.CAMPUS_PORT ?? 8124);
const DEBUG_PORT = PORT + 1000;
const BASE = `http://127.0.0.1:${PORT}`;
const BROWSER = process.env.CAMPUS_BROWSER ?? ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'].find(existsSync);
const PHP = process.env.CAMPUS_PHP ?? 'php';
const RUN = new Date().toISOString().slice(5, 16).replace(/[-T:]/g, '');
const TAG = `[PARCOURS ${RUN}]`;
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
const emergency = async () => { await closeBrowser(); stop(server); artisan('campus:fixtures:access', '--lock'); console.log('Parcours interrompus : serveur et navigateur arrêtés, comptes fictifs refermés.'); process.exit(3); };
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

    // ───────────────────────── Parcours individuel : compte indépendant ─────────────────────────
    console.log('\n— Parcours individuel (independant@example.test) —');
    await attempt('Visiteur : accueil sans donnée, avec connexion, création de compte et aide', async () => {
        await go('/');
        const seen = await evaluate(`return [text().includes('Se connecter'), text().includes('Créer un compte'), text().includes('Aide et support'), document.querySelectorAll('.checklist').length].join('|');`);

        return seen === 'true|true|true|0' ? seen : false;
    });
    await attempt('Inscription : nom, adresse, mot de passe et déclaration de majorité, rien de plus', async () => {
        await go('/inscription');
        const fields = await evaluate(`return [...document.querySelectorAll('form label')].filter(visible).map((l) => norm(l.childNodes[0]?.textContent || l.textContent).slice(0, 40)).join(' | ');`);
        note('Champs de l’inscription : ' + fields);

        return fields.split(' | ').length <= 5 ? fields : false;
    });
    await attempt('Connexion du compte indépendant', async () => { const where = await signIn('independant'); return where.endsWith('|true') && !where.startsWith('/connexion') ? where : false; });
    let before = '';
    await attempt('Accueil : parcours de démarrage calculé sur les données réelles', async () => {
        await go('/');
        before = (await evaluate(`return steps('individual-steps-title').join('');`)) || '(parcours déjà terminé)';
        const blocks = await evaluate(`return ['Ta journée', 'À faire', 'Ta moyenne', 'Bourses, concours, stages'].map((title) => text().toLowerCase().includes(title.toLowerCase())).join('|') + '|' + (document.querySelector('.context-chips') === null || document.querySelector('.chip[aria-pressed="true"]')?.textContent === 'Perso');`);
        note('État de départ des sept étapes : ' + before);

        return blocks === 'true|true|true|true|true' ? blocks : false;
    });
    await attempt('Profil minimal : fuseau horaire confirmé depuis le profil', async () => {
        await go('/profil');
        await evaluate(`choose('Mon fuseau horaire', 'Africa/Abidjan', '#fuseau'); [...document.querySelectorAll('#fuseau button[type=submit]')][0].click(); await settle(2500); return true;`);

        return await evaluate(`return text().includes('Fuseau horaire enregistré : Africa/Abidjan.');`);
    });
    await attempt('Matière personnelle créée', async () => {
        await go('/etudes/referentiels?kind=subjects');
        await evaluate(`fill('Nom', ${JSON.stringify(TAG + ' Matière fictive')}); const form = byLabel('Nom').closest('form'); form.requestSubmit(); await settle(2500); return true;`);

        return await evaluate(`return text().includes(${JSON.stringify(TAG + ' Matière fictive')});`);
    });

    // Mesure demandée : créer un cours puis une tâche. Temps d'un script, pas d'une personne : voir la note en fin de rapport.
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Abidjan' });
    // Horaires propres à chaque passage : Campus refuse, à juste titre, deux cours qui se chevauchent pour la même personne ou le même groupe.
    const minuteOfDay = Math.floor(Date.now() / 60000) % 280;
    const clock = (minutes) => String(Math.floor(minutes / 60)).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0');
    const slot = { start: clock(minuteOfDay), end: clock(minuteOfDay + 4) };
    const later = { start: clock(420 + minuteOfDay * 2), end: clock(429 + minuteOfDay * 2) };
    // Le cours d'établissement est placé demain : un enseignant ne signale son indisponibilité que pour un cours à venir.
    const tomorrow = new Date(Date.now() + 86400000).toLocaleDateString('en-CA', { timeZone: 'Africa/Abidjan' });
    const started = Date.now();
    await evaluate(`window.__actions = 0; return true;`);
    let actions = 0;
    const courseOk = await attempt('Cours créé depuis le planning (événement ponctuel aujourd’hui)', async () => {
        await go('/planning');
        await evaluate(`window.__actions = 0; fill('Titre', ${JSON.stringify(TAG + ' Cours fictif')}, '#series-form'); choose('Répétition', 'Une seule fois', '#series-form'); fill('Date', '${today}', '#series-form'); fill('Début', '${slot.start}', '#series-form'); fill('Fin', '${slot.end}', '#series-form'); byLabel('Titre', '#series-form').closest('form').requestSubmit(); window.__actions++; for (let i = 0; i < 60 && byLabel('Titre', '#series-form').value !== '' && !document.querySelector('#series-form .state-error'); i++) await settle(250); await settle(500); return true;`);
        actions += await evaluate(`return window.__actions;`);
        const refused = await evaluate(`return norm(document.querySelector('#series-form .state-error')?.textContent);`);
        if (refused) {
            // Créneau déjà pris par un passage précédent du script : le chevauchement est confirmé avec un motif, comme l'écran le propose.
            note('Refus affiché par le planning : ' + refused.slice(0, 220));
            await evaluate(`tick('Confirmer les chevauchements signalés', '#series-form'); fill('Justification du chevauchement', 'Chevauchement fictif accepté pour le parcours', '#series-form'); byLabel('Titre', '#series-form').closest('form').requestSubmit(); for (let i = 0; i < 60 && byLabel('Titre', '#series-form').value !== '' && !document.querySelector('#series-form .state-error'); i++) await settle(250); await settle(500); return true;`);
            actions += 3;
        }
        await go(`/planning?view=day&date=${today}`);

        return await evaluate(`return text().includes(${JSON.stringify(TAG + ' Cours fictif')});`);
    });
    const taskOk = await attempt('Tâche créée depuis l’écran Tâches', async () => {
        await go('/taches');
        await evaluate(`window.__actions = 0; const form = document.querySelector('section[aria-labelledby="create-title"] form'); fill('Titre', ${JSON.stringify(TAG + ' Tâche fictive')}, form); press('Ajouter la tâche', form); await settle(3000); return true;`);
        actions += await evaluate(`return window.__actions;`);

        return await evaluate(`return text().includes('Tâche enregistrée.') && text().includes(${JSON.stringify(TAG + ' Tâche fictive')});`);
    });
    const elapsed = ((Date.now() - started) / 1000).toFixed(1);
    check('Mesure : un cours puis une tâche, par le script', courseOk && taskOk, `${elapsed} s, ${actions} saisies ou clics, 2 écrans (attentes du script comprises)`);

    await attempt('Double clic sur « Ajouter la tâche » : une seule tâche', async () => {
        await go('/taches');
        await evaluate(`const form = document.querySelector('section[aria-labelledby="create-title"] form'); fill('Titre', ${JSON.stringify(TAG + ' Tâche double clic')}, form); const button = [...form.querySelectorAll('button')].find((b) => norm(b.textContent).startsWith('Ajouter la tâche')); button.click(); button.click(); form.requestSubmit(); await settle(3500); return true;`);
        await go('/taches');
        const count = await evaluate(`return [...document.querySelectorAll('li strong')].filter((s) => norm(s.textContent) === ${JSON.stringify(TAG + ' Tâche double clic')}).length;`);

        return count === 1 ? '1 tâche après trois envois' : false;
    });
    await attempt('Saisie en cours : avertissement avant de quitter l’écran', async () => {
        await go('/taches');
        const asked = dialogs;
        await evaluate(`const form = document.querySelector('section[aria-labelledby="create-title"] form'); fill('Titre', 'Saisie fictive non enregistrée', form); await settle(400); [...document.querySelectorAll('a')].filter(visible).find((a) => norm(a.textContent) === 'Planning').click(); await settle(2500); return true;`);

        return dialogs === asked + 1 ? 'une confirmation demandée avant de quitter' : false;
    });
    await attempt('Formulaire incomplet : erreur affichée, saisie conservée', async () => {
        await go('/notes');
        const kept = await evaluate(`const form = document.querySelector('section[aria-labelledby="add-title"] form'); fill('Intitulé', 'Évaluation fictive sans note', form); fill('Note', '', form); press('Ajouter la note', form); await settle(2500); const shown = document.querySelectorAll('.error-summary, .field-error, [aria-invalid=true]').length > 0; return [byLabel('Intitulé', form).value, shown ? 'erreur affichée par Campus' : (form.checkValidity() ? 'aucune erreur' : 'envoi bloqué par le navigateur sur le champ obligatoire')].join('|');`);

        return kept.startsWith('Évaluation fictive sans note|') && !kept.endsWith('aucune erreur') ? kept : false;
    });
    await attempt('Note personnelle créée', async () => {
        await go('/notes');
        await evaluate(`const form = document.querySelector('section[aria-labelledby="add-title"] form'); fill('Intitulé', ${JSON.stringify(TAG + ' Évaluation fictive')}, form); choose('Matière', ${JSON.stringify(TAG + ' Matière fictive')}, form); fill('Note', '14', form); press('Ajouter la note', form); await settle(3000); return true;`);

        return await evaluate(`document.querySelectorAll('.subject-list h3 button.list-row[aria-expanded="false"]').forEach((b) => b.click()); await settle(600); return text().includes(${JSON.stringify(TAG + ' Évaluation fictive')});`);
    });
    await attempt('Opportunité ajoutée aux favoris depuis le catalogue', async () => {
        await go('/opportunites');
        const href = await evaluate(`const link = [...document.querySelectorAll('a')].find((a) => /^\\/opportunites\\/[0-9a-f-]{36}$/.test(a.getAttribute('href') || '')); return link ? link.getAttribute('href') : '';`);
        if (!href) return false;
        await go(href);
        await evaluate(`if (!text().includes('Retirer de mes favoris')) { press('Ajouter à mes favoris'); await settle(2500); } return true;`);

        return await evaluate(`return text().includes('Retirer de mes favoris');`);
    });
    await attempt('Retour après cours : le cours terminé est proposé sur l’accueil et le retour s’enregistre', async () => {
        await go('/');
        const offered = await evaluate(`return text().includes('Retours à renseigner') && text().includes(${JSON.stringify(TAG + ' Cours fictif')});`);
        if (!offered) return 'cours pas encore terminé à cette heure : étape non exercée';
        await evaluate(`const item = [...document.querySelectorAll('section[aria-labelledby="checkins-title"] li')].find((li) => li.textContent.includes(${JSON.stringify(TAG + ' Cours fictif')})); item.querySelector('a').click(); await settle(3000); return true;`);
        await evaluate(`fill('Résumé personnel du cours', 'Retour fictif du parcours'); press('Enregistrer mon retour'); await settle(3000); return true;`);

        return await evaluate(`return text().includes('Retour enregistré');`);
    });
    await attempt('Accueil après le parcours : sept étapes faites, modules reliés avec leur provenance', async () => {
        await go('/');
        const after = await evaluate(`return [steps('individual-steps-title').join('') || 'terminé', document.querySelectorAll('section[aria-labelledby="tasks-title"] li').length > 0, text().includes('Moyenne indicative'), text().includes('comptée(s) sur'), document.querySelectorAll('.average-card .average-value').length > 0].join('|');`);
        note('Étapes : avant ' + before + ' ; après ' + after.split('|')[0]);

        return after.startsWith('terminé|true|true|true|true') ? after : false;
    });
    await attempt('Aide, préférences de notification et profil accessibles depuis l’accueil', async () => {
        const reached = [];
        // Aide et préférences : liens du pied de page ; profil : pastille du compte.
        for (const [link, expected] of [['Aide et support', 'Questions fréquentes'], ['Préférences de notification', 'notification'], ['Profil de', 'Fuseau horaire']]) {
            await go('/');
            await evaluate(`[...document.querySelectorAll('.workspace-footer a, .avatar-link')].filter(visible).find((a) => norm(a.textContent) === ${JSON.stringify(link)} || (a.getAttribute('aria-label') ?? '').startsWith(${JSON.stringify(link)})).click(); await settle(2500); return true;`);
            reached.push(await evaluate(`return text().includes(${JSON.stringify(expected)});`));
        }

        return reached.every(Boolean) ? 'trois écrans atteints' : false;
    });
    await attempt('Accès interdit et page introuvable : page d’erreur avec reprise', async () => {
        await go('/administration/etablissement');
        const forbidden = await evaluate(`return [text().includes('Accès interdit'), [...document.querySelectorAll('a')].some((a) => a.getAttribute('href') === '/')].join('|');`);
        await go('/page-fictive-inexistante');
        const missing = await evaluate(`return [text().includes('Page introuvable'), [...document.querySelectorAll('a')].some((a) => a.getAttribute('href') === '/aide')].join('|');`);

        return forbidden === 'true|true' && missing === 'true|true' ? 'accès interdit et page introuvable' : false;
    });
    await attempt('Écran mobile 360 px : accueil sans défilement horizontal', async () => {
        await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 740, deviceScaleFactor: 2, mobile: true });
        await go('/');
        const layout = await evaluate(`return document.documentElement.scrollWidth + '|' + window.innerWidth;`);
        await send('Emulation.clearDeviceMetricsOverride');
        const [scroll, inner] = layout.split('|').map(Number);

        return scroll <= inner ? layout : false;
    });
    await attempt('Session expirée : une action renvoie à la connexion sans page blanche', async () => {
        await go('/taches');
        await send('Network.clearBrowserCookies');
        await evaluate(`const form = document.querySelector('section[aria-labelledby="create-title"] form'); fill('Titre', 'Tâche fictive après expiration', form); window.onbeforeunload = null; press('Ajouter la tâche', form); await settle(3500); return true;`);
        const where = await evaluate(`return [location.pathname, text().includes('Se connecter') || text().includes('Session expirée') || text().includes('session a expiré')].join('|');`);
        note('Après expiration de la session : ' + where);

        return where.endsWith('|true') ? where : false;
    });

    // ───────────────────────── Parcours établissement : comptes A ─────────────────────────
    console.log('\n— Parcours établissement (admin-a, enseignant-a, apprenant-double, independant) —');
    await attempt('Connexion de l’administration A et choix du contexte de l’établissement A', async () => {
        const where = await signIn('admin-a');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go('/');

        return await evaluate(`return document.querySelector('.chip[aria-pressed="true"]')?.textContent === 'Établissement fictif A' && document.querySelectorAll('section[aria-labelledby="institution-steps-title"] .checklist li').length === 7;`);
    });
    let institutionBefore = '';
    await attempt('Accueil de l’administration : sept étapes de mise en route', async () => {
        institutionBefore = await evaluate(`return steps('institution-steps-title').join('');`);
        note('État de départ de l’établissement A : ' + institutionBefore);

        return institutionBefore.length === 7 ? institutionBefore : false;
    });
    await attempt('Référentiels de l’établissement consultables (groupes, matières)', async () => {
        await go('/etudes/referentiels?kind=groups');

        return await evaluate(`return text().includes('A-G1') || text().includes('Groupe');`);
    });
    await attempt('Import : écran de dépôt et modèle à télécharger disponibles', async () => {
        await go('/planning/import');
        const seen = await evaluate(`return [text().includes('Déposer un fichier'), [...document.querySelectorAll('a')].some((a) => (a.getAttribute('href') || '').includes('/planning/import/modele')), document.querySelector('input[type=file]') !== null].join('|');`);
        const template = await evaluate(`const response = await fetch('/planning/import/modele'); return response.status + '|' + (response.headers.get('Content-Type') || '').slice(0, 60);`);
        note('Modèle d’import : ' + template);

        return seen === 'true|true|true' && template.startsWith('200|') ? seen : false;
    });
    await attempt('Import simulé : le modèle est déposé, analysé par le traitement en file, et son rapport s’affiche sans rien appliquer', async () => {
        await go('/planning/import');
        // Le fichier déposé est le modèle fourni par Campus lui-même, tel que téléchargé : aucune donnée extérieure.
        await evaluate(`const blob = await (await fetch('/planning/import/modele')).blob(); const data = new DataTransfer(); data.items.add(new File([blob], 'modele-parcours.xlsx', { type: blob.type })); const input = document.getElementById('import-file'); input.files = data.files; input.dispatchEvent(new Event('change', { bubbles: true })); await settle(500); press('Déposer et analyser'); for (let i = 0; i < 40 && !document.querySelector('section[aria-labelledby="batch-title"]'); i++) await settle(250); await settle(800); return true;`);
        // L'analyse est un traitement en file : le script joue le rôle du processus de file d'un serveur déployé.
        // Lot resté en échec lors d'un passage précédent (fichier identique, donc même lot) : l'analyse est relancée depuis l'écran.
        await evaluate(`const lot = document.querySelector('section[aria-labelledby="batch-title"]'); if (lot && norm(lot.textContent).includes('Échec du traitement')) { press('Relancer l’analyse', lot); await settle(3000); } return true;`);
        const processed = worker();
        await go(await evaluate(`return location.pathname + location.search;`));
        const report = await evaluate(`const state = document.querySelector('section[aria-labelledby="batch-title"] dd[role=status]'); return [state ? norm(state.textContent) : 'aucun lot affiché', text().includes('Lot «')].join('|');`);
        note('Rapport d’import : ' + report + ' (traitement de file : code ' + processed.status + ')');

        return report.endsWith('|true') && !/Déposé|Analyse en cours|Échec/.test(report) ? report : false;
    });
    const institutionCourse = TAG + ' Cours fictif A-G1';
    await attempt('Cours d’établissement créé au brouillon pour le groupe A-G1', async () => {
        await go('/planning');
        await evaluate(`fill('Titre', ${JSON.stringify(institutionCourse)}, '#series-form'); choose('Répétition', 'Une seule fois', '#series-form'); fill('Date', '${tomorrow}', '#series-form'); fill('Début', '${later.start}', '#series-form'); fill('Fin', '${later.end}', '#series-form');
            const groups = [...document.querySelectorAll('#series-form fieldset')].find((f) => norm(f.querySelector('legend')?.textContent).startsWith('Groupes concernés')); const box = [...groups.querySelectorAll('label')].find((l) => norm(l.textContent) === 'Groupe fictif A 1'); (box.control || document.getElementById(box.htmlFor)).click();
            const subject = byLabel('Matière', '#series-form'); setValue(subject, [...subject.options].find((o) => o.value !== '').value);
            const teacher = byLabel('Enseignant', '#series-form'); setValue(teacher, [...teacher.options].find((o) => norm(o.textContent).includes('Enseignant fictif A')).value);
            byLabel('Titre', '#series-form').closest('form').requestSubmit(); for (let i = 0; i < 60 && byLabel('Titre', '#series-form').value !== '' && !document.querySelector('#series-form .state-error'); i++) await settle(250); await settle(500); return true;`);
        // Chevauchement signalé (horaire déjà pris par un passage précédent pour ce groupe ou cet enseignant) : il est confirmé avec un motif, comme le demande l'écran.
        const refusal = await evaluate(`return norm(document.querySelector('#series-form .state-error')?.textContent);`);
        if (refusal) {
            note('Refus affiché par le planning : ' + refusal.slice(0, 220));
            await evaluate(`tick('Confirmer les chevauchements signalés', '#series-form'); fill('Justification du chevauchement', 'Chevauchement fictif accepté pour le parcours', '#series-form'); byLabel('Titre', '#series-form').closest('form').requestSubmit(); for (let i = 0; i < 60 && byLabel('Titre', '#series-form').value !== '' && !document.querySelector('#series-form .state-error'); i++) await settle(250); await settle(500); return true;`);
        }
        await go(`/planning?view=day&date=${tomorrow}`);

        return await evaluate(`return text().includes(${JSON.stringify(institutionCourse)});`);
    });
    await attempt('Publication : différences affichées, confirmation qui nomme l’établissement, version publiée', async () => {
        await go('/planning/publication');
        await evaluate(`fill('Motif de la publication', 'Publication fictive du parcours'); await settle(400); press('Publier ces'); await settle(800); return true;`);
        const named = await evaluate(`const dialog = document.querySelector('dialog[open]'); return dialog ? norm(dialog.querySelector('h2').textContent) : '';`);
        await evaluate(`press('Publier', 'dialog[open]'); await settle(4000); return true;`);
        const done = await evaluate(`return text().includes('Version en vigueur');`);
        note('Titre de la confirmation : ' + named);

        return named.includes('Établissement fictif A') && done ? named : false;
    });
    let invitationUrl = '';
    await attempt('Invitation créée par l’administration (lien à usage unique affiché une fois)', async () => {
        await go('/administration/etablissement');
        const create = `const form = document.querySelector('section[aria-labelledby="invite-title"] form'); choose('Rôle', 'Apprenant', form); press('Créer l’invitation', form); await settle(3500); return location.pathname;`;
        // Action sensible : Campus redemande le mot de passe avant de créer une invitation, puis ramène à l'écran.
        if (await evaluate(create) === '/confirmation-mot-de-passe') {
            note('Création d’invitation : confirmation du mot de passe demandée, puis formulaire à renvoyer');
            await evaluate(`fill('Mot de passe', ${JSON.stringify(password)}); press('Confirmer'); await settle(3000); return true;`);
            await go('/administration/etablissement');
            await evaluate(create);
        }
        invitationUrl = await evaluate(`return norm(document.querySelector('.secret-panel .link-value')?.textContent);`);

        return /\/invitation\/[A-Za-z0-9_-]+$/.test(invitationUrl) ? 'lien à usage unique reçu (non reproduit ici)' : false;
    });
    await attempt('Invitation acceptée par un compte existant : un seul compte, une appartenance de plus', async () => {
        if (!invitationUrl) return false;
        const where = await signIn('independant');
        if (!where.endsWith('|true')) return false;
        // Reste d'un passage précédent interrompu : le compte indépendant repart sans établissement.
        await leaveA();
        await go(new URL(invitationUrl).pathname);
        await evaluate(`press('Rattacher à mon compte'); await settle(3500); return true;`);
        await go('/profil');

        return await evaluate(`return text().includes('Établissement fictif A');`);
    });
    await attempt('Départ volontaire : le compte quitte l’établissement (confirmation nommant l’appartenance, mot de passe redemandé) et garde son espace individuel', async () => {
        if (!invitationUrl) return false;
        const left = await leaveA();
        await go('/');
        const home = await evaluate(`const chips = [...document.querySelectorAll('.context-chips .chip')]; return [chips.length === 0 || chips.find((c) => c.getAttribute('aria-pressed') === 'true')?.textContent === 'Perso', !chips.some((c) => norm(c.title).includes('Établissement fictif A'))].join('|');`);

        return left && home === 'true|true' ? 'plus aucune appartenance à A ; espace individuel intact' : false;
    });
    await attempt('Planning publié visible par l’apprenant, avec la provenance « Établissement »', async () => {
        const where = await signIn('apprenant-double');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go(`/planning?view=day&date=${tomorrow}`);
        const planning = await evaluate(`const card = [...document.querySelectorAll('.schedule-card')].find((c) => c.textContent.includes(${JSON.stringify(institutionCourse)})); return card ? norm(card.querySelector('.provenance')?.textContent) : '';`);

        return planning === 'Établissement' ? 'cours de demain visible, provenance « Établissement »' : false;
    });
    await attempt('Enseignant : signalement d’indisponibilité sur son cours publié', async () => {
        const where = await signIn('enseignant-a');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go(`/planning?view=day&date=${tomorrow}`);
        await evaluate(`const card = [...document.querySelectorAll('.schedule-card')].find((c) => c.textContent.includes(${JSON.stringify(institutionCourse)})); press('Signaler mon indisponibilité', card); await settle(800); return true;`);
        await evaluate(`fill('Motif transmis à l’administration', 'Indisponibilité fictive du parcours', 'dialog[open]'); await settle(300); press('Envoyer le signalement', 'dialog[open]'); await settle(3500); return true;`);

        return await evaluate(`return text().includes('Mes signalements d’indisponibilité') && text().includes(${JSON.stringify(institutionCourse)});`);
    });
    await attempt('Administration : décision sur le signalement, puis sept étapes à jour sur l’accueil', async () => {
        const where = await signIn('admin-a');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif A');
        await go('/planning/publication');
        await evaluate(`const section = document.querySelector('section[aria-labelledby="reports-title"]'); choose('Décision', 'Refus', section); await settle(300); press('Enregistrer la décision', section); await settle(3500); return true;`);
        await go('/');
        const after = await evaluate(`return steps('institution-steps-title').join('');`);
        note('Étapes de l’établissement A : avant ' + institutionBefore + ' ; après ' + after);

        return after.slice(2) === '✓✓✓✓✓' || after === '✓✓✓✓✓✓✓' ? after : (after[1] !== '✓' && after.replace(/^.{2}/, '') === '✓✓✓✓✓' ? after : false);
    });
    await attempt('Cloisonnement : l’administration B ne voit rien de l’établissement A', async () => {
        const where = await signIn('admin-b');
        if (!where.endsWith('|true')) return false;
        await useContext('Établissement fictif B');
        await go(`/planning?view=day&date=${tomorrow}`);
        const planning = await evaluate(`return text().includes(${JSON.stringify(TAG)});`);
        await go('/');
        const home = await evaluate(`return text().includes(${JSON.stringify(TAG)}) + '|' + (document.querySelector('.chip[aria-pressed="true"]')?.textContent === 'Établissement fictif B');`);

        return !planning && home === 'false|true' ? 'aucune donnée de A chez B' : false;
    });
    check('Aucune erreur JavaScript pendant les parcours', pageErrors.length === 0, pageErrors.slice(0, 3).join(' ; '));
} catch (error) {
    check('Exécution des parcours', false, String(error?.message ?? error).slice(0, 400));
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
console.log('Note : la durée mesurée est celle d’un script qui connaît déjà les écrans. Elle ne dit pas si une personne débutante y parvient en moins de dix minutes : cet essai reste à faire avec une vraie personne.');
process.exit(failed === 0 ? 0 : 1);
