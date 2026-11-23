// Écrit sur la sortie standard les opérations telles que le vrai code de l'appareil (public/offline-core.js) les envoie.
// Sert au test serveur qui vérifie que ces envois réels sont acceptés tels quels (tests/Feature/SyncTest.php). Données fictives.
// Usage : node tests/js/emit-operations.mjs <clé de compte> <identifiant de tâche> <identifiant de cours>
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const [accountKey, taskId, courseId] = process.argv.slice(2);
const self = {};
vm.runInNewContext(readFileSync(new URL('../../public/offline-core.js', import.meta.url), 'utf8'), { self, TextEncoder });
const sent = [];
let counter = 0;
const outbox = self.CampusOffline.createOutbox({
    store: self.CampusOffline.memoryStore(), crypto: webcrypto, csrf: () => null, now: () => new Date(Date.UTC(2026, 10, 16, 8, 0, ++counter)).toISOString(),
    uuid: () => webcrypto.randomUUID(),
    // Le réseau « tombe » après chaque envoi : seules les opérations écrites nous intéressent ici.
    fetch: async (url, options) => { sent.push({ url, body: JSON.parse(options.body) }); throw new TypeError('Réseau coupé'); },
});
const drafts = [
    { type: 'task.create', fields: { title: '  Tâche fictive écrite par l’appareil 😀 ', description: 'Ligne 1\nLigne 2 "citée" / fin', due_at: '2026-11-20T18:00', priority: 'high' } },
    { type: 'task.update', targetId: taskId, baseVersion: 1, fields: { title: 'Titre modifié par l’appareil', description: '', due_at: '', priority: 'low', status: 'in_progress', personal_subject_id: '' } },
    { type: 'check_in.save', targetId: courseId, baseVersion: 0, fields: { summary: 'Retour fictif écrit par l’appareil', work_to_do: ' ', points_to_review: 'Point fictif', understanding: '3' } },
];
for (const draft of drafts) {
    const { errors } = await outbox.draft({ accountKey, label: 'Brouillon fictif', ...draft });
    if (errors.length > 0) throw new Error(errors.join(' '));
}
// Un passage par opération : l'envoi s'arrête à chaque coupure, l'opération tentée reste en attente et la suivante part au passage suivant.
for (let pass = 0; pass < drafts.length; pass++) {
    await outbox.synchronize(accountKey);
    const queue = await outbox.list(accountKey);
    const tried = queue.find((operation) => operation.state === 'pending');
    if (tried) await outbox.discard(tried.id);
}
process.stdout.write(JSON.stringify(sent));
