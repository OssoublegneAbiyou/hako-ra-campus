// Page hors connexion de HAKO-RA Campus. Ce script est public et ne contient aucune donnée : il lit, s'il existe,
// ce que la personne a choisi de conserver sur cet appareil personnel (IndexedDB), et lui permet d'écrire des brouillons
// personnels (tâche personnelle, retour privé après cours). Il n'envoie jamais rien : l'envoi se fait dans Campus, au retour du réseau.
(function () {
    'use strict';

    var STATE_KEY = 'campus.offline.state';
    var core = window.CampusOffline || null;
    var outbox = core ? core.browserOutbox() : null;
    var DEFAULT_LABELS = {
        statuses: { todo: 'À faire', in_progress: 'En cours', done: 'Terminé', abandoned: 'Abandonné' },
        priorities: { low: 'Basse', normal: 'Normale', high: 'Haute' },
        understanding: { 1: 'Pas compris', 2: 'À revoir', 3: 'Plutôt compris', 4: 'Bien compris' },
    };
    var KINDS = { 'task.create': 'Nouvelle tâche personnelle', 'task.update': 'Modification d’une tâche personnelle', 'check_in.save': 'Retour privé après cours' };
    var EXPLANATIONS = {
        local: 'Écrit sur cet appareil, pas encore envoyé. Il partira quand vous rouvrirez Campus avec du réseau.',
        pending: 'Envoi tenté sans réponse. Il sera renvoyé à l’identique : il ne peut pas créer de doublon.',
        synced: 'Enregistré sur le serveur.',
        refused: 'Le serveur a refusé cet envoi. Votre brouillon reste ici tant que vous ne l’abandonnez pas.',
        conflict: 'Cet élément a été modifié ailleurs depuis votre brouillon. Rien n’a été écrasé : choisissez la version à garder.',
    };
    var labels = DEFAULT_LABELS;
    var uid = 0;

    function byId(id) { return document.getElementById(id); }

    function element(tag, text, className) {
        var node = document.createElement(tag);
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        if (className) { node.className = className; }

        return node;
    }

    function button(text, className, action) {
        var node = element('button', text, className);
        node.type = 'button';
        node.addEventListener('click', action);

        return node;
    }

    function say(text) {
        var node = byId('message');
        node.textContent = text;
        node.hidden = !text;
    }

    function readState() {
        try { return JSON.parse(window.localStorage.getItem(STATE_KEY) || 'null'); } catch (error) { return null; }
    }

    function writeState(state) {
        try { window.localStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch (error) { /* stockage refusé */ }
    }

    function purge() {
        try {
            Object.keys(window.localStorage).filter(function (key) { return key.indexOf('campus.offline.') === 0; }).forEach(function (key) { window.localStorage.removeItem(key); });
        } catch (error) { /* stockage indisponible : rien à effacer */ }
        if (!('indexedDB' in window)) { return Promise.resolve(); }

        return new Promise(function (resolve) {
            var request = window.indexedDB.deleteDatabase('campus-private');
            request.onsuccess = request.onerror = request.onblocked = function () { resolve(); };
        });
    }

    function readCopies() {
        if (!core) { return Promise.resolve(null); }

        return core.openDatabase(window.indexedDB).then(function (database) {
            return new Promise(function (resolve) {
                var result = { snapshot: null, consulted: [] };
                var transaction = database.transaction(['snapshot', 'consulted'], 'readonly');
                transaction.objectStore('snapshot').get('current').onsuccess = function (event) { result.snapshot = event.target.result || null; };
                transaction.objectStore('consulted').getAll().onsuccess = function (event) { result.consulted = event.target.result || []; };
                transaction.oncomplete = function () { database.close(); resolve(result); };
                transaction.onerror = function () { database.close(); resolve(null); };
            });
        }).catch(function () { return null; });
    }

    function list(container, items, render, empty, className) {
        container.textContent = '';
        if (!items || items.length === 0) { container.appendChild(element('p', empty, 'meta')); return; }
        var ul = element('ul', null, className);
        items.forEach(function (item) { ul.appendChild(render(item)); });
        container.appendChild(ul);
    }

    function field(form, labelText, control) {
        var id = 'champ-' + (++uid);
        var label = element('label', labelText);
        label.htmlFor = id;
        control.id = id;
        form.appendChild(label);
        form.appendChild(control);

        return control;
    }

    function input(type, value, maxLength) {
        var node = document.createElement(type === 'textarea' ? 'textarea' : 'input');
        if (type !== 'textarea') { node.type = type; }
        if (maxLength) { node.maxLength = maxLength; }
        node.value = value === null || value === undefined ? '' : String(value);

        return node;
    }

    function select(options, value, emptyLabel) {
        var node = document.createElement('select');
        if (emptyLabel) { node.appendChild(new Option(emptyLabel, '')); }
        Object.keys(options).forEach(function (key) { node.appendChild(new Option(options[key], key)); });
        node.value = value === null || value === undefined ? '' : String(value);

        return node;
    }

    function finish(form, submitLabel, collect, onCancel) {
        var errors = element('p', null, 'alert');
        errors.setAttribute('role', 'alert');
        errors.hidden = true;
        var actions = element('div', null, 'actions');
        var submit = element('button', submitLabel);
        submit.type = 'submit';
        actions.appendChild(submit);
        if (onCancel) { actions.appendChild(button('Annuler', 'secondary', onCancel)); }
        form.appendChild(errors);
        form.appendChild(actions);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit.disabled = true;
            collect().then(function (result) {
                submit.disabled = false;
                if (result.errors.length > 0) { errors.textContent = result.errors.join(' '); errors.hidden = false; return; }
                render();
            });
        });

        return form;
    }

    /** Formulaire d'une tâche personnelle. `save` reçoit les champs et renvoie le résultat de la mise en file. */
    function taskForm(initial, withStatus, timezone, submitLabel, save, onCancel) {
        var form = document.createElement('form');
        form.noValidate = true;
        var title = field(form, 'Titre', input('text', initial.title, 255));
        var description = field(form, 'Description (facultative)', input('textarea', initial.description, 5000));
        var due = field(form, 'Échéance (facultative)' + (timezone ? ' — fuseau ' + timezone : ''), input('datetime-local', initial.due_at));
        var priority = field(form, 'Priorité', select(labels.priorities, initial.priority || 'normal'));
        var status = withStatus ? field(form, 'État', select(labels.statuses, initial.status || 'todo')) : null;

        return finish(form, submitLabel, function () {
            return save({ title: title.value, description: description.value, due_at: due.value, priority: priority.value, status: status ? status.value : 'todo',
                personal_subject_id: initial.personal_subject_id === undefined ? null : initial.personal_subject_id });
        }, onCancel);
    }

    function checkInForm(initial, save, onCancel) {
        var form = document.createElement('form');
        form.noValidate = true;
        form.appendChild(element('p', 'Ce retour est privé : vous seul pouvez le lire. Tous les champs sont facultatifs.', 'meta'));
        var summary = field(form, 'Résumé personnel du cours', input('textarea', initial.summary, 2000));
        var work = field(form, 'Travail à effectuer', input('textarea', initial.work_to_do, 2000));
        var points = field(form, 'Points à revoir', input('textarea', initial.points_to_review, 2000));
        var understanding = field(form, 'Mon niveau de compréhension', select(labels.understanding, initial.understanding, 'Non renseigné'));

        return finish(form, 'Enregistrer ce brouillon sur cet appareil', function () {
            return save({ summary: summary.value, work_to_do: work.value, points_to_review: points.value, understanding: understanding.value });
        }, onCancel);
    }

    function shown(fieldName, value) {
        if (value === null || value === undefined || value === '') { return '(vide)'; }
        if (fieldName === 'status') { return labels.statuses[value] || value; }
        if (fieldName === 'priority') { return labels.priorities[value] || value; }
        if (fieldName === 'understanding') { return labels.understanding[value] || value; }
        if (fieldName === 'due_at') { return String(value).replace('T', ' à '); }

        return String(value);
    }

    /** Ouvre le formulaire de modification d'un brouillon : s'il n'est pas encore parti il est remplacé, sinon la saisie partira après lui. */
    function editDraft(operation, state, host, timezone) {
        host.textContent = '';
        var close = function () { render(); };
        if (operation.type === 'check_in.save') {
            host.appendChild(checkInForm(operation.payload, function (fields) {
                return outbox.draft({ accountKey: state.accountKey, type: 'check_in.save', targetId: operation.targetId, baseVersion: operation.baseVersion, fields: fields, label: operation.label });
            }, close));

            return;
        }
        var existing = Boolean(operation.targetId);
        host.appendChild(taskForm(operation.payload, operation.type === 'task.update', timezone, 'Enregistrer ce brouillon sur cet appareil', function (fields) {
            return outbox.draft(existing
                ? { accountKey: state.accountKey, type: 'task.update', targetId: operation.targetId, baseVersion: operation.baseVersion, fields: fields, label: fields.title }
                : { accountKey: state.accountKey, type: 'task.update', targetOperation: operation.id, fields: fields, label: fields.title });
        }, close));
    }

    function renderDrafts(operations, state, timezone) {
        var when = new Intl.DateTimeFormat('fr', { dateStyle: 'medium', timeStyle: 'short' });
        list(byId('drafts'), operations, function (operation) {
            var li = element('li');
            li.setAttribute('data-outbox-state', operation.state);
            li.appendChild(element('strong', KINDS[operation.type] + ' — ' + operation.label));
            var meta = element('p', null, 'meta');
            meta.appendChild(element('span', core.STATE_LABELS[operation.state], 'state state-' + operation.state));
            meta.appendChild(document.createTextNode(' · écrit le ' + when.format(new Date(operation.createdAt)) + (operation.attempts > 0 ? ' · ' + operation.attempts + ' envoi(s) tenté(s)' : '')));
            li.appendChild(meta);
            li.appendChild(element('p', core.supported(operation) ? EXPLANATIONS[operation.state] : 'Ce brouillon a été écrit par une version plus récente de Campus. Il est conservé tel quel.', 'meta'));
            if (operation.state === 'synced') { return li; }
            var host = element('div');
            var actions = element('div', null, 'actions');
            if (operation.state === 'refused' || operation.state === 'conflict') {
                li.appendChild(element('p', (operation.ack && operation.ack.message) || 'Envoi non abouti.', 'alert'));
            }
            if (operation.state === 'conflict') {
                var changes = core.differences(operation);
                var dl = element('dl');
                changes.forEach(function (change) {
                    dl.appendChild(element('dt', change.label + ' — votre brouillon'));
                    dl.appendChild(element('dd', shown(change.field, change.mine)));
                    dl.appendChild(element('dt', change.label + ' — version enregistrée'));
                    dl.appendChild(element('dd', shown(change.field, change.theirs)));
                });
                li.appendChild(dl);
                if (operation.type !== 'task.create' && ((operation.ack && operation.ack.current) || operation.type === 'check_in.save')) {
                    actions.appendChild(button('Appliquer mon brouillon à la place', 'secondary', function () { outbox.resolve(operation.id, 'mine').then(render); }));
                }
                actions.appendChild(button('Garder la version enregistrée', 'secondary', function () { outbox.resolve(operation.id, 'server').then(render); }));
            } else {
                var details = element('details');
                details.appendChild(element('summary', 'Voir mon brouillon'));
                var content = element('dl');
                Object.keys(operation.payload).forEach(function (name) {
                    if (operation.payload[name] === null || !core.FIELD_LABELS[name] || name === 'personal_subject_id') { return; }
                    content.appendChild(element('dt', core.FIELD_LABELS[name]));
                    content.appendChild(element('dd', shown(name, operation.payload[name])));
                });
                details.appendChild(content);
                li.appendChild(details);
                if (operation.state === 'refused') {
                    actions.appendChild(button('Réessayer cet envoi', 'secondary', function () { outbox.retry(operation.id).then(render); }));
                } else if (core.supported(operation)) {
                    actions.appendChild(button('Modifier ce brouillon', 'secondary', function () { editDraft(operation, state, host, timezone); }));
                }
                actions.appendChild(button('Abandonner ce brouillon', 'danger', function () {
                    if (window.confirm('Abandonner « ' + operation.label + ' » ? Ce brouillon sera effacé de cet appareil.')) { outbox.discard(operation.id).then(render); }
                }));
            }
            li.appendChild(actions);
            li.appendChild(host);

            return li;
        }, 'Aucun brouillon. Ce que vous écrivez ici sans réseau apparaîtra dans cette liste, avec son état d’envoi.', 'plain');
    }

    function renderCopies(data, state, operations) {
        var snapshot = data.snapshot;
        var zone = snapshot.timezone || undefined;
        var day = new Intl.DateTimeFormat('fr', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', timeZone: zone });
        var open = {};
        operations.forEach(function (operation) { if (operation.state === 'local' || operation.state === 'pending') { open[operation.subject] = true; } });

        list(byId('tasks'), snapshot.tasks, function (task) {
            var li = element('li');
            li.appendChild(element('strong', task.title));
            li.appendChild(element('span', (task.dueAt ? ' — échéance ' + day.format(new Date(task.dueAt)) : ' — sans échéance') + (task.collective ? ' — devoir de l’établissement, à modifier en ligne' : ''), 'meta'));
            if (task.collective || !task.version) { return li; }
            if (open['task:' + task.id]) { li.appendChild(element('p', 'Un brouillon de modification est enregistré sur cet appareil (voir plus haut).', 'meta')); return li; }
            var host = element('div');
            var actions = element('div', null, 'actions');
            actions.appendChild(button('Modifier', 'secondary', function () {
                host.textContent = '';
                host.appendChild(taskForm({ title: task.title, description: task.description, due_at: task.dueLocal, priority: task.priority, status: task.status, personal_subject_id: task.personalSubjectId }, true, zone,
                    'Enregistrer ce brouillon sur cet appareil', function (fields) {
                        return outbox.draft({ accountKey: state.accountKey, type: 'task.update', targetId: task.id, baseVersion: task.version, fields: fields, label: fields.title });
                    }, function () { host.textContent = ''; }));
            }));
            li.appendChild(actions);
            li.appendChild(host);

            return li;
        }, 'Aucune tâche ouverte au moment de la synchronisation.', 'plain');

        // Cours sur lesquels un retour peut être préparé : terminés sans retour, ou commencés depuis la synchronisation, suivis comme apprenant.
        var courses = {};
        (snapshot.checkIns || []).forEach(function (course) { courses[course.id] = { id: course.id, title: course.title, startsAt: course.startsAt, version: course.version }; });
        (snapshot.planning || []).forEach(function (course) {
            var free = course.checkIn && (course.checkIn.status === null || course.checkIn.status === 'deferred');
            if (course.id && free && !course.cancelled && new Date(course.startsAt).getTime() <= Date.now() && !courses[course.id]) {
                courses[course.id] = { id: course.id, title: course.title, startsAt: course.startsAt, version: course.checkIn.version };
            }
        });
        list(byId('checkins'), Object.keys(courses).map(function (id) { return courses[id]; }), function (course) {
            var li = element('li');
            li.appendChild(element('strong', course.title));
            li.appendChild(element('span', ' — ' + day.format(new Date(course.startsAt)), 'meta'));
            if (open['check-in:' + course.id]) { li.appendChild(element('p', 'Un brouillon de retour est enregistré sur cet appareil (voir plus haut).', 'meta')); return li; }
            var host = element('div');
            var actions = element('div', null, 'actions');
            actions.appendChild(button('Écrire mon retour', 'secondary', function () {
                host.textContent = '';
                host.appendChild(checkInForm({}, function (fields) {
                    return outbox.draft({ accountKey: state.accountKey, type: 'check_in.save', targetId: course.id, baseVersion: course.version, fields: fields, label: course.title });
                }, function () { host.textContent = ''; }));
            }));
            li.appendChild(actions);
            li.appendChild(host);

            return li;
        }, 'Aucun cours commencé sans retour dans la copie de cet appareil.', 'plain');

        list(byId('planning'), snapshot.planning, function (course) {
            var li = element('li');
            li.appendChild(element('strong', course.title));
            li.appendChild(element('span', ' — ' + day.format(new Date(course.startsAt)) + (course.cancelled ? ' — annulé' : ''), 'meta'));

            return li;
        }, 'Aucun cours enregistré pour les ' + snapshot.windowDays + ' jours qui suivaient la synchronisation.');

        var grades = byId('grades');
        grades.textContent = '';
        if (!snapshot.grades || snapshot.grades.length === 0) { grades.appendChild(element('p', 'Aucune note enregistrée.', 'meta')); }
        (snapshot.grades || []).forEach(function (view) {
            grades.appendChild(element('h3', view.label + (view.period ? ' — ' + view.period : '')));
            if (view.general) { grades.appendChild(element('p', 'Moyenne générale indicative : ' + view.general + ' / 20', 'meta')); }
            var ul = element('ul');
            view.subjects.forEach(function (subject) {
                var li = element('li');
                li.appendChild(element('strong', subject.name + (subject.average ? ' : ' + subject.average + ' / 20' : '')));
                var inner = element('ul');
                subject.grades.forEach(function (grade) {
                    inner.appendChild(element('li', grade.title + ' — ' + (grade.status === 'graded' ? grade.value + ' / ' + grade.scale : 'non notée (ne vaut pas zéro)'), 'meta'));
                });
                li.appendChild(inner);
                ul.appendChild(li);
            });
            grades.appendChild(ul);
        });

        var consulted = (data.consulted || []).map(function (item) { return { title: item.title, organizer: item.organizer, deadline: item.deadline, state: 'consulted' }; });
        var states = { soon: 'favori, bientôt clos', open: 'favori', suspended: 'favori suspendu : ne candidatez pas', expired: 'favori expiré', consulted: 'consultée récemment' };
        list(byId('opportunities'), (snapshot.opportunities || []).concat(consulted), function (item) {
            var li = element('li');
            li.appendChild(element('strong', item.title));
            li.appendChild(element('span', ' — ' + item.organizer + ' — ' + states[item.state] + (item.deadline ? ' — date limite : ' + item.deadline : ''), 'meta'));

            return li;
        }, 'Aucune opportunité enregistrée.');
    }

    function render() {
        var state = readState();
        // Appareil partagé (réglage par défaut) : page neutre, et tout reste éventuel est effacé.
        if (!state || state.mode !== 'personal' || !state.accountKey) {
            if (state || !('indexedDB' in window)) { purge(); }

            return;
        }
        var operationsReady = outbox ? outbox.list(state.accountKey).catch(function () { return []; }) : Promise.resolve([]);
        operationsReady.then(function (operations) {
            var unsynced = operations.filter(function (operation) { return operation.state !== 'synced'; });
            var ttlDays = state.ttlDays || 7;
            var stale = state.lastSyncAt && Date.now() - new Date(state.lastSyncAt).getTime() > ttlDays * 86400000;
            if (stale && unsynced.length === 0) {
                // Copie trop ancienne sans contrôle des droits, et aucun brouillon : tout est effacé à l'ouverture.
                purge();
                byId('intro').textContent = 'Campus ne peut pas joindre le réseau. Les données conservées sur cet appareil dataient de plus de ' + ttlDays + ' jours sans vérification : elles ont été effacées. Reconnectez-vous au réseau pour les retrouver.';

                return null;
            }
            if (stale) {
                // Copie trop ancienne, mais des brouillons attendent : seule la copie de lecture est effacée. Ce que la personne a écrit reste.
                state.lastSyncAt = null;
                writeState(state);

                return core.clearReadCopies(window.indexedDB).catch(function () { return null; }).then(function () { return { operations: operations, data: null, cleared: ttlDays }; });
            }
            if (!state.lastSyncAt && unsynced.length === 0) { return null; }

            return readCopies().then(function (data) { return { operations: operations, data: data, cleared: 0 }; });
        }).then(function (view) {
            if (!view) { return; }
            var data = view.data && view.data.snapshot && view.data.snapshot.accountKey === state.accountKey ? view.data : null;
            labels = (data && data.snapshot.labels) || DEFAULT_LABELS;
            var zone = data ? data.snapshot.timezone : null;
            byId('title').textContent = 'Hors connexion';
            byId('intro').textContent = 'Campus ne peut pas joindre le réseau. Voici ce que vous avez choisi de conserver sur cet appareil personnel.';
            byId('sync').textContent = data
                ? 'Dernière synchronisation : ' + new Intl.DateTimeFormat('fr', { dateStyle: 'full', timeStyle: 'short', timeZone: zone || undefined }).format(new Date(state.lastSyncAt)) + '. Données potentiellement périmées.'
                : (view.cleared ? 'La copie de lecture datait de plus de ' + view.cleared + ' jours sans vérification : elle a été effacée. Vos brouillons sont conservés.' : 'Aucune copie de lecture sur cet appareil. Vos brouillons sont conservés.');
            byId('copies').hidden = !data;
            renderDrafts(view.operations, state, zone);
            var host = byId('new-task');
            host.textContent = '';
            if (outbox) {
                host.appendChild(taskForm({}, false, zone, 'Enregistrer cette tâche sur cet appareil', function (fields) {
                    return outbox.draft({ accountKey: state.accountKey, type: 'task.create', fields: fields, label: fields.title }).then(function (result) {
                        if (result.errors.length === 0) { say('Tâche enregistrée sur cet appareil. Elle sera envoyée quand vous rouvrirez Campus avec du réseau.'); }

                        return result;
                    });
                }));
            } else {
                host.appendChild(element('p', 'Ce navigateur ne permet pas d’écrire de brouillon hors connexion.', 'meta'));
            }
            if (data) { renderCopies(data, state, view.operations); }
            byId('private').hidden = false;
        });
    }

    byId('retry').addEventListener('click', function () { window.location.reload(); });
    byId('erase').addEventListener('click', function () {
        var state = readState();
        var pending = outbox && state && state.accountKey ? outbox.unsynced(state.accountKey).catch(function () { return []; }) : Promise.resolve([]);
        pending.then(function (operations) {
            // Rien de ce que la personne a écrit n'est effacé sans qu'elle le confirme.
            if (operations.length > 0 && !window.confirm(operations.length + ' brouillon(s) ne sont pas enregistrés sur le serveur. Tout effacer, brouillons compris ?')) { return; }
            purge().then(function () { window.location.reload(); });
        });
    });
    window.addEventListener('online', function () { byId('online').hidden = false; });

    render();
})();
