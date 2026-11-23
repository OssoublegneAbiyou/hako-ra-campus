// Règles de conservation des données privées sur l'appareil (resources/js/offline/policy.ts), exécutées telles quelles par Node.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { accepts, decide, expired, initialState, synced } from '../../resources/js/offline/policy.ts';

const DAY = 86_400_000;
const NOW = Date.parse('2026-11-22T08:00:00Z');
const ago = (days) => new Date(NOW - days * DAY).toISOString();
const personal = (accountKey, days = 1, ttlDays = 7) => ({ mode: 'personal', accountKey, lastSyncAt: ago(days), ttlDays });

test('par défaut, l’appareil est partagé et rien n’est conservé', () => {
    assert.deepEqual(initialState(), { mode: 'shared', accountKey: null, lastSyncAt: null, ttlDays: 7 });
    assert.deepEqual(decide(null, 'compte-a', NOW, true), { purge: false, sync: false, reason: 'shared-device' });
    assert.deepEqual(decide(initialState(), null, NOW, true), { purge: false, sync: false, reason: 'shared-device' });
});

test('appareil partagé : tout reste éventuel est effacé, et rien n’est synchronisé', () => {
    // Un état incohérent (mode partagé mais des traces d'un compte) est nettoyé.
    assert.deepEqual(decide({ mode: 'shared', accountKey: 'compte-a', lastSyncAt: ago(1), ttlDays: 7 }, 'compte-a', NOW, true), { purge: true, sync: false, reason: 'shared-device' });
    assert.deepEqual(decide({ mode: 'personal', accountKey: null, lastSyncAt: ago(1), ttlDays: 7 }, 'compte-a', NOW, true), { purge: true, sync: false, reason: 'shared-device' });
});

test('R47 — deux comptes successifs sur le même appareil : les données du premier sont effacées avant tout', () => {
    const decision = decide(personal('compte-a'), 'compte-b', NOW, true);
    assert.deepEqual(decision, { purge: true, sync: false, reason: 'account-changed' });
    // Même hors connexion, même si la copie est récente : le second compte ne lit jamais la copie du premier.
    assert.equal(decide(personal('compte-a', 0), 'compte-b', NOW, false).purge, true);
    // Après l'effacement, l'appareil est de nouveau « partagé » pour le second compte : le choix ne se transmet pas.
    assert.deepEqual(decide(initialState(), 'compte-b', NOW, true), { purge: false, sync: false, reason: 'shared-device' });
    // Une réponse du serveur pour un autre compte que celui qui a fait le choix n'est jamais enregistrée.
    assert.equal(accepts(personal('compte-a'), 'compte-b'), false);
    assert.equal(accepts(personal('compte-a'), undefined), false);
    assert.equal(accepts(initialState(), 'compte-a'), false);
    assert.equal(accepts(personal('compte-a'), 'compte-a'), true);
});

test('même compte, en ligne : resynchronisation, donc droits réévalués par le serveur', () => {
    assert.deepEqual(decide(personal('compte-a'), 'compte-a', NOW, true), { purge: false, sync: true, reason: 'up-to-date' });
    // Choix fait mais pas encore de synchronisation réussie : on synchronise.
    assert.deepEqual(decide({ mode: 'personal', accountKey: 'compte-a', lastSyncAt: null, ttlDays: 7 }, 'compte-a', NOW, true), { purge: false, sync: true, reason: 'up-to-date' });
});

test('réseau coupé : la copie est conservée et lue, sans tentative de synchronisation', () => {
    assert.deepEqual(decide(personal('compte-a', 3), 'compte-a', NOW, false), { purge: false, sync: false, reason: 'offline' });
    // Page ouverte sans session (réseau coupé, session inconnue) : la copie reste lisible tant qu'elle n'a pas expiré.
    assert.deepEqual(decide(personal('compte-a', 3), null, NOW, false), { purge: false, sync: false, reason: 'signed-out' });
});

test('copie non contrôlée depuis plus de 7 jours : effacée à l’ouverture, en ligne comme hors ligne', () => {
    assert.equal(expired(ago(6.9), NOW, 7), false);
    assert.equal(expired(ago(7.1), NOW, 7), true);
    assert.equal(expired(null, NOW, 7), true);
    assert.equal(expired('date illisible', NOW, 7), true);
    assert.deepEqual(decide(personal('compte-a', 8), 'compte-a', NOW, true), { purge: true, sync: false, reason: 'expired' });
    assert.deepEqual(decide(personal('compte-a', 8), null, NOW, false), { purge: true, sync: false, reason: 'expired' });
    // Durée paramétrable : 2 jours.
    assert.equal(decide(personal('compte-a', 3, 2), 'compte-a', NOW, true).reason, 'expired');
    assert.equal(decide(personal('compte-a', 1, 2), 'compte-a', NOW, true).reason, 'up-to-date');
    // Une durée nulle ou négative est ramenée à un jour : jamais de conservation illimitée.
    assert.equal(expired(ago(2), NOW, 0), true);
    assert.equal(expired(ago(0.5), NOW, 0), false);
});

test('après une synchronisation réussie, l’état porte le compte, la date et la durée', () => {
    assert.deepEqual(synced('compte-a', '2026-11-22T08:00:00+00:00', 7), { mode: 'personal', accountKey: 'compte-a', lastSyncAt: '2026-11-22T08:00:00+00:00', ttlDays: 7 });
    assert.equal(synced('compte-a', '2026-11-22T08:00:00+00:00', 0).ttlDays, 1);
});
