// Garde-fou : chaque jeu d'options passé à Intl.DateTimeFormat dans les écrans doit être accepté par le moteur.
// Mélanger dateStyle/timeStyle avec des champs détaillés (heure, fuseau…) lève une erreur à l'affichage et vide l'écran :
// ni le typage ni le lint ne le détectent. Défaut réel trouvé au lot 21 sur la fiche d'une opportunité.
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const roots = [fileURLToPath(new URL('../../resources/js', import.meta.url)), fileURLToPath(new URL('../../public', import.meta.url))];
const files = (directory) => readdirSync(directory).flatMap((name) => {
    const path = join(directory, name);
    if (statSync(path).isDirectory()) return name === 'build' ? [] : files(path);

    return /\.(tsx?|js)$/.test(name) ? [path] : [];
});

test('toutes les options de format de date écrites dans les écrans sont valides', () => {
    let checked = 0;
    for (const path of roots.flatMap(files)) {
        const source = readFileSync(path, 'utf8');
        for (const match of source.matchAll(/Intl\.DateTimeFormat\([^,()]+,\s*(\{[^{}]*\})/g)) {
            // Les valeurs qui ne sont pas des littéraux (fuseau venu des données) sont remplacées par une valeur valide.
            const literal = match[1].replace(/timeZone:\s*[^,}'"]+(?=[,}])/g, "timeZone: 'UTC'").replace(/(\w+):\s*([a-z_$][\w.$?]*)(?=\s*[,}])/gi, (whole, key, value) => (['true', 'false', 'undefined'].includes(value) ? whole : `${key}: undefined`));
            let options;
            try { options = Function(`return (${literal});`)(); } catch { continue; }
            assert.doesNotThrow(() => new Intl.DateTimeFormat('fr', options), `${path} : ${match[1]}`);
            checked++;
        }
    }
    assert.ok(checked > 20, `seulement ${checked} formats contrôlés`);
    // Le cas fautif reste bien refusé par le moteur : le test détecterait son retour.
    assert.throws(() => new Intl.DateTimeFormat('fr', { dateStyle: 'long', timeStyle: 'short', timeZoneName: 'short' }));
});
